<?php
/**
 * Settings storage and form handlers for the Questrade Tracker & Tax Assistant.
 *
 * Persistence + admin-post handlers only. The screens that render these forms
 * live in MM_Admin (the "Settings" and "Connection" tabs of the Money Maker
 * menu). This class:
 *   - reads / normalises the `mm_settings` option (environment toggle)
 *   - handles the environment, encryption-key, refresh-token, and clear-token
 *     form submissions, each behind a capability check + nonce
 *
 * Milestone 1a: environment toggle. 1b: encryption-key generate/rotate.
 * 1c: refresh-token exchange. 1d: (moved) "Test connection" AJAX now in MM_Admin.
 *
 * @package MoneyMaker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings persistence + form handlers.
 */
final class MM_Settings {

	const OPTION       = 'mm_settings';
	const CAPABILITY   = 'manage_options';
	const ENVIRONMENTS = array( 'practice', 'live' );

	const SAVE_ACTION     = 'mm_save_settings';
	const CURRENCY_ACTION = 'mm_save_display_currency';
	const KEY_ACTION      = 'mm_manage_crypto_key';
	const TOKEN_ACTION    = 'mm_save_refresh_token';
	const CLEAR_ACTION    = 'mm_clear_token';

	/**
	 * Singleton instance.
	 *
	 * @var MM_Settings|null
	 */
	private static $instance = null;

	/**
	 * Get the shared instance.
	 */
	public static function instance(): MM_Settings {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Register WordPress hooks. Called once from mm_bootstrap().
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_' . self::CURRENCY_ACTION, array( $this, 'handle_save_display_currency' ) );
		add_action( 'admin_post_' . self::KEY_ACTION, array( $this, 'handle_manage_key' ) );
		add_action( 'admin_post_' . self::TOKEN_ACTION, array( $this, 'handle_save_refresh_token' ) );
		add_action( 'admin_post_' . self::CLEAR_ACTION, array( $this, 'handle_clear_token' ) );
	}

	/**
	 * Current settings, merged over defaults.
	 *
	 * @return array{environment:string,display_currency:string}
	 */
	public function get_settings(): array {
		$defaults = array(
			'environment'      => 'practice',
			'display_currency' => MM_Money::DEFAULT_CURRENCY,
		);
		$stored   = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = array_merge( $defaults, $stored );

		if ( ! in_array( $settings['environment'], self::ENVIRONMENTS, true ) ) {
			$settings['environment'] = 'practice';
		}

		// Read straight off the option rather than through MM_Money, which reads
		// this method — the whitelist lives there, the normalisation here.
		if ( ! in_array( $settings['display_currency'], MM_Money::SUPPORTED, true ) ) {
			$settings['display_currency'] = MM_Money::DEFAULT_CURRENCY;
		}

		return $settings;
	}

