<?php
/**
 * Encrypted storage for the Questrade OAuth token bundle (Milestone 1c).
 *
 * Persists the *whole* token response, not just the tokens:
 *   - access_token   : bearer token for data calls (~30 min life)
 *   - refresh_token  : rolling, one-time-use; the current link in the chain
 *   - api_server     : host for all v1/* data calls — changes over time, always
 *                      read from here, never hardcoded
 *   - token_type     : "Bearer"
 *   - expires_at     : absolute unix time the access token dies (computed from
 *                      expires_in at store time)
 *   - obtained_at    : when this bundle was stored
 *   - environment    : 'practice' | 'live' — practice and live tokens must never
 *                      be mixed
 *
 * Plus a rolling history of the last REFRESH_HISTORY_MAX refresh tokens, so a
 * broken chain can be recovered by hand from the settings page.
 *
 * The entire bundle is JSON-encoded then encrypted with MM_Crypto before it
 * touches the database, and the option is stored with autoload = 'no'. A DB dump
 * without the out-of-DB key exposes nothing.
 *
 * Stateless static utility: no hooks, no register().
 *
 * @package MoneyMaker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read/write the encrypted Questrade token bundle.
 */
final class MM_Token_Store {

	/** Option holding the encrypted bundle. */
	const OPTION = 'mm_token_bundle';

	/** How many past refresh tokens to keep for manual recovery. */
	const REFRESH_HISTORY_MAX = 5;

	/**
	 * Seconds shaved off Questrade's expires_in when computing expires_at, to
	 * absorb clock skew and network latency.
	 */
	const EXPIRY_SAFETY_MARGIN = 60;

	/** Bundle keys that must be present and non-empty after a token exchange. */
	const REQUIRED_KEYS = array( 'access_token', 'refresh_token', 'api_server' );

	/**
	 * Build a bundle from a raw Questrade token response and persist it.
	 *
	 * Call this the moment a token exchange succeeds and *before* any other API
	 * call — the new refresh token must be committed before it could be lost.
	 *
	 * @param array  $response    Decoded JSON from the oauth2/token endpoint.
	 * @param string $environment 'practice' | 'live'.
	 * @return true|WP_Error
	 */
	public static function store_from_response( array $response, string $environment ) {
		foreach ( self::REQUIRED_KEYS as $key ) {
			if ( empty( $response[ $key ] ) || ! is_string( $response[ $key ] ) ) {
				return new WP_Error(
					'mm_token_response_incomplete',
					sprintf(
						/* translators: %s: missing field name */
						__( 'Questrade token response is missing "%s".', 'money-maker' ),
						$key
					)
				);
			}
		}

		$expires_in = isset( $response['expires_in'] ) ? (int) $response['expires_in'] : 1800;
		$now        = time();

		$previous = self::get();
		$history  = self::build_history( $previous, $response['refresh_token'] );

		$bundle = array(
			'access_token'   => $response['access_token'],
			'refresh_token'  => $response['refresh_token'],
			'api_server'     => untrailingslashit( $response['api_server'] ),
			'token_type'     => isset( $response['token_type'] ) ? (string) $response['token_type'] : 'Bearer',
			'expires_at'     => $now + max( 0, $expires_in - self::EXPIRY_SAFETY_MARGIN ),
			'obtained_at'    => $now,
			'environment'    => 'live' === $environment ? 'live' : 'practice',
			'refresh_history' => $history,
		);

		return self::save( $bundle );
	}

