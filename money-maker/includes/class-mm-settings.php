<?php
/**
 * Settings page for the Questrade Tracker & Tax Assistant.
 *
 * Milestone 1a: options page shell + practice/live environment toggle.
 * Later sub-steps extend render() and add more admin-post / AJAX handlers.
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

			<?php settings_errors( 'mm_settings' ); ?>

			<?php
			$this->render_environment_section();
			$this->render_encryption_section();
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
	 * Stash queued admin notices in a transient (they do not survive the
	 * redirect otherwise) and bounce back to the settings screen.
	 */
	private function persist_notices_and_redirect(): void {
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => self::MENU_SLUG,
					'settings-updated' => 'true',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}
}
