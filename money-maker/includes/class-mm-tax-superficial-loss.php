<?php
/**
 * Superficial-loss detection (Milestone 3c, Module C).
 *
 * CRA: a capital loss is "superficial" (and denied) when, in the 61-day window
 * running 30 days before the sale through 30 days after, the same or an
 * identical security is bought — by the taxpayer or an affiliated person — and
 * that substituted property is still held at the end of the window. The denied
 * portion is added, pro-rata, to the ACB of the shares still held.
 *
 * This is a **mechanical, warning-only** check over the dispositions produced by
 * MM_Tax_ACB. Affiliated-person purchases (a spouse, the user's own registered
 * accounts) are out of automated scope — every result says "review with your
 * accountant".
 *
 * Option dispositions (asset_class 'option', M3f) are skipped. The rule turns on
 * re-acquiring *identical property*, and an option series is its own property —
 * matching a written contract against a share purchase would be wrong, and
 * matching contract against contract needs same-series logic this does not have.
 *
 * Static utility: no register(). Required directly by money-maker.php.
 *
 * @package MoneyMaker
 */

defined( 'ABSPATH' ) || exit;

/**
 * 61-day-window scan over realised losses.
 */
final class MM_Tax_Superficial_Loss {

	/** Days before and after the sale that form the window. */
	const WINDOW_DAYS = 30;

	/** Float tolerance for share-quantity comparisons. */
	const EPSILON = 0.0000001;

	/** Fixed reviewer caveat appended to every warning. */
	public static function disclaimer(): string {
		return __(
			'Mechanical check only: purchases by an affiliated person — a spouse, or your own registered (TFSA/RRSP) accounts — are not detected and would also deny the loss. Losses on option contracts are not scanned at all. Confirm every superficial-loss result with your accountant.',
			'money-maker'
		);
	}

	/**
	 * Flag superficial losses among a computed ACB result.
	 *
	 * @param array    $acb_result      MM_Tax_ACB::get() / compute() output.
	 * @param string[] $account_numbers In-scope (non-registered) account numbers.
	 * @return array<int,array<string,mixed>> Warning rows, newest sale first.
	 */
	public static function analyze( array $acb_result, array $account_numbers ): array {
		$account_numbers = array_values( array_filter( array_map( 'strval', $account_numbers ) ) );
		$warnings        = array();

		foreach ( $acb_result['dispositions'] as $disposition ) {
			if ( (float) $disposition['gain'] >= 0 ) {
				continue;
			}

			// Options are their own property class — see the class docblock.
			if ( 'option' === ( $disposition['asset_class'] ?? 'stock' ) ) {
				continue;
			}

			$symbol     = (string) $disposition['symbol'];
			$sale_date  = (string) $disposition['date'];
			$shares_sold = (float) $disposition['quantity'];
			$loss       = abs( (float) $disposition['gain'] );

			if ( $shares_sold <= self::EPSILON ) {
				continue;
			}

			$window_start = gmdate( 'Y-m-d', strtotime( $sale_date . ' -' . self::WINDOW_DAYS . ' days' ) );
			$window_end   = gmdate( 'Y-m-d', strtotime( $sale_date . ' +' . self::WINDOW_DAYS . ' days' ) );

			$timeline = self::timeline( $symbol, $account_numbers );

			$repurchased = 0.0;
			$acquisitions = array();
			foreach ( $timeline as $entry ) {
				if ( $entry['acquisition'] && $entry['date'] >= $window_start && $entry['date'] <= $window_end ) {
					$repurchased   += $entry['qty'];
					$acquisitions[] = $entry;
				}
			}

			if ( $repurchased <= self::EPSILON ) {
				continue;
			}

			$held_at_end = 0.0;
			foreach ( $timeline as $entry ) {
				if ( $entry['date'] <= $window_end ) {
					$held_at_end += $entry['signed_qty'];
				}
			}

			if ( $held_at_end <= self::EPSILON ) {
				continue;
			}

			$denied_shares = min( $repurchased, $shares_sold, $held_at_end );
			$denied        = round( $loss * ( $denied_shares / $shares_sold ), 2 );

			if ( $denied <= 0 ) {
				continue;
			}

			$warnings[] = array(
				'account_number' => (string) $disposition['account_number'],
				'symbol'         => $symbol,
				'sale_date'      => $sale_date,
				'window_start'   => $window_start,
				'window_end'     => $window_end,
				'shares_sold'    => $shares_sold,
				'loss'           => round( $loss, 2 ),
				'repurchased'    => round( $repurchased, 6 ),
				'held_at_end'    => round( $held_at_end, 6 ),
				'denied'         => $denied,
				'allowed'        => round( $loss - $denied, 2 ),
				'acb_bump_per_share' => $held_at_end > self::EPSILON ? round( $denied / $held_at_end, 6 ) : 0.0,
				'acquisitions'   => $acquisitions,
			);
		}

		usort(
			$warnings,
			static function ( $a, $b ) {
				return $b['sale_date'] <=> $a['sale_date'];
			}
		);

		return $warnings;
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Merged share-movement timeline for one symbol across a set of accounts.
	 * Each entry: date, signed_qty (buys +, sells −, adjustments as their delta),
	 * qty (magnitude of an acquisition), acquisition (bool — a purchase of
	 * substituted property: a Buy or a reinvested distribution).
	 *
	 * @param string[] $account_numbers
	 * @return array<int,array{date:string,signed_qty:float,qty:float,acquisition:bool}>
	 */
	private static function timeline( string $symbol, array $account_numbers ): array {
		$entries = array();

		foreach ( $account_numbers as $account ) {
			foreach ( MM_Activities::for_acb( $account, $symbol ) as $row ) {
				$class = MM_Tax_ACB::classify( $row );
				$qty   = abs( (float) $row['quantity'] );
				$date  = MM_Activities::row_date( $row );

				if ( 'buy' === $class ) {
					$entries[] = array( 'date' => $date, 'signed_qty' => $qty, 'qty' => $qty, 'acquisition' => true );
				} elseif ( 'sell' === $class ) {
					$entries[] = array( 'date' => $date, 'signed_qty' => -$qty, 'qty' => $qty, 'acquisition' => false );
				}
			}

			foreach ( MM_Manual_Adjustments::for_symbol( $account, $symbol ) as $adj ) {
				$delta = (float) $adj['quantity_delta'];
				if ( abs( $delta ) < self::EPSILON ) {
					continue;
				}
				$entries[] = array(
					'date'        => substr( (string) $adj['adjustment_date'], 0, 10 ),
					'signed_qty'  => $delta,
					'qty'         => abs( $delta ),
					'acquisition' => ( 'reinvested_distribution' === $adj['kind'] && $delta > 0 ),
				);
			}
		}

		usort(
			$entries,
			static function ( $a, $b ) {
				return $a['date'] <=> $b['date'];
			}
		);

		return $entries;
	}
}
