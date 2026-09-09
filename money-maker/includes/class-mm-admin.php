<?php
/**
 * Admin UI: top-level "Money Maker" menu, page chrome, and the three screens.
 *
 * View + navigation layer only. Persistence and form handling live in
 * MM_Settings; this class renders the screens and owns:
 *   - the top-level admin menu and its Dashboard / Connection / Settings subpages
 *   - shared page chrome (brand bar, tab nav, flash notices)
 *   - asset enqueue (scoped to this plugin's own screens)
 *   - the "Test connection" AJAX endpoint
 *   - the post-redirect-get notice transient shared by every handler
 *
 * Milestone 1: moved out from under Settings into its own menu and rebuilt as a
 * card-based dashboard. Common actions (status, test connection) live on the
 * Dashboard; recovery (refresh-token paste, history) on Connection; set-once
 * config (environment, encryption key) on Settings.
 *
 * @package MoneyMaker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the admin menu and renders every plugin screen.
 */
final class MM_Admin {

	const CAPABILITY = 'manage_options';

	/** Top-level menu slug — also the Dashboard screen. */
	const MENU_SLUG = 'money-maker';

	/** Subpage slugs. */
	const HOLDINGS_SLUG    = 'mm-holdings';
	const WHEELS_SLUG      = 'mm-wheels';
	const GAINS_SLUG       = 'mm-gains';
	const ADJUSTMENTS_SLUG = 'mm-adjustments';
	const CONNECTION_SLUG  = 'mm-connection';
	const SYNC_SLUG        = 'mm-sync';
	const SETTINGS_SLUG    = 'mm-settings';

	/** AJAX action for the "Test connection" button. */
	const TEST_ACTION = 'mm_test_connection';

	/** Transient carrying admin notices across the post-redirect-get bounce. */
	const NOTICE_TRANSIENT = 'mm_admin_notices';

	/**
	 * Singleton instance.
	 *
	 * @var MM_Admin|null
	 */
	private static $instance = null;

	/**
	 * Page hook suffixes for our screens, so asset enqueue can scope itself.
	 *
	 * @var array<string,string>
	 */
	private $hooks = array();

