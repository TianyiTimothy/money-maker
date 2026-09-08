<?php
/**
 * Pooled Adjusted Cost Base engine (Milestone 3b, Module C).
 *
 * CRA rules for a non-registered account: cost base is pooled per security as a
 * running *average cost*, not FIFO and not per-lot. A buy adds its total cost
 * (commission included) to the pool; a sell realises a gain/loss against the
 * average cost and removes that share of the pool. Every figure is CAD — USD
 * activities use the CAD amount stamped at sync time (`net_amount_cad`).
 *
 * Scope is the caller's problem: pass non-registered account numbers only
 * (MM_Accounts::non_registered_numbers()).
 *
 * Corporate actions are not in Questrade's feed — splits, mergers, return of
 * capital, reinvested "phantom" distributions come from MM_Manual_Adjustments
 * and are folded into the same chronological walk.
 *
 * Anything with a share quantity that is not an outright Buy/Sell (transfers-in,
 * journalled shares, option assignment, corporate actions) is **never guessed**:
 * it is emitted as a `review` item for the user to handle with an adjustment.
 *
 * Results are cached in a transient, fingerprinted on activity + adjustment
 * state; flush() clears it (wired to mm/sync/completed and every adjustment
 * write).
 *
 * Static utility: no register(). Required directly by money-maker.php.
 *
 * @package MoneyMaker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Average-cost ACB computation + realised-disposition list.
 */
final class MM_Tax_ACB {

	/** Transient holding the last computed result + its fingerprint. */
	const CACHE_KEY = 'mm_acb_cache';

	/** Float tolerance for share-quantity comparisons. */
	const EPSILON = 0.0000001;

	/**
	 * Cached compute() for a set of accounts.
	 *
	 * @param string[] $account_numbers Non-registered account numbers.
	 * @return array See compute().
	 */
	public static function get( array $account_numbers ): array {
		$account_numbers = self::normalise_accounts( $account_numbers );

		if ( empty( $account_numbers ) ) {
			return self::empty_result();
		}

		$fingerprint = self::fingerprint( $account_numbers );
		$cached      = get_transient( self::CACHE_KEY );

		if ( is_array( $cached ) && isset( $cached['fp'], $cached['data'] ) && $cached['fp'] === $fingerprint ) {
			return $cached['data'];
		}

		$data = self::compute( $account_numbers );

		set_transient( self::CACHE_KEY, array( 'fp' => $fingerprint, 'data' => $data ), DAY_IN_SECONDS );

		return $data;
	}

