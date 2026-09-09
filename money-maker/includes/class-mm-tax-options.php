<?php
/**
 * Option-contract tax engine (Milestone 3f, Module C).
 *
 * Options do not fit the pooled-average-cost model that MM_Tax_ACB uses for
 * shares, so they get their own walk and their own CRA rules (ITA s.49,
 * IT-479R "Transactions in Securities"):
 *
 *   WRITER (sold to open)
 *     - Granting the option is itself a disposition: proceeds = the net premium
 *       received, ACB = nil. The capital gain falls in the year of the GRANT,
 *       not the year the contract closes.
 *     - Buying it back (closing purchase) is a capital LOSS in the year of the
 *       buy-back, equal to what was paid.
 *     - Expiring worthless changes nothing — the grant gain already stands.
 *     - Assignment retroactively un-does the grant (s.49(3)): the premium is
 *       instead rolled into the underlying — a written PUT reduces the ACB of
 *       the shares delivered to you; a written CALL increases the proceeds of
 *       the shares called away. If the grant was in an earlier calendar year
 *       that year's return has to be amended — every such case is reported in
 *       `amendments`.
 *
 *   HOLDER (bought to open)
 *     - Pooled average cost per contract (identical property).
 *     - Selling to close: capital gain/loss vs. that average cost.
 *     - Expiring worthless: deemed disposition for nil proceeds — a capital
 *       loss equal to the pooled cost, dated at expiry.
 *     - Exercising rolls the option's cost into the underlying: a CALL adds it
 *       to the ACB of the shares bought, a PUT reduces the proceeds of the
 *       shares sold.
 *
 * Nothing here depends on Questrade labelling a trade "open" or "close":
 *   - open vs. close is derived from the running signed position;
 *   - the expiry date, right and strike are parsed out of the contract symbol;
 *   - the contract multiplier is derived from the row's own gross amount;
 *   - assignment / exercise is taken from an explicit keyword when Questrade
 *     supplies one, and otherwise matched against a trade in the underlying at
 *     exactly the strike price for exactly the right number of shares inside
 *     the expiry window. Every match is reported with its evidence so it can be
 *     eyeballed, and anything that cannot be mapped becomes a review row rather
 *     than a guess.
 *
 * Output feeds MM_Tax_ACB: `dispositions` merge into the realised list, and
 * `effects` (keyed by the underlying activity's row id) are the ACB / proceeds
 * adjustments the share pool has to apply when it walks that row.
 *
 * Static utility: no register(). Required directly by money-maker.php.
 *
 * @package MoneyMaker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Per-contract option lifecycle, CRA capital treatment, and the ACB effects
 * assignment/exercise push onto the underlying share pool.
 */
final class MM_Tax_Options {

	/** Float tolerance for contract-quantity comparisons. */
	const EPSILON = 0.0000001;

	/** Shares per contract when it cannot be derived from the data. */
	const DEFAULT_MULTIPLIER = 100.0;

	/** Days after expiry an assignment/exercise trade may still settle. */
	const SETTLEMENT_WINDOW_DAYS = 5;

	/** Price tolerance when matching an underlying trade against the strike. */
	const STRIKE_TOLERANCE = 0.005;

	/** Three-letter month codes as they appear in a Questrade option symbol. */
	const MONTHS = array(
		'JAN' => 1,
		'FEB' => 2,
		'MAR' => 3,
		'APR' => 4,
		'MAY' => 5,
		'JUN' => 6,
		'JUL' => 7,
		'AUG' => 8,
		'SEP' => 9,
		'OCT' => 10,
		'NOV' => 11,
		'DEC' => 12,
	);

	/**
	 * Which money basis the current walk is running on — 'cad' for the tax
	 * screens, 'native' for the holdings and wheel screens (M4f). Held as walk
	 * state rather than threaded through nine private signatures, in the same
	 * way the caches below already are.
	 *
	 * @var string
	 */
	private static $basis = 'cad';

	/**
	 * Per-request cache of a whole account's activity symbols, so underlying
	 * matching does not re-query for every contract.
	 *
	 * @var array<string,string[]>
	 */
	private static $symbol_cache = array();

	/**
	 * Per-request cache of underlying activity rows, keyed "account|symbol".
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private static $row_cache = array();

	/** Fixed reviewer caveat shown wherever option numbers are displayed. */
	public static function disclaimer(): string {
		return __(
			'Option treatment follows ITA s.49: a premium you receive is a capital gain in the year you WRITE the contract, and assignment retroactively cancels that gain and rolls the premium into the underlying instead. Assignments Questrade does not label are matched by strike price and share count — check every linked row below. Employee/derivative or trader (income) treatment is not modelled. Review with your accountant.',
			'money-maker'
		);
	}

	/**
	 * Whether a symbol string is a Questrade option contract, e.g.
	 * "APP11Sep26P290.00" — root, day, 3-letter month, 2-digit year, C|P, strike.
	 */
	public static function is_option_symbol( string $symbol ): bool {
		return null !== self::parse_symbol( $symbol );
	}

	/**
	 * Break a contract symbol into its parts.
	 *
	 * The optional digit at the end of the root covers adjusted contracts
	 * ("AAPL1..."); it is matched lazily so an ordinary root wins first.
	 *
	 * @return array{root:string,right:string,strike:float,expiry:string}|null
	 */
	public static function parse_symbol( string $symbol ): ?array {
		$symbol = strtoupper( trim( $symbol ) );

		if ( '' === $symbol ) {
			return null;
		}

		$months  = implode( '|', array_keys( self::MONTHS ) );
		$pattern = '/^([A-Z.]{1,6}\d??)(\d{1,2})(' . $months . ')(\d{2})([CP])(\d+(?:\.\d+)?)$/';

		if ( 1 !== preg_match( $pattern, $symbol, $m ) ) {
			return null;
		}

		$day   = (int) $m[2];
		$month = self::MONTHS[ $m[3] ];
		$year  = 2000 + (int) $m[4];

		if ( ! checkdate( $month, $day, $year ) ) {
			return null;
		}

		return array(
			'root'   => $m[1],
			'right'  => $m[5],
			'strike' => (float) $m[6],
			'expiry' => sprintf( '%04d-%02d-%02d', $year, $month, $day ),
		);
	}

