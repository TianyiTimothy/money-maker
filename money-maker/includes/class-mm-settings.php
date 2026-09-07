<?php
/**
 * Settings page for the Questrade Tracker & Tax Assistant.
 *
 * Milestone 1a: options page shell + practice/live environment toggle.
 * Milestone 1b: encryption-key status + generate/rotate control.
 * Milestone 1c: refresh-token paste field, token status, recovery history.
 * Milestone 1d: "Test connection" button (AJAX GET v1/time).
 *
 * @package MoneyMaker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin settings screen and its form handlers.
 */
final class MM_Settings {

	const OPTION       = 'mm_settings';
	const MENU_SLUG    = 'mm-settings';
	const CAPABILITY   = 'manage_options';
	const ENVIRONMENTS = array( 'practice', 'live' );
	const SAVE_ACTION  = 'mm_save_settings';
	const KEY_ACTION   = 'mm_manage_crypto_key';
	const TOKEN_ACTION = 'mm_save_refresh_token';
	const CLEAR_ACTION = 'mm_clear_token';
	const TEST_ACTION  = 'mm_test_connection';

	/** Transient carrying admin notices across the post-redirect-get bounce. */
	const NOTICE_TRANSIENT = 'mm_admin_notices';

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
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_' . self::KEY_ACTION, array( $this, 'handle_manage_key' ) );
		add_action( 'admin_post_' . self::TOKEN_ACTION, array( $this, 'handle_save_refresh_token' ) );
		add_action( 'admin_post_' . self::CLEAR_ACTION, array( $this, 'handle_clear_token' ) );
		add_action( 'wp_ajax_' . self::TEST_ACTION, array( $this, 'ajax_test_connection' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add the options page under Settings.
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'Questrade Tracker', 'money-maker' ),
			__( 'Questrade Tracker', 'money-maker' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Load the admin stylesheet only on this plugin's screen.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'settings_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'mm-admin',
			MM_PLUGIN_URL . 'assets/admin.css',
			array(),
			MM_VERSION
		);

		wp_enqueue_script(
			'mm-admin',
			MM_PLUGIN_URL . 'assets/admin.js',
			array(),
			MM_VERSION,
			true
		);

		wp_localize_script(
			'mm-admin',
			'mmAdmin',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'testAction' => self::TEST_ACTION,
				'nonce'    => wp_create_nonce( self::TEST_ACTION ),
				'strings'  => array(
					'testing'  => __( 'Testing…', 'money-maker' ),
					'failed'   => __( 'Test failed.', 'money-maker' ),
				),
			)
		);
	}

	/**
	 * Current settings, merged over defaults.
	 *
	 * @return array{environment:string}
	 */
	public function get_settings(): array {
		$defaults = array( 'environment' => 'practice' );
		$stored   = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = array_merge( $defaults, $stored );

		if ( ! in_array( $settings['environment'], self::ENVIRONMENTS, true ) ) {
			$settings['environment'] = 'practice';
		}

		return $settings;
	}

	/**
	 * Render the settings page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'money-maker' ) );
		}
		?>
		<div class="wrap mm-settings">
			<h1><?php esc_html_e( 'Questrade Tracker &amp; Tax Assistant', 'money-maker' ); ?></h1>

			<?php
			$this->render_notices();

			$this->render_environment_section();
			$this->render_encryption_section();
			$this->render_connection_section();
			?>
		</div>
		<?php
	}

	/**
	 * Practice / live environment toggle.
	 */
	private function render_environment_section(): void {
		$environment = $this->get_settings()['environment'];
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
			<?php wp_nonce_field( self::SAVE_ACTION ); ?>

			<h2 class="title"><?php esc_html_e( 'Environment', 'money-maker' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Questrade keeps completely separate credentials for the practice and live systems. Pick the one this site should talk to.', 'money-maker' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Connect to', 'money-maker' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="mm_environment" value="practice" <?php checked( $environment, 'practice' ); ?> />
									<?php esc_html_e( 'Practice (practicelogin.questrade.com)', 'money-maker' ); ?>
								</label>
								<br />
								<label>
									<input type="radio" name="mm_environment" value="live" <?php checked( $environment, 'live' ); ?> />
									<?php esc_html_e( 'Live (login.questrade.com)', 'money-maker' ); ?>
								</label>
								<p class="description mm-warning">
									<?php esc_html_e( 'Switching environments does not delete a stored token, but the token for one environment will not work against the other. You will need to enter a fresh refresh token after switching.', 'money-maker' ); ?>
								</p>
							</fieldset>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( __( 'Save settings', 'money-maker' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Encryption-key status and the generate / rotate control.
	 */
	private function render_encryption_section(): void {
		$available   = MM_Crypto::is_available();
		$source      = MM_Crypto::key_source();
		$fingerprint = MM_Crypto::key_fingerprint();
		?>
		<h2 class="title"><?php esc_html_e( 'Encryption key', 'money-maker' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Questrade tokens are encrypted before they are written to the database. The key is stored outside the database and is never displayed. If you lose it, every stored token is unrecoverable and you must re-authenticate with Questrade.', 'money-maker' ); ?>
		</p>

		<?php if ( ! $available ) : ?>
			<div class="notice notice-error inline"><p>
				<?php esc_html_e( 'libsodium is not available on this server, so tokens cannot be encrypted. Ask your host to enable the PHP sodium extension before entering a token.', 'money-maker' ); ?>
			</p></div>
			<?php return; ?>
		<?php endif; ?>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'money-maker' ); ?></th>
					<td>
						<?php
						if ( 'constant' === $source ) {
							printf(
								'<p><span class="mm-ok">%s</span> &mdash; %s</p>',
								esc_html__( 'Configured', 'money-maker' ),
								esc_html__( 'the key is pinned by the MM_CRYPTO_KEY constant in wp-config.php.', 'money-maker' )
							);
						} elseif ( 'file' === $source ) {
							printf(
								'<p><span class="mm-ok">%s</span> &mdash; %s <code>%s</code></p>',
								esc_html__( 'Configured', 'money-maker' ),
								esc_html__( 'key file:', 'money-maker' ),
								esc_html( MM_Crypto::key_file_label() )
							);
						} else {
							printf(
								'<p><span class="mm-warning">%s</span> &mdash; %s</p>',
								esc_html__( 'Not configured', 'money-maker' ),
								esc_html__( 'generate a key before entering a Questrade token.', 'money-maker' )
							);
						}

						if ( $fingerprint ) {
							printf(
								'<p class="description">%s <code>%s</code></p>',
								esc_html__( 'Key fingerprint:', 'money-maker' ),
								esc_html( $fingerprint )
							);
						}
						?>
					</td>
				</tr>
			</tbody>
		</table>

		<?php if ( 'constant' === $source ) : ?>
			<p class="description"><?php esc_html_e( 'To change the key, edit the MM_CRYPTO_KEY constant in wp-config.php.', 'money-maker' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<?php
		$rotating = ( 'file' === $source );
		$confirm  = __( 'Generate a new key? Any Questrade token already stored will need to be entered again.', 'money-maker' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			<?php if ( $rotating ) : ?>onsubmit="return window.confirm( '<?php echo esc_js( $confirm ); ?>' );"<?php endif; ?>>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::KEY_ACTION ); ?>" />
			<input type="hidden" name="mm_key_op" value="<?php echo $rotating ? 'rotate' : 'generate'; ?>" />
			<?php wp_nonce_field( self::KEY_ACTION ); ?>
			<?php
			submit_button(
				$rotating ? __( 'Generate new key (rotate)', 'money-maker' ) : __( 'Generate encryption key', 'money-maker' ),
				$rotating ? 'secondary' : 'primary',
				'submit',
				true
			);
			?>
			<?php if ( $rotating ) : ?>
				<p class="description mm-warning">
					<?php esc_html_e( 'Rotating replaces the key immediately. Data encrypted with the old key can no longer be read. Back up the new key file afterwards.', 'money-maker' ); ?>
				</p>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Questrade connection: token status, refresh-token paste field, recovery
	 * history, and the "Test connection" button.
	 */
	private function render_connection_section(): void {
		$environment = $this->get_settings()['environment'];
		$crypto_ok   = MM_Crypto::is_configured();
		$bundle      = MM_Token_Store::get();
		?>
		<h2 class="title"><?php esc_html_e( 'Questrade connection', 'money-maker' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Questrade issues a one-time-use refresh token from its app hub. Paste it below; the plugin exchanges it for an access token and immediately stores the new refresh token in its place.', 'money-maker' ); ?>
		</p>

		<?php if ( ! $crypto_ok ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php esc_html_e( 'Generate an encryption key above before entering a refresh token.', 'money-maker' ); ?>
			</p></div>
		<?php endif; ?>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'money-maker' ); ?></th>
					<td><?php $this->render_token_status( $bundle, $environment ); ?></td>
				</tr>
			</tbody>
		</table>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::TOKEN_ACTION ); ?>" />
			<?php wp_nonce_field( self::TOKEN_ACTION ); ?>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="mm_refresh_token"><?php esc_html_e( 'New refresh token', 'money-maker' ); ?></label>
						</th>
						<td>
							<input type="password" id="mm_refresh_token" name="mm_refresh_token"
								class="regular-text" autocomplete="off" spellcheck="false"
								<?php disabled( ! $crypto_ok ); ?> />
							<p class="description">
								<?php
								printf(
									/* translators: %s: environment name */
									esc_html__( 'This will be exchanged against the %s environment.', 'money-maker' ),
									'<strong>' . esc_html( $environment ) . '</strong>'
								);
								?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>
			<?php submit_button( __( 'Connect to Questrade', 'money-maker' ), 'primary', 'submit', true, $crypto_ok ? array() : array( 'disabled' => 'disabled' ) ); ?>
		</form>

		<?php if ( null !== $bundle ) : ?>
			<p>
				<button type="button" class="button button-secondary" id="mm-test-connection">
					<?php esc_html_e( 'Test connection', 'money-maker' ); ?>
				</button>
				<span id="mm-test-result" class="mm-test-result" role="status" aria-live="polite"></span>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				onsubmit="return window.confirm( '<?php echo esc_js( __( 'Forget the stored Questrade token? You will need to paste a fresh refresh token to reconnect.', 'money-maker' ) ); ?>' );">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::CLEAR_ACTION ); ?>" />
				<?php wp_nonce_field( self::CLEAR_ACTION ); ?>
				<?php submit_button( __( 'Forget stored token', 'money-maker' ), 'link-delete', 'submit', false ); ?>
			</form>

			<?php $this->render_refresh_history(); ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * One-line token status plus access-token expiry.
	 *
	 * @param array|null $bundle      Decrypted token bundle, or null.
	 * @param string     $environment Configured environment.
	 */
	private function render_token_status( ?array $bundle, string $environment ): void {
		if ( null === $bundle ) {
			printf(
				'<p><span class="mm-warning">%s</span></p>',
				esc_html__( 'Not connected — no token stored.', 'money-maker' )
			);
			return;
		}

		if ( $bundle['environment'] !== $environment ) {
			printf(
				'<p><span class="mm-warning">%s</span></p>',
				sprintf(
					/* translators: 1: stored environment, 2: configured environment */
					esc_html__( 'Stored token is for %1$s but this site is set to %2$s. Paste a fresh %2$s token.', 'money-maker' ),
					esc_html( $bundle['environment'] ),
					esc_html( $environment )
				)
			);
			return;
		}

		$seconds_left = $bundle['expires_at'] - time();

		if ( $seconds_left > 0 ) {
			printf(
				'<p><span class="mm-ok">%s</span> &mdash; %s</p>',
				esc_html__( 'Connected', 'money-maker' ),
				sprintf(
					/* translators: %s: human time difference, e.g. "12 mins" */
					esc_html__( 'access token valid for about %s.', 'money-maker' ),
					esc_html( human_time_diff( time(), $bundle['expires_at'] ) )
				)
			);
		} else {
			printf(
				'<p><span class="mm-ok">%s</span> &mdash; %s</p>',
				esc_html__( 'Connected', 'money-maker' ),
				esc_html__( 'access token expired — it will refresh on the next call.', 'money-maker' )
			);
		}

		printf(
			'<p class="description">%s</p>',
			sprintf(
				/* translators: %s: masked api server host */
				esc_html__( 'API server: %s', 'money-maker' ),
				esc_html( (string) wp_parse_url( $bundle['api_server'], PHP_URL_HOST ) )
			)
		);
	}

	/**
	 * Masked recovery list of recent refresh tokens.
	 */
	private function render_refresh_history(): void {
		$history = MM_Token_Store::refresh_history_for_display();

		if ( empty( $history ) ) {
			return;
		}
		?>
		<h3><?php esc_html_e( 'Recent refresh tokens (recovery)', 'money-maker' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Kept only so a broken token chain can be recovered by hand. Values are masked; the plugin cannot show them in full.', 'money-maker' ); ?>
		</p>
		<ul class="mm-token-history">
			<?php foreach ( $history as $entry ) : ?>
				<li>
					<code><?php echo esc_html( $entry['masked'] ); ?></code>
					<?php if ( $entry['stored_at'] ) : ?>
						<span class="description">
							<?php
							printf(
								/* translators: %s: human time difference */
								esc_html__( 'stored %s ago', 'money-maker' ),
								esc_html( human_time_diff( $entry['stored_at'], time() ) )
							);
							?>
						</span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
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
			__( 'Settings saved.', 'money-maker' ),
			'updated'
		);

		$this->persist_notices_and_redirect();
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
			$this->persist_notices_and_redirect();
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

		$this->persist_notices_and_redirect();
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
			$this->persist_notices_and_redirect();
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

		$this->persist_notices_and_redirect();
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

		$this->persist_notices_and_redirect();
	}

	/**
	 * AJAX: "Test connection" — GET v1/time and report the server clock.
	 */
	public function ajax_test_connection(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'money-maker' ) ), 403 );
		}

		check_ajax_referer( self::TEST_ACTION );

		$result = MM_Questrade_Client::test_connection();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: Questrade server time (ISO 8601) */
					__( 'OK — Questrade server time: %s', 'money-maker' ),
					$result['time']
				),
			)
		);
	}

	/**
	 * Stash queued notices in a private transient and bounce back to the
	 * settings screen.
	 *
	 * We deliberately do NOT use core's `settings_errors` transient +
	 * `settings-updated` query arg: on an `add_options_page()` screen that path
	 * renders each notice twice (once by core, once by our explicit call). A
	 * private transient rendered by render_notices() shows each exactly once.
	 */
	private function persist_notices_and_redirect(): void {
		$notices = get_settings_errors();

		if ( ! empty( $notices ) ) {
			set_transient( self::NOTICE_TRANSIENT, $notices, MINUTE_IN_SECONDS );
		}

		wp_safe_redirect(
			add_query_arg( array( 'page' => self::MENU_SLUG ), admin_url( 'options-general.php' ) )
		);
		exit;
	}

	/**
	 * Render (and clear) any notices queued by the last form submission.
	 */
	private function render_notices(): void {
		$notices = get_transient( self::NOTICE_TRANSIENT );

		if ( empty( $notices ) || ! is_array( $notices ) ) {
			return;
		}

		delete_transient( self::NOTICE_TRANSIENT );

		foreach ( $notices as $notice ) {
			$type  = isset( $notice['type'] ) ? (string) $notice['type'] : 'error';
			$class = 'updated' === $type ? 'notice-success' : 'notice-' . sanitize_html_class( $type );

			printf(
				'<div class="notice %s is-dismissible"><p>%s</p></div>',
				esc_attr( $class ),
				esc_html( isset( $notice['message'] ) ? (string) $notice['message'] : '' )
			);
		}
	}
}