	/**
	 * Drop the cached result. Hooked to mm/sync/completed and called after any
	 * manual-adjustment write. Extra hook args are ignored.
	 */
	public static function flush(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Walk every (account, symbol) pool chronologically.
	 *
	 * @param string[] $account_numbers
	 * @return array{
	 *   holdings:array<int,array<string,mixed>>,
	 *   dispositions:array<int,array<string,mixed>>,
	 *   reviews:array<int,array<string,mixed>>,
	 *   warnings:string[],
	 *   missing_cad:int
	 * }
	 */
	public static function compute( array $account_numbers ): array {
		$account_numbers = self::normalise_accounts( $account_numbers );

		$holdings     = array();
		$dispositions = array();
		$reviews      = array();
		$warnings     = array();

		foreach ( MM_Activities::account_symbols( $account_numbers ) as $pair ) {
			$account = $pair['account_number'];
			$symbol  = $pair['symbol'];

			$events = self::merge_events(
				MM_Activities::for_acb( $account, $symbol ),
				MM_Manual_Adjustments::for_symbol( $account, $symbol )
			);

			$pool_qty  = 0.0;
			$pool_cost = 0.0;
			$currency  = 'CAD';

			foreach ( $events as $event ) {
				if ( 'adjustment' === $event['kind'] ) {
					$adj        = $event['adj'];
					$pool_qty  += (float) $adj['quantity_delta'];
					$pool_cost += (float) $adj['acb_delta'];

					if ( $pool_cost < -0.005 ) {
						$warnings[] = sprintf(
							/* translators: 1: symbol, 2: account label, 3: date */
							__( '%1$s in %2$s: an adjustment on %3$s pushed the cost base below zero. A negative ACB is a capital gain in the year it occurs — review with your accountant.', 'money-maker' ),
							$symbol,
							MM_Accounts::label( $account ),
							$adj['adjustment_date']
						);
					}
					continue;
				}

				$row  = $event['row'];
				$cls  = $event['kind'];
				$date = $event['date'];

				if ( 'ignore' === $cls ) {
					continue;
				}

				if ( 'review' === $cls ) {
					$reviews[] = array(
						'account_number' => $account,
						'symbol'         => $symbol,
						'date'           => $date,
						'action'         => (string) $row['action'],
						'type'           => (string) $row['type'],
						'quantity'       => (float) $row['quantity'],
						'description'    => self::describe_review( $row ),
					);
					continue;
				}

				$row_currency = strtoupper( trim( (string) $row['currency'] ) );
				if ( '' !== $row_currency ) {
					$currency = $row_currency;
				}

				$cad = self::cad_amount( $row );

				if ( 'buy' === $cls ) {
					$qty = abs( (float) $row['quantity'] );

					if ( null === $cad ) {
						$warnings[] = self::missing_cad_warning( $symbol, $account, $date );
						continue;
					}

					$pool_qty  += $qty;
					$pool_cost += $cad;
					continue;
				}

				// Sell.
				$qty_sold = abs( (float) $row['quantity'] );
				$proceeds = null === $cad ? 0.0 : $cad;
				$flags    = array();

				if ( null === $cad ) {
					$warnings[] = self::missing_cad_warning( $symbol, $account, $date );
					$flags[]    = 'missing_cad';
				}

				if ( $pool_qty < $qty_sold - self::EPSILON ) {
					$warnings[] = sprintf(
						/* translators: 1: symbol, 2: account label, 3: date */
						__( '%1$s in %2$s: a sale on %3$s is larger than the shares on record. History may be incomplete — back-fill activities or add a transfer-in adjustment.', 'money-maker' ),
						$symbol,
						MM_Accounts::label( $account ),
						$date
					);
					$flags[]    = 'insufficient_history';
					$cost_basis = $pool_cost;
					$avg_cost   = $qty_sold > 0 ? $pool_cost / $qty_sold : 0.0;
					$pool_qty   = 0.0;
					$pool_cost  = 0.0;
				} else {
					$avg_cost   = $pool_qty > self::EPSILON ? $pool_cost / $pool_qty : 0.0;
					$cost_basis = $avg_cost * $qty_sold;
					$pool_qty  -= $qty_sold;
					$pool_cost -= $cost_basis;

					if ( $pool_qty < self::EPSILON ) {
						$pool_qty  = 0.0;
						$pool_cost = 0.0;
					}
				}

				$dispositions[] = array(
					'account_number' => $account,
					'symbol'         => $symbol,
					'date'           => $date,
					'year'           => (int) substr( $date, 0, 4 ),
					'quantity'       => $qty_sold,
					'proceeds'       => round( $proceeds, 2 ),
					'acb'            => round( $cost_basis, 2 ),
					'gain'           => round( $proceeds - $cost_basis, 2 ),
					'avg_cost'       => round( $avg_cost, 6 ),
					'currency'       => $currency,
					'flags'          => $flags,
				);
			}

			if ( $pool_qty > self::EPSILON ) {
				$holdings[] = array(
					'account_number' => $account,
					'symbol'         => $symbol,
					'quantity'       => $pool_qty,
					'total_acb'      => round( $pool_cost, 2 ),
					'avg_cost'       => round( $pool_cost / $pool_qty, 6 ),
					'currency'       => $currency,
				);
			}
		}

		usort(
			$dispositions,
			static function ( $a, $b ) {
				return array( $a['date'], $a['symbol'] ) <=> array( $b['date'], $b['symbol'] );
			}
		);

		return array(
			'holdings'     => $holdings,
			'dispositions' => $dispositions,
			'reviews'      => $reviews,
			'warnings'     => array_values( array_unique( $warnings ) ),
			'missing_cad'  => MM_Activities::missing_cad_count( $account_numbers ),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Screen helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Calendar years that have at least one disposition, newest first.
	 *
	 * @param array $result compute()/get() output.
	 * @return int[]
	 */
	public static function years( array $result ): array {
		$years = array();
		foreach ( $result['dispositions'] as $d ) {
			$years[ (int) $d['year'] ] = true;
		}
		$years = array_keys( $years );
		rsort( $years );

		return $years;
	}

	/**
	 * Dispositions for one calendar year.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function dispositions_for_year( array $result, int $year ): array {
		return array_values(
			array_filter(
				$result['dispositions'],
				static function ( $d ) use ( $year ) {
					return (int) $d['year'] === $year;
				}
			)
		);
	}

	/**
	 * Realised gain/loss summed per calendar year (CAD).
	 *
	 * @return array<int,float> year => total gain
	 */
	public static function realized_by_year( array $result ): array {
		$totals = array();
		foreach ( $result['dispositions'] as $d ) {
			$year            = (int) $d['year'];
			$totals[ $year ] = ( $totals[ $year ] ?? 0.0 ) + (float) $d['gain'];
		}
		ksort( $totals );

		return array_map(
			static function ( $v ) {
				return round( $v, 2 );
			},
			$totals
		);
	}

	/**
	 * Pooled holding for one (account, symbol) from a computed result, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function holding( array $result, string $account_number, string $symbol ): ?array {
		foreach ( $result['holdings'] as $h ) {
			if ( $h['account_number'] === $account_number && $h['symbol'] === $symbol ) {
				return $h;
			}
		}

		return null;
	}

	/* ------------------------------------------------------------------ *
	 * Classification
	 * ------------------------------------------------------------------ */

	/**
	 * Map one activity row to an ACB event class.
	 *
	 * Questrade `type` values seen in the wild: Deposits, Withdrawals, Trades,
	 * Dividends, Interest, "FX conversion", "Transfers", "Fees and rebates",
	 * "Corporate actions", Other. Only `Trades` (and reinvested-dividend buys,
	 * which also carry action = Buy) move the ACB pool automatically.
	 *
	 * @return string 'buy' | 'sell' | 'ignore' | 'review'
	 */
	public static function classify( array $row ): string {
		$action = strtoupper( trim( (string) ( $row['action'] ?? '' ) ) );
		$qty    = (float) ( $row['quantity'] ?? 0 );
		$symbol = trim( (string) ( $row['symbol'] ?? '' ) );

		// Outright trades (covers DRIP purchases: action Buy, type Dividends).
		if ( 'BUY' === $action ) {
			return abs( $qty ) > self::EPSILON ? 'buy' : 'ignore';
		}
		if ( 'SELL' === $action ) {
			return abs( $qty ) > self::EPSILON ? 'sell' : 'ignore';
		}

		// No share movement → cash event, irrelevant to the cost base.
		if ( '' === $symbol || abs( $qty ) < self::EPSILON ) {
			return 'ignore';
		}

		// Has a symbol and a share quantity but is not a Buy/Sell: transfer-in,
		// journalled shares, option assignment/exercise, corporate action. Never
		// guessed — the user handles it with a manual adjustment.
		return 'review';
	}

	/* ------------------------------------------------------------------ *
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Interleave classified activities and manual adjustments into one list,
	 * ordered by date then (activities before same-day adjustments).
	 *
	 * @param array<int,array<string,mixed>> $activities
	 * @param array<int,array<string,mixed>> $adjustments
	 * @return array<int,array<string,mixed>>
	 */
	private static function merge_events( array $activities, array $adjustments ): array {
		$events = array();

		foreach ( $activities as $row ) {
			$date = self::row_date( $row );
			$events[] = array(
				'sort' => array( $date, (string) ( $row['transaction_at'] ?? $date ), 0, (int) $row['id'] ),
				'kind' => self::classify( $row ),
				'row'  => $row,
				'date' => $date,
			);
		}

		foreach ( $adjustments as $adj ) {
			$date = (string) $adj['adjustment_date'];
			$events[] = array(
				'sort' => array( $date, $date . ' 00:00:00', 1, (int) $adj['id'] ),
				'kind' => 'adjustment',
				'adj'  => $adj,
				'date' => $date,
			);
		}

		usort(
			$events,
			static function ( $a, $b ) {
				return $a['sort'] <=> $b['sort'];
			}
		);

		return $events;
	}

	/**
	 * Best available calendar date for an activity row.
	 */
	private static function row_date( array $row ): string {
		foreach ( array( 'settlement_date', 'trade_date' ) as $key ) {
			if ( ! empty( $row[ $key ] ) ) {
				return substr( (string) $row[ $key ], 0, 10 );
			}
		}

		if ( ! empty( $row['transaction_at'] ) ) {
			return substr( (string) $row['transaction_at'], 0, 10 );
		}

		return gmdate( 'Y-m-d' );
	}

	/**
	 * CAD magnitude of an activity's net amount, or null if it cannot be priced.
	 */
	private static function cad_amount( array $row ): ?float {
		$currency = strtoupper( trim( (string) $row['currency'] ) );

		if ( 'CAD' === $currency || '' === $currency ) {
			return abs( (float) $row['net_amount'] );
		}

		if ( null !== $row['net_amount_cad'] && '' !== $row['net_amount_cad'] ) {
			return abs( (float) $row['net_amount_cad'] );
		}

		return null;
	}

	/**
	 * One-line human description of a review row.
	 */
	private static function describe_review( array $row ): string {
		$bits = array_filter(
			array(
				trim( (string) $row['type'] ),
				trim( (string) $row['action'] ),
			)
		);
		$label = $bits ? implode( ' / ', $bits ) : __( 'unclassified', 'money-maker' );

		return sprintf(
			/* translators: 1: activity type/action, 2: quantity */
			__( '%1$s, quantity %2$s — enter a matching cost-base adjustment if this affects ACB.', 'money-maker' ),
			$label,
			rtrim( rtrim( number_format( (float) $row['quantity'], 4, '.', '' ), '0' ), '.' )
		);
	}

	private static function missing_cad_warning( string $symbol, string $account, string $date ): string {
		return sprintf(
			/* translators: 1: symbol, 2: account label, 3: date */
			__( '%1$s in %2$s: a trade on %3$s has no CAD conversion yet. Run an FX sync so it can be priced.', 'money-maker' ),
			$symbol,
			MM_Accounts::label( $account ),
			$date
		);
	}

	/**
	 * @param string[] $account_numbers
	 * @return string[]
	 */
	private static function normalise_accounts( array $account_numbers ): array {
		$account_numbers = array_values( array_unique( array_filter( array_map( 'strval', $account_numbers ) ) ) );
		sort( $account_numbers );

		return $account_numbers;
	}

	/**
	 * Cache key material: the account set plus activity + adjustment state.
	 *
	 * @param string[] $account_numbers
	 */
	private static function fingerprint( array $account_numbers ): string {
		$activities  = MM_Activities::fingerprint_parts();
		$adjustments = MM_Manual_Adjustments::fingerprint_parts();

		return md5(
			implode(
				'|',
				array(
					implode( ',', $account_numbers ),
					$activities['count'],
					(string) $activities['synced_at'],
					$adjustments['count'],
					(string) $adjustments['updated_at'],
				)
			)
		);
	}

	/**
	 * @return array{holdings:array,dispositions:array,reviews:array,warnings:array,missing_cad:int}
	 */
	private static function empty_result(): array {
		return array(
			'holdings'     => array(),
			'dispositions' => array(),
			'reviews'      => array(),
			'warnings'     => array(),
			'missing_cad'  => 0,
		);
	}
}
