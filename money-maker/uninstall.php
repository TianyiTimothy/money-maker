<?php
/**
 * Uninstall cleanup for the Questrade Tracker & Tax Assistant.
 *
 * Runs only when the plugin is deleted from the WordPress admin. Removes every
 * option, transient, and out-of-database file the plugin creates.
 *
 * @package MoneyMaker
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Options.
delete_option( 'mm_settings' );       // M1a: environment toggle.
delete_option( 'mm_token_bundle' );    // M1c: encrypted Questrade token bundle.
delete_option( 'mm_token_lock' );      // M1c: token-refresh lock.
delete_option( 'mm_db_version' );      // M2a: installed schema version.
delete_option( 'mm_sync_state' );      // M2d: backfill progress.
delete_option( 'mm_fx_coverage' );     // M2c: fetched FX date span.

// Scheduled sync events (deactivation clears these too; belt and braces).
wp_clear_scheduled_hook( 'mm/sync/incremental' );
wp_clear_scheduled_hook( 'mm/sync/backfill' );

// Custom tables (M2a). Load the schema class for its table list + drop helper.
require_once __DIR__ . '/includes/class-mm-db.php';
MM_DB::drop_all();

// Transients.
delete_transient( 'mm_admin_notices' ); // Admin notices across redirects.
delete_transient( 'mm_acb_cache' );     // M3b: cached pooled-ACB computation.

// Out-of-database crypto key file (M1b). Kept in sync with
// MM_Crypto::KEY_FILENAME / MM_Crypto::key_file_path(). Not removed when the
// key is pinned by the MM_CRYPTO_KEY constant (there is no file to delete).
$mm_key_file = WP_CONTENT_DIR . '/mm-crypto-key.php';
if ( file_exists( $mm_key_file ) ) {
	@unlink( $mm_key_file );
}
unset( $mm_key_file );