	/**
	 * Handle the display-currency form submission.
	 *
	 * This only changes what the *totals* on the holdings, wheels and dashboard
	 * screens are expressed in. Per-position figures always stay in the
	 * security's own trading currency, and the tax screens are always CAD.
	 */
	public function handle_save_display_currency(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'money-maker' ) );
		}

		check_admin_referer( self::CURRENCY_ACTION );

		$currency = isset( $_POST['mm_display_currency'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_POST['mm_display_currency'] ) ) )
			: MM_Money::DEFAULT_CURRENCY;

		if ( ! in_array( $currency, MM_Money::SUPPORTED, true ) ) {
			$currency = MM_Money::DEFAULT_CURRENCY;
		}

		$settings                     = $this->get_settings();
		$settings['display_currency'] = $currency;

		update_option( self::OPTION, $settings );

		add_settings_error(
			'mm_settings',
			'mm_display_currency_saved',
			sprintf(
				/* translators: %s: currency code */
				__( 'Totals are now shown in %s.', 'money-maker' ),
				$currency
			),
			'updated'
		);

		MM_Admin::redirect_with_notices( MM_Admin::SETTINGS_SLUG );
	}

	/**
	 * Handle the environment form submission.
	 */
	public function handle_save_settings(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'money-maker' ) );
		}

		check_admin_referer( self::SAVE_ACTION );

		$environment = isset( $_POST['mm_environment'] )
			? sanitize_text_field( wp_unslash( $_POST['mm_environment'] ) )
			: 'practice';

		if ( ! in_array( $environment, self::ENVIRONMENTS, true ) ) {
			$environment = 'practice';
		}

		$settings                = $this->get_settings();
		$settings['environment'] = $environment;

		update_option( self::OPTION, $settings );

		add_settings_error(
			'mm_settings',
			'mm_settings_saved',
			__( 'Environment saved.', 'money-maker' ),
			'updated'
		);

		MM_Admin::redirect_with_notices( MM_Admin::SETTINGS_SLUG );
	}

	/**
	 * Handle the "generate" / "rotate" encryption-key form submission.
	 */
	public function handle_manage_key(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'money-maker' ) );
		}

		check_admin_referer( self::KEY_ACTION );

		$op = isset( $_POST['mm_key_op'] ) ? sanitize_key( wp_unslash( $_POST['mm_key_op'] ) ) : '';

		if ( ! in_array( $op, array( 'generate', 'rotate' ), true ) ) {
			add_settings_error(
				'mm_settings',
				'mm_key_bad_request',
				__( 'Unrecognized key operation.', 'money-maker' ),
				'error'
			);
			MM_Admin::redirect_with_notices( MM_Admin::SETTINGS_SLUG );
		}

		$rotating = ( 'rotate' === $op );
		$result   = MM_Crypto::generate_key( $rotating );

		if ( is_wp_error( $result ) ) {
			add_settings_error(
				'mm_settings',
				$result->get_error_code(),
				$result->get_error_message(),
				'error'
			);
		} else {
			add_settings_error(
				'mm_settings',
				'mm_key_generated',
				$rotating
					? __( 'A new encryption key was generated. Any previously stored Questrade token must be entered again.', 'money-maker' )
					: sprintf(
						/* translators: %s: key file path, e.g. wp-content/mm-crypto-key.php */
						__( 'Encryption key generated and saved to %s. Back this file up somewhere safe.', 'money-maker' ),
						MM_Crypto::key_file_label()
					),
				'updated'
			);
		}

		MM_Admin::redirect_with_notices( MM_Admin::SETTINGS_SLUG );
	}

	/**
	 * Handle the refresh-token paste form: exchange it and store the bundle.
	 */
	public function handle_save_refresh_token(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'money-maker' ) );
		}

		check_admin_referer( self::TOKEN_ACTION );

		// Deliberately not sanitize_text_field(): a refresh token is opaque and
		// must survive verbatim. Trim whitespace only.
		$raw   = isset( $_POST['mm_refresh_token'] ) ? wp_unslash( $_POST['mm_refresh_token'] ) : '';
		$token = is_string( $raw ) ? trim( $raw ) : '';

		if ( '' === $token ) {
			add_settings_error( 'mm_settings', 'mm_token_empty', __( 'Enter a refresh token first.', 'money-maker' ), 'error' );
			MM_Admin::redirect_with_notices( MM_Admin::CONNECTION_SLUG );
		}

		$environment = $this->get_settings()['environment'];
		$result      = MM_Questrade_Client::exchange_refresh_token( $token, $environment );

		if ( is_wp_error( $result ) ) {
			add_settings_error( 'mm_settings', $result->get_error_code(), $result->get_error_message(), 'error' );
		} else {
			add_settings_error(
				'mm_settings',
				'mm_token_connected',
				__( 'Connected to Questrade. The refresh token has been exchanged and stored.', 'money-maker' ),
				'updated'
			);
		}

		MM_Admin::redirect_with_notices( MM_Admin::CONNECTION_SLUG );
	}

	/**
	 * Handle "Forget stored token".
	 */
	public function handle_clear_token(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'money-maker' ) );
		}

		check_admin_referer( self::CLEAR_ACTION );

		MM_Token_Store::clear();
		MM_Lock::release();

		add_settings_error(
			'mm_settings',
			'mm_token_cleared',
			__( 'Stored Questrade token forgotten.', 'money-maker' ),
			'updated'
		);

		MM_Admin::redirect_with_notices( MM_Admin::CONNECTION_SLUG );
	}
}
