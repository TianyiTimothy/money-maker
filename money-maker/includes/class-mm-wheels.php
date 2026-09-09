<?php
/**
 * Wheel-strategy tracker (Milestone 4g).
 *
 * Absorbs the old standalone "Wheel Tracker" plugin. That version was a manual
 * ledger: every put sold, every roll, every assignment typed in by hand into
 * user meta. Everything it asked for is already in `mm_activities`, so this
 * version derives the same ledger from the Questrade feed instead — the user
 * types nothing, and the numbers cannot drift from the broker's.
 *
 * A **wheel** here is one (account, underlying) pair that has ever had an
 * option contract written on it. Plain stock positions are not wheels. Within
 * a wheel the ledger is segmented into **rounds**: a round opens on the first
 * event after the position is flat (no shares, no open contracts) and closes
 * when it is flat again — so "sold a put, it expired, sold another" is one
 * wheel with two rounds, which is how the strategy is actually reasoned about.
 *
 * Where the numbers come from:
 *   - Option lifecycle events (write / buy-to-close / expiry / assignment) come
 *     from MM_Tax_Options via the cached MM_Tax_ACB result, so the wheel
 *     screen, the holdings screen and the tax screen cannot disagree about what
 *     happened to a contract.
 *   - The cash on an assignment comes from the **share trade Questrade actually
 *     booked** (the option engine already matched it and published it in
 *     `effects`), not from strike x contracts. If the two ever differ, the
 *     broker is right.
 *   - Share trades, dividends and stray cash lines on the underlying come
 *     straight from the activity rows.
 *
 * **Currency.** Everything is in the underlying's own trading currency — a
 * wheel on a US ticker is a USD wheel and reads in USD. Only the cross-wheel
 * summary converts, into the display currency (M4f). Nothing here is a tax
 * figure: this measures cash, exactly as the original tracker did.
 *
 * Static utility: no register(). Required directly by money-maker.php.
 *
 * @package MoneyMaker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Derives wheel cycles and their cash metrics from synced Questrade activity.
 */
final class MM_Wheels {

	/** Float tolerance for share and contract quantities. */
	const EPSILON = 0.0000001;

	/**
	 * A flat wheel with no activity for this long is treated as finished rather
	 * than merely between trades. Long enough that a quiet quarter does not
	 * close a wheel the user still considers live.
	 */
	const DORMANT_DAYS = 120;

	/** Shares per contract when a contract does not report its own. */
	const DEFAULT_MULTIPLIER = 100.0;

	/**
	 * Per-request memo of all(). The dashboard card and the Wheels screen both
	 * ask, and the walk costs one query per contract on top of the (transient-
	 * cached) ACB result — cheap to do once, wasteful to do twice.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $memo = null;

	/**
	 * Every wheel, newest activity first.
	 *
	 * @return array{
	 *   wheels:array<int,array<string,mixed>>,
	 *   totals:array<string,mixed>,
	 *   display_currency:string,
	 *   has_data:bool
	 * }
	 */
	public static function all(): array {
		if ( null !== self::$memo ) {
			return self::$memo;
		}

		$display  = MM_Money::display_currency();
		$accounts = MM_Accounts::numbers();

		if ( empty( $accounts ) ) {
			self::$memo = self::empty_result( $display );

			return self::$memo;
		}

		// Native basis: a wheel is a cash story in the currency it was traded
		// in. The tax screens keep their own CAD walk.
		$acb       = MM_Tax_ACB::get( $accounts, 'native' );
		$contracts = $acb['options']['contracts'] ?? array();
		$effects   = $acb['options']['effects'] ?? array();

		if ( empty( $contracts ) ) {
			self::$memo = self::empty_result( $display );

			return self::$memo;
		}

		$wheels = array();

		foreach ( self::group_contracts( $contracts, $accounts ) as $group ) {
			$wheel = self::build_wheel( $group, $effects, $acb );

			if ( null !== $wheel ) {
				$wheels[] = $wheel;
			}
		}

		usort(
			$wheels,
			static function ( $a, $b ) {
				// Live wheels first, then by most recent activity.
				$rank = array( 'active' => 0, 'flat' => 1, 'finished' => 2 );

				return array( $rank[ $a['status'] ] ?? 3, $b['last_date'] )
					<=> array( $rank[ $b['status'] ] ?? 3, $a['last_date'] );
			}
		);

		self::$memo = array(
			'wheels'           => $wheels,
			'totals'           => self::totals( $wheels, $display ),
			'display_currency' => $display,
			'has_data'         => ! empty( $wheels ),
		);

		return self::$memo;
	}

	/**
	 * Drop the per-request memo. Only the test harness and a caller that has
	 * just changed the underlying data need this.
	 */
	public static function flush(): void {
		self::$memo = null;
	}

