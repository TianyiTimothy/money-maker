<?php
/**
 * Positions-snapshot repository (Milestone 2e).
 *
 * Questrade's /v1/accounts/{id}/positions returns *current* open positions with
 * no history, so each sync writes a dated snapshot. One row per
 * account / snapshot_date / symbol; re-running a sync the same day overwrites
 * that day's snapshot rather than stacking duplicates.
 *
 * Positions are not used by the tax math (ACB is derived from activities) — they
 * feed the Milestone 3 dashboard and historical charts.
 *
 * Static utility: no hooks, no register(). Required directly by money-maker.php.
 *
 * @package MoneyMaker
 */

defined( 'ABSPATH' ) || exit;

/**
 * mm_positions_snapshots persistence.
 */
final class MM_Positions {

	/**
	 * Replace today's snapshot for one account with a fresh set of position rows.
	 *
	 * @param string $account_number Full account number.
	 * @param array  $positions      Raw Questrade position objects.
	 * @param string $snapshot_date  Y-m-d. Defaults to today (UTC).
	 * @return int Rows written.
	 */
	public static function store_snapshot( string $account_number, array $positions, string $snapshot_date = '' ): int {
		global $wpdb;

		$account_number = trim( $account_number );
		if ( '' === $account_number ) {
			return 0;
		}

		$snapshot_date = $snapshot_date ?: gmdate( 'Y-m-d' );
		$snapshot_at   = current_time( 'mysql', true );
		$table         = MM_DB::table( 'positions_snapshots' );

		// Clear any existing snapshot for this account/day so a shrinking position
		// list does not leave stale rows behind.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete(
			$table,
			array(
				'account_number' => $account_number,
				'snapshot_date'  => $snapshot_date,
			),
			array( '%s', '%s' )
		);

		$written = 0;
		foreach ( $positions as $position ) {
			if ( ! is_array( $position ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ok = $wpdb->insert(
				$table,
				array(
					'account_number'       => $account_number,
					'snapshot_date'        => $snapshot_date,
					'snapshot_at'          => $snapshot_at,
					'symbol'               => sanitize_text_field( (string) ( $position['symbol'] ?? '' ) ),
					'symbol_id'            => (int) ( $position['symbolId'] ?? 0 ),
					'open_quantity'        => (float) ( $position['openQuantity'] ?? 0 ),
					'current_price'        => isset( $position['currentPrice'] ) ? (float) $position['currentPrice'] : null,
					'current_market_value' => isset( $position['currentMarketValue'] ) ? (float) $position['currentMarketValue'] : null,
					'average_entry_price'  => isset( $position['averageEntryPrice'] ) ? (float) $position['averageEntryPrice'] : null,
					'total_cost'           => isset( $position['totalCost'] ) ? (float) $position['totalCost'] : null,
					'open_pnl'             => isset( $position['openPnl'] ) ? (float) $position['openPnl'] : null,
					'currency'             => null,
					'raw_json'             => wp_json_encode( $position ),
				),
				array( '%s', '%s', '%s', '%s', '%d', '%f', '%f', '%f', '%f', '%f', '%f', '%s', '%s' )
			);

			if ( $ok ) {
				++$written;
			}
		}

		return $written;
	}

	/**
	 * The most recent snapshot date for one account, or null.
	 */
	public static function latest_date_for( string $account_number ): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$date = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MAX(snapshot_date) FROM ' . MM_DB::table( 'positions_snapshots' ) . ' WHERE account_number = %s',
				trim( $account_number )
			)
		);

		return $date ?: null;
	}

	/**
	 * The rows of the most recent snapshot for one account (open positions only).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function latest_snapshot( string $account_number ): array {
		global $wpdb;

		$latest = self::latest_date_for( $account_number );
		if ( null === $latest ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . MM_DB::table( 'positions_snapshots' ) . '
				 WHERE account_number = %s AND snapshot_date = %s AND open_quantity <> 0
				 ORDER BY symbol ASC',
				trim( $account_number ),
				$latest
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Portfolio market value per snapshot date (sum of current_market_value as
	 * reported by Questrade, not FX-normalised), oldest first.
	 *
	 * @return array<string,float> snapshot_date => total
	 */
	public static function value_series(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			'SELECT snapshot_date, SUM(current_market_value) AS total
			 FROM ' . MM_DB::table( 'positions_snapshots' ) . '
			 WHERE current_market_value IS NOT NULL
			 GROUP BY snapshot_date
			 ORDER BY snapshot_date ASC',
			ARRAY_A
		);

		$series = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$series[ (string) $row['snapshot_date'] ] = (float) $row['total'];
		}

		return $series;
	}

	/**
	 * The most recent snapshot date on record, or null.
	 */
	public static function latest_date(): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$date = $wpdb->get_var( 'SELECT MAX(snapshot_date) FROM ' . MM_DB::table( 'positions_snapshots' ) );

		return $date ?: null;
	}

	/**
	 * Total stored snapshot rows.
	 */
	public static function count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . MM_DB::table( 'positions_snapshots' ) );
	}
}
