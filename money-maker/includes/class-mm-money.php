<?php
/**
 * Display currency (Milestone 4f).
 *
 * The plugin used to normalise every figure to CAD, because CAD is what the CRA
 * wants. That is right for the tax screens and wrong for everything else: most
 * of this book trades in USD, so a USD position shown in CAD is a number the
 * user has to mentally undo before it means anything.
 *
 * The split is now explicit:
 *
 *   - **Tax screens stay CAD.** Realized Gains, superficial loss and the ACB
 *     engine's `cad` basis are unchanged — a Schedule 3 figure is CAD by law.
 *   - **Everything else is shown in its own trading currency**, with the odd
 *     one out marked, and only the *totals* converted into one display
 *     currency (USD by default) so a mixed book still adds up.
 *
 * That second rule is why this class does not simply convert the stored CAD
 * numbers back: the CAD cost base was accumulated at each trade's own historic
 * rate, so dividing today's total by today's rate would not give the USD amount
 * that was actually paid. Native cost comes from the ACB engine's `native`
 * basis instead, and this class is only used where two currencies have to be
 * added together.
 *
 * Conversion goes through the one FX pair the plugin stores, USD/CAD
 * (Bank of Canada FXUSDCAD, via MM_FX). Anything else returns null rather than
 * a guess.
 *
 * Static utility: no register(). Required directly by money-maker.php.
 *
 * @package MoneyMaker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Currency conversion + formatting for the non-tax screens.
 */
final class MM_Money {

	/** Settings key inside the `mm_settings` option. */
	const SETTING = 'display_currency';

	/**
	 * What the totals are shown in when nothing is configured.
	 *
	 * USD, not CAD: the user's own framing (2026-09-08) is that USD is the
	 * working currency and CAD is the exception worth marking.
	 */
	const DEFAULT_CURRENCY = 'USD';

	/** Currencies the totals can be expressed in — limited by FX coverage. */
	const SUPPORTED = array( 'USD', 'CAD' );

	/**
	 * The currency totals are shown in.
	 */
	public static function display_currency(): string {
		$settings = MM_Settings::instance()->get_settings();
		$currency = strtoupper( trim( (string) ( $settings[ self::SETTING ] ?? '' ) ) );

		return in_array( $currency, self::SUPPORTED, true ) ? $currency : self::DEFAULT_CURRENCY;
	}

	/**
	 * Short prefix for a currency, e.g. "US$". Both are dollars, so the code has
	 * to be part of the symbol or the two are indistinguishable.
	 */
	public static function symbol( string $currency ): string {
		$currency = strtoupper( trim( $currency ) );

		$symbols = array(
			'USD' => 'US$',
			'CAD' => 'C$',
		);

		return $symbols[ $currency ] ?? ( $currency . ' ' );
	}

	/**
	 * Convert between two currencies at a given date's stored rate.
	 *
	 * Only USD <-> CAD is possible: FXUSDCAD is the only series the plugin
	 * stores. Returns null when the rate is not on hand — a null propagates to
	 * an em dash on screen, which is the honest answer. Never fetches: a page
	 * render must not block on an HTTP call.
	 *
	 * @param string $date Y-m-d the rate should be taken from.
	 */
	public static function convert( float $amount, string $from, string $to, string $date ): ?float {
		$from = strtoupper( trim( $from ) );
		$to   = strtoupper( trim( $to ) );

		if ( '' === $from ) {
			$from = 'CAD';
		}
		if ( '' === $to ) {
			$to = 'CAD';
		}

		if ( $from === $to ) {
			return $amount;
		}

		if ( 'CAD' === $to ) {
			$rate = MM_FX::rate( $from, $date, false );

			return is_wp_error( $rate ) ? null : $amount * (float) $rate;
		}

		if ( 'CAD' === $from ) {
			$rate = MM_FX::rate( $to, $date, false );

			if ( is_wp_error( $rate ) || abs( (float) $rate ) < 0.000001 ) {
				return null;
			}

			return $amount / (float) $rate;
		}

		// USD -> some third currency: two legs, neither of which is stored.
		return null;
	}

	/**
	 * Convert into the configured display currency.
	 */
	public static function to_display( float $amount, string $from, string $date ): ?float {
		return self::convert( $amount, $from, self::display_currency(), $date );
	}

	/**
	 * Format a money amount. Null renders as an em dash so a missing rate is
	 * visibly missing rather than silently zero.
	 *
	 * @param string $currency Prefix the amount with this currency's symbol.
	 */
	public static function fmt( ?float $amount, string $currency = '' ): string {
		if ( null === $amount ) {
			return '—';
		}

		$prefix = '' === $currency ? '' : self::symbol( $currency );
		$text   = number_format( abs( $amount ), 2, '.', ',' );

		return ( $amount < -0.004 ? '-' : '' ) . $prefix . $text;
	}

	/**
	 * Format with an explicit +/- sign — for anything that is a movement
	 * (premium collected, net cash) rather than a level (market value).
	 */
	public static function fmt_signed( ?float $amount, string $currency = '' ): string {
		if ( null === $amount ) {
			return '—';
		}

		$sign = $amount > 0.004 ? '+' : ( $amount < -0.004 ? '-' : '' );

		return $sign . ( '' === $currency ? '' : self::symbol( $currency ) ) . number_format( abs( $amount ), 2, '.', ',' );
	}
}
