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

// Milestone 1 onward: bootstrap Auth, DB, Sync, Tax, and UI modules from here.
