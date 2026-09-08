<?php
/**
 * Activities repository (Milestone 2d).
 *
 * Questrade activities have no stable id and sync windows overlap by design, so
 * every row carries a deterministic dedup_hash (UNIQUE) built from the
 * business-identifying fields, and writes are upserts keyed on that hash.
 *
 * Normalisation on store:
 *   - Questrade timestamps are US-Eastern with an explicit offset; transaction_at
 *     is converted to UTC. trade_date / settlement_date are kept as calendar
 *     DATEs (no timezone shift).
 *   - Money columns are stored in their native currency. Once an FX rate resolves
 *     (via MM_FX), fx_rate / fx_rate_date / net_amount_cad are filled; CAD rows
 *     get fx_rate = 1.
 *
 * Static utility: no hooks, no register(). Required directly by money-maker.php.
 *
 * @package MoneyMaker
 */

defined( 'ABSPATH' ) || exit;

/**
 * mm_activities persistence + dedup + FX stamping.
 */
final class MM_Activities {

	/**
	 * Deterministic dedup key for one activity in one account.
	 *
	 * Fields (per CLAUDE.md Module B): account + settlementDate + action + symbol
	 * + quantity + price + netAmount + currency. Numbers are formatted to a fixed
	 * precision so 1 and 1.0 hash the same.
	 *
	 * @param string $account_number Full account number.
	 * @param array  $activity       Raw Questrade activity object.
	 */
	public static function dedup_hash( string $account_number, array $activity ): string {
		$parts = array(
			trim( $account_number ),
			self::date_part( $activity['settlementDate'] ?? '' ),
			strtoupper( trim( (string) ( $activity['action'] ?? '' ) ) ),
			strtoupper( trim( (string) ( $activity['symbol'] ?? '' ) ) ),
			self::num( $activity['quantity'] ?? 0 ),
			self::num( $activity['price'] ?? 0 ),
			self::num( $activity['netAmount'] ?? 0 ),
			strtoupper( trim( (string) ( $activity['currency'] ?? '' ) ) ),
		);

		return hash( 'sha256', implode( '|', $parts ) );
	}

	/**
	 * Insert or update one activity. Resolves an FX rate for non-CAD rows.
	 *
	 * @param string $account_number Full account number.
	 * @param array  $activity       Raw Questrade activity object.
	 * @return string '' on failure, otherwise 'inserted' | 'updated' | 'unchanged'.
	 */
	public static function upsert( string $account_number, array $activity ): string {
		global $wpdb;

		$account_number = trim( $account_number );
		if ( '' === $account_number ) {
			return '';
		}

		$hash  = self::dedup_hash( $account_number, $activity );
		$table = MM_DB::table( 'activities' );

		$currency        = strtoupper( trim( (string) ( $activity['currency'] ?? 'CAD' ) ) ) ?: 'CAD';
		$settlement_date = self::date_part( $activity['settlementDate'] ?? '' );
		$trade_date      = self::date_part( $activity['tradeDate'] ?? '' );
		$net_amount      = (float) ( $activity['netAmount'] ?? 0 );

		list( $fx_rate, $fx_rate_date ) = self::resolve_fx( $currency, $settlement_date ?: $trade_date );

		$net_amount_cad = null;
		if ( null !== $fx_rate ) {
			$net_amount_cad = round( $net_amount * $fx_rate, 4 );
		}

		$data = array(
			'account_number' => $account_number,
			'dedup_hash'     => $hash,
			'trade_date'     => $trade_date,
			'settlement_date' => $settlement_date,
			'transaction_at' => self::utc_datetime( $activity['transactionDate'] ?? ( $activity['tradeDate'] ?? '' ) ),
			'action'         => sanitize_text_field( (string) ( $activity['action'] ?? '' ) ),
			'type'           => sanitize_text_field( (string) ( $activity['type'] ?? '' ) ),
			'symbol'         => sanitize_text_field( (string) ( $activity['symbol'] ?? '' ) ),
			'symbol_id'      => (int) ( $activity['symbolId'] ?? 0 ),
			'description'    => sanitize_text_field( (string) ( $activity['description'] ?? '' ) ),
			'quantity'       => (float) ( $activity['quantity'] ?? 0 ),
			'price'          => (float) ( $activity['price'] ?? 0 ),
			'gross_amount'   => (float) ( $activity['grossAmount'] ?? 0 ),
			'commission'     => (float) ( $activity['commission'] ?? 0 ),
			'net_amount'     => $net_amount,
			'currency'       => $currency,
			'fx_rate'        => $fx_rate,
			'fx_rate_date'   => $fx_rate_date,
			'net_amount_cad' => $net_amount_cad,
			'raw_json'       => wp_json_encode( $activity ),
			'synced_at'      => current_time( 'mysql', true ),
		);

		$formats = array(
			'%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s',
			'%f', '%f', '%f', '%f', '%f', '%s', '%f', '%s', '%f', '%s', '%s',
		);

		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE dedup_hash = %s", $hash ) );

		if ( ! $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ok = $wpdb->insert( $table, $data, $formats );
			return $ok ? 'inserted' : '';
		}

		// Only rewrite when a meaningful field moved. FX backfill counts as a change.
		$before = $wpdb->get_row( $wpdb->prepare( "SELECT net_amount, fx_rate, description, symbol_id FROM {$table} WHERE id = %d", (int) $existing ), ARRAY_A );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $table, $data, array( 'id' => (int) $existing ), $formats, array( '%d' ) );

