<?php
/**
 * Cross-process lock for the token refresh path (Milestone 1c).
 *
 * Questrade's refresh token is rolling and one-time-use: every access-token
 * request invalidates the refresh token that produced it. If two PHP processes
 * refresh at the same time (say a cron sync and a manual "Sync now"), one of the
 * two new refresh tokens is lost the instant it is issued and the token chain is
 * permanently broken — recovery then needs a fresh token pasted in by hand.
 *
 * So every refresh must happen under this lock. Holders that die without
 * releasing (fatal error, killed worker) are taken over once the lock is older
 * than STALE_SECONDS, so a crash cannot wedge refreshes forever.
 *
 * Option-based rather than MySQL GET_LOCK() so it works on any host and can be
 * inspected/cleared from the settings page. Stored with autoload = 'no'.
 *
 * Stateless static utility: no hooks, no register(). Required directly by
 * money-maker.php.
 *
 * @package MoneyMaker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Advisory lock guarding token refresh.
 */
final class MM_Lock {

	/** Option name holding the lock timestamp + owner token. */
	const OPTION = 'mm_token_lock';

	/** A lock older than this (seconds) is assumed abandoned and taken over. */
	const STALE_SECONDS = 30;

	/** Default seconds to wait for a held lock before giving up. */
	const DEFAULT_WAIT = 12;

	/** Poll interval while waiting, in microseconds (250ms). */
	const POLL_INTERVAL_US = 250000;

	/**
	 * This process's lock token for the current request. Lets release() and
	 * is_held_by_us() tell our lock apart from one a later process took over.
	 *
	 * @var string
	 */
	private static $owner = '';

	/**
	 * Try to acquire the lock, waiting up to $max_wait seconds for a lock held
	 * by another process to clear.
	 *
	 * @param int $max_wait Seconds to wait before giving up.
	 * @return bool True if this process now holds the lock.
	 */
	public static function acquire( int $max_wait = self::DEFAULT_WAIT ): bool {
		$deadline = microtime( true ) + max( 0, $max_wait );

		do {
			if ( self::try_acquire() ) {
				return true;
			}
			usleep( self::POLL_INTERVAL_US );
		} while ( microtime( true ) < $deadline );

		// Last chance: a stale lock may have appeared while we waited.
		return self::try_acquire();
	}

	/**
	 * One non-blocking acquisition attempt. Grabs a free lock, or takes over one
	 * that has gone stale.
	 */
	private static function try_acquire(): bool {
		$owner = self::owner_token();
		$now   = time();
		$value = wp_json_encode(
			array(
				'owner'      => $owner,
				'acquired_at' => $now,
			)
		);

		// add_option() is an INSERT and fails if the row already exists, which
		// makes it a usable compare-and-set primitive.
		if ( add_option( self::OPTION, $value, '', 'no' ) ) {
			self::$owner = $owner;
			return true;
		}

		$held = self::read();

		// A live lock held by someone else — wait.
		if ( null !== $held && ( $now - $held['acquired_at'] ) < self::STALE_SECONDS ) {
			return false;
		}

		// Stale (or unparseable): clear it and race for the fresh insert. If a
		// competitor wins the race, their add_option() succeeds and ours fails.
		delete_option( self::OPTION );

		if ( add_option( self::OPTION, $value, '', 'no' ) ) {
			self::$owner = $owner;
			return true;
		}

		return false;
	}

	/**
	 * Release the lock — but only if this process still holds it. A no-op
	 * otherwise, so we never delete a lock another process took over after ours
	 * went stale.
	 */
	public static function release(): void {
		if ( '' === self::$owner ) {
			// Called defensively (e.g. from deactivation) with no lock held by
			// this request. Only clear an obviously abandoned lock.
			$held = self::read();
			if ( null === $held || ( time() - $held['acquired_at'] ) >= self::STALE_SECONDS ) {
				delete_option( self::OPTION );
			}
			return;
		}

		$held = self::read();
		if ( null !== $held && $held['owner'] === self::$owner ) {
			delete_option( self::OPTION );
		}

		self::$owner = '';
	}

	/**
	 * Whether this process currently holds the lock.
	 */
	public static function is_held_by_us(): bool {
		if ( '' === self::$owner ) {
			return false;
		}

		$held = self::read();

		return null !== $held && $held['owner'] === self::$owner;
	}

	/**
	 * Age of the current lock in seconds, or null if the lock is free.
	 */
	public static function age(): ?int {
		$held = self::read();

		return null === $held ? null : max( 0, time() - $held['acquired_at'] );
	}

	/**
	 * Read and normalise the stored lock, or null if absent / unparseable.
	 *
	 * @return array{owner:string,acquired_at:int}|null
	 */
	private static function read(): ?array {
		$raw = get_option( self::OPTION );

		if ( empty( $raw ) ) {
			return null;
		}

		$data = json_decode( is_string( $raw ) ? $raw : '', true );

		if ( ! is_array( $data ) || ! isset( $data['acquired_at'] ) ) {
			return null;
		}

		return array(
			'owner'       => isset( $data['owner'] ) ? (string) $data['owner'] : '',
			'acquired_at' => (int) $data['acquired_at'],
		);
	}

	/**
	 * A stable per-request identifier for this process's lock ownership.
	 */
	private static function owner_token(): string {
		static $token = null;

		if ( null === $token ) {
			$token = function_exists( 'wp_generate_uuid4' )
				? wp_generate_uuid4()
				: uniqid( 'mm', true );
		}

		return $token;
	}
}
