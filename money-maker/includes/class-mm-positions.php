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

		// Questrade's position payload has no currency field, so resolve it from
		// activity history (M4b). Without this the market values below are
		// unlabelled numbers and cannot be summed across a mixed USD/CAD book.
		$currency_map = MM_Activities::symbol_currencies();

		$written = 0;
		foreach ( $positions as $position ) {
			if ( ! is_array( $position ) ) {
				continue;
			}

			$symbol = sanitize_text_field( (string) ( $position['symbol'] ?? '' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ok = $wpdb->insert(
				$table,
				array(
					'account_number'       => $account_number,
					'snapshot_date'        => $snapshot_date,
					'snapshot_at'          => $snapshot_at,
					'symbol'               => $symbol,
					'symbol_id'            => (int) ( $position['symbolId'] ?? 0 ),
					'open_quantity'        => (float) ( $position['openQuantity'] ?? 0 ),
					'current_price'        => isset( $position['currentPrice'] ) ? (float) $position['currentPrice'] : null,
					'current_market_value' => isset( $position['currentMarketValue'] ) ? (float) $position['currentMarketValue'] : null,
					'average_entry_price'  => isset( $position['averageEntryPrice'] ) ? (float) $position['averageEntryPrice'] : null,
					'total_cost'           => isset( $position['totalCost'] ) ? (float) $position['totalCost'] : null,
					'open_pnl'             => isset( $position['openPnl'] ) ? (float) $position['openPnl'] : null,
					'currency'             => $currency_map[ $symbol ] ?? null,
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
	 * Portfolio market value per snapshot date, oldest first, expressed in one
	 * currency (the display currency by default).
	 *
	 * M4b fix: this was a bare `SUM(current_market_value) GROUP BY snapshot_date`,
	 * which added USD and CAD market values as though they were the same unit —
	 * wrong for any book holding US-listed securities, and increasingly wrong as
	 * USD/CAD moves. Each row is now converted at its own snapshot date's rate,
	 * so the chart agrees with the totals MM_Holdings::current() puts in the
	 * table. (This supersedes the M3e decision to leave the series unconverted;
	 * that call was made when the chart was a tail-end nicety.)
	 *
	 * M4f: the target is no longer hardcoded to CAD — it follows the display
	 * currency, so the chart and the holdings total stay in the same unit.
	 *
	 * @param string $currency Target currency; defaults to the display currency.
	 * @return array<string,float> snapshot_date => total market value
	 */
	public static function value_series( string $currency = '' ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			'SELECT snapshot_date, symbol, currency, current_market_value
			 FROM ' . MM_DB::table( 'positions_snapshots' ) . '
			 WHERE current_market_value IS NOT NULL
			 ORDER BY snapshot_date ASC',
			ARRAY_A
		);

		$target = strtoupper( trim( $currency ) );
		if ( '' === $target ) {
			$target = MM_Money::display_currency();
		}

		$fallback  = null;   // Activity-derived currency map, loaded on first need.
		$converted = array(); // currency|date => factor, so each date costs one lookup.
		$series    = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$date     = (string) $row['snapshot_date'];
			$amount   = (float) $row['current_market_value'];
			$currency = strtoupper( trim( (string) ( $row['currency'] ?? '' ) ) );

			// Snapshots written before M4b have currency NULL. Resolve them from
			// activity history rather than silently assuming CAD.
			if ( '' === $currency ) {
				if ( null === $fallback ) {
					$fallback = MM_Activities::symbol_currencies();
				}
				$currency = $fallback[ (string) $row['symbol'] ] ?? 'CAD';
			}

			if ( $target !== $currency ) {
				$key = $currency . '|' . $date;

				if ( ! array_key_exists( $key, $converted ) ) {
					$one                = MM_Money::convert( 1.0, $currency, $target, $date );
					$converted[ $key ] = $one;
				}

				// No stored rate: leave the amount as-is, matching
				// MM_Holdings. Better a slightly wrong point than a hole in the
				// line — the holdings screen counts and reports the gap.
				if ( null !== $converted[ $key ] ) {
					$amount *= (float) $converted[ $key ];
				}
			}

			$series[ $date ] = ( $series[ $date ] ?? 0.0 ) + $amount;
		}

		foreach ( $series as $date => $total ) {
			$series[ $date ] = round( $total, 2 );
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
