<?php
/**
 * Pooled Adjusted Cost Base engine (Milestone 3b, Module C).
 *
 * CRA rules for a non-registered account: cost base is pooled per security as a
 * running *average cost*, not FIFO and not per-lot. A buy adds its total cost
 * (commission included) to the pool; a sell realises a gain/loss against the
 * average cost and removes that share of the pool.
 *
 * Since M4f the walk runs on one of two money bases, chosen by the caller:
 * `cad` (USD rows use the CAD amount stamped at sync time — this is the tax
 * answer and the default) or `native` (each pool stays in the security's own
 * trading currency, which is what the holdings and wheel screens show). The
 * arithmetic is identical; only the amount read off each row differs.
 *
 * Scope is the caller's problem. Since M4a there are two legitimate scopes:
 * **tax** callers (Realized Gains, superficial loss) must pass
 * MM_Accounts::non_registered_numbers(), because CRA cost-base rules apply to
 * non-registered accounts only; the **holdings** screen passes every account,
 * where the pool is not a tax figure at all but simply "what did this cost me".
 * The engine itself is scope-agnostic — it pools whatever it is handed.
 *
 * Corporate actions are not in Questrade's feed — splits, mergers, return of
 * capital, reinvested "phantom" distributions come from MM_Manual_Adjustments
 * and are folded into the same chronological walk.
 *
 * Anything with a share quantity that is not an outright Buy/Sell (transfers-in,
 * journalled shares, corporate actions) is **never guessed**: it is emitted as a
 * `review` item for the user to handle with an adjustment.
 *
 * Option contracts are not pooled here — a written contract is a short position
 * this model cannot represent. MM_Tax_Options walks them under their own CRA
 * rules (M3f) and hands back two things this walk consumes: realised option
 * dispositions, which are merged into the same list, and `effects` — the ACB /
 * proceeds adjustments an assignment or exercise pushes onto a specific share
 * trade, keyed by that activity's row id.
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

	/** Transient holding one {fp,data} entry per account set (see get()). */
	const CACHE_KEY = 'mm_acb_cache';

	/** How many account sets the cache transient keeps before dropping the oldest. */
	const CACHE_MAX_SETS = 6;

	/** Float tolerance for share-quantity comparisons. */
	const EPSILON = 0.0000001;

	/**
	 * Bumped whenever the shape or the maths of a computed result changes, so an
	 * upgrade cannot serve a cached payload the screens no longer understand.
	 * '2' = M3f (option dispositions merged in, options.contracts replaces
	 * options.positions).
	 * '3' = M4e (contracts carry their still-open quantity and cost so the
	 * holdings screen can put a book value on an option position).
	 * '4' = M4f (results are computed on a money basis — 'cad' for tax,
	 * 'native' for the trading-currency screens — so a cached CAD payload can
	 * never be served to a native-basis caller).
	 */
	const ENGINE_VERSION = '4';

	/**
	 * Cached compute() for a set of accounts.
	 *
	 * The transient holds one entry **per account set**, not a single result.
	 * Since M4a there are two live callers asking for different sets — Holdings
	 * passes every account, the tax screens pass non-registered only — and a
	 * single-slot cache would make each screen evict the other's result and
	 * recompute on every page load.
	 *
	 * @param string[] $account_numbers Accounts to pool. Holdings passes all of
	 *                                  them; tax callers must pass
	 *                                  MM_Accounts::non_registered_numbers().
	 * @param string   $basis           'cad' for tax callers, 'native' for the
	 *                                  trading-currency screens (M4f). Cached
	 *                                  separately — the two are different pools,
	 *                                  not two views of one.
	 * @return array See compute().
	 */
	public static function get( array $account_numbers, string $basis = 'cad' ): array {
		$account_numbers = self::normalise_accounts( $account_numbers );
		$basis           = 'native' === $basis ? 'native' : 'cad';

		if ( empty( $account_numbers ) ) {
			return self::empty_result();
		}

		$set_key     = md5( $basis . '|' . implode( ',', $account_numbers ) );
		$fingerprint = self::fingerprint( $account_numbers, $basis );
		$cached      = get_transient( self::CACHE_KEY );

		// Pre-M4a caches stored a bare {fp,data} pair. Discard that shape rather
		// than letting its keys collide with the per-set map.
		if ( ! is_array( $cached ) || isset( $cached['fp'] ) ) {
			$cached = array();
		}

		if (
			isset( $cached[ $set_key ]['fp'], $cached[ $set_key ]['data'] )
			&& $cached[ $set_key ]['fp'] === $fingerprint
		) {
			return $cached[ $set_key ]['data'];
		}

		$data = self::compute( $account_numbers, $basis );

		$cached[ $set_key ] = array(
			'fp'   => $fingerprint,
			'data' => $data,
		);

		// Bound the map so a changing account list cannot grow the transient
		// without limit; oldest entries drop first.
		if ( count( $cached ) > self::CACHE_MAX_SETS ) {
			$cached = array_slice( $cached, -self::CACHE_MAX_SETS, null, true );
		}

		set_transient( self::CACHE_KEY, $cached, DAY_IN_SECONDS );

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
	 * Option contracts (symbols like "APP11Sep26P290.00") are pulled out of the
	 * pooled model entirely and handled by MM_Tax_Options, whose realised
	 * dispositions are merged into `dispositions` (tagged asset_class 'option')
	 * and whose assignment/exercise effects are applied to the share trades
	 * below.
	 *
	 * @param string[] $account_numbers
	 * @param string   $basis 'cad' | 'native' — see get().
	 * @return array{
	 *   holdings:array<int,array<string,mixed>>,
	 *   dispositions:array<int,array<string,mixed>>,
	 *   reviews:array<int,array<string,mixed>>,
	 *   options:array<string,mixed>,
	 *   warnings:string[],
	 *   missing_cad:int
	 * }
	 */
	public static function compute( array $account_numbers, string $basis = 'cad' ): array {
		$account_numbers = self::normalise_accounts( $account_numbers );
		$basis           = 'native' === $basis ? 'native' : 'cad';

		$holdings     = array();
		$dispositions = array();
		$reviews      = array();
		$warnings     = array();

		// Options are walked first: an assignment or exercise retroactively moves
		// premium into a share trade this loop is about to price, so those
		// effects have to exist before the pool is built.
		$options = MM_Tax_Options::analyze( $account_numbers, $basis );
		$effects = $options['effects'];

		foreach ( MM_Activities::account_symbols( $account_numbers ) as $pair ) {
			$account = $pair['account_number'];
			$symbol  = $pair['symbol'];

			// Options do not fit the pooled-average-cost model (a written /
			// sold-to-open contract is a short position with no prior "buy").
			if ( self::is_option_symbol( $symbol ) ) {
				continue;
			}

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

				$cad = MM_Activities::amount( $row, $basis );

				$effect = $effects[ (int) $row['id'] ] ?? null;

				if ( 'buy' === $cls ) {
					$qty = abs( (float) $row['quantity'] );

					if ( null === $cad ) {
						$warnings[] = self::missing_cad_warning( $symbol, $account, $date );
						continue;
					}

					// A written put that was assigned: its premium comes off the
					// cost of the shares delivered. A held call that was
					// exercised: its cost is added. (MM_Tax_Options, s.49.)
					$pool_qty  += $qty;
					$pool_cost += $cad + ( $effect ? (float) $effect['acb_delta'] : 0.0 );
					continue;
				}

				// Sell.
				$qty_sold = abs( (float) $row['quantity'] );
				$proceeds = null === $cad ? 0.0 : $cad;
				$flags    = array();
				$note     = '';

				if ( null === $cad ) {
					$warnings[] = self::missing_cad_warning( $symbol, $account, $date );
					$flags[]    = 'missing_cad';
				}

				// A written call that was assigned: its premium is part of the
				// proceeds of the shares called away. A held put that was
				// exercised: its cost reduces them. (MM_Tax_Options, s.49.)
				if ( $effect && abs( (float) $effect['proceeds_delta'] ) > 0 ) {
					$proceeds += (float) $effect['proceeds_delta'];
					$flags[]   = 'option_premium';
					$note      = sprintf(
						/* translators: 1: option contract symbol, 2: CAD amount */
						__( 'Proceeds include %2$s of option premium rolled in from %1$s.', 'money-maker' ),
						(string) $effect['contract'],
						number_format( (float) $effect['proceeds_delta'], 2, '.', '' )
					);
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
					'asset_class'    => 'stock',
					'note'           => $note,
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

		$dispositions = array_merge( $dispositions, $options['dispositions'] );
		$reviews      = array_merge( $reviews, $options['reviews'] );
		$warnings     = array_merge( $warnings, $options['warnings'] );

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
			'options'      => array(
				'contracts'  => $options['contracts'],
				'amendments' => $options['amendments'],
				'effects'    => $options['effects'],
				'unmapped'   => $options['unmapped'],
				'totals'     => $options['totals'],
			),
			'warnings'     => array_values( array_unique( $warnings ) ),
			'basis'        => $basis,
			// Only meaningful on the CAD basis: a native pool is built from the
			// amount Questrade reported, which is never missing.
			'missing_cad'  => 'cad' === $basis ? MM_Activities::missing_cad_count( $account_numbers ) : 0,
		);
	}

	/**
	 * Whether a symbol string is a Questrade option contract, e.g.
	 * "APP11Sep26P290.00" — root, day, 3-letter month, 2-digit year, C|P, strike.
	 *
	 * Kept here as the plugin-wide entry point (MM_Holdings and MM_Admin call
	 * it); the parser itself lives with the option engine.
	 */
	public static function is_option_symbol( string $symbol ): bool {
		return MM_Tax_Options::is_option_symbol( $symbol );
	}

	/**
	 * The still-open leg of an option contract, shaped like holding() (M4e).
	 *
	 * Options are deliberately outside the pooled walk, so holding() will never
	 * find one. This is the matching lookup for them, kept here for the same
	 * reason as is_option_symbol(): callers go through MM_Tax_ACB and the
	 * option engine stays an implementation detail.
	 *
	 * @param array $result compute()/get() output.
	 * @return array<string,mixed>|null
	 */
	public static function option_holding( array $result, string $account_number, string $symbol ): ?array {
		return MM_Tax_Options::open_position( $result['options'] ?? array(), $account_number, $symbol );
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
		// journalled shares, corporate action. Never guessed — the user handles
		// it with a manual adjustment. (Option contracts never reach here: they
		// are filtered out of the pool loop and walked by MM_Tax_Options.)
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
	 * Best available calendar date for an activity row. Shared with the option
	 * engine and the superficial-loss scan so all three agree on the date.
	 */
	private static function row_date( array $row ): string {
		return MM_Activities::row_date( $row );
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
	private static function fingerprint( array $account_numbers, string $basis ): string {
		$activities  = MM_Activities::fingerprint_parts();
		$adjustments = MM_Manual_Adjustments::fingerprint_parts();

		return md5(
			implode(
				'|',
				array(
					self::ENGINE_VERSION,
					$basis,
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
	 * @return array{holdings:array,dispositions:array,reviews:array,options:array,warnings:array,missing_cad:int}
	 */
	private static function empty_result(): array {
		return array(
			'holdings'     => array(),
			'dispositions' => array(),
			'reviews'      => array(),
			'options'      => array(
				'contracts'  => array(),
				'amendments' => array(),
				'effects'    => array(),
				'unmapped'   => array(),
				'totals'     => array(
					'premium_in'  => 0.0,
					'premium_out' => 0.0,
					'realized'    => 0.0,
					'rolled'      => 0.0,
				),
			),
			'warnings'     => array(),
			'basis'        => 'cad',
			'missing_cad'  => 0,
		);
	}
}
