<?php
/**
 * Bank of Canada foreign-exchange rates (Milestone 2c).
 *
 * Every tax figure is stored in CAD. USD activities convert at the transaction
 * date's rate, so the plugin keeps a local table of daily USD→CAD rates from the
 * Bank of Canada Valet API, series FXUSDCAD (one published rate per business day
 * since 2017-01-03).
 *
 *   - ensure_range()  fetches + upserts any rates the local table is missing for
 *                     a date window, in one Valet call.
 *   - rate()          returns the rate for a date, falling back to the most recent
 *                     prior business day (weekends / holidays have no observation).
 *
 * Static utility: no hooks, no register(). Required directly by money-maker.php.
 *
 * @package MoneyMaker
 */

defined( 'ABSPATH' ) || exit;

/**
 * USD/CAD rate storage + lookup.
 */
final class MM_FX {

	/** Valet observations endpoint (JSON). */
	const VALET_URL = 'https://www.bankofcanada.ca/valet/observations/%s/json';

	/** Series for a single daily USD→CAD rate. Only valid from EARLIEST_DATE. */
	const SERIES_USDCAD = 'FXUSDCAD';

	/** Earliest date the single daily FXUSDCAD series covers. */
	const EARLIEST_DATE = '2017-01-03';

	/** Per-request timeout for the Valet call (seconds). */
	const HTTP_TIMEOUT = 20;

	/** Source tag written to mm_fx_rates.source. */
	const SOURCE = 'boc-valet';

	/** Option recording the [from,to] span already fetched per pair. */
	const COVERAGE_OPTION = 'mm_fx_coverage';

	/**
	 * CAD value of one unit of $base on $date.
	 *
	 * @param string $base  Base currency, e.g. 'USD'. 'CAD' always returns 1.0.
	 * @param string $date  Y-m-d.
	 * @param bool   $fetch When true, try to fetch a small window around $date if
	 *                      nothing suitable is stored yet.
	 * @return float|WP_Error Rate (CAD per 1 $base), or WP_Error if unavailable.
	 */
	public static function rate( string $base, string $date, bool $fetch = true ) {
		$base = strtoupper( trim( $base ) );
		$date = self::normalise_date( $date );

		if ( 'CAD' === $base ) {
			return 1.0;
		}

		if ( null === $date ) {
			return new WP_Error( 'mm_fx_bad_date', __( 'Invalid date for an exchange-rate lookup.', 'money-maker' ) );
		}

		if ( 'USD' !== $base ) {
			return new WP_Error(
				'mm_fx_unsupported',
				sprintf(
					/* translators: %s: currency code */
					__( 'No exchange-rate source configured for %s.', 'money-maker' ),
					$base
				)
			);
		}

		$stored = self::lookup( $base, 'CAD', $date );

		if ( null === $stored && $fetch ) {
			// Pull a 10-day window ending on the requested date so a weekend /
			// holiday date still resolves to a prior business day.
			$from = gmdate( 'Y-m-d', strtotime( $date . ' -10 days' ) );
			$result = self::ensure_range( $from, $date );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$stored = self::lookup( $base, 'CAD', $date );
		}

		if ( null === $stored ) {
			return new WP_Error(
				'mm_fx_missing',
				sprintf(
					/* translators: %s: date */
					__( 'No USD/CAD rate available on or before %s.', 'money-maker' ),
					$date
				)
			);
		}

		return $stored;
	}