	/**
	 * Walk every option contract held in a set of accounts.
	 *
	 * Scope is the caller's problem, exactly as in MM_Tax_ACB: the tax screens
	 * pass non-registered accounts only, the holdings screen passes every
	 * account. Matching is per-account throughout, so a wider account list can
	 * never change the result for an account already in a narrower one.
	 *
	 * @param string[] $account_numbers Accounts to walk.
	 * @param string   $basis           'cad' (tax) or 'native' (trading currency).
	 * @return array{
	 *   contracts:array<int,array<string,mixed>>,
	 *   dispositions:array<int,array<string,mixed>>,
	 *   effects:array<int,array<string,mixed>>,
	 *   amendments:array<int,array<string,mixed>>,
	 *   reviews:array<int,array<string,mixed>>,
	 *   warnings:string[],
	 *   unmapped:string[],
	 *   totals:array{premium_in:float,premium_out:float,realized:float,rolled:float}
	 * }
	 */
	public static function analyze( array $account_numbers, string $basis = 'cad' ): array {
		self::$basis        = 'native' === $basis ? 'native' : 'cad';
		self::$symbol_cache = array();
		self::$row_cache    = array();

		$out = self::empty_result();

		$account_numbers = array_values( array_filter( array_map( 'strval', $account_numbers ) ) );
		if ( empty( $account_numbers ) ) {
			return $out;
		}

		// Underlying activity rows already consumed by a link, so two contracts
		// can never claim the same share trade.
		$linked = array();

		foreach ( MM_Activities::account_symbols( $account_numbers ) as $pair ) {
			if ( ! self::is_option_symbol( $pair['symbol'] ) ) {
				continue;
			}

			self::walk_contract( $pair['account_number'], $pair['symbol'], $out, $linked );
		}

		usort(
			$out['contracts'],
			static function ( $a, $b ) {
				return array( (string) $b['last_date'], (string) $a['symbol'] ) <=> array( (string) $a['last_date'], (string) $b['symbol'] );
			}
		);

		usort(
			$out['amendments'],
			static function ( $a, $b ) {
				return (string) $b['event_date'] <=> (string) $a['event_date'];
			}
		);

		foreach ( $out['contracts'] as $contract ) {
			$out['totals']['premium_in']  += (float) $contract['premium_in'];
			$out['totals']['premium_out'] += (float) $contract['premium_out'];
			$out['totals']['rolled']      += (float) $contract['rolled_into_underlying'];
		}

		foreach ( $out['dispositions'] as $disposition ) {
			$out['totals']['realized'] += (float) $disposition['gain'];
		}

		$out['totals']  = array_map( static fn( $v ) => round( (float) $v, 2 ), $out['totals'] );
		$out['warnings'] = array_values( array_unique( $out['warnings'] ) );
		$out['unmapped'] = array_values( array_unique( $out['unmapped'] ) );

		if ( ! empty( $out['unmapped'] ) ) {
			$out['warnings'][] = sprintf(
				/* translators: %s: comma-separated Questrade action/type labels */
				__( 'Questrade reported option activity this engine has no rule for: %s. Nothing was assumed — those rows are listed under "Needs your review".', 'money-maker' ),
				implode( ', ', $out['unmapped'] )
			);
		}

		return $out;
	}

	/**
	 * Human label for a lifecycle event key.
	 */
	public static function event_label( string $event ): string {
		$labels = array(
			'grant'          => __( 'Wrote (sold to open)', 'money-maker' ),
			'close_short'    => __( 'Bought to close', 'money-maker' ),
			'open_long'      => __( 'Bought to open', 'money-maker' ),
			'close_long'     => __( 'Sold to close', 'money-maker' ),
			'expiry_short'   => __( 'Expired worthless (written)', 'money-maker' ),
			'expiry_long'    => __( 'Expired worthless (held)', 'money-maker' ),
			'put_assignment' => __( 'Assigned — shares put to you', 'money-maker' ),
			'call_assignment' => __( 'Assigned — shares called away', 'money-maker' ),
			'call_exercise'  => __( 'Exercised — shares bought', 'money-maker' ),
			'put_exercise'   => __( 'Exercised — shares sold', 'money-maker' ),
			'unlinked'       => __( 'Assignment/exercise not matched', 'money-maker' ),
			'review'         => __( 'Unclassified', 'money-maker' ),
		);

		return $labels[ $event ] ?? $event;
	}

	/**
	 * What an assignment or exercise did to the underlying share pool.
	 */
	private static function roll_note( string $kind ): string {
		$notes = array(
			'put_assignment'  => __( 'of premium came off the cost base', 'money-maker' ),
			'call_assignment' => __( 'of premium was added to the proceeds', 'money-maker' ),
			'call_exercise'   => __( 'of option cost was added to the cost base', 'money-maker' ),
			'put_exercise'    => __( 'of option cost came off the proceeds', 'money-maker' ),
		);

		return $notes[ $kind ] ?? __( 'moved into the share pool', 'money-maker' );
	}

	/**
	 * Human label for a contract status key.
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			'open'       => __( 'Open', 'money-maker' ),
			'closed'     => __( 'Closed', 'money-maker' ),
			'expired'    => __( 'Expired', 'money-maker' ),
			'assigned'   => __( 'Assigned', 'money-maker' ),
			'exercised'  => __( 'Exercised', 'money-maker' ),
			'unresolved' => __( 'Needs review', 'money-maker' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/* ------------------------------------------------------------------ *
	 * The walk
	 * ------------------------------------------------------------------ */