	/**
	 * Compact figures for the dashboard card.
	 *
	 * @return array{count:int,active:int,net_cash:?float,premium:?float,at_risk:?float,currency:string}
	 */
	public static function summary(): array {
		$model = self::all();

		return array(
			'count'    => count( $model['wheels'] ),
			'active'   => (int) ( $model['totals']['active'] ?? 0 ),
			'net_cash' => $model['totals']['net_cash'] ?? null,
			'premium'  => $model['totals']['net_premium'] ?? null,
			'at_risk'  => $model['totals']['at_risk'] ?? null,
			'currency' => $model['display_currency'],
		);
	}

	/* ------------------------------------------------------------------ *
	 * Grouping
	 * ------------------------------------------------------------------ */

	/**
	 * Bucket contracts into (account, underlying) wheels.
	 *
	 * The underlying is whatever the option engine already linked through an
	 * assignment; failing that, the option root matched against the symbols the
	 * account has actually traded (so "BMO" finds "BMO.TO"); failing that, the
	 * root itself, which at least groups the contracts together.
	 *
	 * @param array<int,array<string,mixed>> $contracts
	 * @param string[]                       $accounts
	 * @return array<string,array<string,mixed>>
	 */
	private static function group_contracts( array $contracts, array $accounts ): array {
		$traded = array();
		foreach ( $accounts as $account ) {
			$traded[ $account ] = array();
			foreach ( MM_Activities::account_symbols( array( $account ) ) as $pair ) {
				if ( ! MM_Tax_ACB::is_option_symbol( $pair['symbol'] ) ) {
					$traded[ $account ][] = strtoupper( (string) $pair['symbol'] );
				}
			}
		}

		$groups = array();

		foreach ( $contracts as $contract ) {
			$account    = (string) $contract['account_number'];
			$underlying = self::resolve_underlying( $contract, $traded[ $account ] ?? array() );
			$key        = $account . '|' . $underlying;

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'account_number' => $account,
					'underlying'     => $underlying,
					'contracts'      => array(),
				);
			}

