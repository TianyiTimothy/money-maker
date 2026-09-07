<?php
/**
 * Plugin Name:       Questrade Tracker & Tax Assistant
 * Plugin URI:        https://github.com/TianyiTimothy/money-maker
 * Description:        Pulls personal Questrade investment data into WordPress and assists with Canadian tax reporting (ACB, superficial-loss warnings). Stage 1: read-only.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Timothy Zhang
 * Author URI:        https://github.com/TianyiTimothy
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       money-maker
 *
 * @package MoneyMaker
 */

defined( 'ABSPATH' ) || exit;

define( 'MM_VERSION', '0.1.0' );
define( 'MM_PLUGIN_FILE', __FILE__ );
define( 'MM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'MM_INCLUDES_DIR', MM_PLUGIN_DIR . 'includes/' );

require_once MM_INCLUDES_DIR . 'class-mm-crypto.php';
require_once MM_INCLUDES_DIR . 'class-mm-lock.php';
require_once MM_INCLUDES_DIR . 'class-mm-token-store.php';
require_once MM_INCLUDES_DIR . 'class-mm-questrade-client.php';
require_once MM_INCLUDES_DIR . 'class-mm-settings.php';
require_once MM_INCLUDES_DIR . 'class-mm-admin.php';

/**
 * Wire up the plugin's hooks once WordPress is loaded.
 *
 * Classes are kept passive: they register their own hooks from a single
 * bootstrap() entry point called here, not from constructors.
 */
function mm_bootstrap() {
	load_plugin_textdomain( 'money-maker', false, dirname( MM_PLUGIN_BASENAME ) . '/languages' );

	MM_Settings::instance()->register();
	MM_Admin::instance()->register();
}
add_action( 'plugins_loaded', 'mm_bootstrap' );

/**
 * Activation: seed default options. Safe to run repeatedly.
 */
function mm_activate() {
	if ( false === get_option( 'mm_settings' ) ) {
		add_option( 'mm_settings', array( 'environment' => 'practice' ) );
	}
}
register_activation_hook( __FILE__, 'mm_activate' );

/**
 * Deactivation: release any held token-refresh lock so a later reactivation
 * starts clean.
 */
function mm_deactivate() {
	if ( class_exists( 'MM_Lock' ) ) {
		MM_Lock::release();
	}
}
register_deactivation_hook( __FILE__, 'mm_deactivate' );