	/**
	 * Build one contract's lifecycle and fold its results into $out.
	 *
	 * @param array<string,mixed> $out    Passed by reference.
	 * @param array<int,bool>     $linked Underlying activity ids already claimed.
	 */
	private static function walk_contract( string $account, string $symbol, array &$out, array &$linked ): void {
		$rows = MM_Activities::for_acb( $account, $symbol );
		$meta = self::parse_symbol( $symbol );

		if ( empty( $rows ) || null === $meta ) {
			return;
		}

		$multiplier = self::derive_multiplier( $rows );

		$state = array(
			'long_qty'   => 0.0,
			'long_cost'  => 0.0,
			'short_lots' => array(),
		);

		$contract = array(
			'account_number'         => $account,
			'symbol'                 => $symbol,
			'root'                   => $meta['root'],
			'underlying'             => null,
			'right'                  => $meta['right'],
			'strike'                 => $meta['strike'],
			'expiry'                 => $meta['expiry'],
			'multiplier'             => $multiplier,
			'currency'               => 'CAD',
			'premium_in'             => 0.0,
			'premium_out'            => 0.0,
			'realized'               => 0.0,
			'rolled_into_underlying' => 0.0,
			'net_contracts'          => 0.0,
			// What is still open when the walk ends, so the holdings screen can
			// put a book cost on an option position (M4e). Long contracts carry
			// a real pooled cost in CAD; a written contract has no cost base at
			// all (s.49 already recognised its premium as a gain), so its
			// premium is carried separately and never counted as book value.
			'open_long_quantity'     => 0.0,
			'open_long_cost'         => 0.0,
			'open_short_quantity'    => 0.0,
			'open_short_premium'     => 0.0,
			'status'                 => 'open',
			'priced'                 => true,
			'first_date'             => null,
			'last_date'              => null,
			'events'                 => array(),
		);

		// Grant dispositions are held locally so assignment can retroactively
		// cancel them (s.49(3)) before anything is published.
		$dispositions = array();

		foreach ( $rows as $row ) {
			$date     = MM_Activities::row_date( $row );
			$currency = strtoupper( trim( (string) $row['currency'] ) );
			if ( '' !== $currency ) {
				$contract['currency'] = $currency;
			}

			$contract['first_date'] = null === $contract['first_date'] ? $date : min( $contract['first_date'], $date );
			$contract['last_date']  = null === $contract['last_date'] ? $date : max( $contract['last_date'], $date );

			$marker = self::marker( $row );
			$action = strtoupper( trim( (string) $row['action'] ) );
			$qty    = abs( (float) $row['quantity'] );

			// A keyword only wins over the Buy/Sell branch when no premium
			// actually moved. Assignment, exercise and expiry settle the option
			// leg for nothing; a row with real cash on it is a trade whatever
			// the description happens to say.
			$probe    = MM_Activities::amount( $row, self::$basis );
			$is_trade = ( 'BUY' === $action || 'SELL' === $action )
				&& $qty > self::EPSILON
				&& ( null === $probe || abs( $probe ) > 0.005 );

			if ( '' !== $marker && $is_trade ) {
				$marker = '';
			}

			if ( 'assignment' === $marker || 'exercise' === $marker ) {
				// Questrade told us outright. Settle against the underlying now.
				self::settle_exercise( $account, $symbol, $meta, $multiplier, $qty, $date, $state, $contract, $dispositions, $out, $linked, $marker );
				continue;
			}

			if ( 'expiry' === $marker ) {
				self::settle_expiry( $meta, $qty, $date, $state, $contract, $dispositions );
				continue;
			}

			if ( 'BUY' !== $action && 'SELL' !== $action ) {
				if ( $qty < self::EPSILON ) {
					// A cash line (an option-related fee or rebate) — no tax event.
					continue;
				}

				$label = trim( trim( (string) $row['type'] ) . ' / ' . trim( (string) $row['action'] ) , ' /' );
				$out['unmapped'][] = '' === $label ? __( '(no label)', 'money-maker' ) : $label;
				$out['reviews'][]  = array(
					'account_number' => $account,
					'symbol'         => $symbol,
					'date'           => $date,
					'action'         => (string) $row['action'],
					'type'           => (string) $row['type'],
					'quantity'       => (float) $row['quantity'],
					'description'    => sprintf(
						/* translators: 1: Questrade label, 2: quantity */
						__( 'Option activity "%1$s" (quantity %2$s) is not a buy, sell, expiry, assignment or exercise — the option engine skipped it.', 'money-maker' ),
						'' === $label ? __( 'unlabelled', 'money-maker' ) : $label,
						self::qty_text( (float) $row['quantity'] )
					),
				);
				$contract['events'][] = self::event( $date, 'review', $row, (float) $row['quantity'], null );
				$contract['status']   = 'unresolved';
				continue;
			}

			if ( $qty < self::EPSILON ) {
				continue;
			}

			$cad = MM_Activities::amount( $row, self::$basis );

			if ( null === $cad ) {
				$contract['priced'] = false;
				$out['warnings'][]  = sprintf(
					/* translators: 1: contract symbol, 2: account label, 3: date */
					__( '%1$s in %2$s: the option trade on %3$s has no CAD conversion yet. Run an FX sync so its premium can be priced.', 'money-maker' ),
					$symbol,
					MM_Accounts::label( $account ),
					$date
				);
				$cad = 0.0;
			}

			$per_contract = $cad / $qty;

			if ( 'SELL' === $action ) {
				$close = min( $state['long_qty'], $qty );

				if ( $close > self::EPSILON ) {
					$avg  = $state['long_cost'] / $state['long_qty'];
					$acb  = $avg * $close;
					$proc = $per_contract * $close;

					$state['long_qty']  -= $close;
					$state['long_cost'] -= $acb;
					if ( $state['long_qty'] < self::EPSILON ) {
						$state['long_qty']  = 0.0;
						$state['long_cost'] = 0.0;
					}

					$dispositions[] = self::disposition( $account, $symbol, $date, $close, $proc, $acb, $contract['currency'], 'close_long', $row );
					$contract['premium_in'] += $proc;
					$contract['events'][]    = self::event( $date, 'close_long', $row, $close, $proc );
				}

				$open = $qty - $close;

				if ( $open > self::EPSILON ) {
					$premium = $per_contract * $open;

					// s.49(1): granting the option IS the disposition, ACB nil.
					$dispositions[] = self::disposition( $account, $symbol, $date, $open, $premium, 0.0, $contract['currency'], 'grant', $row );

					$state['short_lots'][] = array(
						'date'    => $date,
						'qty'     => $open,
						'premium' => $premium,
						'index'   => count( $dispositions ) - 1,
					);

					$contract['premium_in'] += $premium;
					$contract['events'][]    = self::event( $date, 'grant', $row, $open, $premium );
				}

				continue;
			}

			// BUY.
			$short_qty = self::short_quantity( $state );
			$close     = min( $short_qty, $qty );

			if ( $close > self::EPSILON ) {
				$cost = $per_contract * $close;
				self::consume_short_lots( $state, $close );

				// A closing purchase is a capital loss in the year it happens;
				// the grant gain stays where it was recognised.
				$dispositions[] = self::disposition( $account, $symbol, $date, $close, 0.0, $cost, $contract['currency'], 'close_short', $row );
				$contract['premium_out'] += $cost;
				$contract['events'][]     = self::event( $date, 'close_short', $row, $close, -$cost );
			}

			$open = $qty - $close;

			if ( $open > self::EPSILON ) {
				$cost                = $per_contract * $open;
				$state['long_qty']  += $open;
				$state['long_cost'] += $cost;

				$contract['premium_out'] += $cost;
				$contract['events'][]     = self::event( $date, 'open_long', $row, $open, -$cost );
			}
		}

		// Nothing explicit closed the position — resolve what is left.
		self::resolve_remainder( $account, $symbol, $meta, $multiplier, $state, $contract, $dispositions, $out, $linked );

		$contract['net_contracts'] = round( $state['long_qty'] - self::short_quantity( $state ), 4 );

		// Publish whatever is still open so MM_Holdings can price the position.
		// long_cost is already on the walk's money basis (every premium goes
		// through MM_Activities::amount() on the way in), so a native-basis
		// walk hands the holdings table a cost in the contract's own currency.
		$contract['open_long_quantity']  = round( $state['long_qty'], 4 );
		$contract['open_long_cost']      = round( $state['long_cost'], 2 );
		$contract['open_short_quantity'] = round( self::short_quantity( $state ), 4 );
		$contract['open_short_premium']  = round( self::short_premium( $state ), 2 );

		foreach ( $dispositions as $disposition ) {
			if ( ! empty( $disposition['reversed'] ) ) {
				continue;
			}

			$contract['realized'] += (float) $disposition['gain'];
			unset( $disposition['reversed'] );
			$out['dispositions'][] = $disposition;
		}

		if ( 'unresolved' !== $contract['status'] && abs( $contract['net_contracts'] ) < self::EPSILON && 'open' === $contract['status'] ) {
			$contract['status'] = 'closed';
		}

		$contract['premium_in']             = round( $contract['premium_in'], 2 );
		$contract['premium_out']            = round( $contract['premium_out'], 2 );
		$contract['realized']               = round( $contract['realized'], 2 );
		$contract['rolled_into_underlying'] = round( $contract['rolled_into_underlying'], 2 );

		$out['contracts'][] = $contract;
	}

