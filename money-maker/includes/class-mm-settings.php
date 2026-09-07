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
		add_action( 'admin_post_mm_save_settings', array( $this, 'handle_save_settings' ) );
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

		$settings    = $this->get_settings();
		$environment = $settings['environment'];
		?>
		<div class="wrap mm-settings">
			<h1><?php esc_html_e( 'Questrade Tracker &amp; Tax Assistant', 'money-maker' ); ?></h1>

			<?php settings_errors( 'mm_settings' ); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mm_save_settings" />
				<?php wp_nonce_field( 'mm_save_settings' ); ?>

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
		</div>
		<?php
	}

	/**
	 * Handle the settings form submission.
	 */
	public function handle_save_settings(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'money-maker' ) );
		}

		check_admin_referer( 'mm_save_settings' );

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
