<?php
/**
 * Questrade API client — OAuth token exchange + authenticated data calls.
 *
 * Milestone 1c: exchange_refresh_token() (turn a refresh token into a bundle).
 * Milestone 1d: get_valid_token() (proactive refresh under lock), request()
 *               (authenticated call with 401/429 retry), test_connection().
 *
 * Token-chain safety (see CLAUDE.md Module A):
 *   - Every refresh runs under MM_Lock. Two concurrent refreshes would kill the
 *     one-time-use refresh token chain.
 *   - The new bundle is persisted (MM_Token_Store) the instant the exchange
 *     succeeds, before any data call.
 *   - Refresh happens proactively a few minutes before expiry, not on a 401 —
 *     the 401 path is only a fallback.
 *   - api_server always comes from the stored bundle, never hardcoded.
 *
 * Stateless static utility: no hooks, no register().
 *
 * @package MoneyMaker
 */

defined( 'ABSPATH' ) || exit;

/**
 * HTTP client for Questrade OAuth + v1 endpoints.
 */
final class MM_Questrade_Client {

	/** OAuth host for each environment. api_server for data calls is dynamic. */
	const LOGIN_HOSTS = array(
		'practice' => 'https://practicelogin.questrade.com',
		'live'     => 'https://login.questrade.com',
	);

	/** Refresh the access token when it has this many seconds or fewer left. */
	const PROACTIVE_REFRESH_MARGIN = 300;

	/** Seconds to wait for the refresh lock before giving up. */
	const LOCK_WAIT = 12;

	/** Default per-request timeout (seconds). */
	const HTTP_TIMEOUT = 20;

	/** Hard cap on how long we will honour a 429 Retry-After (seconds). */
	const MAX_BACKOFF = 10;

	/**
	 * Exchange a refresh token for a full token bundle and persist it.
	 *
	 * Runs under MM_Lock so it can never race another refresh. On success the
	 * new bundle (including the freshly-issued refresh token) is committed
	 * before this returns.
	 *
	 * @param string $refresh_token Refresh token to redeem.
	 * @param string $environment   'practice' | 'live'.
	 * @return array|WP_Error The stored bundle on success.
	 */
	public static function exchange_refresh_token( string $refresh_token, string $environment ) {
		$refresh_token = trim( $refresh_token );

		if ( '' === $refresh_token ) {
			return new WP_Error( 'mm_qt_no_refresh_token', __( 'No refresh token was provided.', 'money-maker' ) );
		}

		if ( ! MM_Crypto::is_configured() ) {
			return new WP_Error(
				'mm_qt_no_crypto',
				__( 'Set up an encryption key before connecting to Questrade.', 'money-maker' )
			);
		}

		$environment = 'live' === $environment ? 'live' : 'practice';

		if ( ! MM_Lock::acquire( self::LOCK_WAIT ) ) {
			return new WP_Error(
				'mm_qt_lock_timeout',
				__( 'Another token refresh is in progress. Try again in a moment.', 'money-maker' )
			);
		}

		try {
			$response = self::post_token_request( $refresh_token, $environment );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$stored = MM_Token_Store::store_from_response( $response, $environment );

			if ( is_wp_error( $stored ) ) {
				return $stored;
			}

			return MM_Token_Store::get();
		} finally {
			MM_Lock::release();
		}
	}

	/**
	 * Return a bundle whose access token is valid for at least
	 * PROACTIVE_REFRESH_MARGIN more seconds, refreshing under lock if needed.
	 *
	 * @param bool $force Refresh even if the current token still looks fresh
	 *                    (used by the 401 fallback).
	 * @return array|WP_Error
	 */
	public static function get_valid_token( bool $force = false ) {
		$bundle = MM_Token_Store::get();

		if ( null === $bundle ) {
			return new WP_Error(
				'mm_qt_no_token',
				__( 'No Questrade token is stored. Paste a refresh token on the settings page.', 'money-maker' )
			);
		}

		$environment = self::current_environment();

		if ( $bundle['environment'] !== $environment ) {
			return new WP_Error(
				'mm_qt_env_mismatch',
				sprintf(
					/* translators: 1: stored environment, 2: configured environment */
					__( 'The stored token is for the %1$s environment but this site is set to %2$s. Paste a fresh %2$s refresh token.', 'money-maker' ),
					$bundle['environment'],
					$environment
				)
			);
		}

		if ( ! $force && $bundle['expires_at'] - time() > self::PROACTIVE_REFRESH_MARGIN ) {
			return $bundle;
		}

		if ( ! MM_Lock::acquire( self::LOCK_WAIT ) ) {
			// Someone else is likely refreshing right now. Re-read; their result
			// may already be usable.
			$fresh = MM_Token_Store::get();
			if ( null !== $fresh && ! $force && $fresh['expires_at'] - time() > 0 ) {
				return $fresh;
			}
			return new WP_Error(
				'mm_qt_lock_timeout',
				__( 'Token refresh is busy. Try again in a moment.', 'money-maker' )
			);
		}

		try {
			// Re-read inside the lock: another process may have just refreshed.
			$bundle = MM_Token_Store::get();
			if ( null === $bundle ) {
				return new WP_Error( 'mm_qt_no_token', __( 'The stored token disappeared during refresh.', 'money-maker' ) );
			}

			if ( ! $force && $bundle['expires_at'] - time() > self::PROACTIVE_REFRESH_MARGIN ) {
				return $bundle;
			}

			$response = self::post_token_request( $bundle['refresh_token'], $environment );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$stored = MM_Token_Store::store_from_response( $response, $environment );

			if ( is_wp_error( $stored ) ) {
				return $stored;
			}

			return MM_Token_Store::get();
		} finally {
			MM_Lock::release();
		}
	}