	/* ------------------------------------------------------------------ *
	 * Settlement
	 * ------------------------------------------------------------------ */

	/**
	 * Resolve whatever position is still open once every activity row is walked:
	 * an assignment/exercise Questrade never labelled, or a plain expiry.
	 *
	 * @param array<string,mixed>            $state        Passed by reference.
	 * @param array<string,mixed>            $contract     Passed by reference.
	 * @param array<int,array<string,mixed>> $dispositions Passed by reference.
	 * @param array<string,mixed>            $out          Passed by reference.
	 * @param array<int,bool>                $linked       Passed by reference.
	 */
	private static function resolve_remainder( string $account, string $symbol, array $meta, float $multiplier, array &$state, array &$contract, array &$dispositions, array &$out, array &$linked ): void {
		$open = self::short_quantity( $state ) + $state['long_qty'];

		if ( $open < self::EPSILON ) {
			return;
		}

		// Still live — leave it alone until it resolves.
		if ( $meta['expiry'] > gmdate( 'Y-m-d' ) ) {
			return;
		}

		self::settle_exercise( $account, $symbol, $meta, $multiplier, 0.0, $meta['expiry'], $state, $contract, $dispositions, $out, $linked, 'auto' );

		self::settle_expiry( $meta, 0.0, $meta['expiry'], $state, $contract, $dispositions );
	}