		$changed = ! $before
			|| (float) $before['net_amount'] !== $net_amount
			|| ( null === $before['fx_rate'] ) !== ( null === $fx_rate )
			|| (string) $before['description'] !== $data['description']
			|| (int) $before['symbol_id'] !== $data['symbol_id'];

		return $changed ? 'updated' : 'unchanged';
	}

	/**
	 * Fill fx_rate / fx_rate_date / net_amount_cad on rows that are still missing
	 * an FX rate (e.g. stored before the Bank of Canada window was fetched).
	 *
	 * @param int $limit Max rows to process in one pass.
	 * @return int Rows updated.
	 */
	public static function backfill_fx( int $limit = 500 ): int {
		global $wpdb;

		$table = MM_DB::table( 'activities' );
		$limit = max( 1, min( 5000, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, currency, settlement_date, trade_date, net_amount
				 FROM {$table}
				 WHERE fx_rate IS NULL AND currency <> 'CAD'
				 ORDER BY id ASC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return 0;
		}

		$updated = 0;
		foreach ( $rows as $row ) {
			$date = $row['settlement_date'] ?: $row['trade_date'];
			list( $rate, $rate_date ) = self::resolve_fx( (string) $row['currency'], (string) $date );

			if ( null === $rate ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$table,
				array(
					'fx_rate'        => $rate,
					'fx_rate_date'   => $rate_date,
					'net_amount_cad' => round( (float) $row['net_amount'] * $rate, 4 ),
				),
				array( 'id' => (int) $row['id'] ),
				array( '%f', '%s', '%f' ),
				array( '%d' )
			);
			++$updated;
		}

		return $updated;
	}

	/**
	 * Total stored activity rows, optionally for one account.
	 */
	public static function count( string $account_number = '' ): int {
		global $wpdb;

		$table = MM_DB::table( 'activities' );

		if ( '' === $account_number ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE account_number = %s", $account_number ) );
	}

	/**
	 * Oldest / newest settlement dates on record, for the admin coverage display.
	 *
	 * @return array{min:?string,max:?string}
	 */
	public static function settlement_span(): array {
		global $wpdb;

		$table = MM_DB::table( 'activities' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( "SELECT MIN(settlement_date) AS min, MAX(settlement_date) AS max FROM {$table} WHERE settlement_date IS NOT NULL", ARRAY_A );

		return array(
			'min' => $row && $row['min'] ? (string) $row['min'] : null,
			'max' => $row && $row['max'] ? (string) $row['max'] : null,
		);
	}

	/**
	 * Distinct (account_number, symbol) pairs that carry a symbol, restricted to
	 * a set of accounts — the pools the ACB engine needs to walk.
	 *
	 * @param string[] $account_numbers
	 * @return array<int,array{account_number:string,symbol:string}>
	 */
	public static function account_symbols( array $account_numbers ): array {
		global $wpdb;

		$account_numbers = array_values( array_filter( array_map( 'strval', $account_numbers ) ) );
		if ( empty( $account_numbers ) ) {
			return array();
		}

		$table        = MM_DB::table( 'activities' );
		$placeholders = implode( ', ', array_fill( 0, count( $account_numbers ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT account_number, symbol FROM {$table}
				 WHERE symbol <> '' AND account_number IN ({$placeholders})
				 ORDER BY account_number ASC, symbol ASC",
				$account_numbers
			),
			ARRAY_A
		);

		return is_array( $rows ) ? array_map(
			static function ( $row ) {
				return array(
					'account_number' => (string) $row['account_number'],
					'symbol'         => (string) $row['symbol'],
				);
			},
			$rows
		) : array();
	}

	/**
	 * Every stored activity for one (account, symbol) pool, in the order the ACB
	 * engine applies them: by settlement date, then intraday by timestamp, then
	 * insertion order.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_acb( string $account_number, string $symbol ): array {
		global $wpdb;

		$table = MM_DB::table( 'activities' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, settlement_date, trade_date, transaction_at, action, type, symbol,
				        quantity, price, commission, gross_amount, net_amount, currency,
				        fx_rate, fx_rate_date, net_amount_cad
				 FROM {$table}
				 WHERE account_number = %s AND symbol = %s
				 ORDER BY COALESCE(settlement_date, trade_date, DATE(transaction_at)) ASC,
				          transaction_at ASC, id ASC",
				$account_number,
				$symbol
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Non-CAD activity rows still missing a CAD conversion, for a set of
	 * accounts. Drives a "run an FX sync" warning on the tax screens.
	 *
	 * @param string[] $account_numbers
	 */
	public static function missing_cad_count( array $account_numbers ): int {
		global $wpdb;

		$account_numbers = array_values( array_filter( array_map( 'strval', $account_numbers ) ) );
		if ( empty( $account_numbers ) ) {
			return 0;
		}

		$table        = MM_DB::table( 'activities' );
		$placeholders = implode( ', ', array_fill( 0, count( $account_numbers ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				 WHERE currency <> 'CAD' AND net_amount_cad IS NULL
				   AND account_number IN ({$placeholders})",
				$account_numbers
			)
		);
	}

	/**
	 * Row count + newest synced_at — part of the ACB cache fingerprint.
	 *
	 * @return array{count:int,synced_at:?string}
	 */
	public static function fingerprint_parts(): array {
		global $wpdb;

		$table = MM_DB::table( 'activities' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( "SELECT COUNT(*) AS c, MAX(synced_at) AS s FROM {$table}", ARRAY_A );

		return array(
			'count'     => $row ? (int) $row['c'] : 0,
			'synced_at' => $row && $row['s'] ? (string) $row['s'] : null,
		);
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Resolve an FX rate for a currency + date.
	 *
	 * @return array{0: float|null, 1: string|null} [rate, rate_date] — rate_date is
	 *         the date passed through (the actual business day used is inside MM_FX).
	 */
	private static function resolve_fx( string $currency, string $date ): array {
		$currency = strtoupper( trim( $currency ) ) ?: 'CAD';

		if ( 'CAD' === $currency ) {
			return array( 1.0, $date ?: null );
		}

		if ( '' === $date ) {
			return array( null, null );
		}

		$rate = MM_FX::rate( $currency, $date, true );

		if ( is_wp_error( $rate ) ) {
			return array( null, null );
		}

		return array( (float) $rate, $date );
	}

	/**
	 * Y-m-d from a Questrade date/timestamp string, or null.
	 */
	private static function date_part( $value ): ?string {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}

		$ts = strtotime( $value );
		return false === $ts ? null : gmdate( 'Y-m-d', $ts );
	}

	/**
	 * UTC 'Y-m-d H:i:s' from a Questrade timestamp (which carries its own offset),
	 * or null.
	 */
	private static function utc_datetime( $value ): ?string {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}

		try {
			$dt = new DateTimeImmutable( $value );
		} catch ( Exception $e ) {
			return null;
		}

		return $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Format a number to a stable string for hashing.
	 */
	private static function num( $value ): string {
		return number_format( (float) $value, 6, '.', '' );
	}
}
