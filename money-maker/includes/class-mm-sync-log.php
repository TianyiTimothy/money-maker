<?php
/**
 * Writer + reader for the sync-run history table (Milestone 2b).
 *
 * One row per endpoint per sync run. A single "Sync now" / cron pass shares one
 * run_id across the accounts / activities / positions / fx rows it writes, so the
 * admin screen can group them.
 *
 * Lifecycle: start() inserts a 'running' row and returns its id; finish() stamps
 * the outcome, row counts, message and duration.
 *
 * Static utility: no hooks, no register(). Required directly by money-maker.php.
 *
 * @package MoneyMaker
 */

defined( 'ABSPATH' ) || exit;

/**
 * mm_sync_log persistence.
 */
final class MM_Sync_Log {

	/** Allowed status values. */
	const STATUSES = array( 'running', 'ok', 'partial', 'error', 'skipped' );

	/**
	 * A fresh run identifier grouping the endpoints touched by one pass.
	 */
	public static function new_run_id(): string {
		return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'mm', true );
	}

	/**
	 * Open a log row for one endpoint. Returns the row id (0 on failure — callers
	 * treat a 0 id as "logging unavailable", never as a hard error).
	 *
	 * @param string      $run_id      Run identifier from new_run_id().
	 * @param string      $endpoint    'accounts' | 'activities' | 'positions' | 'fx'.
	 * @param string      $scope       Optional context (masked account, symbol…).
	 * @param string|null $range_start Y-m-d, if the endpoint covers a date window.
	 * @param string|null $range_end   Y-m-d.
	 */
	public static function start( string $run_id, string $endpoint, string $scope = '', ?string $range_start = null, ?string $range_end = null ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			MM_DB::table( 'sync_log' ),
			array(
				'run_id'      => $run_id,
				'endpoint'    => $endpoint,
				'scope'       => $scope,
				'range_start' => $range_start,
				'range_end'   => $range_end,
				'status'      => 'running',
				'started_at'  => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Close a log row opened by start().
	 *
	 * @param int    $log_id        Row id from start(). A 0 id is a silent no-op.
	 * @param string $status        One of self::STATUSES (not 'running').
	 * @param int    $rows_seen     Records returned by the API.
	 * @param int    $rows_affected Records inserted/updated locally.
	 * @param string $message       Short human summary or error text.
	 */
	public static function finish( int $log_id, string $status, int $rows_seen = 0, int $rows_affected = 0, string $message = '' ): void {
		global $wpdb;

		if ( $log_id <= 0 ) {
			return;
		}

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			$status = 'error';
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT started_at FROM ' . MM_DB::table( 'sync_log' ) . ' WHERE id = %d', $log_id ),
			ARRAY_A
		);

		$now         = current_time( 'mysql', true );
		$duration_ms = null;
		if ( $row && ! empty( $row['started_at'] ) ) {
			$duration_ms = max( 0, ( strtotime( $now ) - strtotime( $row['started_at'] ) ) * 1000 );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			MM_DB::table( 'sync_log' ),
			array(
				'status'        => $status,
				'rows_seen'     => $rows_seen,
				'rows_affected' => $rows_affected,
				'message'       => mb_substr( $message, 0, 2000 ),
				'finished_at'   => $now,
				'duration_ms'   => $duration_ms,
			),
			array( 'id' => $log_id ),
			array( '%s', '%d', '%d', '%s', '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Most recent log rows, newest first, for the admin screen.
	 *
	 * @param int $limit Rows to return.
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent( int $limit = 20 ): array {
		global $wpdb;

		$limit = max( 1, min( 200, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . MM_DB::table( 'sync_log' ) . ' ORDER BY id DESC LIMIT %d', $limit ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The most recent failed rows, newest first.
	 *
	 * recent() is a fixed window of the last N rows of any status, and a couple of
	 * ordinary syncs (2 + 2 per account rows each) will push a backfill failure
	 * straight out of it. This keeps the failures reachable regardless.
	 *
	 * @param int $limit Rows to return.
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent_errors( int $limit = 10 ): array {
		global $wpdb;

		$limit = max( 1, min( 100, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . MM_DB::table( 'sync_log' ) . " WHERE status IN ( 'error', 'partial' ) ORDER BY id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The last completed (non-'running') row for one endpoint, or null.
	 *
	 * @param string $endpoint Endpoint key.
	 * @return array<string,mixed>|null
	 */
	public static function last_for( string $endpoint ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . MM_DB::table( 'sync_log' ) . " WHERE endpoint = %s AND status <> 'running' ORDER BY id DESC LIMIT 1",
				$endpoint
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Delete rows older than the retention window so the table cannot grow without
	 * bound. Called opportunistically at the end of a sync run.
	 *
	 * @param int $keep_days Age in days beyond which rows are pruned.
	 */
	public static function prune( int $keep_days = 90 ): void {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $keep_days ) * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare( 'DELETE FROM ' . MM_DB::table( 'sync_log' ) . ' WHERE started_at < %s', $cutoff )
		);
	}
}