	/**
	 * Try to settle up to $qty contracts (0 = everything open) as an assignment
	 * or exercise, by finding the matching share trade in the underlying.
	 *
	 * @param string $mode 'assignment' | 'exercise' (Questrade said so) or 'auto'
	 *                     (inferred at expiry — a match is required).
	 */
	private static function settle_exercise( string $account, string $symbol, array $meta, float $multiplier, float $qty, string $date, array &$state, array &$contract, array &$dispositions, array &$out, array &$linked, string $mode ): void {
		$short_qty = self::short_quantity( $state );
		$long_qty  = $state['long_qty'];

		// Written contracts are assigned; held contracts are exercised. When
		// both sides somehow exist, settle the short leg first.
		$is_put = 'P' === $meta['right'];

		if ( $short_qty > self::EPSILON ) {
			$want      = $qty > self::EPSILON ? min( $qty, $short_qty ) : $short_qty;
			$kind      = $is_put ? 'put_assignment' : 'call_assignment';
			$direction = $is_put ? 'BUY' : 'SELL';

			// A contract cannot be assigned before it was written. Without this
			// floor, an ordinary purchase made months earlier at a round price
			// that happens to equal the strike would match.
			$opened = null;
			foreach ( $state['short_lots'] as $lot ) {
				$opened = null === $opened ? (string) $lot['date'] : min( $opened, (string) $lot['date'] );
			}
		} elseif ( $long_qty > self::EPSILON ) {
			$want      = $qty > self::EPSILON ? min( $qty, $long_qty ) : $long_qty;
			$kind      = $is_put ? 'put_exercise' : 'call_exercise';
			$direction = $is_put ? 'SELL' : 'BUY';
			$opened    = (string) $contract['first_date'];
		} else {
			return;
		}

		$match = self::find_underlying_trade( $account, $meta, $direction, $want, $multiplier, (string) ( $opened ?? $contract['first_date'] ), $date, $linked );

		if ( null === $match ) {
			if ( 'auto' === $mode ) {
				// Nothing matched: fall through to the expiry path, which is the
				// right answer for an out-of-the-money contract.
				return;
			}

			$out['warnings'][] = sprintf(
				/* translators: 1: contract symbol, 2: account label, 3: date */
				__( '%1$s in %2$s: Questrade reported an assignment/exercise on %3$s but no matching trade in the underlying was found. Its premium was NOT rolled into any cost base — handle it with a manual adjustment.', 'money-maker' ),
				$symbol,
				MM_Accounts::label( $account ),
				$date
			);

			$out['reviews'][] = array(
				'account_number' => $account,
				'symbol'         => $symbol,
				'date'           => $date,
				'action'         => __( 'assignment/exercise', 'money-maker' ),
				'type'           => __( 'Options', 'money-maker' ),
				'quantity'       => $want,
				'description'    => sprintf(
					/* translators: 1: contract count, 2: strike price */
					__( '%1$s contract(s) settled at strike %2$s, but the matching share trade could not be identified. Enter the cost-base effect manually.', 'money-maker' ),
					self::qty_text( $want ),
					number_format( (float) $meta['strike'], 2, '.', '' )
				),
			);

			$contract['events'][] = self::event( $date, 'unlinked', null, $want, null );
			$contract['status']   = 'unresolved';

			// Take the contracts off the books so they are not double-counted as
			// an expiry; the premium keeps whatever recognition it already had.
			if ( $short_qty > self::EPSILON ) {
				self::consume_short_lots( $state, $want );
			} else {
				$avg                 = $state['long_cost'] / max( $state['long_qty'], self::EPSILON );
				$state['long_cost'] -= $avg * $want;
				$state['long_qty']  -= $want;
			}

			return;
		}

		$contracts = $match['contracts'];
		$row       = $match['row'];
		$event_date = MM_Activities::row_date( $row );

		if ( $short_qty > self::EPSILON ) {
			$taken   = self::consume_short_lots( $state, $contracts );
			$premium = $taken['premium'];

			// s.49(3): the grant is deemed never to have been a disposition.
			foreach ( $taken['reversals'] as $reversal ) {
				$index = $reversal['index'];
				$share = $reversal['share'];

				if ( ! isset( $dispositions[ $index ] ) ) {
					continue;
				}

				self::reduce_disposition( $dispositions[ $index ], $share );

				$grant_year = (int) substr( (string) $reversal['date'], 0, 4 );
				$event_year = (int) substr( $event_date, 0, 4 );

				if ( $grant_year !== $event_year ) {
					$out['amendments'][] = array(
						'account_number' => $account,
						'symbol'         => $symbol,
						'grant_date'     => $reversal['date'],
						'grant_year'     => $grant_year,
						'event_date'     => $event_date,
						'event_year'     => $event_year,
						'amount'         => round( $reversal['premium'], 2 ),
						'kind'           => $kind,
					);
				}
			}

			$acb_delta      = 'put_assignment' === $kind ? -$premium : 0.0;
			$proceeds_delta = 'call_assignment' === $kind ? $premium : 0.0;
			$rolled         = $premium;
		} else {
			$avg                 = $state['long_cost'] / max( $state['long_qty'], self::EPSILON );
			$cost                = $avg * $contracts;
			$state['long_cost'] -= $cost;
			$state['long_qty']  -= $contracts;
			if ( $state['long_qty'] < self::EPSILON ) {
				$state['long_qty']  = 0.0;
				$state['long_cost'] = 0.0;
			}

			$acb_delta      = 'call_exercise' === $kind ? $cost : 0.0;
			$proceeds_delta = 'put_exercise' === $kind ? -$cost : 0.0;
			$rolled         = $cost;
		}

		$activity_id = (int) $row['id'];
		$linked[ $activity_id ] = true;

		$out['effects'][ $activity_id ] = array(
			'activity_id'    => $activity_id,
			'account_number' => $account,
			'contract'       => $symbol,
			'underlying'     => (string) $row['symbol'],
			'kind'           => $kind,
			'contracts'      => round( $contracts, 4 ),
			'shares'         => round( $contracts * $multiplier, 4 ),
			'strike'         => (float) $meta['strike'],
			'date'           => $event_date,
			'acb_delta'      => round( $acb_delta, 2 ),
			'proceeds_delta' => round( $proceeds_delta, 2 ),
			'matched'        => $match['how'],
		);

		$contract['underlying']              = (string) $row['symbol'];
		$contract['rolled_into_underlying'] += $rolled;
		$contract['status']                  = $short_qty > self::EPSILON ? 'assigned' : 'exercised';
		$contract['events'][]                = self::event(
			$event_date,
			$kind,
			null,
			$contracts,
			// The rolled amount is not a gain or a loss — it changed the share
			// pool. Shown unsigned, with the direction spelled out below.
			$rolled,
			sprintf(
				/* translators: 1: CAD amount, 2: what the premium did, 3: share count, 4: underlying symbol, 5: strike, 6: match basis */
				__( '%1$s %2$s on %3$s shares of %4$s at %5$s (%6$s)', 'money-maker' ),
				number_format( $rolled, 2, '.', '' ),
				self::roll_note( $kind ),
				self::qty_text( $contracts * $multiplier ),
				(string) $row['symbol'],
				number_format( (float) $meta['strike'], 2, '.', '' ),
				$match['how']
			)
		);

		// More than one tranche can settle on the same contract.
		if ( self::short_quantity( $state ) + $state['long_qty'] > self::EPSILON && 'auto' === $mode ) {
			self::settle_exercise( $account, $symbol, $meta, $multiplier, 0.0, $date, $state, $contract, $dispositions, $out, $linked, $mode );
		}
	}