			$groups[ $key ]['contracts'][] = $contract;
		}

		return $groups;
	}

	/**
	 * Best available underlying symbol for one contract.
	 *
	 * @param array<string,mixed> $contract
	 * @param string[]            $traded   Non-option symbols traded in the account.
	 */
	private static function resolve_underlying( array $contract, array $traded ): string {
		$linked = trim( (string) ( $contract['underlying'] ?? '' ) );

		if ( '' !== $linked ) {
			return strtoupper( $linked );
		}

		$root  = strtoupper( (string) $contract['root'] );
		$roots = array( $root );
		$bare  = rtrim( $root, '0123456789' );

		if ( '' !== $bare && $bare !== $root ) {
			$roots[] = $bare;
		}

		foreach ( $roots as $candidate ) {
			foreach ( $traded as $symbol ) {
				if ( $symbol === $candidate || 0 === strpos( $symbol, $candidate . '.' ) ) {
					return $symbol;
				}
			}
		}

		return $roots[ count( $roots ) - 1 ];
	}

	/* ------------------------------------------------------------------ *
	 * One wheel
	 * ------------------------------------------------------------------ */

	/**
	 * Assemble one wheel: its ledger, its rounds and its metrics.
	 *
	 * @param array<string,mixed>            $group
	 * @param array<int,array<string,mixed>> $effects Option assignment effects, keyed by activity id.
	 * @param array<string,mixed>            $acb     Full ACB result (for the share pool + currency).
	 * @return array<string,mixed>|null
	 */
	private static function build_wheel( array $group, array $effects, array $acb ): ?array {
		$account    = (string) $group['account_number'];
		$underlying = (string) $group['underlying'];

		$events = self::option_events( $group['contracts'], $effects );
		$events = array_merge( $events, self::share_events( $account, $underlying, $effects, $group['contracts'] ) );

		if ( empty( $events ) ) {
			return null;
		}

		usort(
			$events,
			static function ( $a, $b ) {
				return array( $a['date'], $a['sort'], $a['type'] ) <=> array( $b['date'], $b['sort'], $b['type'] );
			}
		);

		$currency = self::wheel_currency( $acb, $account, $underlying, $group['contracts'] );
		$walk     = self::walk( $events );

		$position = MM_Positions::latest_snapshot( $account );
		$market   = null;
		foreach ( $position as $row ) {
			if ( strtoupper( (string) $row['symbol'] ) === $underlying && null !== $row['current_market_value'] ) {
				$market = (float) $row['current_market_value'];
			}
		}

		$first_date = (string) $events[0]['date'];
		$last_date  = (string) $events[ count( $events ) - 1 ]['date'];
		$flat       = $walk['shares'] < self::EPSILON && empty( $walk['open'] );
		$stale_days = self::days_between( $last_date, gmdate( 'Y-m-d' ) );

		if ( ! $flat ) {
			$status = 'active';
		} elseif ( $stale_days > self::DORMANT_DAYS ) {
			$status = 'finished';
		} else {
			$status = 'flat';
		}

		$end_date = 'finished' === $status ? $last_date : gmdate( 'Y-m-d' );
		$days     = max( 1, self::days_between( $first_date, $end_date ) );

		$open_put  = self::pick_open( $walk['open'], 'P' );
		$open_call = self::pick_open( $walk['open'], 'C' );

		$break_even = $walk['shares'] > self::EPSILON ? ( -$walk['net_cash'] / $walk['shares'] ) : null;

		// "If the shares are called away at the open call's strike, where does
		// this wheel finish?" — the number the strategy is actually steered by.
		$if_called_away = null;
		if ( null !== $open_call && $walk['shares'] > self::EPSILON ) {
			$covered        = min( $walk['shares'], $open_call['quantity'] * $open_call['multiplier'] );
			$if_called_away = $walk['net_cash'] + ( $open_call['strike'] * $covered );
		}

		$return_on_capital = $walk['peak_capital'] > 0 ? ( $walk['net_cash'] / $walk['peak_capital'] * 100 ) : null;
		$annualized        = null !== $return_on_capital ? ( $return_on_capital * 365 / $days ) : null;

		return array(
			'key'               => md5( $account . '|' . $underlying ),
			'account_number'    => $account,
			'account_label'     => MM_Accounts::label( $account ),
			'underlying'        => $underlying,
			'currency'          => $currency,
			'status'            => $status,
			'phase'             => self::phase( $status, $walk['shares'], $open_put, $open_call ),
			'first_date'        => $first_date,
			'last_date'         => $last_date,
			'days'              => $days,
			'events'            => $events,
			'ledger'            => $walk['ledger'],
			'rounds'            => $walk['rounds'],
			'shares'            => round( $walk['shares'], 4 ),
			'share_cost'        => round( $walk['share_cost'], 2 ),
			'avg_cost'          => $walk['shares'] > self::EPSILON ? round( $walk['share_cost'] / $walk['shares'], 4 ) : null,
			'net_cash'          => round( $walk['net_cash'], 2 ),
			'premium_in'        => round( $walk['premium_in'], 2 ),
			'premium_out'       => round( $walk['premium_out'], 2 ),
			'net_premium'       => round( $walk['premium_in'] - $walk['premium_out'], 2 ),
			'dividends'         => round( $walk['dividends'], 2 ),
			'fees'              => round( $walk['fees'], 2 ),
			'peak_capital'      => round( $walk['peak_capital'], 2 ),
			'break_even'        => null === $break_even ? null : round( $break_even, 4 ),
			'market_value'      => null === $market ? null : round( $market, 2 ),
			'unrealised'        => ( null !== $market ) ? round( $market - $walk['share_cost'], 2 ) : null,
			'open_value'        => ( null !== $market ) ? round( $walk['net_cash'] + $market, 2 ) : null,
			'if_called_away'    => null === $if_called_away ? null : round( $if_called_away, 2 ),
			'return_on_capital' => null === $return_on_capital ? null : round( $return_on_capital, 2 ),
			'annualized'        => null === $annualized ? null : round( $annualized, 1 ),
			'open_put'          => $open_put,
			'open_call'         => $open_call,
			'open_contracts'    => array_values( $walk['open'] ),
			'at_risk'           => round( $walk['share_cost'] + self::put_exposure( $walk['open'] ), 2 ),
			'warnings'          => $walk['warnings'],
		);
	}

	/* ------------------------------------------------------------------ *
	 * Event construction
	 * ------------------------------------------------------------------ */

	/**
	 * Turn the option engine's lifecycle events into wheel ledger entries.
	 *
	 * Assignment and exercise events are deliberately skipped here: the cash and
	 * the shares belong to the underlying trade, which share_events() emits from
	 * the real activity row.
	 *
	 * @param array<int,array<string,mixed>> $contracts
	 * @param array<int,array<string,mixed>> $effects
	 * @return array<int,array<string,mixed>>
	 */
	private static function option_events( array $contracts, array $effects ): array {
		$map = array(
			'grant'        => array( 'sell', true ),
			'close_short'  => array( 'buy_close', true ),
			'open_long'    => array( 'buy_open', true ),
			'close_long'   => array( 'sell_close', true ),
			'expiry_short' => array( 'expired', false ),
			'expiry_long'  => array( 'expired_long', false ),
		);

		$out = array();

		foreach ( $contracts as $contract ) {
			$symbol     = (string) $contract['symbol'];
			$right      = (string) $contract['right'];
			$multiplier = (float) ( $contract['multiplier'] ?? self::DEFAULT_MULTIPLIER );
			$fees       = self::commissions( (string) $contract['account_number'], $symbol );

			foreach ( (array) $contract['events'] as $event ) {
				$kind = (string) $event['event'];

				if ( in_array( $kind, array( 'put_assignment', 'call_assignment', 'call_exercise', 'put_exercise' ), true ) ) {
					continue;
				}

				if ( 'review' === $kind || 'unlinked' === $kind ) {
					$out[] = self::event(
						(string) $event['date'],
						'review',
						$symbol,
						$contract,
						0.0,
						0.0,
						(float) $event['quantity'],
						0.0,
						(string) ( $event['detail'] ?: MM_Tax_Options::event_label( $kind ) ),
						(string) $event['raw']
					);
					continue;
				}

				if ( ! isset( $map[ $kind ] ) ) {
					continue;
				}

				list( $suffix, $moves_cash ) = $map[ $kind ];

				$type = in_array( $suffix, array( 'buy_open', 'sell_close', 'expired_long' ), true )
					? 'option_' . $suffix
					: ( 'P' === $right ? 'put_' : 'call_' ) . $suffix;

				// An expiry moves no cash — the premium changed hands when the
				// contract was written. The option engine's amount on that event
				// is a tax figure (the deemed disposition), not a cash flow.
				$cash = $moves_cash ? (float) ( $event['amount'] ?? 0 ) : 0.0;
				$id   = $event['activity_id'] ?? null;

				$out[] = self::event(
					(string) $event['date'],
					$type,
					$symbol,
					$contract,
					$cash,
					null !== $id ? ( $fees[ (int) $id ] ?? 0.0 ) : 0.0,
					(float) $event['quantity'],
					$multiplier,
					(string) $event['detail'],
					(string) $event['raw']
				);
			}
		}

		return $out;
	}

	/**
	 * Ledger entries from the underlying's own activity rows.
	 *
	 * @param array<int,array<string,mixed>> $effects
	 * @param array<int,array<string,mixed>> $contracts Contracts in this wheel, to
	 *                                                  claim only our own assignments.
	 * @return array<int,array<string,mixed>>
	 */
	private static function share_events( string $account, string $underlying, array $effects, array $contracts ): array {
		$ours = array();
		foreach ( $contracts as $contract ) {
			$ours[ (string) $contract['symbol'] ] = $contract;
		}

		$out = array();

		foreach ( MM_Activities::for_acb( $account, $underlying ) as $row ) {
			$id     = (int) $row['id'];
			$date   = MM_Activities::row_date( $row );
			$cash   = (float) ( MM_Activities::signed_amount( $row, 'native' ) ?? 0.0 );
			$fee    = abs( (float) ( $row['commission'] ?? 0 ) );
			$qty    = abs( (float) $row['quantity'] );
			$action = strtoupper( trim( (string) $row['action'] ) );
			$raw    = self::raw_label( $row );

			$effect = $effects[ $id ] ?? null;

			// This share trade was matched to one of *our* contracts: it is the
			// assignment or exercise leg, and its premium note belongs on it.
			if ( null !== $effect && isset( $ours[ (string) $effect['contract'] ] ) ) {
				$contract = $ours[ (string) $effect['contract'] ];
				$kinds    = array(
					'put_assignment'  => 'put_assigned',
					'call_assignment' => 'call_assigned',
					'call_exercise'   => 'option_exercised_call',
					'put_exercise'    => 'option_exercised_put',
				);

				$out[] = self::event(
					$date,
					$kinds[ (string) $effect['kind'] ] ?? 'stock_bought',
					(string) $effect['contract'],
					$contract,
					$cash,
					$fee,
					(float) $effect['contracts'],
					(float) ( $contract['multiplier'] ?? self::DEFAULT_MULTIPLIER ),
					sprintf(
						/* translators: 1: share count, 2: strike price, 3: how the trade was matched */
						__( '%1$s shares at %2$s (%3$s)', 'money-maker' ),
						self::qty_text( (float) $effect['shares'] ),
						number_format( (float) $effect['strike'], 2, '.', '' ),
						(string) $effect['matched']
					),
					$raw,
					(float) $effect['shares']
				);
				continue;
			}

			if ( ( 'BUY' === $action || 'SELL' === $action ) && $qty > self::EPSILON ) {
				$out[] = self::event(
					$date,
					'BUY' === $action ? 'stock_bought' : 'stock_sold',
					'',
					null,
					$cash,
					$fee,
					0.0,
					0.0,
					sprintf(
						/* translators: 1: share count, 2: price */
						__( '%1$s shares at %2$s', 'money-maker' ),
						self::qty_text( $qty ),
						number_format( abs( (float) $row['price'] ), 4, '.', '' )
					),
					$raw,
					$qty
				);
				continue;
			}

			// A share movement that is not an outright trade — a transfer in,
			// journalled shares, a corporate action. Never guessed: it goes in
			// the ledger at zero cash and raises a warning, exactly like the
			// ACB engine's review bucket.
			if ( $qty > self::EPSILON ) {
				$out[] = self::event( $date, 'review', '', null, 0.0, $fee, 0.0, 0.0, '', $raw, $qty );
				continue;
			}

			if ( abs( $cash ) < 0.005 ) {
				continue;
			}

			$type = ( false !== stripos( (string) $row['type'], 'div' ) || false !== stripos( (string) $row['action'], 'div' ) )
				? 'dividend'
				: 'cash_adjust';

			$out[] = self::event( $date, $type, '', null, $cash, $fee, 0.0, 0.0, '', $raw );
		}

		return $out;
	}

	/**
	 * One ledger entry.
	 *
	 * @param array<string,mixed>|null $contract Owning contract, when there is one.
	 * @return array<string,mixed>
	 */
	private static function event( string $date, string $type, string $contract_symbol, ?array $contract, float $cash, float $fee, float $contracts, float $multiplier, string $detail, string $raw, float $shares = 0.0 ): array {
		return array(
			'date'       => $date,
			'sort'       => self::sort_rank( $type ),
			'type'       => $type,
			'contract'   => $contract_symbol,
			'right'      => null !== $contract ? (string) $contract['right'] : '',
			'strike'     => null !== $contract ? (float) $contract['strike'] : null,
			'expiry'     => null !== $contract ? (string) $contract['expiry'] : null,
			'contracts'  => round( $contracts, 4 ),
			'multiplier' => $multiplier > self::EPSILON ? $multiplier : self::DEFAULT_MULTIPLIER,
			'shares'     => round( $shares, 4 ),
			'cash'       => round( $cash, 2 ),
			'fee'        => round( $fee, 2 ),
			'detail'     => $detail,
			'raw'        => $raw,
		);
	}

	/**
	 * Same-day ordering. A contract has to close before the next one opens, and
	 * an assignment has to land before a fresh call is written against the
	 * shares it delivered, or the round boundaries come out wrong.
	 */
	private static function sort_rank( string $type ): int {
		$order = array(
			'put_expired'           => 0,
			'call_expired'          => 0,
			'option_expired_long'   => 0,
			'put_buy_close'         => 1,
			'call_buy_close'        => 1,
			'option_sell_close'     => 1,
			'put_assigned'          => 2,
			'call_assigned'         => 2,
			'option_exercised_call' => 2,
			'option_exercised_put'  => 2,
			'stock_bought'          => 3,
			'stock_sold'            => 3,
			'dividend'              => 4,
			'cash_adjust'           => 4,
			'review'                => 4,
			'put_sell'              => 5,
			'call_sell'             => 5,
			'option_buy_open'       => 5,
		);

		return $order[ $type ] ?? 4;
	}

	/* ------------------------------------------------------------------ *
	 * The walk
	 * ------------------------------------------------------------------ */

	/**
	 * Run the ledger: cash, shares, open contracts, rounds.
	 *
	 * @param array<int,array<string,mixed>> $events
	 * @return array<string,mixed>
	 */
	private static function walk( array $events ): array {
		$shares      = 0.0;
		$share_cost  = 0.0;
		$net_cash    = 0.0;
		$premium_in  = 0.0;
		$premium_out = 0.0;
		$dividends   = 0.0;
		$fees        = 0.0;
		$peak        = 0.0;
		$open        = array();
		$ledger      = array();
		$rounds      = array();
		$warnings    = array();

		$round = null;
		$last  = count( $events ) - 1;

		foreach ( $events as $index => $event ) {
			$type = (string) $event['type'];
			$cash = (float) $event['cash'];
			$qty  = (float) $event['contracts'];

			// A round opens on the first event after the position went flat.
			if ( null === $round ) {
				$round = array(
					'opened'   => (string) $event['date'],
					'closed'   => null,
					'net_cash' => 0.0,
					'events'   => 0,
				);
			}

			$net_cash += $cash;
			$fees     += (float) $event['fee'];

			switch ( $type ) {
				case 'put_sell':
				case 'call_sell':
					$premium_in += max( 0.0, $cash );
					self::open_contract( $open, $event, $qty );

					if ( 'call_sell' === $type ) {
						$covered = $qty * (float) $event['multiplier'];
						if ( $covered > $shares + self::EPSILON ) {
							$warnings[] = sprintf(
								/* translators: 1: date, 2: contract count, 3: share count */
								__( 'On %1$s you wrote %2$s call contract(s) against %3$s shares — more than covered.', 'money-maker' ),
								(string) $event['date'],
								self::qty_text( $qty ),
								self::qty_text( $shares )
							);
						}
					}
					break;

				case 'put_buy_close':
				case 'call_buy_close':
					$premium_out += abs( min( 0.0, $cash ) );
					self::close_contract( $open, $event, $qty );
					break;

				case 'option_buy_open':
					$premium_out += abs( min( 0.0, $cash ) );
					self::open_contract( $open, $event, $qty, true );
					break;

				case 'option_sell_close':
					$premium_in += max( 0.0, $cash );
					self::close_contract( $open, $event, $qty );
					break;

				case 'put_expired':
				case 'call_expired':
				case 'option_expired_long':
					self::close_contract( $open, $event, $qty );
					break;

				case 'put_assigned':
				case 'option_exercised_call':
					self::close_contract( $open, $event, $qty );
					$shares     += (float) $event['shares'];
					$share_cost += abs( $cash );
					break;

				case 'call_assigned':
				case 'option_exercised_put':
					self::close_contract( $open, $event, $qty );
					$share_cost -= self::avg_cost( $shares, $share_cost ) * min( $shares, (float) $event['shares'] );
					$shares     -= (float) $event['shares'];
					break;

				case 'stock_bought':
					$shares     += (float) $event['shares'];
					$share_cost += abs( $cash );
					break;

				case 'stock_sold':
					$share_cost -= self::avg_cost( $shares, $share_cost ) * min( $shares, (float) $event['shares'] );
					$shares     -= (float) $event['shares'];
					break;

				case 'dividend':
					$dividends += $cash;
					break;

				case 'review':
					$warnings[] = sprintf(
						/* translators: 1: date, 2: the raw Questrade label */
						__( 'The activity on %1$s ("%2$s") is not a plain trade, so it is in the ledger at zero cash. Check it before trusting this wheel\'s cost base.', 'money-maker' ),
						(string) $event['date'],
						'' === $event['raw'] ? __( 'unlabelled', 'money-maker' ) : (string) $event['raw']
					);
					break;
			}

			if ( $shares < self::EPSILON ) {
				$shares     = 0.0;
				$share_cost = 0.0;
			}

			$committed = $share_cost + self::put_exposure( $open );
			$peak      = max( $peak, $committed );

			$ledger[] = array(
				'event'        => $event,
				'running_cash' => round( $net_cash, 2 ),
				'shares'       => round( $shares, 4 ),
			);

			++$round['events'];
			$round['net_cash'] += $cash;

			// Flat again: the round is done — but only judged at the END of a
			// trading day. A roll closes one contract and opens the next in the
			// same session, and for a moment in between the position is flat;
			// treating that as the end of a round would split every roll into
			// two rounds and reset the clock on a wheel that never stopped.
			$day_ends = $index === $last || (string) $events[ $index + 1 ]['date'] !== (string) $event['date'];

			if ( $day_ends && $shares < self::EPSILON && empty( $open ) ) {
				$round['closed']   = (string) $event['date'];
				$round['net_cash'] = round( $round['net_cash'], 2 );
				$round['days']     = max( 1, self::days_between( $round['opened'], $round['closed'] ) );
				$rounds[]          = $round;
				$round             = null;
			}
		}

		// A round still in flight is reported as open, not dropped.
		if ( null !== $round ) {
			$round['net_cash'] = round( $round['net_cash'], 2 );
			$round['days']     = max( 1, self::days_between( $round['opened'], gmdate( 'Y-m-d' ) ) );
			$rounds[]          = $round;
		}

		return array(
			'shares'       => $shares,
			'share_cost'   => $share_cost,
			'net_cash'     => $net_cash,
			'premium_in'   => $premium_in,
			'premium_out'  => $premium_out,
			'dividends'    => $dividends,
			'fees'         => $fees,
			'peak_capital' => $peak,
			'open'         => $open,
			'ledger'       => $ledger,
			'rounds'       => $rounds,
			'warnings'     => array_values( array_unique( $warnings ) ),
		);
	}

	/**
	 * Record an opened contract on the running position.
	 *
	 * @param array<string,array<string,mixed>> $open Passed by reference.
	 */
	private static function open_contract( array &$open, array $event, float $qty, bool $long = false ): void {
		$symbol = (string) $event['contract'];

		if ( '' === $symbol || $qty < self::EPSILON ) {
			return;
		}

		if ( ! isset( $open[ $symbol ] ) ) {
			$open[ $symbol ] = array(
				'symbol'     => $symbol,
				'right'      => (string) $event['right'],
				'strike'     => (float) $event['strike'],
				'expiry'     => (string) $event['expiry'],
				'multiplier' => (float) $event['multiplier'],
				'quantity'   => 0.0,
				'long'       => $long,
			);
		}

		$open[ $symbol ]['quantity'] += $qty;
	}

	/**
	 * Take contracts back off the running position.
	 *
	 * @param array<string,array<string,mixed>> $open Passed by reference.
	 */
	private static function close_contract( array &$open, array $event, float $qty ): void {
		$symbol = (string) $event['contract'];

		if ( '' === $symbol || ! isset( $open[ $symbol ] ) ) {
			return;
		}

		// A settlement event carries no quantity when it closed "whatever is
		// left"; take the whole line in that case.
		$take = $qty > self::EPSILON ? $qty : $open[ $symbol ]['quantity'];

		$open[ $symbol ]['quantity'] -= $take;

		if ( $open[ $symbol ]['quantity'] < self::EPSILON ) {
			unset( $open[ $symbol ] );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Small helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Cash a written put has tied up: it must be there if the put is assigned.
	 *
	 * @param array<string,array<string,mixed>> $open
	 */
	private static function put_exposure( array $open ): float {
		$total = 0.0;

		foreach ( $open as $line ) {
			if ( 'P' === $line['right'] && empty( $line['long'] ) ) {
				$total += (float) $line['strike'] * (float) $line['quantity'] * (float) $line['multiplier'];
			}
		}

		return $total;
	}

	/**
	 * The soonest-expiring written contract of one right, or null.
	 *
	 * @param array<string,array<string,mixed>> $open
	 * @return array<string,mixed>|null
	 */
	private static function pick_open( array $open, string $right ): ?array {
		$best = null;

		foreach ( $open as $line ) {
			if ( $line['right'] !== $right || ! empty( $line['long'] ) ) {
				continue;
			}

			if ( null === $best || $line['expiry'] < $best['expiry'] ) {
				$best = $line;
			}
		}

		return $best;
	}

	/**
	 * Where the wheel stands right now, in the vocabulary the strategy uses.
	 *
	 * @param array<string,mixed>|null $open_put
	 * @param array<string,mixed>|null $open_call
	 */
	private static function phase( string $status, float $shares, ?array $open_put, ?array $open_call ): string {
		if ( 'finished' === $status ) {
			return 'finished';
		}

		if ( $shares > self::EPSILON ) {
			return null !== $open_call ? 'call' : 'holding';
		}

		if ( null !== $open_put ) {
			return 'put';
		}

		return 'flat';
	}

	/** Human label for a phase. */
	public static function phase_label( string $phase ): string {
		$labels = array(
			'put'      => __( 'Put open', 'money-maker' ),
			'holding'  => __( 'Holding shares', 'money-maker' ),
			'call'     => __( 'Call open', 'money-maker' ),
			'flat'     => __( 'Between trades', 'money-maker' ),
			'finished' => __( 'Finished', 'money-maker' ),
		);

		return $labels[ $phase ] ?? $phase;
	}

	/** Human label for a ledger entry type. */
	public static function type_label( string $type ): string {
		$labels = array(
			'put_sell'              => __( 'Sold put to open', 'money-maker' ),
			'put_buy_close'         => __( 'Bought put back', 'money-maker' ),
			'put_expired'           => __( 'Put expired', 'money-maker' ),
			'put_assigned'          => __( 'Put assigned — shares bought', 'money-maker' ),
			'call_sell'             => __( 'Sold call to open', 'money-maker' ),
			'call_buy_close'        => __( 'Bought call back', 'money-maker' ),
			'call_expired'          => __( 'Call expired', 'money-maker' ),
			'call_assigned'         => __( 'Called away — shares sold', 'money-maker' ),
			'option_buy_open'       => __( 'Bought contract to open', 'money-maker' ),
			'option_sell_close'     => __( 'Sold contract to close', 'money-maker' ),
			'option_expired_long'   => __( 'Held contract expired', 'money-maker' ),
			'option_exercised_call' => __( 'Exercised call — shares bought', 'money-maker' ),
			'option_exercised_put'  => __( 'Exercised put — shares sold', 'money-maker' ),
			'stock_bought'          => __( 'Bought shares', 'money-maker' ),
			'stock_sold'            => __( 'Sold shares', 'money-maker' ),
			'dividend'              => __( 'Dividend', 'money-maker' ),
			'cash_adjust'           => __( 'Cash adjustment', 'money-maker' ),
			'review'                => __( 'Needs review', 'money-maker' ),
		);

		return $labels[ $type ] ?? $type;
	}

	/**
	 * Which side of the wheel a ledger entry belongs to — drives its colour.
	 */
	public static function type_tone( string $type ): string {
		if ( 0 === strpos( $type, 'put_' ) ) {
			return 'put';
		}
		if ( 0 === strpos( $type, 'call_' ) ) {
			return 'call';
		}
		if ( 0 === strpos( $type, 'stock_' ) || 0 === strpos( $type, 'option_exercised' ) ) {
			return 'stock';
		}
		if ( 'review' === $type ) {
			return 'warn';
		}

		return 'cash';
	}

	/**
	 * Commission per activity id for one symbol, so a ledger line can show what
	 * the trade cost as well as what it paid.
	 *
	 * @return array<int,float>
	 */
	private static function commissions( string $account, string $symbol ): array {
		$out = array();

		foreach ( MM_Activities::for_acb( $account, $symbol ) as $row ) {
			$out[ (int) $row['id'] ] = abs( (float) ( $row['commission'] ?? 0 ) );
		}

		return $out;
	}

	/**
	 * The currency this wheel trades in: the share pool's, else the contracts'.
	 *
	 * @param array<string,mixed>            $acb
	 * @param array<int,array<string,mixed>> $contracts
	 */
	private static function wheel_currency( array $acb, string $account, string $underlying, array $contracts ): string {
		$holding = MM_Tax_ACB::holding( $acb, $account, $underlying );

		if ( null !== $holding && ! empty( $holding['currency'] ) ) {
			return (string) $holding['currency'];
		}

		foreach ( $contracts as $contract ) {
			if ( ! empty( $contract['currency'] ) ) {
				return (string) $contract['currency'];
			}
		}

		return 'CAD';
	}

	/**
	 * Cross-wheel roll-up, converted into the display currency.
	 *
	 * Each wheel is converted at today's rate: unlike a cost base, these are
	 * "where do I stand now" figures, so today is the right rate to use.
	 *
	 * @param array<int,array<string,mixed>> $wheels
	 * @return array<string,mixed>
	 */
	private static function totals( array $wheels, string $display ): array {
		$today = gmdate( 'Y-m-d' );

		$sum = array(
			'net_cash'    => 0.0,
			'net_premium' => 0.0,
			'dividends'   => 0.0,
			'fees'        => 0.0,
			'at_risk'     => 0.0,
		);

		$active      = 0;
		$unconverted = 0;

		foreach ( $wheels as $wheel ) {
			if ( 'active' === $wheel['status'] ) {
				++$active;
			}

			foreach ( array_keys( $sum ) as $key ) {
				$value = MM_Money::convert( (float) $wheel[ $key ], (string) $wheel['currency'], $display, $today );

				if ( null === $value ) {
					++$unconverted;
					continue;
				}

				$sum[ $key ] += $value;
			}
		}

		$sum = array_map(
			static function ( $v ) {
				return round( $v, 2 );
			},
			$sum
		);

		$sum['active']      = $active;
		$sum['count']       = count( $wheels );
		$sum['unconverted'] = $unconverted;

		return $sum;
	}

	/**
	 * Whole days between two Y-m-d dates.
	 */
	private static function days_between( string $from, string $to ): int {
		$a = strtotime( $from . ' 00:00:00 UTC' );
		$b = strtotime( $to . ' 00:00:00 UTC' );

		if ( false === $a || false === $b ) {
			return 0;
		}

		return (int) round( ( $b - $a ) / DAY_IN_SECONDS );
	}

	private static function avg_cost( float $shares, float $cost ): float {
		return $shares > self::EPSILON ? $cost / $shares : 0.0;
	}

	/** The raw Questrade label for a row, for the audit column. */
	private static function raw_label( array $row ): string {
		return trim(
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

	/** Trim trailing zeros off a quantity for display inside a sentence. */
	private static function qty_text( float $qty ): string {
		return rtrim( rtrim( number_format( $qty, 4, '.', '' ), '0' ), '.' );
	}

	/**
	 * @return array{wheels:array,totals:array,display_currency:string,has_data:bool}
	 */
	private static function empty_result( string $display ): array {
		return array(
			'wheels'           => array(),
			'totals'           => array(
				'net_cash'    => 0.0,
				'net_premium' => 0.0,
				'dividends'   => 0.0,
				'fees'        => 0.0,
				'at_risk'     => 0.0,
				'active'      => 0,
				'count'       => 0,
				'unconverted' => 0,
			),
			'display_currency' => $display,
			'has_data'         => false,
		);
	}
}
