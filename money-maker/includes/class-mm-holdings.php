<?php
/**
 * Holdings view-model (Milestone 3d).
 *
 * Joins the latest positions snapshot (what Questrade currently reports open)
 * with the pooled ACB from MM_Tax_ACB (what CRA says the cost base is) to give a
 * per-account and consolidated holdings table with unrealised gain/loss.
 *
 * Market values reported by Questrade are in the security's trading currency;
 * they are converted to CAD here using the stored Bank of Canada rate for the
 * snapshot date. Book value / ACB columns apply to non-registered accounts only
 * (ACB is not tracked for registered accounts).
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
	 * Build the holdings view-model.
	 *
	 * @return array{
	 *   accounts:array<int,array<string,mixed>>,
	 *   consolidated:array<int,array<string,mixed>>,
	 *   totals:array{book:float,market:float,unrealised:float},
	 *   as_of:?string,
	 *   has_data:bool
	 * }
	 */
	public static function current(): array {
		$all_numbers      = MM_Accounts::numbers();
		$non_registered   = MM_Accounts::non_registered_numbers();
		$acb              = MM_Tax_ACB::get( $non_registered );

		$accounts     = array();
		$consolidated = array();
		$as_of        = null;
		$has_data     = false;

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

				$is_option = MM_Tax_ACB::is_option_symbol( $symbol );
				$holding   = ( $is_registered || $is_option ) ? null : MM_Tax_ACB::holding( $acb, $number, $symbol );
				$currency  = $holding['currency'] ?? 'CAD';

				$market_native = null !== $row['current_market_value'] ? (float) $row['current_market_value'] : null;
				$market_cad    = null === $market_native ? null : self::to_cad( $market_native, $currency, (string) $snapshot_date );

				$book       = $holding ? (float) $holding['total_acb'] : null;
				$unrealised = ( null !== $market_cad && null !== $book ) ? $market_cad - $book : null;

				$lines[] = array(
					'symbol'        => $symbol,
					'quantity'      => $quantity,
					'currency'      => $currency,
					'avg_cost'      => $holding['avg_cost'] ?? null,
					'book_cad'      => null === $book ? null : round( $book, 2 ),
					'market_native' => $market_native,
					'market_cad'    => null === $market_cad ? null : round( $market_cad, 2 ),
					'unrealised'    => null === $unrealised ? null : round( $unrealised, 2 ),
					'unrealised_pct' => ( $unrealised !== null && $book > 0 ) ? round( $unrealised / $book * 100, 2 ) : null,
					'registered'    => $is_registered,
					'is_option'     => $is_option,
				);

				$subtotal['book']       += (float) ( $book ?? 0 );
				$subtotal['market']     += (float) ( $market_cad ?? 0 );
				$subtotal['unrealised'] += (float) ( $unrealised ?? 0 );

				self::fold_consolidated( $consolidated, $symbol, $currency, $quantity, $book, $market_cad, $is_registered );
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
			$line['book_cad']   = null === $line['book_cad'] ? null : round( $line['book_cad'], 2 );
			$line['market_cad'] = null === $line['market_cad'] ? null : round( $line['market_cad'], 2 );
			$line['unrealised'] = ( null !== $line['market_cad'] && null !== $line['book_cad'] )
				? round( $line['market_cad'] - $line['book_cad'], 2 )
				: null;
			$line['avg_cost'] = ( null !== $line['book_cad'] && $line['quantity'] > 0 )
				? round( $line['book_cad'] / $line['quantity'], 6 )
				: null;
		}
		unset( $line );

		usort(
			$consolidated,
			static function ( $a, $b ) {
				return $a['symbol'] <=> $b['symbol'];
			}
		);

		return array(
			'accounts'     => $accounts,
			'consolidated' => $consolidated,
			'totals'       => array_map(
				static function ( $v ) {
					return round( $v, 2 );
				},
				$grand
			),
			'as_of'        => $as_of,
			'has_data'     => $has_data,
		);
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Add one position line into the consolidated (cross-account) bucket.
	 *
	 * @param array<string,array<string,mixed>> $consolidated Passed by reference.
	 */
	private static function fold_consolidated( array &$consolidated, string $symbol, string $currency, float $quantity, ?float $book, ?float $market_cad, bool $is_registered ): void {
		if ( ! isset( $consolidated[ $symbol ] ) ) {
			$consolidated[ $symbol ] = array(
				'symbol'      => $symbol,
				'currency'    => $currency,
				'quantity'    => 0.0,
				'book_cad'    => null,
				'market_cad'  => null,
				'registered'  => true,
			);
		}

		$consolidated[ $symbol ]['quantity'] += $quantity;

		if ( null !== $book ) {
			$consolidated[ $symbol ]['book_cad'] = (float) $consolidated[ $symbol ]['book_cad'] + $book;
		}
		if ( null !== $market_cad ) {
			$consolidated[ $symbol ]['market_cad'] = (float) $consolidated[ $symbol ]['market_cad'] + $market_cad;
		}
		if ( ! $is_registered ) {
			$consolidated[ $symbol ]['registered'] = false;
		}
	}

	/**
	 * Convert an amount in $currency to CAD using the stored rate for $date.
	 * Falls back to the raw amount if no rate is available.
	 */
	private static function to_cad( float $amount, string $currency, string $date ): float {
		$currency = strtoupper( trim( $currency ) );

		if ( 'CAD' === $currency || '' === $currency ) {
			return $amount;
		}

		$rate = MM_FX::rate( $currency, $date, false );

		return is_wp_error( $rate ) ? $amount : $amount * (float) $rate;
	}
}