	/**
	 * Close out whatever is left as an expiry.
	 *
	 * A written contract that expires needs no entry — its premium was already a
	 * capital gain at grant. A held contract is a deemed disposition for nil
	 * proceeds, i.e. a capital loss equal to its pooled cost.
	 *
	 * @param array<string,mixed>            $state        Passed by reference.
	 * @param array<string,mixed>            $contract     Passed by reference.
	 * @param array<int,array<string,mixed>> $dispositions Passed by reference.
	 */
	private static function settle_expiry( array $meta, float $qty, string $date, array &$state, array &$contract, array &$dispositions ): void {
		$short_qty = self::short_quantity( $state );

		if ( $short_qty > self::EPSILON ) {
			$take = $qty > self::EPSILON ? min( $qty, $short_qty ) : $short_qty;
			self::consume_short_lots( $state, $take );

			$contract['events'][] = self::event( $date, 'expiry_short', null, $take, 0.0, __( 'Premium already taxed in the year the contract was written.', 'money-maker' ) );
			$contract['status']   = 'expired';
		}

		if ( $state['long_qty'] > self::EPSILON ) {
			$take = $qty > self::EPSILON ? min( $qty, $state['long_qty'] ) : $state['long_qty'];
			$avg  = $state['long_cost'] / $state['long_qty'];
			$cost = $avg * $take;

			$state['long_qty']  -= $take;
			$state['long_cost'] -= $cost;
			if ( $state['long_qty'] < self::EPSILON ) {
				$state['long_qty']  = 0.0;
				$state['long_cost'] = 0.0;
			}

			$dispositions[]       = self::disposition( $contract['account_number'], $contract['symbol'], $date, $take, 0.0, $cost, $contract['currency'], 'expiry_long', null );
			$contract['events'][] = self::event( $date, 'expiry_long', null, $take, -$cost );
			$contract['status']   = 'expired';
		}
	}

	/* ------------------------------------------------------------------ *
	 * Underlying matching
	 * ------------------------------------------------------------------ */

	/**
	 * Find the share trade an assignment/exercise produced.
	 *
	 * Deliberately strict: right direction, price equal to the strike, a share
	 * count that is a whole number of contracts no larger than what is open, and
	 * a date between the day the position was opened and expiry + a few days.
	 * Anything looser would silently rewrite a cost base off a guess.
	 *
	 * @param string          $opened Earliest date the position could have settled
	 *                                on — the day it was opened. Trades before it
	 *                                are ordinary trades, not assignments.
	 * @param array<int,bool> $linked Passed by reference.
	 * @return array{row:array<string,mixed>,contracts:float,how:string}|null
	 */
	private static function find_underlying_trade( string $account, array $meta, string $direction, float $want, float $multiplier, string $opened, string $anchor, array &$linked ): ?array {
		$window_end = gmdate( 'Y-m-d', strtotime( $meta['expiry'] . ' +' . self::SETTLEMENT_WINDOW_DAYS . ' days' ) );
		$best       = null;

		foreach ( self::underlying_symbols( $account, $meta['root'] ) as $underlying ) {
			foreach ( self::underlying_rows( $account, $underlying ) as $row ) {
				$id = (int) $row['id'];

				if ( isset( $linked[ $id ] ) ) {
					continue;
				}

				if ( strtoupper( trim( (string) $row['action'] ) ) !== $direction ) {
					continue;
				}

				$date = MM_Activities::row_date( $row );
				if ( $date > $window_end || ( '' !== $opened && $date < $opened ) ) {
					continue;
				}

				$price = abs( (float) $row['price'] );
				if ( abs( $price - (float) $meta['strike'] ) > self::STRIKE_TOLERANCE ) {
					continue;
				}

				$shares    = abs( (float) $row['quantity'] );
				$contracts = $multiplier > self::EPSILON ? $shares / $multiplier : 0.0;

				if ( $contracts < self::EPSILON || $contracts > $want + self::EPSILON ) {
					continue;
				}

				if ( abs( $contracts - round( $contracts ) ) > 0.001 ) {
					continue;
				}

				$distance = abs( strtotime( $date ) - strtotime( $anchor ) );

				if ( null === $best || $distance < $best['distance'] ) {
					$best = array(
						'row'       => $row,
						'contracts' => round( $contracts, 4 ),
						'distance'  => $distance,
						'how'       => sprintf(
							/* translators: 1: trade date, 2: share count, 3: price */
							__( 'matched %1$s trade of %2$s @ %3$s', 'money-maker' ),
							$date,
							self::qty_text( $shares ),
							number_format( $price, 2, '.', '' )
						),
					);
				}
			}
		}

		if ( null === $best ) {
			return null;
		}

		unset( $best['distance'] );

		return $best;
	}

	/**
	 * Candidate underlying symbols for an option root: the root itself, the root
	 * with an exchange suffix ("BMO" → "BMO.TO"), and the root with an adjusted-
	 * contract digit stripped ("AAPL1" → "AAPL").
	 *
	 * @return string[]
	 */
	private static function underlying_symbols( string $account, string $root ): array {
		$key = $account;

		if ( ! isset( self::$symbol_cache[ $key ] ) ) {
			$symbols = array();
			foreach ( MM_Activities::account_symbols( array( $account ) ) as $pair ) {
				$symbols[] = strtoupper( (string) $pair['symbol'] );
			}
			self::$symbol_cache[ $key ] = $symbols;
		}

		$roots = array( strtoupper( $root ) );
		$bare  = rtrim( strtoupper( $root ), '0123456789' );
		if ( '' !== $bare && ! in_array( $bare, $roots, true ) ) {
			$roots[] = $bare;
		}

		$matches = array();

		foreach ( self::$symbol_cache[ $key ] as $symbol ) {
			if ( self::is_option_symbol( $symbol ) ) {
				continue;
			}

			foreach ( $roots as $candidate ) {
				if ( $symbol === $candidate || 0 === strpos( $symbol, $candidate . '.' ) ) {
					$matches[] = $symbol;
					break;
				}
			}
		}

		return array_values( array_unique( $matches ) );
	}

