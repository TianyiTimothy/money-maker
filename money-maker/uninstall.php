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

// Transient carrying admin notices across redirects.
delete_transient( 'mm_admin_notices' );

// Out-of-database crypto key file (M1b). Kept in sync with
// MM_Crypto::KEY_FILENAME / MM_Crypto::key_file_path(). Not removed when the
// key is pinned by the MM_CRYPTO_KEY constant (there is no file to delete).
$mm_key_file = WP_CONTENT_DIR . '/mm-crypto-key.php';
if ( file_exists( $mm_key_file ) ) {
	@unlink( $mm_key_file );
}
unset( $mm_key_file );