	/**
	 * Ensure mm_fx_rates has a USD→CAD row for every business day in [from, to],
	 * fetching the gap from the Bank of Canada in one call.
	 *
	 * @param string $from Y-m-d (clamped to EARLIEST_DATE).
	 * @param string $to   Y-m-d (clamped to today).
	 * @return int|WP_Error Rows upserted, or WP_Error on a fetch/parse failure.
	 */
	public static function ensure_range( string $from, string $to ) {
		$from = self::normalise_date( $from );
		$to   = self::normalise_date( $to );

		if ( null === $from || null === $to ) {
			return new WP_Error( 'mm_fx_bad_range', __( 'Invalid date range for exchange rates.', 'money-maker' ) );
		}

		$today = gmdate( 'Y-m-d' );
		if ( $from < self::EARLIEST_DATE ) {
			$from = self::EARLIEST_DATE;
		}
		if ( $to > $today ) {
			$to = $today;
		}
		if ( $from > $to ) {
			return 0;
		}

		// Already fetched this span (and it is still fresh)? Nothing to do.
		$covered = self::coverage();
		if ( null !== $covered
			&& $from >= $covered['from']
			&& $to <= $covered['to']
			&& $covered['to'] >= gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) ) ) {
			return 0;
		}

		// Fetch one contiguous span covering both the old coverage and the new
		// request, so mm_fx_rates never has interior gaps.
		if ( null !== $covered ) {
			$from = min( $from, $covered['from'] );
			$to   = max( $to, $covered['to'] );
		}
		if ( $from < self::EARLIEST_DATE ) {
			$from = self::EARLIEST_DATE;
		}

		$observations = self::fetch_observations( self::SERIES_USDCAD, $from, $to );

		if ( is_wp_error( $observations ) ) {
			return $observations;
		}

		$upserted = 0;
		foreach ( $observations as $obs_date => $value ) {
			if ( self::store( 'USD', 'CAD', $obs_date, $value, self::SERIES_USDCAD ) ) {
				++$upserted;
			}
		}

		self::set_coverage( $from, $to );

		return $upserted;
	}

	/**
	 * The [from, to] span already fetched from the Bank of Canada, or null.
	 *
	 * @return array{from:string,to:string}|null
	 */
	private static function coverage(): ?array {
		$stored = get_option( self::COVERAGE_OPTION );

		if ( ! is_array( $stored ) || empty( $stored['from'] ) || empty( $stored['to'] ) ) {
			return null;
		}

		return array(
			'from' => (string) $stored['from'],
			'to'   => (string) $stored['to'],
		);
	}

	/**
	 * Widen the recorded coverage span to include [from, to].
	 */
	private static function set_coverage( string $from, string $to ): void {
		$covered = self::coverage();

		if ( null !== $covered ) {
			$from = min( $from, $covered['from'] );
			$to   = max( $to, $covered['to'] );
		}

		$value = array(
			'from' => $from,
			'to'   => $to,
		);

		if ( false === get_option( self::COVERAGE_OPTION ) ) {
			add_option( self::COVERAGE_OPTION, $value, '', 'no' );
		} else {
			update_option( self::COVERAGE_OPTION, $value, false );
		}
	}

	/**
	 * Store (insert or update) one rate row.
	 *
	 * @return bool True if a row was written.
	 */
	public static function store( string $base, string $quote, string $date, float $rate, string $series = '' ): bool {
		global $wpdb;

		$date = self::normalise_date( $date );
		if ( null === $date || $rate <= 0 ) {
			return false;
		}

		$table    = MM_DB::table( 'fx_rates' );
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE rate_date = %s AND base_currency = %s AND quote_currency = %s",
				$date,
				$base,
				$quote
			)
		);

		$row = array(
			'rate'       => $rate,
			'source'     => self::SOURCE,
			'series'     => $series,
			'fetched_at' => current_time( 'mysql', true ),
		);

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, $row, array( 'id' => (int) $existing ), array( '%f', '%s', '%s', '%s' ), array( '%d' ) );
			return true;
		}

		$row['rate_date']      = $date;
		$row['base_currency']  = $base;
		$row['quote_currency'] = $quote;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->insert( $table, $row, array( '%f', '%s', '%s', '%s', '%s', '%s', '%s' ) );
	}

	/**
	 * Newest stored rate date for a pair, or null.
	 */
	public static function latest_date( string $base = 'USD', string $quote = 'CAD' ): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$date = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MAX(rate_date) FROM ' . MM_DB::table( 'fx_rates' ) . ' WHERE base_currency = %s AND quote_currency = %s',
				$base,
				$quote
			)
		);

		return $date ?: null;
	}

	/**
	 * Number of stored rate rows.
	 */
	public static function count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . MM_DB::table( 'fx_rates' ) );
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Most recent stored rate on or before $date, or null.
	 */
	private static function lookup( string $base, string $quote, string $date ): ?float {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT rate FROM ' . MM_DB::table( 'fx_rates' ) . '
				 WHERE base_currency = %s AND quote_currency = %s AND rate_date <= %s
				 ORDER BY rate_date DESC LIMIT 1',
				$base,
				$quote,
				$date
			)
		);

		return null === $value ? null : (float) $value;
	}

	/**
	 * Call the Valet observations endpoint and return date => rate pairs.
	 *
	 * @return array<string,float>|WP_Error
	 */
	private static function fetch_observations( string $series, string $from, string $to ) {
		$url = add_query_arg(
			array(
				'start_date' => $from,
				'end_date'   => $to,
			),
			sprintf( self::VALET_URL, rawurlencode( $series ) )
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::HTTP_TIMEOUT,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'mm_fx_http_' . $code,
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Bank of Canada Valet returned HTTP %d.', 'money-maker' ),
					$code
				)
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || empty( $data['observations'] ) || ! is_array( $data['observations'] ) ) {
			return new WP_Error( 'mm_fx_bad_body', __( 'Could not parse the Bank of Canada exchange-rate response.', 'money-maker' ) );
		}

		$out = array();
		foreach ( $data['observations'] as $obs ) {
			if ( empty( $obs['d'] ) || ! isset( $obs[ $series ]['v'] ) ) {
				continue;
			}
			$value = (float) $obs[ $series ]['v'];
			if ( $value > 0 ) {
				$out[ (string) $obs['d'] ] = $value;
			}
		}

		return $out;
	}

	/**
	 * Validate a Y-m-d string; return it normalised or null.
	 */
	private static function normalise_date( string $date ): ?string {
		$date = trim( $date );

		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			// Accept a fuller timestamp too — take the date part.
			$ts = strtotime( $date );
			if ( false === $ts ) {
				return null;
			}
			return gmdate( 'Y-m-d', $ts );
		}

		return $date;
	}
}