	/**
	 * Persist a fully-formed bundle (encrypt + write). Prefer
	 * store_from_response() unless you are hand-crafting the bundle.
	 *
	 * @param array $bundle Bundle array.
	 * @return true|WP_Error
	 */
	public static function save( array $bundle ) {
		$json = wp_json_encode( $bundle );

		if ( false === $json ) {
			return new WP_Error( 'mm_token_encode_failed', __( 'Could not encode the token bundle.', 'money-maker' ) );
		}

		$cipher = MM_Crypto::encrypt( $json );

		if ( is_wp_error( $cipher ) ) {
			return $cipher;
		}

		// autoload = 'no': the bundle is only needed on the API path.
		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, $cipher, '', 'no' );
		} else {
			update_option( self::OPTION, $cipher, false );
		}

		return true;
	}

	/**
	 * Decrypt and return the stored bundle, or null if none / unreadable.
	 *
	 * @return array{
	 *   access_token:string, refresh_token:string, api_server:string,
	 *   token_type:string, expires_at:int, obtained_at:int, environment:string,
	 *   refresh_history:array
	 * }|null
	 */
	public static function get(): ?array {
		$cipher = get_option( self::OPTION );

		if ( empty( $cipher ) || ! is_string( $cipher ) ) {
			return null;
		}

		$json = MM_Crypto::decrypt( $cipher );

		if ( is_wp_error( $json ) ) {
			return null;
		}

		$bundle = json_decode( $json, true );

		if ( ! is_array( $bundle ) || empty( $bundle['refresh_token'] ) ) {
			return null;
		}

		$bundle += array(
			'access_token'    => '',
			'api_server'      => '',
			'token_type'      => 'Bearer',
			'expires_at'      => 0,
			'obtained_at'     => 0,
			'environment'     => 'practice',
			'refresh_history' => array(),
		);

		$bundle['expires_at']  = (int) $bundle['expires_at'];
		$bundle['obtained_at'] = (int) $bundle['obtained_at'];

		return $bundle;
	}

	/**
	 * Is there a stored, decryptable token?
	 */
	public static function has_token(): bool {
		return null !== self::get();
	}

	/**
	 * Environment the stored token belongs to, or null if no token.
	 */
	public static function environment(): ?string {
		$bundle = self::get();

		return null === $bundle ? null : $bundle['environment'];
	}

	/**
	 * Whether the access token is still usable for at least $margin more seconds.
	 *
	 * @param int $margin Seconds of headroom to require.
	 */
	public static function access_token_is_fresh( int $margin = 0 ): bool {
		$bundle = self::get();

		return null !== $bundle && $bundle['expires_at'] - time() > $margin;
	}

	/**
	 * Masked refresh-token history for display: newest first, token material
	 * reduced to its last 4 characters.
	 *
	 * @return array<int,array{masked:string,stored_at:int}>
	 */
	public static function refresh_history_for_display(): array {
		$bundle = self::get();

		if ( null === $bundle || empty( $bundle['refresh_history'] ) ) {
			return array();
		}

		$out = array();

		foreach ( $bundle['refresh_history'] as $entry ) {
			if ( empty( $entry['token'] ) ) {
				continue;
			}
			$out[] = array(
				'masked'    => self::mask( (string) $entry['token'] ),
				'stored_at' => isset( $entry['stored_at'] ) ? (int) $entry['stored_at'] : 0,
			);
		}

		return $out;
	}

	/**
	 * Delete the stored bundle. Used when switching environments or on uninstall.
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Mask a secret to its last 4 characters for safe display.
	 */
	public static function mask( string $secret ): string {
		$len = strlen( $secret );

		if ( $len <= 4 ) {
			return str_repeat( '•', $len );
		}

		return str_repeat( '•', min( 12, $len - 4 ) ) . substr( $secret, -4 );
	}

	/**
	 * Prepend the new refresh token to the rolling history, capped at
	 * REFRESH_HISTORY_MAX. Skips a duplicate of the most recent entry.
	 *
	 * @param array|null $previous  Prior bundle, if any.
	 * @param string     $new_token Refresh token just issued.
	 * @return array<int,array{token:string,stored_at:int}>
	 */
	private static function build_history( ?array $previous, string $new_token ): array {
		$history = ( null !== $previous && ! empty( $previous['refresh_history'] ) && is_array( $previous['refresh_history'] ) )
			? $previous['refresh_history']
			: array();

		if ( empty( $history ) || ( isset( $history[0]['token'] ) && $history[0]['token'] !== $new_token ) ) {
			array_unshift(
				$history,
				array(
					'token'     => $new_token,
					'stored_at' => time(),
				)
			);
		}

		return array_slice( $history, 0, self::REFRESH_HISTORY_MAX );
	}
}