	/**
	 * Activity rows for one underlying, cached per request.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function underlying_rows( string $account, string $symbol ): array {
		$key = $account . '|' . $symbol;

		if ( ! isset( self::$row_cache[ $key ] ) ) {
			self::$row_cache[ $key ] = MM_Activities::for_acb( $account, $symbol );
		}

		return self::$row_cache[ $key ];
	}

	/* ------------------------------------------------------------------ *
	 * Small helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Which lifecycle marker, if any, Questrade put on a row. Checked before the
	 * Buy/Sell branch because an assignment can arrive labelled as either.
	 *
	 * @return string '' | 'assignment' | 'exercise' | 'expiry'
	 */
	private static function marker( array $row ): string {
		$haystack = strtolower(
			trim( (string) ( $row['action'] ?? '' ) ) . ' ' .
			trim( (string) ( $row['type'] ?? '' ) ) . ' ' .
			trim( (string) ( $row['description'] ?? '' ) )
		);

		if ( false !== strpos( $haystack, 'assign' ) ) {
			return 'assignment';
		}

		if ( false !== strpos( $haystack, 'exercis' ) ) {
			return 'exercise';
		}

		if ( false !== strpos( $haystack, 'expir' ) ) {
			return 'expiry';
		}

		return '';
	}

	/**
	 * Shares per contract, derived from the row's own gross amount rather than
	 * assumed, so a feed that reports quantity in shares still works.
	 */
	private static function derive_multiplier( array $rows ): float {
		$samples = array();

		foreach ( $rows as $row ) {
			$qty   = abs( (float) $row['quantity'] );
			$price = abs( (float) $row['price'] );
			$gross = abs( (float) $row['gross_amount'] );

			if ( $gross < self::EPSILON ) {
				$gross = abs( (float) $row['net_amount'] );
			}

			if ( $qty < self::EPSILON || $price < self::EPSILON || $gross < self::EPSILON ) {
				continue;
			}

			$samples[] = $gross / ( $qty * $price );
		}

		if ( empty( $samples ) ) {
			return self::DEFAULT_MULTIPLIER;
		}

		sort( $samples );
		$median = $samples[ intdiv( count( $samples ), 2 ) ];

		if ( $median > 50 && $median < 200 ) {
			return 100.0;
		}

		if ( $median > 0.5 && $median < 2 ) {
			return 1.0;
		}

		return self::DEFAULT_MULTIPLIER;
	}

	/** Total contracts currently written (short). */
	private static function short_quantity( array $state ): float {
		$total = 0.0;

		foreach ( $state['short_lots'] as $lot ) {
			$total += (float) $lot['qty'];
		}

		return $total;
	}

	/**
	 * Premium still riding on open written lots (CAD). Informational only — it
	 * is *not* a cost base. s.49(1) already recognised it as a gain in the year
	 * the contract was written, so counting it again as book value on the
	 * holdings screen would double up with the realized-gains total.
	 */
	private static function short_premium( array $state ): float {
		$total = 0.0;

		foreach ( $state['short_lots'] as $lot ) {
			$total += (float) $lot['premium'];
		}

		return $total;
	}

	/**
	 * The still-open leg of one contract, shaped like an MM_Tax_ACB holding so
	 * the holdings screen can treat options and shares the same way (M4e).
	 *
	 * Amounts are on whatever basis analyze() ran — CAD for the tax screens,
	 * the contract's own currency for the holdings and wheel screens.
	 *
	 * `total_acb` is nil for a written contract by design: under s.49 a grant
	 * has no cost base, and its premium is already in realized gains. The
	 * position's negative market value then reads as the unrealised cost of
	 * buying it back, which nets correctly against that recognised premium.
	 *
	 * @param array  $result         analyze() output, or MM_Tax_ACB result['options'].
	 * @param string $account_number
	 * @param string $symbol         Option contract symbol.
	 * @return array{quantity:float,total_acb:?float,avg_cost:?float,currency:string,priced:bool,is_short:bool,short_premium:float}|null
	 */
	public static function open_position( array $result, string $account_number, string $symbol ): ?array {
		foreach ( $result['contracts'] ?? array() as $contract ) {
			if ( $contract['account_number'] !== $account_number || $contract['symbol'] !== $symbol ) {
				continue;
			}

			$long  = (float) ( $contract['open_long_quantity'] ?? 0 );
			$short = (float) ( $contract['open_short_quantity'] ?? 0 );

			if ( $long < self::EPSILON && $short < self::EPSILON ) {
				return null;
			}

			$cost   = (float) ( $contract['open_long_cost'] ?? 0 );
			$priced = (bool) ( $contract['priced'] ?? true );

			// An unpriced contract (a USD premium with no FX rate) has no
			// trustworthy cost. Report it as unknown rather than as zero —
			// zero would show the whole market value as unrealised gain.
			return array(
				'quantity'      => round( $long - $short, 4 ),
				'total_acb'     => $priced ? round( $cost, 2 ) : null,
				'avg_cost'      => ( $priced && $long > self::EPSILON ) ? round( $cost / $long, 6 ) : null,
				'currency'      => (string) ( $contract['currency'] ?? 'CAD' ),
				'priced'        => $priced,
				'is_short'      => $short > self::EPSILON && $long < self::EPSILON,
				'short_premium' => (float) ( $contract['open_short_premium'] ?? 0 ),
			);
		}

		return null;
	}

