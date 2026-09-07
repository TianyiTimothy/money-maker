<?php
/**
 * Symmetric encryption for secrets at rest (libsodium secretbox).
 *
 * Milestone 1b. Encrypts short secrets — Questrade refresh / access tokens — with
 * an authenticated XSalsa20-Poly1305 box. The 32-byte key lives OUTSIDE the
 * database, resolved in this order:
 *
 *   1. `MM_CRYPTO_KEY` constant in wp-config.php   (preferred — nothing to write)
 *   2. WP_CONTENT_DIR/mm-crypto-key.php            (written from the settings page)
 *   3. not configured                             (encryption unavailable)
 *
 * Losing the key means every stored token is unrecoverable and the Questrade
 * connection must be re-authenticated. That is by design — the key is never in
 * the database, so a database dump alone never exposes a token.
 *
 * This class is a stateless utility: only static methods, no hooks, so it is
 * required directly by money-maker.php and has no register() entry point.
 *
 * @package MoneyMaker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Token encryption helpers.
 */
final class MM_Crypto {

	/** Ciphertext payload prefix — lets the on-disk format evolve unambiguously. */
	const PAYLOAD_PREFIX = 'mmc1:';

	/** Basename of the UI-written key file under wp-content. */
	const KEY_FILENAME = 'mm-crypto-key.php';

	/**
	 * Is the libsodium API available?
	 *
	 * WordPress 5.2+ bundles the sodium_compat polyfill, so on a supported
	 * install this is effectively always true — but callers must not assume it.
	 */
	public static function is_available(): bool {
		return function_exists( 'sodium_crypto_secretbox' )
			&& defined( 'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' );
	}

	/**
	 * Absolute path to the key file, whether or not it exists.
	 */
	public static function key_file_path(): string {
		/**
		 * Filter the on-disk key file location.
		 *
		 * @param string $path Default: WP_CONTENT_DIR/mm-crypto-key.php.
		 */
		return (string) apply_filters(
			'mm/crypto/key_file_path',
			WP_CONTENT_DIR . '/' . self::KEY_FILENAME
		);
	}

	/**
	 * A short, human-readable form of the key file path for the UI, e.g.
	 * "wp-content/mm-crypto-key.php".
	 */
	public static function key_file_label(): string {
		$path = self::key_file_path();

		return basename( dirname( $path ) ) . '/' . basename( $path );
	}

	/**
	 * Where the active key comes from: 'constant', 'file', or 'none'.
	 */
	public static function key_source(): string {
		if ( null !== self::key_from_constant() ) {
			return 'constant';
		}

		if ( null !== self::key_from_file() ) {
			return 'file';
		}

		return 'none';
	}

	/**
	 * Is a usable key configured?
	 */
	public static function is_configured(): bool {
		return null !== self::get_key();
	}

	/**
	 * Short, non-reversible fingerprint of the active key, so the user can tell
	 * whether the key changed between visits. Never exposes key material.
	 */
	public static function key_fingerprint(): ?string {
		$key = self::get_key();
		if ( null === $key ) {
			return null;
		}

		$hash = substr( hash( 'sha256', 'mm-fp|' . $key ), 0, 12 );
		self::wipe( $key );

		return $hash;
	}

	/**
	 * The active 32-byte key, or null if not configured / invalid.
	 */
	public static function get_key(): ?string {
		$key = self::key_from_constant();
		if ( null !== $key ) {
			return $key;
		}

		return self::key_from_file();
	}

