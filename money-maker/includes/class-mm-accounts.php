<?php
/**
 * Accounts repository (Milestone 2b).
 *
 * Reads and upserts rows in mm_accounts, and owns the registered / non-registered
 * classification the tax layer (Module C) depends on — ACB and superficial-loss
 * logic run on non-registered accounts only.
 *
 * account_number is stored in full (it is the join key for every other table) and
 * only ever masked for display via mask().
 *
 * Static utility: no hooks, no register(). Required directly by money-maker.php.
 *
 * @package MoneyMaker
 */

defined( 'ABSPATH' ) || exit;

/**
 * mm_accounts persistence + account-type rules.
 */
final class MM_Accounts {

	/**
	 * Questrade account "type" values that are tax-sheltered / registered. Anything
	 * not in this list (Cash, Margin, …) is treated as non-registered and is in
	 * scope for ACB + superficial-loss calculations.
	 *
	 * Matching is case-insensitive and ignores a leading "S" (spousal) prefix, so
	 * SRRSP → RRSP, SRSP → RSP.
	 */
	const REGISTERED_TYPES = array(
		'TFSA',
		'FHSA',
		'RRSP',
		'RSP',
		'RRIF',
		'RIF',
		'LIRA',
		'LRSP',
		'LIF',
		'LRIF',
		'PRIF',
		'RESP',
		'RDSP',
	);

	/**
	 * Is a Questrade account type registered (tax-sheltered)?
	 *
	 * @param string $type e.g. 'Cash', 'Margin', 'TFSA', 'RRSP', 'SRRSP'.
	 */
	public static function is_registered_type( string $type ): bool {
		$normalised = strtoupper( trim( $type ) );

		if ( '' === $normalised ) {
			return false;
		}

		if ( in_array( $normalised, self::REGISTERED_TYPES, true ) ) {
			return true;
		}

		// Spousal variants: SRRSP, SRSP, SLIF…
		if ( strlen( $normalised ) > 1 && 'S' === $normalised[0]
			&& in_array( substr( $normalised, 1 ), self::REGISTERED_TYPES, true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Insert or update one account from a Questrade /v1/accounts entry.
	 *
	 * @param array $account Raw account object: number, type, status, isPrimary,
	 *                       isBilling, clientAccountType.
	 * @return string '' on failure, otherwise 'inserted' | 'updated' | 'unchanged'.
	 */
	public static function upsert( array $account ): string {
		global $wpdb;

		$number = isset( $account['number'] ) ? sanitize_text_field( (string) $account['number'] ) : '';

		if ( '' === $number ) {
			return '';
		}

		$type = isset( $account['type'] ) ? sanitize_text_field( (string) $account['type'] ) : '';
		$now  = current_time( 'mysql', true );

		$data = array(
			'type'                => $type,
			'is_registered'       => self::is_registered_type( $type ) ? 1 : 0,
			'status'              => isset( $account['status'] ) ? sanitize_text_field( (string) $account['status'] ) : '',
			'is_primary'          => ! empty( $account['isPrimary'] ) ? 1 : 0,
			'is_billing'          => ! empty( $account['isBilling'] ) ? 1 : 0,
			'client_account_type' => isset( $account['clientAccountType'] ) ? sanitize_text_field( (string) $account['clientAccountType'] ) : '',
			'raw_json'            => wp_json_encode( $account ),
			'updated_at'          => $now,
		);

		$table    = MM_DB::table( 'accounts' );
		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, type, is_registered, status, is_primary, is_billing, client_account_type FROM {$table} WHERE account_number = %s", $number ),
			ARRAY_A
		);

		if ( null === $existing ) {
			$data['account_number'] = $number;
			$data['created_at']     = $now;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ok = $wpdb->insert(
				$table,
				$data,
				array( '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
			);

			return $ok ? 'inserted' : '';
		}

		$changed = (string) $existing['type'] !== $type
			|| (int) $existing['is_registered'] !== $data['is_registered']
			|| (string) $existing['status'] !== $data['status']
			|| (int) $existing['is_primary'] !== $data['is_primary']
			|| (int) $existing['is_billing'] !== $data['is_billing']
			|| (string) $existing['client_account_type'] !== $data['client_account_type'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$table,
			$data,
			array( 'id' => (int) $existing['id'] ),
			array( '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return $changed ? 'updated' : 'unchanged';
	}

	/**
	 * All stored accounts, primary first then by number.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			'SELECT * FROM ' . MM_DB::table( 'accounts' ) . ' ORDER BY is_primary DESC, account_number ASC',
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One account row by its (full) number, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get( string $account_number ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . MM_DB::table( 'accounts' ) . ' WHERE account_number = %s', trim( $account_number ) ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Short display label for an account: type + masked number,
	 * e.g. "Margin ••••1234". Accepts a row or a bare number.
	 *
	 * @param array<string,mixed>|string $account
	 */
	public static function label( $account ): string {
		if ( is_string( $account ) ) {
			$account = self::get( $account ) ?? array( 'account_number' => $account, 'type' => '' );
		}

		$number = self::mask( (string) ( $account['account_number'] ?? '' ) );
		$type   = trim( (string) ( $account['type'] ?? '' ) );

		return '' !== $type ? $type . ' ' . $number : $number;
	}

	/**
	 * Just the account numbers, for sync loops.
	 *
	 * @return string[]
	 */
	public static function numbers(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_col( 'SELECT account_number FROM ' . MM_DB::table( 'accounts' ) . ' ORDER BY account_number ASC' );

		return is_array( $rows ) ? array_map( 'strval', $rows ) : array();
	}

	/**
	 * Non-registered account numbers — the tax-relevant subset.
	 *
	 * @return string[]
	 */
	public static function non_registered_numbers(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_col( 'SELECT account_number FROM ' . MM_DB::table( 'accounts' ) . ' WHERE is_registered = 0 ORDER BY account_number ASC' );

		return is_array( $rows ) ? array_map( 'strval', $rows ) : array();
	}

	/**
	 * How many accounts are stored.
	 */
	public static function count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . MM_DB::table( 'accounts' ) );
	}

	/**
	 * Mask an account number to its last 4 digits for display.
	 */
	public static function mask( string $number ): string {
		$number = trim( $number );
		$len    = strlen( $number );

		if ( $len <= 4 ) {
			return str_repeat( '•', $len );
		}

		return '••••' . substr( $number, -4 );
	}
}
