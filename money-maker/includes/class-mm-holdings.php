<?php
/**
 * Holdings view-model (Milestone 3d, re-based on native currency in M4f).
 *
 * Joins the latest positions snapshot (what Questrade currently reports open)
 * with the cost base from MM_Tax_ACB to give a per-account and consolidated
 * holdings table with unrealised gain/loss.
 *
 * **Currency (M4f).** Every line is carried in the security's *own* trading
 * currency — a USD stock's cost and market value are USD numbers, because that
 * is what was actually paid and what the user thinks in. Only the totals are
 * converted, into the configured display currency (USD by default), because a
 * mixed book has to add up to something.
 *
 * That is why the cost base is read on the ACB engine's `native` basis rather
 * than converting its CAD figure back: the CAD pool was accumulated at each
 * trade's own historic rate, so undoing it at today's rate would produce a USD
 * cost the user never paid. The tax screens keep asking for the `cad` basis.
 *
 * Scope (M4a): **every** account gets a book cost, registered included. In a
 * TFSA/RRSP that figure has no CRA meaning, but "what did this cost me" is the
 * whole point of a holdings summary; the caller labels those rows as
 * for-reference-only. The tax screens keep their own narrower scope.
 *
 * Cost base arrives from two walks (M4e): shares from the pooled average-cost
 * model, open option contracts from MM_Tax_Options, which sits outside that
 * model. Both land in the same book / market / unrealised columns, so the
 * totals reconcile — before M4e an option's market value was counted while its
 * cost was not, and the Total row did not add up.
 *
 * Static utility: no register(). Required directly by money-maker.php.
 *
 * @package MoneyMaker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Current-holdings assembler.
 */
final class MM_Holdings {

	/** Float tolerance for share quantities. */
	const EPSILON = 0.0000001;

	/**
	 * Activity-derived symbol → currency map, loaded once per request.
	 * Same source MM_Positions::value_series() uses, so the portfolio chart and
	 * this table cannot disagree about what currency a position trades in.
	 *
	 * @var array<string,string>|null
	 */
	private static ?array $symbol_currencies = null;