	/**
	 * Take $qty contracts off the written-lot queue, oldest first.
	 *
	 * @param array<string,mixed> $state Passed by reference.
	 * @return array{qty:float,premium:float,reversals:array<int,array<string,mixed>>}
	 */
	private static function consume_short_lots( array &$state, float $qty ): array {
		$taken     = 0.0;
		$premium   = 0.0;
		$reversals = array();

		foreach ( $state['short_lots'] as $i => $lot ) {
			if ( $qty - $taken < self::EPSILON ) {
				break;
			}

			$use   = min( (float) $lot['qty'], $qty - $taken );
			$share = $use / (float) $lot['qty'];
			$part  = (float) $lot['premium'] * $share;

			$taken   += $use;
			$premium += $part;

			$reversals[] = array(
				'index'   => (int) $lot['index'],
				'share'   => $share,
				'premium' => $part,
				'date'    => (string) $lot['date'],
			);

			$state['short_lots'][ $i ]['qty']     = (float) $lot['qty'] - $use;
			$state['short_lots'][ $i ]['premium'] = (float) $lot['premium'] - $part;
		}

		$state['short_lots'] = array_values(
			array_filter(
				$state['short_lots'],
				static function ( $lot ) {
					return (float) $lot['qty'] > self::EPSILON;
				}
			)
		);

		return array(
			'qty'       => $taken,
			'premium'   => $premium,
			'reversals' => $reversals,
		);
	}

	/**
	 * Shrink a grant disposition by $share of its quantity — the portion that
	 * assignment retroactively un-did. A fully consumed row is marked reversed
	 * and never published.
	 *
	 * @param array<string,mixed> $disposition Passed by reference.
	 */
	private static function reduce_disposition( array &$disposition, float $share ): void {
		$share = max( 0.0, min( 1.0, $share ) );
		$keep  = 1.0 - $share;

		$disposition['quantity'] = round( (float) $disposition['quantity'] * $keep, 4 );
		$disposition['proceeds'] = round( (float) $disposition['proceeds'] * $keep, 2 );
		$disposition['acb']      = round( (float) $disposition['acb'] * $keep, 2 );
		$disposition['gain']     = round( (float) $disposition['proceeds'] - (float) $disposition['acb'], 2 );

		if ( $disposition['quantity'] < self::EPSILON ) {
			$disposition['reversed'] = true;
		}
	}

	/**
	 * One realised option disposition, in the same shape MM_Tax_ACB emits for
	 * shares so the Realized Gains tables can render both.
	 *
	 * @param array<string,mixed>|null $row Source activity row, if any.
	 * @return array<string,mixed>
	 */
	private static function disposition( string $account, string $symbol, string $date, float $qty, float $proceeds, float $acb, string $currency, string $event, ?array $row ): array {
		$notes = array(
			'grant'       => __( 'Premium received on writing the contract — a capital gain in this year under s.49(1). Assignment would cancel it.', 'money-maker' ),
			'close_short' => __( 'Closing purchase of a written contract — a capital loss in the year it was bought back.', 'money-maker' ),
			'close_long'  => __( 'Sold a held contract — gain/loss against its pooled average cost.', 'money-maker' ),
			'expiry_long' => __( 'Held contract expired worthless — deemed disposition for nil proceeds.', 'money-maker' ),
		);

		return array(
			'account_number' => $account,
			'symbol'         => $symbol,
			'date'           => $date,
			'year'           => (int) substr( $date, 0, 4 ),
			'quantity'       => round( $qty, 4 ),
			'proceeds'       => round( $proceeds, 2 ),
			'acb'            => round( $acb, 2 ),
			'gain'           => round( $proceeds - $acb, 2 ),
			'avg_cost'       => $qty > self::EPSILON ? round( $acb / $qty, 6 ) : 0.0,
			'currency'       => $currency,
			'flags'          => ( null !== $row && null === MM_Activities::amount( $row, self::$basis ) ) ? array( 'missing_cad' ) : array(),
			'asset_class'    => 'option',
			'event'          => $event,
			'note'           => $notes[ $event ] ?? '',
			'reversed'       => false,
		);
	}

	/**
	 * One audit-trail entry for a contract's lifecycle table.
	 *
	 * @param array<string,mixed>|null $row Source activity row, if any.
	 * @return array<string,mixed>
	 */
	private static function event( string $date, string $event, ?array $row, float $qty, ?float $amount, string $detail = '' ): array {
		$raw = '';

		if ( null !== $row ) {
			$raw = trim(
				implode(
					' / ',
					array_filter(
						array(
							trim( (string) ( $row['type'] ?? '' ) ),
							trim( (string) ( $row['action'] ?? '' ) ),
							trim( (string) ( $row['description'] ?? '' ) ),
						)
					)
				)
			);
		}

		return array(
			'date'        => $date,
			'event'       => $event,
			'quantity'    => round( $qty, 4 ),
			'amount'      => null === $amount ? null : round( $amount, 2 ),
			'detail'      => $detail,
			'raw'         => $raw,
			// The activity row this event came from, when there is one. The
			// wheel ledger (M4g) uses it to read the row's commission and to
			// link a ledger line back to the trade that produced it; synthetic
			// events (expiry, assignment) have no row and carry null.
			'activity_id' => ( null !== $row && isset( $row['id'] ) ) ? (int) $row['id'] : null,
		);
	}

	/** Trim trailing zeros off a quantity for display inside a sentence. */
	private static function qty_text( float $qty ): string {
		return rtrim( rtrim( number_format( $qty, 4, '.', '' ), '0' ), '.' );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function empty_result(): array {
		return array(
			'contracts'    => array(),
			'dispositions' => array(),
			'effects'      => array(),
			'amendments'   => array(),
			'reviews'      => array(),
			'warnings'     => array(),
			'unmapped'     => array(),
			'totals'       => array(
				'premium_in'  => 0.0,
				'premium_out' => 0.0,
				'realized'    => 0.0,
				'rolled'      => 0.0,
			),
		);
	}
}