	/**
	 * Encrypt a secret. Returns an opaque, storable string or a WP_Error.
	 *
	 * @param string $plaintext Secret to protect.
	 * @return string|WP_Error
	 */
	public static function encrypt( string $plaintext ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'mm_crypto_unavailable', __( 'libsodium is not available on this server.', 'money-maker' ) );
		}

		$key = self::get_key();
		if ( null === $key ) {
			return new WP_Error( 'mm_crypto_no_key', __( 'No encryption key is configured.', 'money-maker' ) );
		}

		try {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
		} catch ( Exception $e ) {
			return new WP_Error( 'mm_crypto_encrypt_failed', __( 'Encryption failed.', 'money-maker' ) );
		} finally {
			self::wipe( $key );
		}

		return self::PAYLOAD_PREFIX . base64_encode( $nonce . $cipher );
	}

	/**
	 * Decrypt a payload produced by encrypt(). Returns plaintext or a WP_Error.
	 *
	 * @param string $payload Ciphertext string from encrypt().
	 * @return string|WP_Error
	 */
	public static function decrypt( string $payload ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'mm_crypto_unavailable', __( 'libsodium is not available on this server.', 'money-maker' ) );
		}

		if ( 0 !== strpos( $payload, self::PAYLOAD_PREFIX ) ) {
			return new WP_Error( 'mm_crypto_bad_payload', __( 'Unrecognized ciphertext format.', 'money-maker' ) );
		}

		$key = self::get_key();
		if ( null === $key ) {
			return new WP_Error( 'mm_crypto_no_key', __( 'No encryption key is configured.', 'money-maker' ) );
		}

		$bin = base64_decode( substr( $payload, strlen( self::PAYLOAD_PREFIX ) ), true );
		$min = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;

		if ( ! is_string( $bin ) || strlen( $bin ) < $min ) {
			self::wipe( $key );
			return new WP_Error( 'mm_crypto_bad_payload', __( 'Ciphertext is truncated or corrupt.', 'money-maker' ) );
		}

		$nonce  = substr( $bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		$plain = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
		self::wipe( $key );

		if ( false === $plain ) {
			return new WP_Error(
				'mm_crypto_decrypt_failed',
				__( 'Could not decrypt — the key may have changed, or the data is corrupt.', 'money-maker' )
			);
		}

		return $plain;
	}

	/**
	 * Generate a fresh random key and write it to the key file.
	 *
	 * Fails when the key is pinned by the MM_CRYPTO_KEY constant (nothing to
	 * write) or the wp-content directory is not writable.
	 *
	 * @param bool $rotating True when this replaces an existing key.
	 * @return true|WP_Error
	 */
	public static function generate_key( bool $rotating = false ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'mm_crypto_unavailable', __( 'libsodium is not available on this server.', 'money-maker' ) );
		}

		if ( null !== self::key_from_constant() ) {
			return new WP_Error(
				'mm_crypto_constant_pinned',
				__( 'The key is defined by MM_CRYPTO_KEY in wp-config.php. Edit that constant to change it.', 'money-maker' )
			);
		}

		$file = self::key_file_path();
		$dir  = dirname( $file );

		if ( ! wp_is_writable( $dir ) ) {
			return new WP_Error(
				'mm_crypto_dir_unwritable',
				sprintf(
					/* translators: %s: absolute directory path */
					__( '%s is not writable. Create the key file by hand, or define MM_CRYPTO_KEY in wp-config.php instead.', 'money-maker' ),
					$dir
				)
			);
		}

		$key      = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		$contents = self::render_key_file( base64_encode( $key ) );
		self::wipe( $key );

		$tmp = wp_tempnam( self::KEY_FILENAME, $dir );

		if ( ! $tmp || false === file_put_contents( $tmp, $contents, LOCK_EX ) ) {
			if ( $tmp ) {
				@unlink( $tmp );
			}
			return new WP_Error( 'mm_crypto_write_failed', __( 'Could not write the key file.', 'money-maker' ) );
		}

		if ( ! @rename( $tmp, $file ) ) {
			@unlink( $tmp );
			return new WP_Error( 'mm_crypto_write_failed', __( 'Could not move the key file into place.', 'money-maker' ) );
		}

		@chmod( $file, 0600 );

		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $file, true );
		}

		/**
		 * Fires after a new key file has been written.
		 *
		 * @param bool $rotating Whether this replaced an existing key.
		 */
		do_action( 'mm/crypto/key_generated', $rotating );

		return true;
	}

	/**
	 * Delete the key file. Used by uninstall; a no-op when the key is pinned by
	 * the constant or no file exists.
	 */
	public static function delete_key_file(): void {
		$file = self::key_file_path();

		if ( file_exists( $file ) ) {
			@unlink( $file );
		}
	}

	/**
	 * Read + validate the key from the MM_CRYPTO_KEY constant.
	 */
	private static function key_from_constant(): ?string {
		if ( ! defined( 'MM_CRYPTO_KEY' ) || ! is_string( MM_CRYPTO_KEY ) || '' === MM_CRYPTO_KEY ) {
			return null;
		}

		return self::decode_key( MM_CRYPTO_KEY );
	}

	/**
	 * Read + validate the key from the on-disk key file.
	 */
	private static function key_from_file(): ?string {
		$file = self::key_file_path();

		if ( ! is_readable( $file ) ) {
			return null;
		}

		$stored = include $file;

		if ( ! is_string( $stored ) ) {
			return null;
		}

		return self::decode_key( $stored );
	}

	/**
	 * Accept a base64- or hex-encoded key and return exactly 32 raw bytes, or
	 * null if it does not decode to a valid key length.
	 */
	private static function decode_key( string $encoded ): ?string {
		$encoded = trim( $encoded );
		$want    = self::is_available() ? SODIUM_CRYPTO_SECRETBOX_KEYBYTES : 32;

		$raw = base64_decode( $encoded, true );
		if ( is_string( $raw ) && strlen( $raw ) === $want ) {
			return $raw;
		}

		if ( 1 === preg_match( '/^[0-9a-fA-F]{64}$/', $encoded ) ) {
			$raw = hex2bin( $encoded );
			if ( is_string( $raw ) && strlen( $raw ) === $want ) {
				return $raw;
			}
		}

		return null;
	}

	/**
	 * Build the PHP source for the key file.
	 */
	private static function render_key_file( string $encoded_key ): string {
		$lines = array(
			'<?php',
			'/**',
			' * Money-Maker encryption key — DO NOT COMMIT, DO NOT SHARE.',
			' *',
			' * Written by the Questrade Tracker settings page. If this file is lost,',
			' * every stored Questrade token becomes unrecoverable and the connection',
			' * must be re-authenticated. Back it up somewhere safe and private.',
			' */',
			"defined( 'ABSPATH' ) || exit;",
			'',
			'return ' . var_export( $encoded_key, true ) . ';',
			'',
		);

		return implode( "\n", $lines );
	}

	/**
	 * Best-effort wipe of a key string from memory.
	 *
	 * @param string $secret Passed by reference; emptied in place.
	 */
	private static function wipe( string &$secret ): void {
		if ( function_exists( 'sodium_memzero' ) ) {
			try {
				sodium_memzero( $secret );
				return;
			} catch ( Exception $e ) {
				// Fall through to the manual overwrite.
			}
		}

		$secret = str_repeat( "\0", strlen( $secret ) );
	}
}