	/**
	 * Build the holdings view-model.
	 *
	 * Per-line money (`avg_cost`, `book`, `market`, `unrealised`) is in the
	 * line's own `currency`. Every `*_display` figure, and every total, is in
	 * `display_currency`.
	 *
	 * @return array{
	 *   accounts:array<int,array<string,mixed>>,
	 *   consolidated:array<int,array<string,mixed>>,
	 *   totals:array{book:?float,market:?float,unrealised:?float},
	 *   display_currency:string,
	 *   as_of:?string,
	 *   has_data:bool,
	 *   data_quality:array{reviews:int,unconverted:int,warnings:int,options_no_cost:int}
	 * }
	 */
	public static function current(): array {
		$all_numbers    = MM_Accounts::numbers();
		$non_registered = MM_Accounts::non_registered_numbers();
		$display        = MM_Money::display_currency();

		// M4a: pool every account, registered included. In a TFSA/RRSP the cost
		// base has no CRA meaning, but "what did this cost me / how much am I up"
		// is the whole point of a holdings summary. The tax screens keep passing
		// non_registered_numbers() — the scope split lives at the call site, not
		// in the engine. $non_registered stays in use below purely to label rows.
		// M4f: 'native' basis — see the class docblock.
		$acb = MM_Tax_ACB::get( $all_numbers, 'native' );

		$accounts     = array();
		$consolidated = array();
		$as_of        = null;
		$has_data     = false;

		// Option positions the ACB/option walk could not put a cost on (M4e).
		$options_no_cost = 0;
		// Lines whose own currency could not be converted into the display
		// currency, so they are missing from the totals (M4f).
		$unconverted = 0;

		$grand = array( 'book' => 0.0, 'market' => 0.0, 'unrealised' => 0.0 );

		foreach ( $all_numbers as $number ) {
			$snapshot_date = MM_Positions::latest_date_for( $number );
			$rows          = MM_Positions::latest_snapshot( $number );
			$is_registered = ! in_array( $number, $non_registered, true );

			if ( null !== $snapshot_date ) {
				$as_of = null === $as_of ? $snapshot_date : max( $as_of, $snapshot_date );
			}

			if ( empty( $rows ) ) {
				continue;
			}

			$has_data = true;
			$lines    = array();
			$subtotal = array( 'book' => 0.0, 'market' => 0.0, 'unrealised' => 0.0 );

			foreach ( $rows as $row ) {
				$symbol   = (string) $row['symbol'];
				$quantity = (float) $row['open_quantity'];

				if ( abs( $quantity ) < self::EPSILON ) {
					continue;
				}

				// M4e: an open option is a real position with a real cost, so it
				// carries a book value like anything else. It just comes from a
				// different walk — options are outside the pooled-average model
				// (MM_Tax_ACB), so MM_Tax_Options supplies it.
				$is_option = MM_Tax_ACB::is_option_symbol( $symbol );
				$holding   = $is_option
					? MM_Tax_ACB::option_holding( $acb, $number, $symbol )
					: MM_Tax_ACB::holding( $acb, $number, $symbol );
				$currency  = $holding['currency'] ?? self::row_currency( $row );

				// An option the engine could not cost at all (no activity
				// history) leaves its market value in the totals with nothing
				// behind it in book. Count it so the screen can say so instead
				// of showing a silently short total.
				if ( $is_option && ( null === $holding || empty( $holding['priced'] ) ) ) {
					++$options_no_cost;
				}

				$market = null !== $row['current_market_value'] ? (float) $row['current_market_value'] : null;

				// null total_acb = the cost could not be established. Keep it
				// null so the row shows "—" and adds nothing to unrealised,
				// rather than booking the whole market value as gain against a
				// fabricated zero cost.
				$book       = ( $holding && null !== ( $holding['total_acb'] ?? null ) ) ? (float) $holding['total_acb'] : null;
				$unrealised = ( null !== $market && null !== $book ) ? $market - $book : null;

				$book_display   = self::to_display( $book, $currency, (string) $snapshot_date );
				$market_display = self::to_display( $market, $currency, (string) $snapshot_date );

				if ( $currency !== $display && null !== $market && null === $market_display ) {
					++$unconverted;
				}

				$lines[] = array(
					'symbol'           => $symbol,
					'quantity'         => $quantity,
					'currency'         => $currency,
					'converted'        => $currency !== $display,
					'avg_cost'         => $holding['avg_cost'] ?? null,
					'book'             => null === $book ? null : round( $book, 2 ),
					'market'           => null === $market ? null : round( $market, 2 ),
					'unrealised'       => null === $unrealised ? null : round( $unrealised, 2 ),
					'unrealised_pct'   => ( null !== $unrealised && null !== $book && $book > 0 ) ? round( $unrealised / $book * 100, 2 ) : null,
					'book_display'     => null === $book_display ? null : round( $book_display, 2 ),
					'market_display'   => null === $market_display ? null : round( $market_display, 2 ),
					'registered'       => $is_registered,
					'is_option'        => $is_option,
					// A written contract has no cost base by design (s.49 booked
					// its premium as a gain when it was granted), so its book
					// column is a real 0.00, not a missing number. The table
					// labels it so that reads as intentional.
					'option_short'     => (bool) ( $holding['is_short'] ?? false ),
				);

				$subtotal['book']   += (float) ( $book_display ?? 0 );
				$subtotal['market'] += (float) ( $market_display ?? 0 );
				if ( null !== $book_display && null !== $market_display ) {
					$subtotal['unrealised'] += $market_display - $book_display;
				}

				self::fold_consolidated( $consolidated, $symbol, $currency, $quantity, $book, $market, $book_display, $market_display, $is_registered, $is_option );
			}

			if ( empty( $lines ) ) {
				continue;
			}

			$accounts[] = array(
				'account_number' => $number,
				'label'          => MM_Accounts::label( $number ),
				'registered'     => $is_registered,
				'snapshot_date'  => $snapshot_date,
				'lines'          => $lines,
				'subtotal'       => array_map(
					static function ( $v ) {
						return round( $v, 2 );
					},
					$subtotal
				),
			);

			$grand['book']       += $subtotal['book'];
			$grand['market']     += $subtotal['market'];
			$grand['unrealised'] += $subtotal['unrealised'];
		}

		// Finalise consolidated rows.
		$consolidated = array_values( $consolidated );
		foreach ( $consolidated as &$line ) {
			$line['book']       = null === $line['book'] ? null : round( $line['book'], 2 );
			$line['market']     = null === $line['market'] ? null : round( $line['market'], 2 );
			$line['unrealised'] = ( null !== $line['market'] && null !== $line['book'] )
				? round( $line['market'] - $line['book'], 2 )
				: null;
			$line['unrealised_pct'] = ( null !== $line['unrealised'] && null !== $line['book'] && $line['book'] > 0 )
				? round( $line['unrealised'] / $line['book'] * 100, 2 )
				: null;
			$line['avg_cost'] = ( null !== $line['book'] && $line['quantity'] > 0 )
				? round( $line['book'] / $line['quantity'], 6 )
				: null;
			$line['book_display']   = null === $line['book_display'] ? null : round( $line['book_display'], 2 );
			$line['market_display'] = null === $line['market_display'] ? null : round( $line['market_display'], 2 );
		}
		unset( $line );

		usort(
			$consolidated,
			static function ( $a, $b ) {
				return $a['symbol'] <=> $b['symbol'];
			}
		);

		return array(
			'accounts'         => $accounts,
			'consolidated'     => $consolidated,
			'totals'           => array_map(
				static function ( $v ) {
					return round( $v, 2 );
				},
				$grand
			),
			'display_currency' => $display,
			'as_of'            => $as_of,
			'has_data'         => $has_data,
			// Anything the ACB walk could not account for makes the book values
			// above understated. The Realized Gains screen surfaces these for
			// non-registered accounts only, so without this the holdings screen
			// would show a silently wrong cost base for a registered account
			// holding transferred-in or journalled shares (M4a).
			'data_quality'     => array(
				'reviews'         => count( $acb['reviews'] ),
				'warnings'        => count( $acb['warnings'] ),
				'options_no_cost' => $options_no_cost,
				'unconverted'     => $unconverted,
			),
		);
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Add one position line into the consolidated (cross-account) bucket.
	 *
	 * The same symbol trades in one currency everywhere, so the native columns
	 * fold directly; the display columns are folded separately because each
	 * account's snapshot date can differ and therefore so can its rate.
	 *
	 * @param array<string,array<string,mixed>> $consolidated Passed by reference.
	 */
	private static function fold_consolidated( array &$consolidated, string $symbol, string $currency, float $quantity, ?float $book, ?float $market, ?float $book_display, ?float $market_display, bool $is_registered, bool $is_option = false ): void {
		if ( ! isset( $consolidated[ $symbol ] ) ) {
			$consolidated[ $symbol ] = array(
				'symbol'         => $symbol,
				'currency'       => $currency,
				'converted'      => $currency !== MM_Money::display_currency(),
				'quantity'       => 0.0,
				'book'           => null,
				'market'         => null,
				'book_display'   => null,
				'market_display' => null,
				'registered'     => true,
				'is_option'      => $is_option,
			);
		}

		$consolidated[ $symbol ]['quantity'] += $quantity;

		foreach ( array( 'book' => $book, 'market' => $market, 'book_display' => $book_display, 'market_display' => $market_display ) as $key => $value ) {
			if ( null !== $value ) {
				$consolidated[ $symbol ][ $key ] = (float) $consolidated[ $symbol ][ $key ] + $value;
			}
		}

		if ( ! $is_registered ) {
			$consolidated[ $symbol ]['registered'] = false;
		}
	}

	/**
	 * Convert into the display currency, preserving null.
	 */
	private static function to_display( ?float $amount, string $currency, string $date ): ?float {
		if ( null === $amount ) {
			return null;
		}

		return MM_Money::to_display( $amount, $currency, $date );
	}

	/**
	 * Currency for a snapshot row when no ACB holding supplies one — an option
	 * contract, or a position whose symbol has no activity history (transferred
	 * in before the backfill window). Falls back to CAD.
	 *
	 * @param array<string,mixed> $row
	 */
	private static function row_currency( array $row ): string {
		$currency = strtoupper( trim( (string) ( $row['currency'] ?? '' ) ) );

		if ( '' !== $currency ) {
			return $currency;
		}

		// Snapshots written before M4b have currency NULL (Questrade's position
		// payload carries no currency field). Resolve them from activity
		// history rather than assuming CAD — assuming CAD here while
		// MM_Positions::value_series() resolved the same row to USD is what made
		// the portfolio chart and this table disagree.
		if ( null === self::$symbol_currencies ) {
			self::$symbol_currencies = MM_Activities::symbol_currencies();
		}

		$symbol = (string) ( $row['symbol'] ?? '' );

		return self::$symbol_currencies[ $symbol ] ?? 'CAD';
	}
}