	/**
	 * Get the shared instance.
	 */
	public static function instance(): MM_Admin {
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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::TEST_ACTION, array( $this, 'ajax_test_connection' ) );
	}

	/**
	 * Build the top-level menu and its subpages.
	 */
	public function add_menu(): void {
		$this->hooks['dashboard'] = add_menu_page(
			__( 'Money Maker', 'money-maker' ),
			__( 'Money Maker', 'money-maker' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-chart-area',
			58
		);

		// First submenu re-declares the parent slug so the auto-generated
		// duplicate is renamed "Dashboard" instead of "Money Maker".
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'money-maker' ),
			__( 'Dashboard', 'money-maker' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_dashboard' )
		);

		$this->hooks['holdings'] = (string) add_submenu_page(
			self::MENU_SLUG,
			__( 'Holdings', 'money-maker' ),
			__( 'Holdings', 'money-maker' ),
			self::CAPABILITY,
			self::HOLDINGS_SLUG,
			array( $this, 'render_holdings' )
		);

		$this->hooks['wheels'] = (string) add_submenu_page(
			self::MENU_SLUG,
			__( 'Options Wheels', 'money-maker' ),
			__( 'Wheels', 'money-maker' ),
			self::CAPABILITY,
			self::WHEELS_SLUG,
			array( $this, 'render_wheels' )
		);

		$this->hooks['gains'] = (string) add_submenu_page(
			self::MENU_SLUG,
			__( 'Realized Gains', 'money-maker' ),
			__( 'Realized Gains', 'money-maker' ),
			self::CAPABILITY,
			self::GAINS_SLUG,
			array( $this, 'render_gains' )
		);

		$this->hooks['adjustments'] = (string) add_submenu_page(
			self::MENU_SLUG,
			__( 'ACB Adjustments', 'money-maker' ),
			__( 'Adjustments', 'money-maker' ),
			self::CAPABILITY,
			self::ADJUSTMENTS_SLUG,
			array( $this, 'render_adjustments' )
		);

		$this->hooks['connection'] = (string) add_submenu_page(
			self::MENU_SLUG,
			__( 'Questrade Connection', 'money-maker' ),
			__( 'Connection', 'money-maker' ),
			self::CAPABILITY,
			self::CONNECTION_SLUG,
			array( $this, 'render_connection' )
		);

		$this->hooks['sync'] = (string) add_submenu_page(
			self::MENU_SLUG,
			__( 'Data Sync', 'money-maker' ),
			__( 'Data Sync', 'money-maker' ),
			self::CAPABILITY,
			self::SYNC_SLUG,
			array( $this, 'render_sync' )
		);

		$this->hooks['settings'] = (string) add_submenu_page(
			self::MENU_SLUG,
			__( 'Money Maker Settings', 'money-maker' ),
			__( 'Settings', 'money-maker' ),
			self::CAPABILITY,
			self::SETTINGS_SLUG,
			array( $this, 'render_settings' )
		);
	}

	/**
	 * Load the plugin's admin CSS/JS only on its own screens.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, $this->hooks, true ) ) {
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
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'testAction' => self::TEST_ACTION,
				'nonce'      => wp_create_nonce( self::TEST_ACTION ),
				'strings'    => array(
					'testing' => __( 'Testing…', 'money-maker' ),
					'failed'  => __( 'Test failed.', 'money-maker' ),
				),
			)
		);

		// Charts (M3e) — only on the two screens that draw them.
		if ( in_array( $hook_suffix, array( $this->hooks['holdings'] ?? '', $this->hooks['gains'] ?? '' ), true ) ) {
			wp_enqueue_script(
				'mm-charts',
				MM_PLUGIN_URL . 'assets/mm-charts.js',
				array(),
				MM_VERSION,
				true
			);
			wp_localize_script( 'mm-charts', 'mmCharts', $this->chart_data( $hook_suffix ) );
		}
	}

	/**
	 * Localized chart payload for the current screen. See assets/mm-charts.js.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return array<string,mixed>
	 */
	private function chart_data( string $hook_suffix ): array {
		if ( ( $this->hooks['holdings'] ?? '' ) === $hook_suffix ) {
			$points = array();
			foreach ( MM_Positions::value_series() as $date => $total ) {
				$points[] = array( 'x' => $date, 'y' => round( (float) $total, 2 ) );
			}

			return array(
				'portfolioValue' => array(
					'type'   => 'line',
					'unit'   => MM_Money::symbol( MM_Money::display_currency() ),
					'points' => $points,
				),
			);
		}

		if ( ( $this->hooks['gains'] ?? '' ) === $hook_suffix ) {
			$acb  = MM_Tax_ACB::get( MM_Accounts::non_registered_numbers() );
			$bars = array();
			foreach ( MM_Tax_ACB::realized_by_year( $acb ) as $year => $total ) {
				$bars[] = array( 'label' => (string) $year, 'value' => round( (float) $total, 2 ) );
			}

			return array(
				'realizedPnl' => array(
					'type' => 'bar',
					// Always CAD: this is the Schedule 3 figure, not a display
					// preference (M4f).
					'unit' => 'C$',
					'bars' => $bars,
				),
			);
		}

		return array();
	}

	/* ---------------------------------------------------------------------
	 * Screens
	 * ------------------------------------------------------------------- */

	/**
	 * Dashboard: at-a-glance connection health, setup checklist, quick links.
	 */
	public function render_dashboard(): void {
		$this->guard();

		$settings    = MM_Settings::instance()->get_settings();
		$environment = $settings['environment'];
		$bundle      = MM_Token_Store::get();
		$crypto_ok   = MM_Crypto::is_configured();
		$connected   = ( null !== $bundle && $bundle['environment'] === $environment );

		$this->open( 'dashboard' );

		$this->render_status_hero( $bundle, $environment, $crypto_ok, $connected );

		if ( ! $crypto_ok || null === $bundle || ! $connected ) {
			$this->render_setup_checklist( $crypto_ok, $bundle, $environment );
		}

		if ( null !== $bundle ) {
			?>
			<p class="mm-actions">
				<button type="button" class="button button-primary button-hero" id="mm-test-connection">
					<?php esc_html_e( 'Test connection', 'money-maker' ); ?>
				</button>
				<span id="mm-test-result" class="mm-test-result" role="status" aria-live="polite"></span>
			</p>
			<?php
		}

		echo '<div class="mm-cards">';
		$this->render_connection_card( $bundle, $environment );
		$this->render_encryption_card();
		$this->render_environment_card( $environment );
		$this->render_sync_card();
		if ( MM_DB::is_installed() && MM_Accounts::count() > 0 ) {
			$this->render_holdings_card();
			$this->render_wheels_card();
			$this->render_tax_card();
		}
		echo '</div>';

		$this->close();
	}

	/**
	 * Dashboard card: portfolio market value + unrealised gain/loss.
	 */
	private function render_holdings_card(): void {
		$model   = MM_Holdings::current();
		$totals  = $model['totals'];
		$display = (string) $model['display_currency'];
		?>
		<div class="mm-card mm-card--link">
			<div class="mm-card__head">
				<h3><?php esc_html_e( 'Holdings', 'money-maker' ); ?></h3>
				<?php if ( $model['has_data'] ) : ?>
					<span class="mm-pill mm-pill--muted"><?php echo esc_html( self::fmt_money( (float) $totals['market'], $display ) ); ?></span>
				<?php else : ?>
					<span class="mm-pill mm-pill--warn"><?php esc_html_e( 'No snapshot', 'money-maker' ); ?></span>
				<?php endif; ?>
			</div>
			<p class="mm-muted">
				<?php
				if ( $model['has_data'] ) {
					printf(
						/* translators: 1: unrealised gain/loss, 2: currency code */
						esc_html__( 'Unrealised gain/loss %1$s across every account, totalled in %2$s.', 'money-maker' ),
						esc_html( self::fmt_money( (float) $totals['unrealised'], $display ) ),
						esc_html( $display )
					);
				} else {
					esc_html_e( 'Run a positions sync to see current holdings and unrealised gains.', 'money-maker' );
				}
				?>
			</p>
			<a href="<?php echo esc_url( self::page_url( self::HOLDINGS_SLUG ) ); ?>"><?php esc_html_e( 'Open Holdings →', 'money-maker' ); ?></a>
		</div>
		<?php
	}

	/**
	 * Dashboard card: how the wheels are running.
	 */
	private function render_wheels_card(): void {
		$summary = MM_Wheels::summary();
		?>
		<div class="mm-card mm-card--link">
			<div class="mm-card__head">
				<h3><?php esc_html_e( 'Wheels', 'money-maker' ); ?></h3>
				<?php if ( $summary['count'] > 0 ) : ?>
					<span class="mm-pill mm-pill--ok">
						<?php
						printf(
							/* translators: %d: number of live wheels */
							esc_html( _n( '%d live', '%d live', (int) $summary['active'], 'money-maker' ) ),
							(int) $summary['active']
						);
						?>
					</span>
				<?php else : ?>
					<span class="mm-pill mm-pill--muted"><?php esc_html_e( 'None yet', 'money-maker' ); ?></span>
				<?php endif; ?>
			</div>
			<p class="mm-muted">
				<?php
				if ( $summary['count'] > 0 ) {
					printf(
						/* translators: 1: net cash, 2: capital committed */
						esc_html__( 'Net cash %1$s, with %2$s of capital committed to open puts and shares.', 'money-maker' ),
						esc_html( MM_Money::fmt_signed( $summary['net_cash'], $summary['currency'] ) ),
						esc_html( MM_Money::fmt( $summary['at_risk'], $summary['currency'] ) )
					);
				} else {
					esc_html_e( 'Wheels build themselves from your option activity — sync, and they appear.', 'money-maker' );
				}
				?>
			</p>
			<a href="<?php echo esc_url( self::page_url( self::WHEELS_SLUG ) ); ?>"><?php esc_html_e( 'Open Wheels →', 'money-maker' ); ?></a>
		</div>
		<?php
	}

	/**
	 * Dashboard card: realized gains this year + review-item count.
	 */
	private function render_tax_card(): void {
		$accounts = MM_Accounts::non_registered_numbers();
		$acb      = empty( $accounts ) ? MM_Tax_ACB::compute( array() ) : MM_Tax_ACB::get( $accounts );
		$year     = (int) gmdate( 'Y' );
		$by_year  = MM_Tax_ACB::realized_by_year( $acb );
		$this_year = $by_year[ $year ] ?? 0.0;
		$reviews   = count( $acb['reviews'] );
		?>
		<div class="mm-card mm-card--link">
			<div class="mm-card__head">
				<h3><?php esc_html_e( 'Taxes', 'money-maker' ); ?></h3>
				<?php if ( $reviews > 0 ) : ?>
					<span class="mm-pill mm-pill--warn">
						<?php
						printf(
							/* translators: %d: number of items */
							esc_html( _n( '%d to review', '%d to review', $reviews, 'money-maker' ) ),
							(int) $reviews
						);
						?>
					</span>
				<?php else : ?>
					<span class="mm-pill mm-pill--ok"><?php esc_html_e( 'Clear', 'money-maker' ); ?></span>
				<?php endif; ?>
			</div>
			<p class="mm-muted">
				<?php
				printf(
					/* translators: 1: year, 2: amount */
					esc_html__( '%1$d realized gain/loss so far: %2$s CAD (pooled ACB).', 'money-maker' ),
					(int) $year,
					esc_html( self::fmt_cad( (float) $this_year ) )
				);
				?>
			</p>
			<a href="<?php echo esc_url( self::page_url( self::GAINS_SLUG ) ); ?>"><?php esc_html_e( 'Open Realized Gains →', 'money-maker' ); ?></a>
		</div>
		<?php
	}

	/**
	 * Connection screen: token status, refresh-token paste, recovery history.
	 */
	public function render_connection(): void {
		$this->guard();

		$settings    = MM_Settings::instance()->get_settings();
		$environment = $settings['environment'];
		$crypto_ok   = MM_Crypto::is_configured();
		$bundle      = MM_Token_Store::get();

		$this->open( 'connection' );
		?>
		<div class="mm-card">
			<h2><?php esc_html_e( 'Status', 'money-maker' ); ?></h2>
			<?php $this->render_token_status( $bundle, $environment ); ?>
			<?php if ( null !== $bundle ) : ?>
				<p class="mm-actions">
					<button type="button" class="button" id="mm-test-connection">
						<?php esc_html_e( 'Test connection', 'money-maker' ); ?>
					</button>
					<span id="mm-test-result" class="mm-test-result" role="status" aria-live="polite"></span>
				</p>
			<?php endif; ?>
		</div>

		<div class="mm-card">
			<h2><?php esc_html_e( 'Connect / re-connect', 'money-maker' ); ?></h2>
			<p class="mm-muted">
				<?php esc_html_e( 'Questrade issues a one-time-use refresh token from its app hub. Paste it here; the plugin exchanges it for an access token and immediately stores the new refresh token in its place.', 'money-maker' ); ?>
			</p>

			<?php if ( ! $crypto_ok ) : ?>
				<div class="mm-inline-notice mm-inline-notice--warn">
					<?php
					printf(
						/* translators: %s: link to the Settings screen */
						esc_html__( 'Set up an encryption key on the %s screen before entering a refresh token.', 'money-maker' ),
						'<a href="' . esc_url( self::page_url( self::SETTINGS_SLUG ) ) . '">' . esc_html__( 'Settings', 'money-maker' ) . '</a>'
					);
					?>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mm-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( MM_Settings::TOKEN_ACTION ); ?>" />
				<?php wp_nonce_field( MM_Settings::TOKEN_ACTION ); ?>

				<div class="mm-field">
					<label for="mm_refresh_token"><?php esc_html_e( 'New refresh token', 'money-maker' ); ?></label>
					<input type="password" id="mm_refresh_token" name="mm_refresh_token"
						class="regular-text" autocomplete="off" spellcheck="false"
						<?php disabled( ! $crypto_ok ); ?> />
					<p class="mm-muted">
						<?php
						printf(
							/* translators: %s: environment name */
							esc_html__( 'Exchanged against the %s environment.', 'money-maker' ),
							'<strong>' . esc_html( $environment ) . '</strong>'
						);
						?>
					</p>
				</div>

				<?php submit_button( __( 'Connect to Questrade', 'money-maker' ), 'primary', 'submit', true, $crypto_ok ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>
		</div>

		<?php if ( null !== $bundle ) : ?>
			<?php $this->render_refresh_history(); ?>

			<div class="mm-card mm-card--danger">
				<h2><?php esc_html_e( 'Danger zone', 'money-maker' ); ?></h2>
				<p class="mm-muted">
					<?php esc_html_e( 'Forgetting the stored token wipes the encrypted bundle and its recovery history. You will need a fresh refresh token to reconnect.', 'money-maker' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					onsubmit="return window.confirm( '<?php echo esc_js( __( 'Forget the stored Questrade token? You will need to paste a fresh refresh token to reconnect.', 'money-maker' ) ); ?>' );">
					<input type="hidden" name="action" value="<?php echo esc_attr( MM_Settings::CLEAR_ACTION ); ?>" />
					<?php wp_nonce_field( MM_Settings::CLEAR_ACTION ); ?>
					<?php submit_button( __( 'Forget stored token', 'money-maker' ), 'link-delete', 'submit', false ); ?>
				</form>
			</div>
		<?php endif; ?>
		<?php
		$this->close();
	}

	/**
	 * Settings screen: environment toggle + encryption-key management.
	 */
	public function render_settings(): void {
		$this->guard();

		$this->open( 'settings' );
		$this->render_environment_form();
		$this->render_currency_form();
		$this->render_encryption_form();
		$this->close();
	}

	/**
	 * Data Sync screen: coverage overview, "Sync now", historical backfill, and
	 * the recent-runs log.
	 */
	public function render_sync(): void {
		$this->guard();

		$this->open( 'sync' );

		if ( ! MM_DB::is_installed() ) {
			echo '<div class="mm-inline-notice mm-inline-notice--bad">'
				. esc_html__( 'The custom tables are missing. Deactivate and reactivate the plugin to create them.', 'money-maker' )
				. '</div>';
			$this->close();
			return;
		}

		$this->render_sync_overview();
		$this->render_sync_now_form();
		$this->render_backfill_form();
		$this->render_sync_log_table();

		$this->close();
	}

	/* ---------------------------------------------------------------------
	 * Data Sync building blocks
	 * ------------------------------------------------------------------- */

	/**
	 * Row counts, activity date coverage, and the next scheduled run.
	 */
	private function render_sync_overview(): void {
		$span      = MM_Activities::settlement_span();
		$next_cron = MM_Sync::next_scheduled();
		?>
		<div class="mm-card">
			<h2><?php esc_html_e( 'Overview', 'money-maker' ); ?></h2>
			<div class="mm-keyval">
				<div>
					<span class="mm-keyval__k"><?php esc_html_e( 'Accounts', 'money-maker' ); ?></span>
					<span class="mm-keyval__v"><?php echo esc_html( number_format_i18n( MM_Accounts::count() ) ); ?></span>
				</div>
				<div>
					<span class="mm-keyval__k"><?php esc_html_e( 'Activities', 'money-maker' ); ?></span>
					<span class="mm-keyval__v"><?php echo esc_html( number_format_i18n( MM_Activities::count() ) ); ?></span>
				</div>
				<div>
					<span class="mm-keyval__k"><?php esc_html_e( 'FX rates', 'money-maker' ); ?></span>
					<span class="mm-keyval__v"><?php echo esc_html( number_format_i18n( MM_FX::count() ) ); ?></span>
				</div>
				<div>
					<span class="mm-keyval__k"><?php esc_html_e( 'Position rows', 'money-maker' ); ?></span>
					<span class="mm-keyval__v"><?php echo esc_html( number_format_i18n( MM_Positions::count() ) ); ?></span>
				</div>
				<div>
					<span class="mm-keyval__k"><?php esc_html_e( 'Activity coverage', 'money-maker' ); ?></span>
					<span class="mm-keyval__v">
						<?php
						echo $span['min'] && $span['max']
							? esc_html( $span['min'] . '  →  ' . $span['max'] )
							: esc_html__( 'nothing stored yet', 'money-maker' );
						?>
					</span>
				</div>
				<div>
					<span class="mm-keyval__k"><?php esc_html_e( 'Next scheduled run', 'money-maker' ); ?></span>
					<span class="mm-keyval__v">
						<?php
						echo $next_cron
							? esc_html( sprintf(
								/* translators: %s: human time diff */
								__( 'in %s (WP-Cron)', 'money-maker' ),
								human_time_diff( time(), $next_cron )
							) )
							: esc_html__( 'not scheduled', 'money-maker' );
						?>
					</span>
				</div>
			</div>
			<?php $this->render_account_coverage(); ?>
			<p class="mm-muted">
				<?php esc_html_e( 'WP-Cron only fires when the site gets traffic. For reliable scheduled syncs, point a real system cron at wp-cron.php (see the plugin README).', 'money-maker' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Per-account activity coverage.
	 *
	 * The portfolio-wide MIN/MAX above hides the failure that actually hurts: one
	 * account backfilled to 2018 and another holding only the last 35 days, which
	 * reads on the Holdings screen as a sale with no matching buy.
	 */
	private function render_account_coverage(): void {
		$accounts = MM_Accounts::all();

		if ( empty( $accounts ) ) {
			return;
		}

		$coverage = MM_Activities::coverage_by_account();
		$cutoff   = gmdate( 'Y-m-d', strtotime( '-' . ( MM_Sync::INCREMENTAL_WINDOW_DAYS + 5 ) . ' days' ) );
		?>
		<h3><?php esc_html_e( 'Coverage per account', 'money-maker' ); ?></h3>
		<table class="widefat striped mm-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Account', 'money-maker' ); ?></th>
					<th class="mm-num"><?php esc_html_e( 'Activity rows', 'money-maker' ); ?></th>
					<th><?php esc_html_e( 'Earliest', 'money-maker' ); ?></th>
					<th><?php esc_html_e( 'Latest', 'money-maker' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $accounts as $account ) : ?>
					<?php
					$number = (string) ( $account['account_number'] ?? '' );
					$row    = isset( $coverage[ $number ] )
						? $coverage[ $number ]
						: array(
							'rows' => 0,
							'min'  => null,
							'max'  => null,
						);
					$thin   = empty( $row['min'] ) || $row['min'] > $cutoff;
					?>
					<tr>
						<td><?php echo esc_html( MM_Accounts::label( $account ) ); ?></td>
						<td class="mm-num"><?php echo esc_html( number_format_i18n( $row['rows'] ) ); ?></td>
						<td>
							<?php echo $row['min'] ? esc_html( $row['min'] ) : '&mdash;'; ?>
							<?php if ( $thin ) : ?>
								<span class="mm-pill mm-pill--warn"><?php esc_html_e( 'no history', 'money-maker' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo $row['max'] ? esc_html( $row['max'] ) : '&mdash;'; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="mm-muted">
			<?php
			echo esc_html( sprintf(
				/* translators: %d: incremental window in days */
				__( 'The scheduled sync only re-pulls the last %d days. Anything older can only come from a historical backfill, so an account marked “no history” will show sales with no matching purchase.', 'money-maker' ),
				MM_Sync::INCREMENTAL_WINDOW_DAYS
			) );
			?>
		</p>
		<?php
	}

	/**
	 * "Sync now" — pick endpoints, run synchronously.
	 */
	private function render_sync_now_form(): void {
		$labels = array(
			'accounts'   => __( 'Accounts', 'money-maker' ),
			'fx'         => __( 'FX rates', 'money-maker' ),
			'activities' => __( 'Activities (last 35 days)', 'money-maker' ),
			'positions'  => __( 'Positions snapshot', 'money-maker' ),
		);
		?>
		<div class="mm-card">
			<h2><?php esc_html_e( 'Sync now', 'money-maker' ); ?></h2>
			<p class="mm-muted">
				<?php esc_html_e( 'Runs immediately and may take up to a minute. Activities are re-pulled for the last 35 days and de-duplicated on write.', 'money-maker' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mm-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( MM_Sync::ACTION_RUN ); ?>" />
				<?php wp_nonce_field( MM_Sync::ACTION_RUN ); ?>
				<fieldset class="mm-check-group">
					<?php foreach ( $labels as $key => $label ) : ?>
						<label class="mm-check">
							<input type="checkbox" name="mm_endpoints[]" value="<?php echo esc_attr( $key ); ?>" checked />
							<span><?php echo esc_html( $label ); ?></span>
						</label>
					<?php endforeach; ?>
				</fieldset>
				<?php submit_button( __( 'Sync now', 'money-maker' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Historical backfill control.
	 */
	private function render_backfill_form(): void {
		$status  = MM_Sync::backfill_status();
		$default = gmdate( 'Y-m-d', strtotime( '-3 years' ) );
		?>
		<div class="mm-card">
			<h2><?php esc_html_e( 'Historical backfill', 'money-maker' ); ?></h2>

			<?php if ( ! $status['active'] ) : ?>
				<p class="mm-muted">
					<?php esc_html_e( 'Walk activities month-by-month back to a start date. Runs in the background in small steps so no single request times out.', 'money-maker' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mm-form">
					<input type="hidden" name="action" value="<?php echo esc_attr( MM_Sync::ACTION_BACKFILL ); ?>" />
					<input type="hidden" name="mm_backfill_op" value="start" />
					<?php wp_nonce_field( MM_Sync::ACTION_BACKFILL ); ?>
					<div class="mm-field">
						<label for="mm_backfill_since"><?php esc_html_e( 'Start date', 'money-maker' ); ?></label>
						<input type="date" id="mm_backfill_since" name="mm_backfill_since" value="<?php echo esc_attr( $default ); ?>" required />
						<p class="mm-muted">
							<?php esc_html_e( 'Questrade only serves activities for open accounts; earlier data may be unavailable. WP-Cron advances this in small steps only when the site gets traffic — clicking the step button is the reliable way to finish a long backfill.', 'money-maker' ); ?>
						</p>
					</div>
					<?php submit_button( __( 'Start backfill', 'money-maker' ), 'secondary' ); ?>
				</form>
			<?php else : ?>
				<?php $blocked = (array) $status['blocked']; ?>
				<div class="mm-keyval">
					<div>
						<span class="mm-keyval__k"><?php esc_html_e( 'Started from', 'money-maker' ); ?></span>
						<span class="mm-keyval__v"><?php echo esc_html( (string) $status['since'] ); ?></span>
					</div>
					<div>
						<span class="mm-keyval__k"><?php esc_html_e( 'Progress', 'money-maker' ); ?></span>
						<span class="mm-keyval__v">
							<?php
							if ( ! empty( $blocked ) ) {
								echo '<span class="mm-pill mm-pill--bad">' . esc_html__( 'Some accounts blocked', 'money-maker' ) . '</span> ';
								echo esc_html( sprintf(
									/* translators: %d: number of accounts */
									_n( '%d account kept erroring; the error is shown below.', '%d accounts kept erroring; the errors are shown below.', count( $blocked ), 'money-maker' ),
									count( $blocked )
								) );
							} elseif ( ! empty( $status['stalled'] ) ) {
								echo '<span class="mm-pill mm-pill--warn">' . esc_html__( 'Stopped early', 'money-maker' ) . '</span> ';
								esc_html_e( 'nothing advanced for several attempts.', 'money-maker' );
							} elseif ( $status['complete'] ) {
								echo '<span class="mm-pill mm-pill--ok">' . esc_html__( 'Complete', 'money-maker' ) . '</span>';
							} else {
								echo esc_html( sprintf(
									/* translators: %s: date the backfill has reached */
									__( 'working forward from %s', 'money-maker' ),
									(string) $status['frontier']
								) );
							}
							?>
						</span>
					</div>
					<div>
						<span class="mm-keyval__k"><?php esc_html_e( 'Rows written', 'money-maker' ); ?></span>
						<span class="mm-keyval__v">
							<?php
							echo esc_html( sprintf(
								/* translators: 1: rows written, 2: monthly windows pulled */
								__( '%1$s rows over %2$s windows', 'money-maker' ),
								number_format_i18n( $status['rows_total'] ),
								number_format_i18n( $status['windows'] )
							) );
							?>
						</span>
					</div>
					<div>
						<span class="mm-keyval__k"><?php esc_html_e( 'Last step', 'money-maker' ); ?></span>
						<span class="mm-keyval__v">
							<?php
							echo $status['updated_at']
								? esc_html( sprintf(
									/* translators: %s: human time diff */
									__( '%s ago', 'money-maker' ),
									human_time_diff( (int) $status['updated_at'], time() )
								) )
								: '&mdash;';
							?>
						</span>
					</div>
				</div>

				<?php if ( ! empty( $status['accounts'] ) ) : ?>
					<h3><?php esc_html_e( 'Per account', 'money-maker' ); ?></h3>
					<table class="widefat striped mm-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Account', 'money-maker' ); ?></th>
								<th><?php esc_html_e( 'State', 'money-maker' ); ?></th>
								<th><?php esc_html_e( 'Reached', 'money-maker' ); ?></th>
								<th><?php esc_html_e( 'Last error from Questrade', 'money-maker' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $status['accounts'] as $line ) : ?>
								<tr>
									<td><?php echo esc_html( (string) $line['account'] ); ?></td>
									<td>
										<?php
										if ( ! empty( $line['blocked'] ) ) {
											echo '<span class="mm-pill mm-pill--bad">' . esc_html__( 'blocked', 'money-maker' ) . '</span>';
										} elseif ( ! empty( $line['done'] ) ) {
											echo '<span class="mm-pill mm-pill--ok">' . esc_html__( 'done', 'money-maker' ) . '</span>';
										} else {
											echo '<span class="mm-pill mm-pill--muted">' . esc_html__( 'pending', 'money-maker' ) . '</span>';
										}
										?>
									</td>
									<td><?php echo esc_html( (string) $line['cursor'] ); ?></td>
									<td class="mm-log__detail"><?php echo '' !== $line['error'] ? esc_html( (string) $line['error'] ) : '&mdash;'; ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p class="mm-muted">
						<?php esc_html_e( '“Reached” is the next date that account still has to pull. A blocked account is skipped so the others can finish — fix the cause, then retry it.', 'money-maker' ); ?>
					</p>
				<?php endif; ?>

				<p class="mm-actions">
					<?php if ( ! $status['complete'] ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<input type="hidden" name="action" value="<?php echo esc_attr( MM_Sync::ACTION_BACKFILL ); ?>" />
							<input type="hidden" name="mm_backfill_op" value="tick" />
							<?php wp_nonce_field( MM_Sync::ACTION_BACKFILL ); ?>
							<?php
							submit_button(
								sprintf(
									/* translators: %d: months pulled per click */
									__( 'Run a backfill step now (~%d months)', 'money-maker' ),
									MM_Sync::BACKFILL_MANUAL_WINDOWS
								),
								'secondary',
								'submit',
								false
							);
							?>
						</form>
					<?php endif; ?>
					<?php if ( ! empty( $blocked ) || ! empty( $status['stalled'] ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<input type="hidden" name="action" value="<?php echo esc_attr( MM_Sync::ACTION_BACKFILL ); ?>" />
							<input type="hidden" name="mm_backfill_op" value="resume" />
							<?php wp_nonce_field( MM_Sync::ACTION_BACKFILL ); ?>
							<?php submit_button( __( 'Retry blocked accounts', 'money-maker' ), 'secondary', 'submit', false ); ?>
						</form>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"
						onsubmit="return window.confirm( '<?php echo esc_js( __( 'Cancel the backfill and clear its progress?', 'money-maker' ) ); ?>' );">
						<input type="hidden" name="action" value="<?php echo esc_attr( MM_Sync::ACTION_BACKFILL ); ?>" />
						<input type="hidden" name="mm_backfill_op" value="cancel" />
						<?php wp_nonce_field( MM_Sync::ACTION_BACKFILL ); ?>
						<?php submit_button( __( 'Cancel backfill', 'money-maker' ), 'link-delete', 'submit', false ); ?>
					</form>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The last ~20 sync-log rows.
	 */
	private function render_sync_log_table(): void {
		$errors = MM_Sync_Log::recent_errors( 10 );
		$rows   = MM_Sync_Log::recent( 40 );

		if ( ! empty( $errors ) ) :
			?>
			<div class="mm-card mm-card--warn">
				<h2><?php esc_html_e( 'Recent failures', 'money-maker' ); ?></h2>
				<p class="mm-muted">
					<?php esc_html_e( 'The last 10 failed sync steps, however old. Kept separate because ordinary syncs push failures out of the run list below within a day or two.', 'money-maker' ); ?>
				</p>
				<?php $this->render_log_rows( $errors ); ?>
			</div>
			<?php
		endif;
		?>
		<div class="mm-card">
			<h2><?php esc_html_e( 'Recent runs', 'money-maker' ); ?></h2>
			<?php if ( empty( $rows ) ) : ?>
				<p class="mm-muted"><?php esc_html_e( 'No sync has run yet.', 'money-maker' ); ?></p>
			<?php else : ?>
				<?php $this->render_log_rows( $rows ); ?>
				<p class="mm-muted"><?php esc_html_e( 'Rows shown as seen / written. Log rows older than 90 days are pruned automatically.', 'money-maker' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The shared <table> for a set of mm_sync_log rows.
	 *
	 * @param array<int,array<string,mixed>> $rows Log rows, newest first.
	 */
	private function render_log_rows( array $rows ): void {
		?>
				<table class="widefat striped mm-log">
					<thead>
						<tr>
							<th><?php esc_html_e( 'When', 'money-maker' ); ?></th>
							<th><?php esc_html_e( 'Endpoint', 'money-maker' ); ?></th>
							<th><?php esc_html_e( 'Scope', 'money-maker' ); ?></th>
							<th><?php esc_html_e( 'Range', 'money-maker' ); ?></th>
							<th><?php esc_html_e( 'Status', 'money-maker' ); ?></th>
							<th><?php esc_html_e( 'Rows', 'money-maker' ); ?></th>
							<th><?php esc_html_e( 'Detail', 'money-maker' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<?php
							$tone = 'ok' === $row['status'] ? 'ok' : ( in_array( $row['status'], array( 'error', 'partial' ), true ) ? 'bad' : 'muted' );
							$when = ! empty( $row['started_at'] ) ? strtotime( $row['started_at'] . ' UTC' ) : 0;
							?>
							<tr>
								<td><?php echo $when ? esc_html( human_time_diff( $when, time() ) . ' ' . __( 'ago', 'money-maker' ) ) : '&mdash;'; ?></td>
								<td><?php echo esc_html( (string) $row['endpoint'] ); ?></td>
								<td><?php echo esc_html( (string) $row['scope'] ); ?></td>
								<td>
									<?php
									echo $row['range_start'] && $row['range_end']
										? esc_html( $row['range_start'] . '…' . $row['range_end'] )
										: '&mdash;';
									?>
								</td>
								<td><span class="mm-pill mm-pill--<?php echo esc_attr( $tone ); ?>"><?php echo esc_html( (string) $row['status'] ); ?></span></td>
								<td><?php echo esc_html( $row['rows_seen'] . ' / ' . $row['rows_affected'] ); ?></td>
								<td class="mm-log__detail"><?php echo esc_html( (string) $row['message'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Milestone 3 — Holdings / Realized Gains / Adjustments
	 * ------------------------------------------------------------------- */

	/**
	 * Holdings screen: current positions + pooled ACB + unrealised gain/loss,
	 * per account and consolidated, plus the portfolio-value chart.
	 */
	public function render_holdings(): void {
		$this->guard();
		$this->open( 'holdings' );

		if ( $this->require_tables() ) {
			$this->close();
			return;
		}

		$model   = MM_Holdings::current();
		$display = (string) $model['display_currency'];

		if ( ! $model['has_data'] ) {
			?>
			<div class="mm-card">
				<h2><?php esc_html_e( 'No positions yet', 'money-maker' ); ?></h2>
				<p class="mm-muted">
					<?php esc_html_e( 'Run a positions sync to pull your open holdings from Questrade.', 'money-maker' ); ?>
				</p>
				<a class="button button-primary" href="<?php echo esc_url( self::page_url( self::SYNC_SLUG ) ); ?>">
					<?php esc_html_e( 'Go to Data Sync', 'money-maker' ); ?>
				</a>
			</div>
			<?php
			$this->close();
			return;
		}
		?>
		<?php $this->render_holdings_quality_notice( $model['data_quality'] ?? array() ); ?>

		<div class="mm-card">
			<div class="mm-card__head">
				<h2><?php esc_html_e( 'Portfolio value', 'money-maker' ); ?></h2>
				<?php if ( $model['as_of'] ) : ?>
					<span class="mm-pill mm-pill--muted">
						<?php
						printf(
							/* translators: %s: snapshot date */
							esc_html__( 'as of %s', 'money-maker' ),
							esc_html( (string) $model['as_of'] )
						);
						?>
					</span>
				<?php endif; ?>
			</div>
			<div class="mm-chart" data-mm-chart="portfolioValue"
				data-mm-empty="<?php esc_attr_e( 'Portfolio history appears once at least two positions snapshots exist.', 'money-maker' ); ?>">
			</div>
			<p class="mm-muted">
				<?php
				printf(
					/* translators: %s: display currency code */
					esc_html__( 'Market value as reported by Questrade, summed across every account and converted to %s at each snapshot date\'s rate.', 'money-maker' ),
					esc_html( $display )
				);
				?>
			</p>
		</div>

		<?php foreach ( $model['accounts'] as $account ) : ?>
			<div class="mm-card">
				<div class="mm-card__head">
					<h2><?php echo esc_html( $account['label'] ); ?></h2>
					<span class="mm-pill mm-pill--<?php echo $account['registered'] ? 'muted' : 'ok'; ?>">
						<?php echo esc_html( $account['registered'] ? __( 'Registered', 'money-maker' ) : __( 'Non-registered', 'money-maker' ) ); ?>
					</span>
				</div>
				<?php if ( $account['registered'] ) : ?>
					<p class="mm-muted">
						<?php esc_html_e( 'Cost base shown for your own reference only — a registered account has no CRA cost base and is not reportable.', 'money-maker' ); ?>
					</p>
				<?php endif; ?>
				<?php $this->render_holdings_table( $account['lines'], $account['subtotal'], $display ); ?>
			</div>
		<?php endforeach; ?>

		<?php if ( count( $model['accounts'] ) > 1 ) : ?>
			<div class="mm-card">
				<h2><?php esc_html_e( 'Consolidated', 'money-maker' ); ?></h2>
				<?php
				$this->render_holdings_table(
					$model['consolidated'],
					array(
						'book'       => $model['totals']['book'],
						'market'     => $model['totals']['market'],
						'unrealised' => $model['totals']['unrealised'],
					),
					$display
				);
				?>
			</div>
		<?php endif; ?>

		<?php
		$this->render_tax_disclaimer();
		$this->close();
	}

	/**
	 * Warn when the cost base underlying the holdings table is incomplete.
	 *
	 * The book values on this screen come from the same pooled walk the tax
	 * screens use, but that screen only reports on non-registered accounts —
	 * so without this notice a registered account holding transferred-in or
	 * journalled shares would show a confidently wrong cost base with nothing
	 * on the page saying so.
	 *
	 * @param array{reviews?:int,unconverted?:int,warnings?:int,options_no_cost?:int} $quality
	 */
	private function render_holdings_quality_notice( array $quality ): void {
		$reviews     = (int) ( $quality['reviews'] ?? 0 );
		$unconverted = (int) ( $quality['unconverted'] ?? 0 );
		$no_cost     = (int) ( $quality['options_no_cost'] ?? 0 );

		if ( $reviews < 1 && $unconverted < 1 && $no_cost < 1 ) {
			return;
		}

		?>
		<div class="mm-card mm-card--warn">
			<h2><?php esc_html_e( 'Cost base may be incomplete', 'money-maker' ); ?></h2>
			<ul>
				<?php if ( $reviews > 0 ) : ?>
					<li>
						<?php
						printf(
							/* translators: %d: number of unclassified activities */
							esc_html( _n(
								'%d activity moved shares but was not a plain buy or sell (transfer-in, journalled shares, corporate action), so it is not in the book values above.',
								'%d activities moved shares but were not plain buys or sells (transfers-in, journalled shares, corporate actions), so they are not in the book values above.',
								$reviews,
								'money-maker'
							) ),
							(int) $reviews
						);
						?>
					</li>
				<?php endif; ?>
				<?php if ( $unconverted > 0 ) : ?>
					<li>
						<?php
						printf(
							/* translators: %d: number of positions with no exchange rate */
							esc_html( _n(
								'%d position could not be converted into the total\'s currency — no exchange rate is stored for its snapshot date, so it is missing from the Total row. Run an FX sync.',
								'%d positions could not be converted into the total\'s currency — no exchange rate is stored for their snapshot dates, so they are missing from the Total row. Run an FX sync.',
								$unconverted,
								'money-maker'
							) ),
							(int) $unconverted
						);
						?>
					</li>
				<?php endif; ?>
				<?php if ( $no_cost > 0 ) : ?>
					<li>
						<?php
						printf(
							/* translators: %d: number of option positions */
							esc_html( _n(
								'%d open option contract has no cost base the engine could establish, so its market value is in the totals but its cost is not.',
								'%d open option contracts have no cost base the engine could establish, so their market value is in the totals but their cost is not.',
								$no_cost,
								'money-maker'
							) ),
							(int) $no_cost
						);
						?>
					</li>
				<?php endif; ?>
			</ul>
			<p class="mm-muted">
				<?php esc_html_e( 'Record the missing pieces as manual adjustments to correct the cost base.', 'money-maker' ); ?>
				<a href="<?php echo esc_url( self::page_url( self::ADJUSTMENTS_SLUG ) ); ?>"><?php esc_html_e( 'Go to Adjustments →', 'money-maker' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * One holdings table (per-account or consolidated).
	 *
	 * Since M4a every account has a pooled cost base, so the ACB columns are
	 * always shown — registered accounts included, where they are a personal
	 * performance figure rather than a CRA one (the caller labels that).
	 *
	 * Since M4f each row is in its **own** trading currency, with anything that
	 * is not the display currency marked by a pill. Only the Total row is
	 * converted, because a mixed book has to add up to a single number. That is
	 * the whole point: a USD position reads in the dollars it was bought with,
	 * not in a CAD figure the user has to convert back in their head.
	 *
	 * @param array<int,array<string,mixed>>                  $lines
	 * @param array{book:float,market:float,unrealised:float} $subtotal In $display.
	 * @param string                                          $display  Display currency.
	 */
	private function render_holdings_table( array $lines, array $subtotal, string $display ): void {
		$mixed = false;
		foreach ( $lines as $line ) {
			if ( ! empty( $line['converted'] ) ) {
				$mixed = true;
				break;
			}
		}
		?>
		<table class="widefat striped mm-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Symbol', 'money-maker' ); ?></th>
					<th class="mm-num"><?php esc_html_e( 'Quantity', 'money-maker' ); ?></th>
					<th class="mm-num"><?php esc_html_e( 'Avg cost', 'money-maker' ); ?></th>
					<th class="mm-num"><?php esc_html_e( 'Book value', 'money-maker' ); ?></th>
					<th class="mm-num"><?php esc_html_e( 'Market value', 'money-maker' ); ?></th>
					<th class="mm-num"><?php esc_html_e( 'Unrealised', 'money-maker' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $lines as $line ) : ?>
					<?php $currency = (string) ( $line['currency'] ?? $display ); ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $line['symbol'] ); ?></strong>
							<?php echo wp_kses_post( self::currency_pill( $currency ) ); ?>
							<?php if ( ! empty( $line['is_option'] ) ) : ?>
								<span class="mm-pill mm-pill--muted"><?php esc_html_e( 'option', 'money-maker' ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $line['option_short'] ) ) : ?>
								<span class="mm-pill mm-pill--muted"><?php esc_html_e( 'written', 'money-maker' ); ?></span>
								<span class="mm-muted mm-block">
									<?php esc_html_e( 'A written contract has no cost base — its premium was already booked as a gain when it was granted, so the unrealised figure is what it would cost to close.', 'money-maker' ); ?>
								</span>
							<?php endif; ?>
						</td>
						<td class="mm-num"><?php echo esc_html( self::fmt_qty( (float) $line['quantity'] ) ); ?></td>
						<td class="mm-num"><?php echo esc_html( self::fmt_money( isset( $line['avg_cost'] ) ? $line['avg_cost'] : null, $currency ) ); ?></td>
						<td class="mm-num"><?php echo esc_html( self::fmt_money( isset( $line['book'] ) ? $line['book'] : null, $currency ) ); ?></td>
						<td class="mm-num"><?php echo esc_html( self::fmt_money( isset( $line['market'] ) ? $line['market'] : null, $currency ) ); ?></td>
						<td class="mm-num"><?php echo wp_kses_post( self::gain_cell( isset( $line['unrealised'] ) ? $line['unrealised'] : null, isset( $line['unrealised_pct'] ) ? $line['unrealised_pct'] : null, $currency ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<th>
						<?php
						printf(
							/* translators: %s: display currency code */
							esc_html__( 'Total (%s)', 'money-maker' ),
							esc_html( $display )
						);
						?>
					</th>
					<td class="mm-num">—</td>
					<td class="mm-num">—</td>
					<td class="mm-num"><?php echo esc_html( self::fmt_money( (float) $subtotal['book'], $display ) ); ?></td>
					<td class="mm-num"><?php echo esc_html( self::fmt_money( (float) $subtotal['market'], $display ) ); ?></td>
					<td class="mm-num"><?php echo wp_kses_post( self::gain_cell( (float) $subtotal['unrealised'], null, $display ) ); ?></td>
				</tr>
			</tfoot>
		</table>
		<?php if ( $mixed ) : ?>
			<p class="mm-muted">
				<?php
				printf(
					/* translators: %s: display currency code */
					esc_html__( 'Each row is in the currency it trades in; anything other than %s is tagged. Only the total is converted, at the snapshot date\'s rate.', 'money-maker' ),
					esc_html( $display )
				);
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Wheels (M4g)
	 * ------------------------------------------------------------------- */

	/**
	 * Options-wheel screen.
	 *
	 * The old standalone Wheel Tracker plugin asked the user to type every put,
	 * roll and assignment in by hand. Everything it wanted is in the activity
	 * feed, so this screen derives the same ledger from MM_Wheels instead. It
	 * measures **cash**, not tax — the tax treatment of the same contracts lives
	 * on Realized Gains and is deliberately a different number (a written
	 * premium is taxable at grant, but the cash story is not settled until the
	 * contract closes).
	 */
	public function render_wheels(): void {
		$this->guard();
		$this->open( 'wheels' );

		if ( $this->require_tables() ) {
			$this->close();
			return;
		}

		$model   = MM_Wheels::all();
		$display = (string) $model['display_currency'];

		if ( ! $model['has_data'] ) {
			?>
			<div class="mm-card">
				<h2><?php esc_html_e( 'No wheels yet', 'money-maker' ); ?></h2>
				<p class="mm-muted">
					<?php esc_html_e( 'A wheel appears here as soon as an option contract shows up in your synced activity — nothing to set up and nothing to type in. Sync your activities to get started.', 'money-maker' ); ?>
				</p>
				<a class="button button-primary" href="<?php echo esc_url( self::page_url( self::SYNC_SLUG ) ); ?>">
					<?php esc_html_e( 'Go to Data Sync', 'money-maker' ); ?>
				</a>
			</div>
			<?php
			$this->render_playbook();
			$this->close();
			return;
		}

		$live   = array();
		$closed = array();

		foreach ( $model['wheels'] as $wheel ) {
			if ( 'finished' === $wheel['status'] ) {
				$closed[] = $wheel;
			} else {
				$live[] = $wheel;
			}
		}

		$this->render_wheel_summary( $model['totals'], $display );

		foreach ( $live as $wheel ) {
			$this->render_wheel_card( $wheel );
		}

		if ( ! empty( $closed ) ) {
			?>
			<div class="mm-card">
				<div class="mm-card__head">
					<h2><?php esc_html_e( 'Finished wheels', 'money-maker' ); ?></h2>
					<span class="mm-pill mm-pill--muted">
						<?php
						printf(
							/* translators: %d: number of wheels */
							esc_html( _n( '%d wheel', '%d wheels', count( $closed ), 'money-maker' ) ),
							count( $closed )
						);
						?>
					</span>
				</div>
				<p class="mm-muted">
					<?php
					printf(
						/* translators: %d: number of days */
						esc_html__( 'Flat — no shares, no open contracts — and nothing traded for over %d days.', 'money-maker' ),
						(int) MM_Wheels::DORMANT_DAYS
					);
					?>
				</p>
				<table class="widefat striped mm-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Ticker', 'money-maker' ); ?></th>
							<th><?php esc_html_e( 'Account', 'money-maker' ); ?></th>
							<th><?php esc_html_e( 'Ran', 'money-maker' ); ?></th>
							<th class="mm-num"><?php esc_html_e( 'Rounds', 'money-maker' ); ?></th>
							<th class="mm-num"><?php esc_html_e( 'Net premium', 'money-maker' ); ?></th>
							<th class="mm-num"><?php esc_html_e( 'Net cash', 'money-maker' ); ?></th>
							<th class="mm-num"><?php esc_html_e( 'Return', 'money-maker' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $closed as $wheel ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $wheel['underlying'] ); ?></strong>
									<?php echo wp_kses_post( self::currency_pill( (string) $wheel['currency'] ) ); ?>
								</td>
								<td><?php echo esc_html( $wheel['account_label'] ); ?></td>
								<td><?php echo esc_html( $wheel['first_date'] . ' → ' . $wheel['last_date'] ); ?></td>
								<td class="mm-num"><?php echo esc_html( (string) count( $wheel['rounds'] ) ); ?></td>
								<td class="mm-num"><?php echo esc_html( self::fmt_money( (float) $wheel['net_premium'], (string) $wheel['currency'] ) ); ?></td>
								<td class="mm-num"><?php echo wp_kses_post( self::gain_cell( (float) $wheel['net_cash'], null, (string) $wheel['currency'] ) ); ?></td>
								<td class="mm-num">
									<?php echo esc_html( null === $wheel['return_on_capital'] ? '—' : number_format( (float) $wheel['return_on_capital'], 1 ) . '%' ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php
		}

		$this->render_playbook();
		$this->close();
	}

	/**
	 * Cross-wheel roll-up card.
	 *
	 * @param array<string,mixed> $totals
	 */
	private function render_wheel_summary( array $totals, string $display ): void {
		?>
		<div class="mm-card">
			<div class="mm-card__head">
				<h2><?php esc_html_e( 'All wheels', 'money-maker' ); ?></h2>
				<span class="mm-pill mm-pill--ok">
					<?php
					printf(
						/* translators: %d: number of live wheels */
						esc_html( _n( '%d live', '%d live', (int) $totals['active'], 'money-maker' ) ),
						(int) $totals['active']
					);
					?>
				</span>
			</div>
			<div class="mm-metrics">
				<?php
				$this->metric( __( 'Net cash', 'money-maker' ), MM_Money::fmt_signed( (float) $totals['net_cash'], $display ), self::tone( (float) $totals['net_cash'] ) );
				$this->metric( __( 'Net premium', 'money-maker' ), MM_Money::fmt_signed( (float) $totals['net_premium'], $display ), self::tone( (float) $totals['net_premium'] ) );
				$this->metric( __( 'Dividends', 'money-maker' ), self::fmt_money( (float) $totals['dividends'], $display ), '' );
				$this->metric( __( 'Commissions', 'money-maker' ), self::fmt_money( (float) $totals['fees'], $display ), '' );
				$this->metric( __( 'Capital committed', 'money-maker' ), self::fmt_money( (float) $totals['at_risk'], $display ), '' );
				?>
			</div>
			<p class="mm-muted">
				<?php
				printf(
					/* translators: %s: display currency code */
					esc_html__( 'Derived from your synced Questrade activity — nothing here is typed in. Each wheel below is in its own trading currency; this roll-up is converted to %s at today\'s rate. These are cash figures, not tax figures.', 'money-maker' ),
					esc_html( $display )
				);
				?>
			</p>
			<?php if ( ! empty( $totals['unconverted'] ) ) : ?>
				<p class="mm-inline-notice mm-inline-notice--warn">
					<?php esc_html_e( 'Some wheels could not be converted into the roll-up currency because no exchange rate is stored — run an FX sync. They are missing from the figures above but correct in their own cards.', 'money-maker' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * One live wheel.
	 *
	 * @param array<string,mixed> $wheel
	 */
	private function render_wheel_card( array $wheel ): void {
		$currency = (string) $wheel['currency'];
		$phase    = (string) $wheel['phase'];
		?>
		<div class="mm-card mm-wheel mm-wheel--<?php echo esc_attr( $phase ); ?>">
			<div class="mm-card__head">
				<h2>
					<?php echo esc_html( $wheel['underlying'] ); ?>
					<?php echo wp_kses_post( self::currency_pill( $currency ) ); ?>
				</h2>
				<span class="mm-pill mm-pill--phase-<?php echo esc_attr( $phase ); ?>">
					<?php echo esc_html( MM_Wheels::phase_label( $phase ) ); ?>
				</span>
			</div>

			<p class="mm-muted mm-wheel__meta">
				<?php
				printf(
					/* translators: 1: account label, 2: start date, 3: number of days, 4: number of rounds */
					esc_html__( '%1$s · running since %2$s (%3$d days) · %4$d rounds', 'money-maker' ),
					esc_html( $wheel['account_label'] ),
					esc_html( $wheel['first_date'] ),
					(int) $wheel['days'],
					count( $wheel['rounds'] )
				);
				?>
			</p>

			<div class="mm-metrics">
				<?php
				$this->metric( __( 'Net cash', 'money-maker' ), MM_Money::fmt_signed( (float) $wheel['net_cash'], $currency ), self::tone( (float) $wheel['net_cash'] ) );
				$this->metric( __( 'Net premium', 'money-maker' ), MM_Money::fmt_signed( (float) $wheel['net_premium'], $currency ), self::tone( (float) $wheel['net_premium'] ) );

				if ( $wheel['shares'] > 0 ) {
					$this->metric( __( 'Shares held', 'money-maker' ), self::fmt_qty( (float) $wheel['shares'] ), '' );
					$this->metric(
						__( 'Break-even', 'money-maker' ),
						self::fmt_money( isset( $wheel['break_even'] ) ? $wheel['break_even'] : null, $currency ),
						'',
						__( 'What each share still has to fetch for the whole wheel to be cash-positive — premium and dividends already subtracted.', 'money-maker' )
					);
				}

				if ( null !== $wheel['open_value'] ) {
					$this->metric(
						__( 'If closed now', 'money-maker' ),
						MM_Money::fmt_signed( (float) $wheel['open_value'], $currency ),
						self::tone( (float) $wheel['open_value'] ),
						__( 'Cash collected so far plus what the shares are worth at the last snapshot. Any open contract still has to be bought back.', 'money-maker' )
					);
				}

				if ( null !== $wheel['if_called_away'] ) {
					$this->metric(
						__( 'If called away', 'money-maker' ),
						MM_Money::fmt_signed( (float) $wheel['if_called_away'], $currency ),
						self::tone( (float) $wheel['if_called_away'] ),
						__( 'Where the wheel lands if the open call is assigned at its strike.', 'money-maker' )
					);
				}

				if ( null !== $wheel['annualized'] ) {
					$this->metric(
						__( 'Annualised', 'money-maker' ),
						number_format( (float) $wheel['annualized'], 1 ) . '%',
						self::tone( (float) $wheel['annualized'] ),
						__( 'Net cash against the most capital this wheel ever tied up, scaled to a year. A short wheel can flatter this badly.', 'money-maker' )
					);
				}

				if ( abs( (float) $wheel['dividends'] ) > 0.004 ) {
					$this->metric( __( 'Dividends', 'money-maker' ), self::fmt_money( (float) $wheel['dividends'], $currency ), '' );
				}
				?>
			</div>

			<?php $this->render_wheel_open_positions( $wheel ); ?>

			<?php if ( ! empty( $wheel['warnings'] ) ) : ?>
				<div class="mm-inline-notice mm-inline-notice--warn">
					<?php foreach ( $wheel['warnings'] as $warning ) : ?>
						<p><?php echo esc_html( $warning ); ?></p>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<details class="mm-wheel__details">
				<summary>
					<?php
					printf(
						/* translators: %d: number of ledger entries */
						esc_html( _n( 'Ledger — %d entry', 'Ledger — %d entries', count( $wheel['ledger'] ), 'money-maker' ) ),
						count( $wheel['ledger'] )
					);
					?>
				</summary>
				<?php $this->render_wheel_ledger( $wheel ); ?>
			</details>

			<?php if ( count( $wheel['rounds'] ) > 1 ) : ?>
				<details class="mm-wheel__details">
					<summary><?php esc_html_e( 'Rounds', 'money-maker' ); ?></summary>
					<?php $this->render_wheel_rounds( $wheel ); ?>
				</details>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The contracts a wheel currently has open, and what they commit.
	 *
	 * @param array<string,mixed> $wheel
	 */
	private function render_wheel_open_positions( array $wheel ): void {
		if ( empty( $wheel['open_contracts'] ) ) {
			return;
		}

		$currency = (string) $wheel['currency'];
		$today    = gmdate( 'Y-m-d' );
		?>
		<ul class="mm-wheel__open">
			<?php foreach ( $wheel['open_contracts'] as $line ) : ?>
				<?php
				$days     = (int) round( ( strtotime( $line['expiry'] . ' 00:00:00 UTC' ) - strtotime( $today . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS );
				$notional = (float) $line['strike'] * (float) $line['quantity'] * (float) $line['multiplier'];
				?>
				<li>
					<span class="mm-pill mm-pill--<?php echo 'P' === $line['right'] ? 'put' : 'call'; ?>">
						<?php echo esc_html( empty( $line['long'] ) ? __( 'written', 'money-maker' ) : __( 'held', 'money-maker' ) ); ?>
						<?php echo esc_html( 'P' === $line['right'] ? __( 'put', 'money-maker' ) : __( 'call', 'money-maker' ) ); ?>
					</span>
					<?php
					printf(
						/* translators: 1: contract count, 2: strike, 3: expiry date */
						esc_html__( '%1$s × %2$s strike, expires %3$s', 'money-maker' ),
						esc_html( self::fmt_qty( (float) $line['quantity'] ) ),
						esc_html( self::fmt_money( (float) $line['strike'], $currency ) ),
						esc_html( (string) $line['expiry'] )
					);
					?>
					<span class="mm-muted">
						<?php
						if ( $days >= 0 ) {
							printf(
								/* translators: %d: days to expiry */
								esc_html( _n( '(%d day left)', '(%d days left)', $days, 'money-maker' ) ),
								$days
							);
						} else {
							esc_html_e( '(past expiry — not yet settled in the feed)', 'money-maker' );
						}

						if ( 'P' === $line['right'] && empty( $line['long'] ) ) {
							echo ' · ';
							printf(
								/* translators: %s: cash amount */
								esc_html__( '%s of cash committed', 'money-maker' ),
								esc_html( self::fmt_money( $notional, $currency ) )
							);
						}
						?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * A wheel's full ledger, newest last, with the running cash balance.
	 *
	 * @param array<string,mixed> $wheel
	 */
	private function render_wheel_ledger( array $wheel ): void {
		$currency = (string) $wheel['currency'];
		?>
		<table class="widefat striped mm-table mm-table--nested">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'money-maker' ); ?></th>
					<th><?php esc_html_e( 'Action', 'money-maker' ); ?></th>
					<th><?php esc_html_e( 'Detail', 'money-maker' ); ?></th>
					<th class="mm-num"><?php esc_html_e( 'Cash', 'money-maker' ); ?></th>
					<th class="mm-num"><?php esc_html_e( 'Running', 'money-maker' ); ?></th>
					<th class="mm-num"><?php esc_html_e( 'Shares', 'money-maker' ); ?></th>
					<th><?php esc_html_e( 'Questrade said', 'money-maker' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $wheel['ledger'] as $row ) : ?>
					<?php $event = $row['event']; ?>
					<tr>
						<td><?php echo esc_html( (string) $event['date'] ); ?></td>
						<td>
							<span class="mm-wheel__tag mm-wheel__tag--<?php echo esc_attr( MM_Wheels::type_tone( (string) $event['type'] ) ); ?>">
								<?php echo esc_html( MM_Wheels::type_label( (string) $event['type'] ) ); ?>
							</span>
						</td>
						<td>
							<?php echo esc_html( (string) $event['detail'] ); ?>
							<?php if ( '' !== $event['contract'] ) : ?>
								<span class="mm-muted mm-block"><?php echo esc_html( (string) $event['contract'] ); ?></span>
							<?php endif; ?>
						</td>
						<td class="mm-num">
							<?php echo wp_kses_post( abs( (float) $event['cash'] ) < 0.005 ? '<span class="mm-muted">&mdash;</span>' : self::gain_cell( (float) $event['cash'], null, $currency ) ); ?>
						</td>
						<td class="mm-num"><?php echo esc_html( self::fmt_money( (float) $row['running_cash'], $currency ) ); ?></td>
						<td class="mm-num"><?php echo esc_html( self::fmt_qty( (float) $row['shares'] ) ); ?></td>
						<td class="mm-log__detail"><?php echo esc_html( (string) $event['raw'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * The wheel broken into rounds: flat to flat.
	 *
	 * @param array<string,mixed> $wheel
	 */
	private function render_wheel_rounds( array $wheel ): void {
		$currency = (string) $wheel['currency'];
		?>
		<table class="widefat striped mm-table mm-table--nested">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Round', 'money-maker' ); ?></th>
					<th><?php esc_html_e( 'Opened', 'money-maker' ); ?></th>
					<th><?php esc_html_e( 'Closed', 'money-maker' ); ?></th>
					<th class="mm-num"><?php esc_html_e( 'Days', 'money-maker' ); ?></th>
					<th class="mm-num"><?php esc_html_e( 'Entries', 'money-maker' ); ?></th>
					<th class="mm-num"><?php esc_html_e( 'Net cash', 'money-maker' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $wheel['rounds'] as $index => $round ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( (int) $index + 1 ) ); ?></td>
						<td><?php echo esc_html( (string) $round['opened'] ); ?></td>
						<td>
							<?php echo esc_html( $round['closed'] ? (string) $round['closed'] : __( 'still open', 'money-maker' ) ); ?>
						</td>
						<td class="mm-num"><?php echo esc_html( (string) $round['days'] ); ?></td>
						<td class="mm-num"><?php echo esc_html( (string) $round['events'] ); ?></td>
						<td class="mm-num"><?php echo wp_kses_post( self::gain_cell( (float) $round['net_cash'], null, $currency ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * The wheel playbook, carried over verbatim from the standalone tracker.
	 */
	private function render_playbook(): void {
		?>
		<div class="mm-card">
			<details class="mm-wheel__details">
				<summary><?php esc_html_e( 'Wheel discipline — the playbook', 'money-maker' ); ?></summary>
				<p class="mm-muted">
					<?php esc_html_e( 'The rules exist so the decision is made before the money is on the line. Read the relevant section before you trade, not after.', 'money-maker' ); ?>
				</p>

				<div class="mm-quickref">
					<?php foreach ( MM_Wheel_Playbook::quick_reference() as $row ) : ?>
						<div>
							<strong><?php echo esc_html( $row[0] ); ?></strong>
							<span><?php echo esc_html( $row[1] ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>

				<?php foreach ( MM_Wheel_Playbook::sections() as $index => $section ) : ?>
					<div class="mm-playbook">
						<h3><?php echo esc_html( $section['title'] ); ?></h3>
						<?php foreach ( $section['rules'] as $rule_index => $rule ) : ?>
							<div class="mm-playbook__rule">
								<span class="mm-playbook__num"><?php echo esc_html( ( (int) $index + 1 ) . '.' . ( (int) $rule_index + 1 ) ); ?></span>
								<span><?php echo esc_html( $rule ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
			</details>
		</div>
		<?php
	}

	/**
	 * One metric tile.
	 *
	 * @param string $tone '' | 'pos' | 'neg' | 'flat'
	 */
	private function metric( string $label, string $value, string $tone = '', string $hint = '' ): void {
		?>
		<div class="mm-metric"<?php echo '' === $hint ? '' : ' title="' . esc_attr( $hint ) . '"'; ?>>
			<span class="mm-metric__label"><?php echo esc_html( $label ); ?></span>
			<span class="mm-metric__value<?php echo '' === $tone ? '' : ' mm-gain mm-gain--' . esc_attr( $tone ); ?>">
				<?php echo esc_html( $value ); ?>
			</span>
		</div>
		<?php
	}

	/** Colour tone for a signed figure. */
	private static function tone( float $amount ): string {
		return $amount > 0.004 ? 'pos' : ( $amount < -0.004 ? 'neg' : 'flat' );
	}

	/**
	 * Realized Gains screen: dispositions by tax year, realised-P&L chart,
	 * review items, and superficial-loss warnings. Non-registered accounts only.
	 */
	public function render_gains(): void {
		$this->guard();
		$this->open( 'gains' );

		if ( $this->require_tables() ) {
			$this->close();
			return;
		}

		$accounts = MM_Accounts::non_registered_numbers();

		if ( empty( $accounts ) ) {
			echo '<div class="mm-card"><p class="mm-muted">'
				. esc_html__( 'No non-registered accounts are synced. ACB and realized-gain reporting only applies to Cash and Margin accounts.', 'money-maker' )
				. '</p></div>';
			$this->close();
			return;
		}

		$acb   = MM_Tax_ACB::get( $accounts );
		$years = MM_Tax_ACB::years( $acb );

		$selected_year = isset( $_GET['year'] ) ? absint( wp_unslash( $_GET['year'] ) ) : ( $years[0] ?? (int) gmdate( 'Y' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $years ) && ! in_array( $selected_year, $years, true ) ) {
			$selected_year = $years[0];
		}

		$this->render_tax_disclaimer();

		if ( $acb['missing_cad'] > 0 ) {
			echo '<div class="mm-inline-notice mm-inline-notice--warn">'
				. esc_html(
					sprintf(
						/* translators: %d: row count */
						_n(
							'%d USD activity has no CAD conversion yet — its gain is understated until you run an FX sync.',
							'%d USD activities have no CAD conversion yet — their gains are understated until you run an FX sync.',
							$acb['missing_cad'],
							'money-maker'
						),
						$acb['missing_cad']
					)
				)
				. '</div>';
		}

		foreach ( $acb['warnings'] as $warning ) {
			echo '<div class="mm-inline-notice mm-inline-notice--warn">' . esc_html( $warning ) . '</div>';
		}

		if ( empty( $years ) ) {
			echo '<div class="mm-card"><p class="mm-muted">'
				. esc_html__( 'No completed dispositions on record yet. Sell a position (or back-fill history) and it will show up here.', 'money-maker' )
				. '</p></div>';
			// Amendments belong here too: a written put that was assigned leaves
			// zero dispositions (the grant is un-done, the shares are still held)
			// while still requiring the earlier year's return to be amended.
			// Skipping the card in this branch hid it in exactly that case.
			$this->render_option_amendments_card( $acb['options']['amendments'] );
			$this->render_options_card( $acb['options'] );
			$this->render_reviews_card( $acb['reviews'] );
			$this->close();
			return;
		}
		?>
		<div class="mm-card">
			<div class="mm-card__head">
				<h2><?php esc_html_e( 'Realized profit &amp; loss by year', 'money-maker' ); ?></h2>
			</div>
			<div class="mm-chart" data-mm-chart="realizedPnl"
				data-mm-empty="<?php esc_attr_e( 'No realized gains or losses yet.', 'money-maker' ); ?>"></div>
			<form method="get" class="mm-form mm-year-picker">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::GAINS_SLUG ); ?>" />
				<label for="mm-year"><?php esc_html_e( 'Tax year', 'money-maker' ); ?></label>
				<select id="mm-year" name="year" onchange="this.form.submit()">
					<?php foreach ( $years as $year ) : ?>
						<option value="<?php echo esc_attr( (string) $year ); ?>" <?php selected( $year, $selected_year ); ?>>
							<?php echo esc_html( (string) $year ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<noscript><button type="submit" class="button"><?php esc_html_e( 'Show', 'money-maker' ); ?></button></noscript>
			</form>
		</div>

		<?php
		$rows         = MM_Tax_ACB::dispositions_for_year( $acb, $selected_year );
		$total        = 0.0;
		$option_total = 0.0;
		foreach ( $rows as $r ) {
			$total += (float) $r['gain'];

			if ( 'option' === ( $r['asset_class'] ?? 'stock' ) ) {
				$option_total += (float) $r['gain'];
			}
		}
		?>
		<div class="mm-card">
			<h2>
				<?php
				printf(
					/* translators: %d: year */
					esc_html__( 'Dispositions — %d', 'money-maker' ),
					(int) $selected_year
				);
				?>
			</h2>
			<table class="widefat striped mm-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Settled', 'money-maker' ); ?></th>
						<th><?php esc_html_e( 'Account', 'money-maker' ); ?></th>
						<th><?php esc_html_e( 'Symbol', 'money-maker' ); ?></th>
						<th class="mm-num"><?php esc_html_e( 'Quantity', 'money-maker' ); ?></th>
						<th class="mm-num"><?php esc_html_e( 'Proceeds (CAD)', 'money-maker' ); ?></th>
						<th class="mm-num"><?php esc_html_e( 'ACB (CAD)', 'money-maker' ); ?></th>
						<th class="mm-num"><?php esc_html_e( 'Gain / loss (CAD)', 'money-maker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="7" class="mm-muted"><?php esc_html_e( 'No dispositions settled in this year.', 'money-maker' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $r ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $r['date'] ); ?></td>
								<td><?php echo esc_html( MM_Accounts::label( (string) $r['account_number'] ) ); ?></td>
								<td>
									<strong><?php echo esc_html( (string) $r['symbol'] ); ?></strong>
									<?php if ( 'option' === ( $r['asset_class'] ?? 'stock' ) ) : ?>
										<span class="mm-pill mm-pill--muted"><?php esc_html_e( 'option', 'money-maker' ); ?></span>
									<?php endif; ?>
									<?php if ( ! empty( $r['flags'] ) ) : ?>
										<span class="mm-pill mm-pill--warn" title="<?php echo esc_attr( implode( ', ', $r['flags'] ) ); ?>"><?php esc_html_e( 'check', 'money-maker' ); ?></span>
									<?php endif; ?>
									<?php if ( ! empty( $r['note'] ) ) : ?>
										<span class="mm-muted mm-block"><?php echo esc_html( (string) $r['note'] ); ?></span>
									<?php endif; ?>
								</td>
								<td class="mm-num"><?php echo esc_html( self::fmt_qty( (float) $r['quantity'] ) ); ?></td>
								<td class="mm-num"><?php echo esc_html( self::fmt_cad( (float) $r['proceeds'] ) ); ?></td>
								<td class="mm-num"><?php echo esc_html( self::fmt_cad( (float) $r['acb'] ) ); ?></td>
								<td class="mm-num"><?php echo wp_kses_post( self::gain_cell( (float) $r['gain'], null ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
				<tfoot>
					<?php if ( abs( $option_total ) > 0 ) : ?>
						<tr>
							<th colspan="6"><?php esc_html_e( 'of which option contracts', 'money-maker' ); ?></th>
							<td class="mm-num"><?php echo wp_kses_post( self::gain_cell( round( $option_total, 2 ), null ) ); ?></td>
						</tr>
					<?php endif; ?>
					<tr>
						<th colspan="6"><?php esc_html_e( 'Net realized gain / loss', 'money-maker' ); ?></th>
						<td class="mm-num"><?php echo wp_kses_post( self::gain_cell( round( $total, 2 ), null ) ); ?></td>
					</tr>
				</tfoot>
			</table>
			<p class="mm-muted"><?php esc_html_e( 'Pooled average-cost basis per CRA rules. Bucketed by settlement date. 50% inclusion rate is not applied here. Option premium is included on the date the contract was written, sold, bought back or expired — see the contracts card below.', 'money-maker' ); ?></p>
		</div>

		<?php
		$this->render_superficial_loss_card( MM_Tax_Superficial_Loss::analyze( $acb, $accounts ) );
		$this->render_option_amendments_card( $acb['options']['amendments'] );
		$this->render_options_card( $acb['options'] );
		$this->render_reviews_card( $acb['reviews'] );
		$this->close();
	}

	/**
	 * Prior-year returns that assignment retroactively changed (M3f).
	 *
	 * s.49(3): once a written contract is assigned, granting it was never a
	 * disposition — so a premium already reported as a gain in an earlier
	 * calendar year has to come back out of that year's return.
	 *
	 * @param array<int,array<string,mixed>> $amendments
	 */
	private function render_option_amendments_card( array $amendments ): void {
		if ( empty( $amendments ) ) {
			return;
		}
		?>
		<div class="mm-card mm-card--warn">
			<h2><?php esc_html_e( 'Prior-year returns to amend', 'money-maker' ); ?></h2>
			<div class="mm-inline-notice mm-inline-notice--warn">
				<?php esc_html_e( 'These contracts were written in one calendar year and assigned in another. The premium was a capital gain in the year it was written, but assignment cancels that gain and rolls the premium into the underlying instead — so the earlier year has been reported too high and needs an adjustment request. The tables on this screen already show the corrected position.', 'money-maker' ); ?>
			</div>
			<table class="widefat striped mm-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Contract', 'money-maker' ); ?></th>
						<th><?php esc_html_e( 'Account', 'money-maker' ); ?></th>
						<th><?php esc_html_e( 'Written', 'money-maker' ); ?></th>
						<th><?php esc_html_e( 'Assigned', 'money-maker' ); ?></th>
						<th class="mm-num"><?php esc_html_e( 'Premium removed (CAD)', 'money-maker' ); ?></th>
						<th><?php esc_html_e( 'Year to amend', 'money-maker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $amendments as $a ) : ?>
						<tr>
							<td><strong><?php echo esc_html( (string) $a['symbol'] ); ?></strong></td>
							<td><?php echo esc_html( MM_Accounts::label( (string) $a['account_number'] ) ); ?></td>
							<td><?php echo esc_html( (string) $a['grant_date'] ); ?></td>
							<td><?php echo esc_html( (string) $a['event_date'] ); ?></td>
							<td class="mm-num"><?php echo esc_html( self::fmt_cad( (float) $a['amount'] ) ); ?></td>
							<td><strong><?php echo esc_html( (string) $a['grant_year'] ); ?></strong></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Option contracts (M3f): one row per contract with its CRA outcome, plus an
	 * expandable lifecycle showing exactly what Questrade reported and what the
	 * engine did with it.
	 *
	 * Unlike the pre-M3f cash-flow card, the realised figures here ARE already in
	 * the disposition table above — this card explains them rather than standing
	 * apart from them.
	 *
	 * @param array<string,mixed> $options MM_Tax_ACB result's `options` bucket.
	 */
	private function render_options_card( array $options ): void {
		$contracts = $options['contracts'] ?? array();

		if ( empty( $contracts ) ) {
			return;
		}

		$totals = $options['totals'] ?? array();
		?>
		<div class="mm-card">
			<div class="mm-card__head">
				<h2><?php esc_html_e( 'Option contracts', 'money-maker' ); ?></h2>
				<span class="mm-pill mm-pill--muted">
					<?php
					printf(
						/* translators: %d: contract count */
						esc_html( _n( '%d contract', '%d contracts', count( $contracts ), 'money-maker' ) ),
						count( $contracts )
					);
					?>
				</span>
			</div>
			<div class="mm-inline-notice mm-inline-notice--warn"><?php echo esc_html( MM_Tax_Options::disclaimer() ); ?></div>
			<p class="mm-muted">
				<?php
				printf(
					/* translators: 1: premium received, 2: premium paid, 3: amount rolled into the underlying */
					esc_html__( 'Premium received %1$s · premium paid %2$s · rolled into underlying cost base / proceeds %3$s.', 'money-maker' ),
					esc_html( self::fmt_cad( (float) ( $totals['premium_in'] ?? 0 ) ) ),
					esc_html( self::fmt_cad( (float) ( $totals['premium_out'] ?? 0 ) ) ),
					esc_html( self::fmt_cad( (float) ( $totals['rolled'] ?? 0 ) ) )
				);
				?>
			</p>
			<table class="widefat striped mm-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Contract', 'money-maker' ); ?></th>
						<th><?php esc_html_e( 'Account', 'money-maker' ); ?></th>
						<th class="mm-num"><?php esc_html_e( 'Net contracts', 'money-maker' ); ?></th>
						<th class="mm-num"><?php esc_html_e( 'Premium in (CAD)', 'money-maker' ); ?></th>
						<th class="mm-num"><?php esc_html_e( 'Premium out (CAD)', 'money-maker' ); ?></th>
						<th class="mm-num"><?php esc_html_e( 'Realized (CAD)', 'money-maker' ); ?></th>
						<th class="mm-num"><?php esc_html_e( 'Rolled into underlying', 'money-maker' ); ?></th>
						<th><?php esc_html_e( 'Status', 'money-maker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $contracts as $c ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( (string) $c['symbol'] ); ?></strong>
								<span class="mm-muted mm-block">
									<?php
									printf(
										/* translators: 1: Put or Call, 2: strike price, 3: expiry date */
										esc_html__( '%1$s @ %2$s, expiry %3$s', 'money-maker' ),
										esc_html( 'P' === $c['right'] ? __( 'Put', 'money-maker' ) : __( 'Call', 'money-maker' ) ),
										esc_html( number_format( (float) $c['strike'], 2, '.', '' ) ),
										esc_html( (string) $c['expiry'] )
									);
									?>
								</span>
							</td>
							<td><?php echo esc_html( MM_Accounts::label( (string) $c['account_number'] ) ); ?></td>
							<td class="mm-num"><?php echo esc_html( self::fmt_qty( (float) $c['net_contracts'] ) ); ?></td>
							<td class="mm-num"><?php echo esc_html( self::fmt_cad( (float) $c['premium_in'] ) ); ?></td>
							<td class="mm-num"><?php echo esc_html( self::fmt_cad( (float) $c['premium_out'] ) ); ?></td>
							<td class="mm-num"><?php echo wp_kses_post( self::gain_cell( (float) $c['realized'], null ) ); ?></td>
							<td class="mm-num"><?php echo esc_html( 0.0 === (float) $c['rolled_into_underlying'] ? '—' : self::fmt_cad( (float) $c['rolled_into_underlying'] ) ); ?></td>
							<td>
								<?php
								$status = (string) $c['status'];
								$tone   = 'muted';
								if ( 'unresolved' === $status ) {
									$tone = 'bad';
								} elseif ( in_array( $status, array( 'assigned', 'exercised' ), true ) ) {
									$tone = 'warn';
								} elseif ( in_array( $status, array( 'closed', 'expired' ), true ) ) {
									$tone = 'ok';
								}
								?>
								<span class="mm-pill mm-pill--<?php echo esc_attr( $tone ); ?>"><?php echo esc_html( MM_Tax_Options::status_label( $status ) ); ?></span>
								<?php if ( empty( $c['priced'] ) ) : ?>
									<span class="mm-pill mm-pill--bad"><?php esc_html_e( 'no FX', 'money-maker' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<tr class="mm-detail-row">
							<td colspan="8">
								<details>
									<summary><?php esc_html_e( 'Lifecycle and raw Questrade labels', 'money-maker' ); ?></summary>
									<?php $this->render_option_events( $c['events'] ); ?>
								</details>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="mm-muted">
				<?php esc_html_e( 'Realized amounts on this card are already included in the dispositions table above. "Rolled into underlying" is not a gain — it moved into the share pool instead.', 'money-maker' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * One contract's event trail. The "Questrade said" column is deliberate: it
	 * is how the exact action/type/description strings get verified against real
	 * account data instead of being assumed.
	 *
	 * @param array<int,array<string,mixed>> $events
	 */
	private function render_option_events( array $events ): void {
		if ( empty( $events ) ) {
			echo '<p class="mm-muted">' . esc_html__( 'No lifecycle events recorded.', 'money-maker' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped mm-table mm-table--nested">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'money-maker' ); ?></th>
					<th><?php esc_html_e( 'What happened', 'money-maker' ); ?></th>
					<th class="mm-num"><?php esc_html_e( 'Contracts', 'money-maker' ); ?></th>
					<th class="mm-num"><?php esc_html_e( 'Amount (CAD)', 'money-maker' ); ?></th>
					<th><?php esc_html_e( 'Questrade said', 'money-maker' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $events as $e ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $e['date'] ); ?></td>
						<td>
							<?php echo esc_html( MM_Tax_Options::event_label( (string) $e['event'] ) ); ?>
							<?php if ( ! empty( $e['detail'] ) ) : ?>
								<span class="mm-muted mm-block"><?php echo esc_html( (string) $e['detail'] ); ?></span>
							<?php endif; ?>
						</td>
						<td class="mm-num"><?php echo esc_html( self::fmt_qty( (float) $e['quantity'] ) ); ?></td>
						<td class="mm-num">
							<?php echo null === $e['amount'] ? '—' : wp_kses_post( self::gain_cell( (float) $e['amount'], null ) ); ?>
						</td>
						<td class="mm-muted"><?php echo esc_html( '' === $e['raw'] ? '—' : (string) $e['raw'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Superficial-loss warnings section.
	 *
	 * @param array<int,array<string,mixed>> $warnings
	 */
	private function render_superficial_loss_card( array $warnings ): void {
		?>
		<div class="mm-card">
			<h2><?php esc_html_e( 'Superficial-loss warnings', 'money-maker' ); ?></h2>
			<div class="mm-inline-notice mm-inline-notice--warn"><?php echo esc_html( MM_Tax_Superficial_Loss::disclaimer() ); ?></div>
			<?php if ( empty( $warnings ) ) : ?>
				<p class="mm-muted"><?php esc_html_e( 'No realized loss on record was followed by a repurchase inside the 61-day window.', 'money-maker' ); ?></p>
			<?php else : ?>
				<table class="widefat striped mm-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Sale', 'money-maker' ); ?></th>
							<th><?php esc_html_e( 'Symbol', 'money-maker' ); ?></th>
							<th class="mm-num"><?php esc_html_e( 'Loss (CAD)', 'money-maker' ); ?></th>
							<th class="mm-num"><?php esc_html_e( 'Denied (CAD)', 'money-maker' ); ?></th>
							<th class="mm-num"><?php esc_html_e( 'Allowed (CAD)', 'money-maker' ); ?></th>
							<th class="mm-num"><?php esc_html_e( 'ACB add / share', 'money-maker' ); ?></th>
							<th><?php esc_html_e( 'Window', 'money-maker' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $warnings as $w ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $w['sale_date'] ); ?></td>
								<td><strong><?php echo esc_html( (string) $w['symbol'] ); ?></strong></td>
								<td class="mm-num"><?php echo esc_html( self::fmt_cad( (float) $w['loss'] ) ); ?></td>
								<td class="mm-num"><?php echo esc_html( self::fmt_cad( (float) $w['denied'] ) ); ?></td>
								<td class="mm-num"><?php echo esc_html( self::fmt_cad( (float) $w['allowed'] ) ); ?></td>
								<td class="mm-num"><?php echo esc_html( self::fmt_cad( (float) $w['acb_bump_per_share'] ) ); ?></td>
								<td class="mm-muted"><?php echo esc_html( $w['window_start'] . ' → ' . $w['window_end'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="mm-muted"><?php esc_html_e( 'The denied loss is added, pro-rata, to the ACB of the shares still held at the end of the window. Amounts here are not folded into the tables above.', 'money-maker' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Activities the ACB engine could not classify automatically.
	 *
	 * @param array<int,array<string,mixed>> $reviews
	 */
	private function render_reviews_card( array $reviews ): void {
		if ( empty( $reviews ) ) {
			return;
		}
		?>
		<div class="mm-card mm-card--danger">
			<h2><?php esc_html_e( 'Needs your review', 'money-maker' ); ?></h2>
			<p class="mm-muted">
				<?php esc_html_e( 'These activities carry a share quantity but are not plain buys or sells (transfers-in, corporate actions, option assignments). The ACB engine leaves them alone — enter a matching adjustment on the Adjustments tab if they change your cost base.', 'money-maker' ); ?>
			</p>
			<table class="widefat striped mm-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'money-maker' ); ?></th>
						<th><?php esc_html_e( 'Account', 'money-maker' ); ?></th>
						<th><?php esc_html_e( 'Symbol', 'money-maker' ); ?></th>
						<th><?php esc_html_e( 'What Questrade called it', 'money-maker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $reviews as $rev ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $rev['date'] ); ?></td>
							<td><?php echo esc_html( MM_Accounts::label( (string) $rev['account_number'] ) ); ?></td>
							<td><strong><?php echo esc_html( (string) $rev['symbol'] ); ?></strong></td>
							<td class="mm-muted"><?php echo esc_html( (string) $rev['description'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Adjustments screen: the manual ACB corrections editor + list (M3a).
	 */
	public function render_adjustments(): void {
		$this->guard();
		$this->open( 'adjustments' );

		if ( $this->require_tables() ) {
			$this->close();
			return;
		}

		$accounts = MM_Accounts::all();
		$editing  = null;
		if ( isset( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$editing = MM_Manual_Adjustments::get( absint( wp_unslash( $_GET['edit'] ) ) );
		}

		$this->render_tax_disclaimer();

		if ( empty( $accounts ) ) {
			echo '<div class="mm-card"><p class="mm-muted">'
				. esc_html__( 'Sync your accounts first — an adjustment has to attach to one of them.', 'money-maker' )
				. '</p></div>';
			$this->close();
			return;
		}

		$values = wp_parse_args(
			is_array( $editing ) ? $editing : array(),
			array(
				'id'              => 0,
				'account_number' => $accounts[0]['account_number'],
				'symbol'         => '',
				'adjustment_date' => gmdate( 'Y-m-d' ),
				'kind'           => 'return_of_capital',
				'quantity_delta' => '0',
				'acb_delta'      => '0',
				'note'           => '',
			)
		);
		?>
		<div class="mm-card">
			<h2><?php echo esc_html( $values['id'] ? __( 'Edit adjustment', 'money-maker' ) : __( 'Add an adjustment', 'money-maker' ) ); ?></h2>
			<p class="mm-muted">
				<?php esc_html_e( 'Sign convention: return of capital → negative ACB change, zero quantity. Reinvested "phantom" distribution → positive ACB change (and positive quantity if new units were issued). A 2-for-1 split → positive quantity change, zero ACB change.', 'money-maker' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mm-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( MM_Manual_Adjustments::ACTION_SAVE ); ?>" />
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $values['id'] ); ?>" />
				<?php wp_nonce_field( MM_Manual_Adjustments::ACTION_SAVE ); ?>

				<div class="mm-field">
					<label for="mm_adj_account"><?php esc_html_e( 'Account', 'money-maker' ); ?></label>
					<select id="mm_adj_account" name="account_number">
						<?php foreach ( $accounts as $account ) : ?>
							<option value="<?php echo esc_attr( (string) $account['account_number'] ); ?>" <?php selected( $account['account_number'], $values['account_number'] ); ?>>
								<?php echo esc_html( MM_Accounts::label( $account ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="mm-field">
					<label for="mm_adj_symbol"><?php esc_html_e( 'Symbol', 'money-maker' ); ?></label>
					<input type="text" id="mm_adj_symbol" name="symbol" class="regular-text"
						value="<?php echo esc_attr( (string) $values['symbol'] ); ?>" required
						autocomplete="off" spellcheck="false" style="text-transform:uppercase" />
				</div>

				<div class="mm-field">
					<label for="mm_adj_date"><?php esc_html_e( 'Effective date', 'money-maker' ); ?></label>
					<input type="date" id="mm_adj_date" name="adjustment_date"
						value="<?php echo esc_attr( (string) $values['adjustment_date'] ); ?>" required />
				</div>

				<div class="mm-field">
					<label for="mm_adj_kind"><?php esc_html_e( 'Kind', 'money-maker' ); ?></label>
					<select id="mm_adj_kind" name="kind">
						<?php foreach ( MM_Manual_Adjustments::kind_labels() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $values['kind'] ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="mm-field">
					<label for="mm_adj_qty"><?php esc_html_e( 'Quantity change', 'money-maker' ); ?></label>
					<input type="number" step="any" id="mm_adj_qty" name="quantity_delta"
						value="<?php echo esc_attr( (string) $values['quantity_delta'] ); ?>" />
				</div>

				<div class="mm-field">
					<label for="mm_adj_acb"><?php esc_html_e( 'Cost-base change (CAD)', 'money-maker' ); ?></label>
					<input type="number" step="any" id="mm_adj_acb" name="acb_delta"
						value="<?php echo esc_attr( (string) $values['acb_delta'] ); ?>" />
				</div>

				<div class="mm-field">
					<label for="mm_adj_note"><?php esc_html_e( 'Note', 'money-maker' ); ?></label>
					<textarea id="mm_adj_note" name="note" rows="2" class="large-text"><?php echo esc_textarea( (string) $values['note'] ); ?></textarea>
				</div>

				<?php submit_button( $values['id'] ? __( 'Save adjustment', 'money-maker' ) : __( 'Add adjustment', 'money-maker' ) ); ?>
				<?php if ( $values['id'] ) : ?>
					<a class="button" href="<?php echo esc_url( self::page_url( self::ADJUSTMENTS_SLUG ) ); ?>"><?php esc_html_e( 'Cancel', 'money-maker' ); ?></a>
				<?php endif; ?>
			</form>
		</div>

		<?php
		$rows = MM_Manual_Adjustments::all();
		?>
		<div class="mm-card">
			<h2><?php esc_html_e( 'Adjustments on record', 'money-maker' ); ?></h2>
			<?php if ( empty( $rows ) ) : ?>
				<p class="mm-muted"><?php esc_html_e( 'None yet.', 'money-maker' ); ?></p>
			<?php else : ?>
				<table class="widefat striped mm-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'money-maker' ); ?></th>
							<th><?php esc_html_e( 'Account', 'money-maker' ); ?></th>
							<th><?php esc_html_e( 'Symbol', 'money-maker' ); ?></th>
							<th><?php esc_html_e( 'Kind', 'money-maker' ); ?></th>
							<th class="mm-num"><?php esc_html_e( 'Δ Qty', 'money-maker' ); ?></th>
							<th class="mm-num"><?php esc_html_e( 'Δ ACB (CAD)', 'money-maker' ); ?></th>
							<th><?php esc_html_e( 'Note', 'money-maker' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $row['adjustment_date'] ); ?></td>
								<td><?php echo esc_html( MM_Accounts::label( (string) $row['account_number'] ) ); ?></td>
								<td><strong><?php echo esc_html( (string) $row['symbol'] ); ?></strong></td>
								<td><?php echo esc_html( MM_Manual_Adjustments::kind_label( (string) $row['kind'] ) ); ?></td>
								<td class="mm-num"><?php echo esc_html( self::fmt_qty( (float) $row['quantity_delta'] ) ); ?></td>
								<td class="mm-num"><?php echo esc_html( self::fmt_cad( (float) $row['acb_delta'] ) ); ?></td>
								<td class="mm-muted"><?php echo esc_html( (string) $row['note'] ); ?></td>
								<td class="mm-row-actions">
									<a href="<?php echo esc_url( add_query_arg( 'edit', (int) $row['id'], self::page_url( self::ADJUSTMENTS_SLUG ) ) ); ?>"><?php esc_html_e( 'Edit', 'money-maker' ); ?></a>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"
										onsubmit="return window.confirm( '<?php echo esc_js( __( 'Delete this adjustment?', 'money-maker' ) ); ?>' );">
										<input type="hidden" name="action" value="<?php echo esc_attr( MM_Manual_Adjustments::ACTION_DELETE ); ?>" />
										<input type="hidden" name="id" value="<?php echo esc_attr( (string) $row['id'] ); ?>" />
										<?php wp_nonce_field( MM_Manual_Adjustments::ACTION_DELETE ); ?>
										<button type="submit" class="button-link mm-delete-link"><?php esc_html_e( 'Delete', 'money-maker' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
		$this->close();
	}

	/* ---------------------------------------------------------------------
	 * Milestone 3 — shared helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Print the "not tax advice" disclaimer. Called on every tax screen.
	 */
	private function render_tax_disclaimer(): void {
		echo '<div class="mm-inline-notice">'
			. esc_html__( 'This tool assists with tax reporting — it does not file your taxes and is not tax advice. Pooled ACB, realized gains, and superficial-loss checks are mechanical estimates from your synced data. You are responsible for what you file; review with your accountant.', 'money-maker' )
			. '</div>';
	}

	/**
	 * "Tables missing" guard for the M3 screens. Returns true when it printed a
	 * notice and the caller should stop.
	 */
	private function require_tables(): bool {
		if ( MM_DB::is_installed() ) {
			return false;
		}

		echo '<div class="mm-inline-notice mm-inline-notice--bad">'
			. esc_html__( 'The custom tables are missing. Deactivate and reactivate the plugin to create them.', 'money-maker' )
			. '</div>';

		return true;
	}

	/**
	 * Format a CAD amount, e.g. "1,234.56" or "-42.00".
	 */
	private static function fmt_cad( float $amount ): string {
		return number_format( $amount, 2, '.', ',' );
	}

	/**
	 * Format an amount in a named currency, e.g. "US$1,204.50" (M4f). Null
	 * renders as an em dash — a figure that could not be established is shown
	 * as missing, never as zero.
	 */
	private static function fmt_money( ?float $amount, string $currency = '' ): string {
		return MM_Money::fmt( $amount, $currency );
	}

	/**
	 * A currency pill for a line whose currency is not the display currency —
	 * the "mark the odd one out" half of the M4f currency decision.
	 */
	private static function currency_pill( string $currency ): string {
		if ( $currency === MM_Money::display_currency() ) {
			return '';
		}

		return '<span class="mm-pill mm-pill--fx">' . esc_html( $currency ) . '</span>';
	}

	/**
	 * Format a share quantity: up to 4 dp, trailing zeros trimmed.
	 */
	private static function fmt_qty( float $qty ): string {
		$s = number_format( $qty, 4, '.', ',' );
		return false !== strpos( $s, '.' ) ? rtrim( rtrim( $s, '0' ), '.' ) : $s;
	}

	/**
	 * A coloured gain/loss cell, optionally with a percentage and a currency
	 * prefix. Returns markup limited to a span + text (safe for wp_kses_post at
	 * the call site).
	 */
	private static function gain_cell( ?float $amount, ?float $pct, string $currency = '' ): string {
		if ( null === $amount ) {
			return '<span class="mm-muted">&mdash;</span>';
		}

		$tone = $amount > 0.004 ? 'pos' : ( $amount < -0.004 ? 'neg' : 'flat' );
		$text = '' === $currency ? self::fmt_cad( $amount ) : MM_Money::fmt( $amount, $currency );
		if ( null !== $pct ) {
			$text .= ' (' . number_format( $pct, 1, '.', ',' ) . '%)';
		}

		return '<span class="mm-gain mm-gain--' . esc_attr( $tone ) . '">' . esc_html( $text ) . '</span>';
	}

	/* ---------------------------------------------------------------------
	 * Dashboard building blocks
	 * ------------------------------------------------------------------- */

	/**
	 * Big status banner at the top of the dashboard.
	 *
	 * @param array|null $bundle      Decrypted token bundle, or null.
	 * @param string     $environment Configured environment.
	 * @param bool       $crypto_ok   Whether an encryption key is configured.
	 * @param bool       $connected   Whether a matching-environment token is stored.
	 */
	private function render_status_hero( ?array $bundle, string $environment, bool $crypto_ok, bool $connected ): void {
		if ( $connected ) {
			$seconds_left = $bundle['expires_at'] - time();
			$tone         = 'ok';
			$headline     = __( 'Connected to Questrade', 'money-maker' );
			$detail       = $seconds_left > 0
				? sprintf(
					/* translators: %s: human time difference, e.g. "12 mins" */
					__( 'Access token valid for about %s. It refreshes automatically before it expires.', 'money-maker' ),
					human_time_diff( time(), $bundle['expires_at'] )
				)
				: __( 'Access token expired — it refreshes on the next API call.', 'money-maker' );
		} elseif ( null !== $bundle ) {
			$tone     = 'warn';
			$headline = __( 'Environment mismatch', 'money-maker' );
			$detail   = sprintf(
				/* translators: 1: stored environment, 2: configured environment */
				__( 'The stored token is for %1$s but this site is set to %2$s. Paste a fresh %2$s token on the Connection screen.', 'money-maker' ),
				$bundle['environment'],
				$environment
			);
		} else {
			$tone     = 'warn';
			$headline = __( 'Not connected yet', 'money-maker' );
			$detail   = $crypto_ok
				? __( 'Paste a Questrade refresh token on the Connection screen to finish setup.', 'money-maker' )
				: __( 'Generate an encryption key on the Settings screen, then connect Questrade.', 'money-maker' );
		}
		?>
		<div class="mm-hero mm-hero--<?php echo esc_attr( $tone ); ?>">
			<div class="mm-hero__body">
				<h2 class="mm-hero__headline"><?php echo esc_html( $headline ); ?></h2>
				<p class="mm-hero__detail"><?php echo esc_html( $detail ); ?></p>
			</div>
			<div class="mm-hero__meta">
				<span class="mm-pill mm-pill--<?php echo 'live' === $environment ? 'live' : 'muted'; ?>">
					<?php echo esc_html( 'live' === $environment ? __( 'Live', 'money-maker' ) : __( 'Practice', 'money-maker' ) ); ?>
				</span>
				<?php if ( $connected ) : ?>
					<span class="mm-hero__host"><?php echo esc_html( (string) wp_parse_url( $bundle['api_server'], PHP_URL_HOST ) ); ?></span>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Ordered setup checklist shown until the plugin is fully connected.
	 *
	 * @param bool       $crypto_ok   Whether an encryption key is configured.
	 * @param array|null $bundle      Decrypted token bundle, or null.
	 * @param string     $environment Configured environment.
	 */
	private function render_setup_checklist( bool $crypto_ok, ?array $bundle, string $environment ): void {
		$connected = ( null !== $bundle && $bundle['environment'] === $environment );

		$steps = array(
			array(
				'done'  => $crypto_ok,
				'label' => __( 'Generate an encryption key', 'money-maker' ),
				'url'   => self::page_url( self::SETTINGS_SLUG ),
				'cta'   => __( 'Open Settings', 'money-maker' ),
			),
			array(
				'done'  => true,
				'label' => sprintf(
					/* translators: %s: environment name */
					__( 'Choose an environment (currently %s)', 'money-maker' ),
					$environment
				),
				'url'   => self::page_url( self::SETTINGS_SLUG ),
				'cta'   => __( 'Change', 'money-maker' ),
			),
			array(
				'done'  => $connected,
				'label' => __( 'Paste a Questrade refresh token', 'money-maker' ),
				'url'   => self::page_url( self::CONNECTION_SLUG ),
				'cta'   => __( 'Open Connection', 'money-maker' ),
			),
		);
		?>
		<div class="mm-card">
			<h2><?php esc_html_e( 'Finish setup', 'money-maker' ); ?></h2>
			<ol class="mm-checklist">
				<?php foreach ( $steps as $step ) : ?>
					<li class="mm-checklist__item <?php echo $step['done'] ? 'is-done' : 'is-todo'; ?>">
						<span class="mm-checklist__mark" aria-hidden="true"><?php echo $step['done'] ? '✓' : ''; ?></span>
						<span class="mm-checklist__label"><?php echo esc_html( $step['label'] ); ?></span>
						<?php if ( ! $step['done'] ) : ?>
							<a class="button button-small" href="<?php echo esc_url( $step['url'] ); ?>"><?php echo esc_html( $step['cta'] ); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>
		<?php
	}

	/**
	 * Dashboard card summarising the Questrade connection.
	 *
	 * @param array|null $bundle      Decrypted token bundle, or null.
	 * @param string     $environment Configured environment.
	 */
	private function render_connection_card( ?array $bundle, string $environment ): void {
		if ( null === $bundle ) {
			$pill = array( 'bad', __( 'Not connected', 'money-maker' ) );
		} elseif ( $bundle['environment'] !== $environment ) {
			$pill = array( 'warn', __( 'Env mismatch', 'money-maker' ) );
		} elseif ( $bundle['expires_at'] - time() > 0 ) {
			$pill = array( 'ok', __( 'Active', 'money-maker' ) );
		} else {
			$pill = array( 'warn', __( 'Refresh due', 'money-maker' ) );
		}
		?>
		<div class="mm-card mm-card--link">
			<div class="mm-card__head">
				<h3><?php esc_html_e( 'Connection', 'money-maker' ); ?></h3>
				<span class="mm-pill mm-pill--<?php echo esc_attr( $pill[0] ); ?>"><?php echo esc_html( $pill[1] ); ?></span>
			</div>
			<p class="mm-muted">
				<?php
				echo null === $bundle
					? esc_html__( 'No refresh token stored yet.', 'money-maker' )
					: esc_html( sprintf(
						/* translators: %s: masked refresh token */
						__( 'Refresh token on file: %s', 'money-maker' ),
						MM_Token_Store::mask( $bundle['refresh_token'] )
					) );
				?>
			</p>
			<a href="<?php echo esc_url( self::page_url( self::CONNECTION_SLUG ) ); ?>"><?php esc_html_e( 'Manage connection →', 'money-maker' ); ?></a>
		</div>
		<?php
	}

	/**
	 * Dashboard card summarising encryption-key status.
	 */
	private function render_encryption_card(): void {
		$source      = MM_Crypto::key_source();
		$fingerprint = MM_Crypto::key_fingerprint();

		if ( 'none' === $source ) {
			$pill = array( 'bad', __( 'Not set', 'money-maker' ) );
		} else {
			$pill = array( 'ok', 'constant' === $source ? __( 'wp-config', 'money-maker' ) : __( 'Key file', 'money-maker' ) );
		}
		?>
		<div class="mm-card mm-card--link">
			<div class="mm-card__head">
				<h3><?php esc_html_e( 'Encryption', 'money-maker' ); ?></h3>
				<span class="mm-pill mm-pill--<?php echo esc_attr( $pill[0] ); ?>"><?php echo esc_html( $pill[1] ); ?></span>
			</div>
			<p class="mm-muted">
				<?php
				echo $fingerprint
					? esc_html( sprintf(
						/* translators: %s: key fingerprint */
						__( 'Key fingerprint %s', 'money-maker' ),
						$fingerprint
					) )
					: esc_html__( 'Tokens cannot be stored until a key exists.', 'money-maker' );
				?>
			</p>
			<a href="<?php echo esc_url( self::page_url( self::SETTINGS_SLUG ) ); ?>"><?php esc_html_e( 'Manage key →', 'money-maker' ); ?></a>
		</div>
		<?php
	}

	/**
	 * Dashboard card summarising the active environment.
	 *
	 * @param string $environment Configured environment.
	 */
	private function render_environment_card( string $environment ): void {
		?>
		<div class="mm-card mm-card--link">
			<div class="mm-card__head">
				<h3><?php esc_html_e( 'Environment', 'money-maker' ); ?></h3>
				<span class="mm-pill mm-pill--<?php echo 'live' === $environment ? 'live' : 'muted'; ?>">
					<?php echo esc_html( 'live' === $environment ? __( 'Live', 'money-maker' ) : __( 'Practice', 'money-maker' ) ); ?>
				</span>
			</div>
			<p class="mm-muted">
				<?php
				echo 'live' === $environment
					? esc_html__( 'Talking to your real Questrade account.', 'money-maker' )
					: esc_html__( 'Talking to practicelogin.questrade.com — safe for development.', 'money-maker' );
				?>
			</p>
			<a href="<?php echo esc_url( self::page_url( self::SETTINGS_SLUG ) ); ?>"><?php esc_html_e( 'Switch →', 'money-maker' ); ?></a>
		</div>
		<?php
	}

	/**
	 * Dashboard card summarising data-sync health.
	 */
	private function render_sync_card(): void {
		$installed      = MM_DB::is_installed();
		$last           = $installed ? MM_Sync_Log::last_for( 'activities' ) : null;
		$activity_count = $installed ? MM_Activities::count() : 0;

		if ( ! $installed ) {
			$pill = array( 'bad', __( 'No tables', 'money-maker' ) );
		} elseif ( null === $last ) {
			$pill = array( 'warn', __( 'Never synced', 'money-maker' ) );
		} elseif ( 'error' === $last['status'] ) {
			$pill = array( 'warn', __( 'Last run failed', 'money-maker' ) );
		} else {
			$pill = array( 'ok', __( 'Synced', 'money-maker' ) );
		}
		?>
		<div class="mm-card mm-card--link">
			<div class="mm-card__head">
				<h3><?php esc_html_e( 'Data sync', 'money-maker' ); ?></h3>
				<span class="mm-pill mm-pill--<?php echo esc_attr( $pill[0] ); ?>"><?php echo esc_html( $pill[1] ); ?></span>
			</div>
			<p class="mm-muted">
				<?php
				if ( null !== $last && ! empty( $last['finished_at'] ) ) {
					printf(
						/* translators: 1: activity row count, 2: human time diff */
						esc_html__( '%1$s activities stored · last run %2$s ago', 'money-maker' ),
						esc_html( number_format_i18n( $activity_count ) ),
						esc_html( human_time_diff( strtotime( $last['finished_at'] ), time() ) )
					);
				} else {
					esc_html_e( 'Pull accounts, activities and FX rates into local tables.', 'money-maker' );
				}
				?>
			</p>
			<a href="<?php echo esc_url( self::page_url( self::SYNC_SLUG ) ); ?>"><?php esc_html_e( 'Open Data Sync →', 'money-maker' ); ?></a>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Settings building blocks
	 * ------------------------------------------------------------------- */

	/**
	 * Practice / live environment toggle.
	 */
	private function render_environment_form(): void {
		$environment = MM_Settings::instance()->get_settings()['environment'];
		?>
		<div class="mm-card">
			<h2><?php esc_html_e( 'Environment', 'money-maker' ); ?></h2>
			<p class="mm-muted">
				<?php esc_html_e( 'Questrade keeps completely separate credentials for the practice and live systems. Pick the one this site should talk to.', 'money-maker' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mm-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( MM_Settings::SAVE_ACTION ); ?>" />
				<?php wp_nonce_field( MM_Settings::SAVE_ACTION ); ?>

				<fieldset class="mm-radio-group">
					<label class="mm-radio">
						<input type="radio" name="mm_environment" value="practice" <?php checked( $environment, 'practice' ); ?> />
						<span>
							<strong><?php esc_html_e( 'Practice', 'money-maker' ); ?></strong>
							<em>practicelogin.questrade.com</em>
						</span>
					</label>
					<label class="mm-radio">
						<input type="radio" name="mm_environment" value="live" <?php checked( $environment, 'live' ); ?> />
						<span>
							<strong><?php esc_html_e( 'Live', 'money-maker' ); ?></strong>
							<em>login.questrade.com</em>
						</span>
					</label>
				</fieldset>

				<p class="mm-inline-notice mm-inline-notice--warn">
					<?php esc_html_e( 'Switching environments does not delete a stored token, but a token for one environment will not work against the other. Paste a fresh refresh token after switching.', 'money-maker' ); ?>
				</p>

				<?php submit_button( __( 'Save environment', 'money-maker' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Settings card: which currency the totals are expressed in (M4f).
	 *
	 * This is a *display* setting and nothing more. Every position keeps its own
	 * trading currency on screen, and the tax screens are always CAD because the
	 * CRA figure is CAD by law — this only decides what unit a mixed book gets
	 * added up in.
	 */
	private function render_currency_form(): void {
		$current = MM_Money::display_currency();
		?>
		<div class="mm-card">
			<h2><?php esc_html_e( 'Display currency', 'money-maker' ); ?></h2>
			<p class="mm-muted">
				<?php esc_html_e( 'Individual positions, wheels and trades always show in the currency they were traded in, with anything other than this one tagged. This setting only decides what unit the totals are added up in.', 'money-maker' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mm-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( MM_Settings::CURRENCY_ACTION ); ?>" />
				<?php wp_nonce_field( MM_Settings::CURRENCY_ACTION ); ?>

				<fieldset class="mm-radio-group">
					<label class="mm-radio">
						<input type="radio" name="mm_display_currency" value="USD" <?php checked( $current, 'USD' ); ?> />
						<span>
							<strong><?php esc_html_e( 'US dollars', 'money-maker' ); ?></strong>
							<em><?php esc_html_e( 'Default. Most of this book trades in USD.', 'money-maker' ); ?></em>
						</span>
					</label>
					<label class="mm-radio">
						<input type="radio" name="mm_display_currency" value="CAD" <?php checked( $current, 'CAD' ); ?> />
						<span>
							<strong><?php esc_html_e( 'Canadian dollars', 'money-maker' ); ?></strong>
							<em><?php esc_html_e( 'Matches the tax screens.', 'money-maker' ); ?></em>
						</span>
					</label>
				</fieldset>

				<p class="mm-inline-notice">
					<?php esc_html_e( 'Realized Gains, superficial-loss warnings and the pooled ACB stay in CAD whatever is chosen here — those are the numbers a Canadian return needs, and converting them would make them wrong.', 'money-maker' ); ?>
				</p>

				<?php submit_button( __( 'Save display currency', 'money-maker' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Encryption-key status and the generate / rotate control.
	 */
	private function render_encryption_form(): void {
		$available   = MM_Crypto::is_available();
		$source      = MM_Crypto::key_source();
		$fingerprint = MM_Crypto::key_fingerprint();
		$rotating    = ( 'file' === $source );
		$confirm     = __( 'Generate a new key? Any Questrade token already stored will need to be entered again.', 'money-maker' );
		?>
		<div class="mm-card">
			<h2><?php esc_html_e( 'Encryption key', 'money-maker' ); ?></h2>
			<p class="mm-muted">
				<?php esc_html_e( 'Questrade tokens are encrypted before they are written to the database. The key is stored outside the database and is never displayed. If you lose it, every stored token is unrecoverable and you must re-authenticate with Questrade.', 'money-maker' ); ?>
			</p>

			<?php if ( ! $available ) : ?>
				<div class="mm-inline-notice mm-inline-notice--bad">
					<?php esc_html_e( 'libsodium is not available on this server, so tokens cannot be encrypted. Ask your host to enable the PHP sodium extension before entering a token.', 'money-maker' ); ?>
				</div>
			<?php else : ?>
				<div class="mm-keyval">
					<div>
						<span class="mm-keyval__k"><?php esc_html_e( 'Status', 'money-maker' ); ?></span>
						<span class="mm-keyval__v">
							<?php
							if ( 'constant' === $source ) {
								printf(
									'<span class="mm-pill mm-pill--ok">%s</span> %s',
									esc_html__( 'Configured', 'money-maker' ),
									esc_html__( 'pinned by MM_CRYPTO_KEY in wp-config.php', 'money-maker' )
								);
							} elseif ( 'file' === $source ) {
								printf(
									'<span class="mm-pill mm-pill--ok">%s</span> <code>%s</code>',
									esc_html__( 'Configured', 'money-maker' ),
									esc_html( MM_Crypto::key_file_label() )
								);
							} else {
								printf(
									'<span class="mm-pill mm-pill--bad">%s</span> %s',
									esc_html__( 'Not configured', 'money-maker' ),
									esc_html__( 'generate a key before entering a Questrade token', 'money-maker' )
								);
							}
							?>
						</span>
					</div>
					<?php if ( $fingerprint ) : ?>
						<div>
							<span class="mm-keyval__k"><?php esc_html_e( 'Fingerprint', 'money-maker' ); ?></span>
							<span class="mm-keyval__v"><code><?php echo esc_html( $fingerprint ); ?></code></span>
						</div>
					<?php endif; ?>
				</div>

				<?php if ( 'constant' === $source ) : ?>
					<p class="mm-muted"><?php esc_html_e( 'To change the key, edit the MM_CRYPTO_KEY constant in wp-config.php.', 'money-maker' ); ?></p>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mm-form"
						<?php if ( $rotating ) : ?>onsubmit="return window.confirm( '<?php echo esc_js( $confirm ); ?>' );"<?php endif; ?>>
						<input type="hidden" name="action" value="<?php echo esc_attr( MM_Settings::KEY_ACTION ); ?>" />
						<input type="hidden" name="mm_key_op" value="<?php echo $rotating ? 'rotate' : 'generate'; ?>" />
						<?php wp_nonce_field( MM_Settings::KEY_ACTION ); ?>
						<?php
						submit_button(
							$rotating ? __( 'Generate new key (rotate)', 'money-maker' ) : __( 'Generate encryption key', 'money-maker' ),
							$rotating ? 'secondary' : 'primary',
							'submit',
							true
						);
						?>
						<?php if ( $rotating ) : ?>
							<p class="mm-inline-notice mm-inline-notice--warn">
								<?php esc_html_e( 'Rotating replaces the key immediately. Data encrypted with the old key can no longer be read. Back up the new key file afterwards.', 'money-maker' ); ?>
							</p>
						<?php endif; ?>
					</form>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Connection building blocks
	 * ------------------------------------------------------------------- */

	/**
	 * One-line token status plus access-token expiry.
	 *
	 * @param array|null $bundle      Decrypted token bundle, or null.
	 * @param string     $environment Configured environment.
	 */
	private function render_token_status( ?array $bundle, string $environment ): void {
		if ( null === $bundle ) {
			echo '<p><span class="mm-pill mm-pill--bad">' . esc_html__( 'Not connected', 'money-maker' ) . '</span> ';
			esc_html_e( 'no token stored.', 'money-maker' );
			echo '</p>';
			return;
		}

		if ( $bundle['environment'] !== $environment ) {
			echo '<p><span class="mm-pill mm-pill--warn">' . esc_html__( 'Environment mismatch', 'money-maker' ) . '</span> ';
			printf(
				/* translators: 1: stored environment, 2: configured environment */
				esc_html__( 'Stored token is for %1$s but this site is set to %2$s. Paste a fresh %2$s token.', 'money-maker' ),
				esc_html( $bundle['environment'] ),
				esc_html( $environment )
			);
			echo '</p>';
			return;
		}

		$seconds_left = $bundle['expires_at'] - time();

		echo '<p><span class="mm-pill mm-pill--ok">' . esc_html__( 'Connected', 'money-maker' ) . '</span> ';
		if ( $seconds_left > 0 ) {
			printf(
				/* translators: %s: human time difference, e.g. "12 mins" */
				esc_html__( 'access token valid for about %s.', 'money-maker' ),
				esc_html( human_time_diff( time(), $bundle['expires_at'] ) )
			);
		} else {
			esc_html_e( 'access token expired — it will refresh on the next call.', 'money-maker' );
		}
		echo '</p>';

		echo '<div class="mm-keyval">';
		printf(
			'<div><span class="mm-keyval__k">%s</span><span class="mm-keyval__v"><code>%s</code></span></div>',
			esc_html__( 'API server', 'money-maker' ),
			esc_html( (string) wp_parse_url( $bundle['api_server'], PHP_URL_HOST ) )
		);
		printf(
			'<div><span class="mm-keyval__k">%s</span><span class="mm-keyval__v">%s</span></div>',
			esc_html__( 'Obtained', 'money-maker' ),
			$bundle['obtained_at']
				? esc_html( sprintf(
					/* translators: %s: human time difference */
					__( '%s ago', 'money-maker' ),
					human_time_diff( $bundle['obtained_at'], time() )
				) )
				: '&mdash;'
		);
		echo '</div>';
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
		<div class="mm-card">
			<h2><?php esc_html_e( 'Recovery history', 'money-maker' ); ?></h2>
			<p class="mm-muted">
				<?php esc_html_e( 'The last few refresh tokens, kept only so a broken chain can be recovered by hand. Values are masked; the plugin cannot show them in full.', 'money-maker' ); ?>
			</p>
			<ul class="mm-history">
				<?php foreach ( $history as $entry ) : ?>
					<li>
						<code><?php echo esc_html( $entry['masked'] ); ?></code>
						<?php if ( $entry['stored_at'] ) : ?>
							<span class="mm-muted">
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
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------- */

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

	/* ---------------------------------------------------------------------
	 * Shared chrome + notices
	 * ------------------------------------------------------------------- */

	/**
	 * Admin URL for one of this plugin's screens.
	 *
	 * @param string $slug Screen slug (a *_SLUG constant).
	 */
	public static function page_url( string $slug ): string {
		return add_query_arg( array( 'page' => $slug ), admin_url( 'admin.php' ) );
	}

	/**
	 * Stash queued notices in a private transient and bounce to a plugin screen.
	 *
	 * We deliberately do NOT use core's `settings_errors` transient +
	 * `settings-updated` query arg: on a non-Settings screen that path renders
	 * each notice twice. A private transient rendered by render_notices() shows
	 * each exactly once.
	 *
	 * @param string $slug Screen slug to redirect back to.
	 */
	public static function redirect_with_notices( string $slug ): void {
		$notices = get_settings_errors();

		if ( ! empty( $notices ) ) {
			set_transient( self::NOTICE_TRANSIENT, $notices, MINUTE_IN_SECONDS );
		}

		wp_safe_redirect( self::page_url( $slug ) );
		exit;
	}

	/**
	 * Deny access to non-admins.
	 */
	private function guard(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'money-maker' ) );
		}
	}

	/**
	 * Open the page: wrapper, brand bar, tab nav, flash notices.
	 *
	 * @param string $active One of 'dashboard' | 'connection' | 'settings'.
	 */
	private function open( string $active ): void {
		$environment = MM_Settings::instance()->get_settings()['environment'];
		$tabs        = array(
			'dashboard'   => array( __( 'Dashboard', 'money-maker' ), self::MENU_SLUG ),
			'holdings'    => array( __( 'Holdings', 'money-maker' ), self::HOLDINGS_SLUG ),
			'wheels'      => array( __( 'Wheels', 'money-maker' ), self::WHEELS_SLUG ),
			'gains'       => array( __( 'Realized Gains', 'money-maker' ), self::GAINS_SLUG ),
			'adjustments' => array( __( 'Adjustments', 'money-maker' ), self::ADJUSTMENTS_SLUG ),
			'connection'  => array( __( 'Connection', 'money-maker' ), self::CONNECTION_SLUG ),
			'sync'        => array( __( 'Data Sync', 'money-maker' ), self::SYNC_SLUG ),
			'settings'    => array( __( 'Settings', 'money-maker' ), self::SETTINGS_SLUG ),
		);
		?>
		<div class="wrap mm-app">
			<div class="mm-topbar">
				<div class="mm-brand">
					<span class="mm-brand__mark" aria-hidden="true">$</span>
					<span class="mm-brand__text">
						<h1 class="mm-brand__name"><?php esc_html_e( 'Money Maker', 'money-maker' ); ?></h1>
						<span class="mm-brand__sub"><?php esc_html_e( 'Questrade Tracker &amp; Tax Assistant', 'money-maker' ); ?></span>
					</span>
				</div>
				<span class="mm-env mm-env--<?php echo 'live' === $environment ? 'live' : 'practice'; ?>">
					<span class="mm-env__dot" aria-hidden="true"></span>
					<?php echo esc_html( 'live' === $environment ? __( 'Live account', 'money-maker' ) : __( 'Practice', 'money-maker' ) ); ?>
				</span>
			</div>

			<nav class="mm-tabs">
				<?php foreach ( $tabs as $key => $tab ) : ?>
					<a class="mm-tab <?php echo $key === $active ? 'is-active' : ''; ?>"
						href="<?php echo esc_url( self::page_url( $tab[1] ) ); ?>">
						<?php echo esc_html( $tab[0] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<hr class="wp-header-end" />
			<div class="mm-notices"><?php $this->render_notices(); ?></div>
		<?php
	}

	/**
	 * Close the page wrapper.
	 */
	private function close(): void {
		echo '</div>';
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