	/**
	 * Make an authenticated call against a v1 endpoint.
	 *
	 * @param string $method HTTP method, e.g. 'GET'.
	 * @param string $path   Path relative to api_server, e.g. 'v1/time'.
	 * @param array  $args   Optional: 'query' (array), 'body' (array|string),
	 *                       'headers' (array), 'timeout' (int).
	 * @return array|WP_Error Decoded JSON body on success.
	 */
	public static function request( string $method, string $path, array $args = array() ) {
		$token = self::get_valid_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$attempt      = 0;
		$max_attempts = 3;

		while ( true ) {
			++$attempt;

			$result = self::do_request( $method, $path, $args, $token );

			if ( ! is_wp_error( $result ) && ! isset( $result['__mm_retry'] ) ) {
				return $result;
			}

			if ( is_wp_error( $result ) || $attempt >= $max_attempts ) {
				return is_wp_error( $result )
					? $result
					: new WP_Error( 'mm_qt_retry_exhausted', __( 'Questrade kept asking us to retry. Giving up.', 'money-maker' ) );
			}

			if ( 401 === $result['__mm_retry'] ) {
				$token = self::get_valid_token( true );
				if ( is_wp_error( $token ) ) {
					return $token;
				}
				continue;
			}

			if ( 429 === $result['__mm_retry'] ) {
				$wait = min( self::MAX_BACKOFF, max( 1, (int) $result['__mm_retry_after'] ) );
				sleep( $wait );
				continue;
			}

			return new WP_Error( 'mm_qt_unexpected', __( 'Unexpected retry state.', 'money-maker' ) );
		}
	}

	/**
	 * "Test connection": call GET v1/time and return the parsed server time.
	 *
	 * @return array{time:string}|WP_Error
	 */
	public static function test_connection() {
		$result = self::request( 'GET', 'v1/time' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['time'] ) ) {
			return new WP_Error( 'mm_qt_bad_time', __( 'Questrade did not return a server time.', 'money-maker' ) );
		}

		return array( 'time' => (string) $result['time'] );
	}

	/**
	 * Single HTTP attempt. Returns the decoded body, a WP_Error for hard
	 * failures, or an array with '__mm_retry' set for 401/429 so request() can
	 * decide whether to retry.
	 *
	 * @param string $method
	 * @param string $path
	 * @param array  $args
	 * @param array  $token Bundle from get_valid_token().
	 * @return array|WP_Error
	 */
	private static function do_request( string $method, string $path, array $args, array $token ) {
		$url = trailingslashit( $token['api_server'] ) . ltrim( $path, '/' );

		if ( ! empty( $args['query'] ) && is_array( $args['query'] ) ) {
			$url = add_query_arg( $args['query'], $url );
		}

		$http_args = array(
			'method'  => strtoupper( $method ),
			'timeout' => isset( $args['timeout'] ) ? (int) $args['timeout'] : self::HTTP_TIMEOUT,
			'headers' => array_merge(
				array(
					'Authorization' => trim( ( $token['token_type'] ?: 'Bearer' ) . ' ' . $token['access_token'] ),
					'Accept'        => 'application/json',
				),
				isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array()
			),
		);

		if ( isset( $args['body'] ) ) {
			$http_args['body'] = $args['body'];
		}

		$response = wp_remote_request( $url, $http_args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 401 === $code ) {
			return array(
				'__mm_retry' => 401,
			);
		}

		if ( 429 === $code ) {
			return array(
				'__mm_retry'       => 429,
				'__mm_retry_after' => (int) wp_remote_retrieve_header( $response, 'retry-after' ),
			);
		}

		$decoded = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			$detail = '';
			if ( is_array( $decoded ) && ! empty( $decoded['message'] ) ) {
				$detail = (string) $decoded['message'];
			}
			return new WP_Error(
				'mm_qt_http_' . $code,
				sprintf(
					/* translators: 1: HTTP status code, 2: error detail from Questrade */
					__( 'Questrade returned HTTP %1$d. %2$s', 'money-maker' ),
					$code,
					$detail
				)
			);
		}

		if ( null === $decoded && '' !== trim( $body ) ) {
			return new WP_Error( 'mm_qt_bad_json', __( 'Questrade returned a response we could not parse.', 'money-maker' ) );
		}

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * POST to the oauth2/token endpoint and return the decoded response.
	 *
	 * @param string $refresh_token
	 * @param string $environment
	 * @return array|WP_Error
	 */
	private static function post_token_request( string $refresh_token, string $environment ) {
		$host = self::LOGIN_HOSTS[ $environment ] ?? self::LOGIN_HOSTS['practice'];
		$url  = $host . '/oauth2/token';

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => self::HTTP_TIMEOUT,
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 400 === $code || 401 === $code ) {
			return new WP_Error(
				'mm_qt_refresh_rejected',
				__( 'Questrade rejected the refresh token. It may be expired, already used, or for the other environment. Paste a fresh one.', 'money-maker' )
			);
		}

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'mm_qt_refresh_http_' . $code,
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Questrade token endpoint returned HTTP %d.', 'money-maker' ),
					$code
				)
			);
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'mm_qt_refresh_bad_json', __( 'Could not parse the token response from Questrade.', 'money-maker' ) );
		}

		return $data;
	}

	/**
	 * The environment this site is currently configured for.
	 */
	private static function current_environment(): string {
		$settings = MM_Settings::instance()->get_settings();

		return 'live' === $settings['environment'] ? 'live' : 'practice';
	}
}
