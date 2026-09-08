<?php
/**
 * Data-sync engine (Milestone 2b / 2d / 2e).
 *
 * Orchestrates pulling Questrade data into the local tables:
 *   - accounts    /v1/accounts                         → mm_accounts
 *   - activities  /v1/accounts/{id}/activities         → mm_activities (dedup upsert)
 *   - positions   /v1/accounts/{id}/positions          → mm_positions_snapshots
 *   - fx          Bank of Canada Valet (via MM_FX)     → mm_fx_rates
 *
 * Scheduling: WP-Cron `twicedaily` runs an incremental sync (an overlapping
 * 35-day activities window, always upserting). WP-Cron only fires on site
 * traffic, so a "Sync now" button is always available and README documents a real
 * system cron. A separate, self-rescheduling backfill walks history
 * month-by-month so no single request runs long.
 *
 * Every endpoint of every run writes an mm_sync_log row (grouped by run_id).
 *
 * @package MoneyMaker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sync orchestration, cron wiring, and the admin-post handlers behind the
 * Data Sync screen.
 */
final class MM_Sync {

	const CAPABILITY = 'manage_options';

	/** Cron hooks. */
	const CRON_INCREMENTAL = 'mm/sync/incremental';
	const CRON_BACKFILL    = 'mm/sync/backfill';

	/** admin-post actions. */
	const ACTION_RUN      = 'mm_sync_run';
	const ACTION_BACKFILL = 'mm_sync_backfill';

	/** Option holding backfill progress. */
	const STATE_OPTION = 'mm_sync_state';

	/** Incremental activities window: re-pull the last N days every run. */
	const INCREMENTAL_WINDOW_DAYS = 35;

	/** Per-request activities window. Questrade caps each call at ~31 days. */
	const ACTIVITY_CHUNK_DAYS = 28;

	/** Monthly windows a single backfill tick will pull before rescheduling. */
	const BACKFILL_WINDOWS_PER_TICK = 6;

	/** Give up (and stop rescheduling) after this many ticks make no progress. */
	const BACKFILL_MAX_STALLED_TICKS = 3;

	/** Polite pause between Questrade calls in a loop (microseconds). */
	const LOOP_PAUSE_US = 200000;

	/** Every endpoint this engine knows how to sync, in run order. */
	const ENDPOINTS = array( 'accounts', 'fx', 'activities', 'positions' );

	/**
	 * Register hooks. Called once from mm_bootstrap().
	 */
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
		add_action( self::CRON_INCREMENTAL, array( __CLASS__, 'cron_incremental' ) );
		add_action( self::CRON_BACKFILL, array( __CLASS__, 'cron_backfill' ) );
		add_action( 'admin_post_' . self::ACTION_RUN, array( __CLASS__, 'handle_run' ) );
		add_action( 'admin_post_' . self::ACTION_BACKFILL, array( __CLASS__, 'handle_backfill' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Cron
	 * ------------------------------------------------------------------ */

	/**
	 * Make sure the recurring incremental sync is scheduled. Runs on every load
	 * (cheap) so an already-active install picks the schedule up after upgrade.
	 */
	public static function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::CRON_INCREMENTAL ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', self::CRON_INCREMENTAL );
		}
	}

	/**
	 * Remove all scheduled sync events. Called from deactivation.
	 */
	public static function unschedule_all(): void {
		wp_clear_scheduled_hook( self::CRON_INCREMENTAL );
		wp_clear_scheduled_hook( self::CRON_BACKFILL );
	}

	/**
	 * Timestamp of the next incremental run, or null.
	 */
	public static function next_scheduled(): ?int {
		$ts = wp_next_scheduled( self::CRON_INCREMENTAL );

		return $ts ? (int) $ts : null;
	}

	/**
	 * WP-Cron entry point: incremental sync of every endpoint.
	 */
	public static function cron_incremental(): void {
		self::run( self::ENDPOINTS, 'cron' );
	}

	/**
	 * WP-Cron entry point: one backfill tick, rescheduling itself until complete.
	 */
	public static function cron_backfill(): void {
		$status = self::backfill_tick();

		if ( empty( $status['complete'] ) && ! is_wp_error( $status ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_BACKFILL );
		}
	}

	/* ------------------------------------------------------------------ *
	 * admin-post handlers
	 * ------------------------------------------------------------------ */

	/**
	 * "Sync now" — run the requested endpoints synchronously, then redirect with a
	 * per-endpoint summary notice.
	 */
	public static function handle_run(): void {
		self::guard( self::ACTION_RUN );

		$requested = isset( $_POST['mm_endpoints'] ) && is_array( $_POST['mm_endpoints'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['mm_endpoints'] ) )
			: self::ENDPOINTS;

		$endpoints = array_values( array_intersect( self::ENDPOINTS, $requested ) );
		if ( empty( $endpoints ) ) {
			$endpoints = self::ENDPOINTS;
		}

		$summary = self::run( $endpoints, 'manual' );

		foreach ( $summary['results'] as $endpoint => $result ) {
			$type = 'ok' === $result['status'] ? 'updated' : ( 'skipped' === $result['status'] ? 'info' : 'error' );
			add_settings_error(
				'mm_sync',
				'mm_sync_' . $endpoint,
				sprintf(
					/* translators: 1: endpoint, 2: status, 3: detail */
					__( '%1$s: %2$s — %3$s', 'money-maker' ),
					ucfirst( $endpoint ),
					$result['status'],
					$result['message']
				),
				$type
			);
		}

		MM_Admin::redirect_with_notices( MM_Admin::SYNC_SLUG );
	}

	/**
	 * Backfill control: start (or restart), run one tick now, or cancel.
	 */
	public static function handle_backfill(): void {
		self::guard( self::ACTION_BACKFILL );

		$op = isset( $_POST['mm_backfill_op'] ) ? sanitize_key( wp_unslash( $_POST['mm_backfill_op'] ) ) : '';

		if ( 'cancel' === $op ) {
			self::cancel_backfill();
			add_settings_error( 'mm_sync', 'mm_backfill_cancelled', __( 'Backfill cancelled.', 'money-maker' ), 'info' );
			MM_Admin::redirect_with_notices( MM_Admin::SYNC_SLUG );
		}

		if ( 'start' === $op ) {
			$since  = isset( $_POST['mm_backfill_since'] ) ? sanitize_text_field( wp_unslash( $_POST['mm_backfill_since'] ) ) : '';
			$result = self::start_backfill( $since );

			if ( is_wp_error( $result ) ) {
				add_settings_error( 'mm_sync', $result->get_error_code(), $result->get_error_message(), 'error' );
			} else {
				add_settings_error(
					'mm_sync',
					'mm_backfill_started',
					sprintf(
						/* translators: %s: start date */
						__( 'Backfill queued from %s. It runs in the background; use “Run a backfill step now” if WP-Cron is not firing.', 'money-maker' ),
						$result['since']
					),
					'updated'
				);
			}

			MM_Admin::redirect_with_notices( MM_Admin::SYNC_SLUG );
		}

		// Default: run one tick now.
		$status = self::backfill_tick();

		if ( is_wp_error( $status ) ) {
			add_settings_error( 'mm_sync', $status->get_error_code(), $status->get_error_message(), 'error' );
		} elseif ( empty( $status['active'] ) ) {
			add_settings_error( 'mm_sync', 'mm_backfill_none', __( 'No backfill is in progress.', 'money-maker' ), 'info' );
		} else {
			add_settings_error(
				'mm_sync',
				'mm_backfill_step',
				$status['complete']
					? sprintf(
						/* translators: %d: rows written */
						__( 'Backfill complete. %d activity rows written in this step.', 'money-maker' ),
						$status['rows_affected']
					)
					: sprintf(
						/* translators: 1: rows written, 2: earliest remaining date */
						__( 'Backfill step done — %1$d rows written. Still working back from %2$s; run again or let cron continue.', 'money-maker' ),
						$status['rows_affected'],
						$status['frontier']
					),
				$status['complete'] ? 'updated' : 'info'
			);
		}

		MM_Admin::redirect_with_notices( MM_Admin::SYNC_SLUG );
	}

	/* ------------------------------------------------------------------ *
	 * Run orchestration
	 * ------------------------------------------------------------------ */

	/**
	 * Run a set of endpoints under one run_id.
	 *
	 * @param string[] $endpoints Subset of self::ENDPOINTS.
	 * @param string   $trigger   'manual' | 'cron' | 'backfill' — for the log.
	 * @return array{run_id:string,results:array<string,array{status:string,seen:int,affected:int,message:string}>}
	 */
	public static function run( array $endpoints, string $trigger = 'manual' ): array {
		self::relax_limits();

		$run_id  = MM_Sync_Log::new_run_id();
		$results = array();

		foreach ( self::ENDPOINTS as $endpoint ) {
			if ( ! in_array( $endpoint, $endpoints, true ) ) {
				continue;
			}

			$method               = 'sync_' . $endpoint;
			$results[ $endpoint ] = self::{$method}( $run_id );
		}

		MM_Sync_Log::prune();

		/**
		 * Fires after a sync run finishes.
		 *
		 * @param string $run_id  Run identifier.
		 * @param array  $results Per-endpoint outcome.
		 * @param string $trigger What started the run.
		 */
		do_action( 'mm/sync/completed', $run_id, $results, $trigger );

		return array(
			'run_id'  => $run_id,
			'results' => $results,
		);
	}

	/**
	 * Sync /v1/accounts → mm_accounts.
	 *
	 * @param string $run_id Run identifier.
	 * @return array{status:string,seen:int,affected:int,message:string}
	 */
	private static function sync_accounts( string $run_id ): array {
		$log = MM_Sync_Log::start( $run_id, 'accounts' );

		$response = MM_Questrade_Client::request( 'GET', 'v1/accounts' );

		if ( is_wp_error( $response ) ) {
			return self::fail( $log, $response->get_error_message() );
		}

		$accounts = isset( $response['accounts'] ) && is_array( $response['accounts'] ) ? $response['accounts'] : array();
		$affected = 0;

		foreach ( $accounts as $account ) {
			if ( ! is_array( $account ) ) {
				continue;
			}
			$outcome = MM_Accounts::upsert( $account );
			if ( 'inserted' === $outcome || 'updated' === $outcome ) {
				++$affected;
			}
		}

		$message = sprintf(
			/* translators: 1: number of accounts, 2: number changed */
			__( '%1$d accounts, %2$d new/updated', 'money-maker' ),
			count( $accounts ),
			$affected
		);

		MM_Sync_Log::finish( $log, 'ok', count( $accounts ), $affected, $message );

		return array(
			'status'   => 'ok',
			'seen'     => count( $accounts ),
			'affected' => $affected,
			'message'  => $message,
		);
	}

	/**
	 * Ensure Bank of Canada USD/CAD rates cover the span of stored activity dates
	 * (plus the incremental window), so activity rows can be priced in CAD.
	 *
	 * @param string $run_id Run identifier.
	 * @return array{status:string,seen:int,affected:int,message:string}
	 */
	private static function sync_fx( string $run_id ): array {
		$span  = MM_Activities::settlement_span();
		$today = gmdate( 'Y-m-d' );

		$from = $span['min'] ?: gmdate( 'Y-m-d', strtotime( '-' . self::INCREMENTAL_WINDOW_DAYS . ' days' ) );
		$to   = $today;

		$log      = MM_Sync_Log::start( $run_id, 'fx', '', $from, $to );
		$upserted = MM_FX::ensure_range( $from, $to );

		if ( is_wp_error( $upserted ) ) {
			return self::fail( $log, $upserted->get_error_message() );
		}

		// Price any activity rows still missing a rate.
		$priced  = MM_Activities::backfill_fx();
		$message = sprintf(
			/* translators: 1: rate rows fetched, 2: activity rows priced */
			__( '%1$d rate rows fetched, %2$d activities priced', 'money-maker' ),
			$upserted,
			$priced
		);

		MM_Sync_Log::finish( $log, 'ok', $upserted, $upserted + $priced, $message );

		return array(
			'status'   => 'ok',
			'seen'     => $upserted,
			'affected' => $upserted + $priced,
			'message'  => $message,
		);
	}

	/**
	 * Incremental activities sync: re-pull the last INCREMENTAL_WINDOW_DAYS for
	 * every known account and upsert.
	 *
	 * @param string $run_id Run identifier.
	 * @return array{status:string,seen:int,affected:int,message:string}
	 */
	private static function sync_activities( string $run_id ): array {
		$accounts = MM_Accounts::numbers();

		if ( empty( $accounts ) ) {
			$log = MM_Sync_Log::start( $run_id, 'activities' );
			MM_Sync_Log::finish( $log, 'skipped', 0, 0, __( 'No accounts stored yet — run an accounts sync first.', 'money-maker' ) );

			return array(
				'status'   => 'skipped',
				'seen'     => 0,
				'affected' => 0,
				'message'  => __( 'no accounts stored', 'money-maker' ),
			);
		}

		$from = gmdate( 'Y-m-d', strtotime( '-' . self::INCREMENTAL_WINDOW_DAYS . ' days' ) );
		$to   = gmdate( 'Y-m-d' );

		// One FX fetch up front so per-row lookups resolve locally.
		MM_FX::ensure_range( $from, $to );

		$total_seen     = 0;
		$total_affected = 0;
		$errors         = array();

		foreach ( $accounts as $account ) {
			$log = MM_Sync_Log::start( $run_id, 'activities', MM_Accounts::mask( $account ), $from, $to );

			$result = self::pull_activities_window( $account, $from, $to );

			if ( is_wp_error( $result ) ) {
				$errors[] = MM_Accounts::mask( $account ) . ': ' . $result->get_error_message();
				MM_Sync_Log::finish( $log, 'error', 0, 0, $result->get_error_message() );
				continue;
			}

			$total_seen     += $result['seen'];
			$total_affected += $result['affected'];
			MM_Sync_Log::finish( $log, 'ok', $result['seen'], $result['affected'], sprintf( '%d seen, %d written', $result['seen'], $result['affected'] ) );
		}

		if ( ! empty( $errors ) ) {
			return array(
				'status'   => 'error',
				'seen'     => $total_seen,
				'affected' => $total_affected,
				'message'  => implode( '; ', $errors ),
			);
		}

		return array(
			'status'   => 'ok',
			'seen'     => $total_seen,
			'affected' => $total_affected,
			'message'  => sprintf(
				/* translators: 1: activities seen, 2: rows written */
				__( '%1$d activities seen, %2$d inserted/updated', 'money-maker' ),
				$total_seen,
				$total_affected
			),
		);
	}

	/**
	 * Snapshot open positions for every account.
	 *
	 * @param string $run_id Run identifier.
	 * @return array{status:string,seen:int,affected:int,message:string}
	 */
	private static function sync_positions( string $run_id ): array {
		$accounts = MM_Accounts::numbers();

		if ( empty( $accounts ) ) {
			$log = MM_Sync_Log::start( $run_id, 'positions' );
			MM_Sync_Log::finish( $log, 'skipped', 0, 0, __( 'No accounts stored yet.', 'money-maker' ) );

			return array(
				'status'   => 'skipped',
				'seen'     => 0,
				'affected' => 0,
				'message'  => __( 'no accounts stored', 'money-maker' ),
			);
		}

		$today          = gmdate( 'Y-m-d' );
		$total_seen     = 0;
		$total_written  = 0;
		$errors         = array();

		foreach ( $accounts as $account ) {
			$log = MM_Sync_Log::start( $run_id, 'positions', MM_Accounts::mask( $account ) );

			$response = MM_Questrade_Client::request( 'GET', 'v1/accounts/' . rawurlencode( $account ) . '/positions' );
			usleep( self::LOOP_PAUSE_US );

			if ( is_wp_error( $response ) ) {
				$errors[] = MM_Accounts::mask( $account ) . ': ' . $response->get_error_message();
				MM_Sync_Log::finish( $log, 'error', 0, 0, $response->get_error_message() );
				continue;
			}

			$positions = isset( $response['positions'] ) && is_array( $response['positions'] ) ? $response['positions'] : array();
			$written   = MM_Positions::store_snapshot( $account, $positions, $today );

			$total_seen    += count( $positions );
			$total_written += $written;
			MM_Sync_Log::finish( $log, 'ok', count( $positions ), $written, sprintf( '%d positions', $written ) );
		}

		if ( ! empty( $errors ) ) {
			return array(
				'status'   => 'error',
				'seen'     => $total_seen,
				'affected' => $total_written,
				'message'  => implode( '; ', $errors ),
			);
		}

		return array(
			'status'   => 'ok',
			'seen'     => $total_seen,
			'affected' => $total_written,
			'message'  => sprintf(
				/* translators: 1: positions seen, 2: rows written */
				__( '%1$d positions across %2$d accounts snapshotted', 'money-maker' ),
				$total_seen,
				count( $accounts )
			),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Backfill
	 * ------------------------------------------------------------------ */

	/**
	 * Begin (or restart) a historical backfill from $since to today.
	 *
	 * @param string $since Y-m-d.
	 * @return array{since:string}|WP_Error
	 */
	public static function start_backfill( string $since ) {
		$since = trim( $since );

		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since ) || false === strtotime( $since ) ) {
			return new WP_Error( 'mm_backfill_bad_date', __( 'Enter a valid start date (YYYY-MM-DD).', 'money-maker' ) );
		}

		$today = gmdate( 'Y-m-d' );
		if ( $since >= $today ) {
			return new WP_Error( 'mm_backfill_future', __( 'The start date must be in the past.', 'money-maker' ) );
		}

		$accounts = MM_Accounts::numbers();
		if ( empty( $accounts ) ) {
			return new WP_Error( 'mm_backfill_no_accounts', __( 'Sync accounts first so the backfill knows what to walk.', 'money-maker' ) );
		}

		$cursor = array();
		foreach ( $accounts as $account ) {
			$cursor[ $account ] = $since;
		}

		$state             = self::get_state();
		$state['backfill'] = array(
			'since'       => $since,
			'cursor'      => $cursor,
			'started_at'  => time(),
			'updated_at'  => time(),
			'complete'    => false,
			'stalled'     => false,
			'stall_ticks' => 0,
			'rows_total'  => 0,
		);
		self::put_state( $state );

		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_BACKFILL );

		return array( 'since' => $since );
	}

	/**
	 * Clear backfill progress and cancel its cron event.
	 */
	public static function cancel_backfill(): void {
		$state = self::get_state();
		unset( $state['backfill'] );
		self::put_state( $state );

		wp_clear_scheduled_hook( self::CRON_BACKFILL );
	}

	/**
	 * Advance the backfill by up to BACKFILL_WINDOWS_PER_TICK monthly windows,
	 * spread across accounts.
	 *
	 * @return array{active:bool,complete:bool,rows_affected:int,frontier:?string}|WP_Error
	 */
	public static function backfill_tick() {
		self::relax_limits();

		$state = self::get_state();

		if ( empty( $state['backfill'] ) || ! empty( $state['backfill']['complete'] ) ) {
			return array(
				'active'        => ! empty( $state['backfill'] ),
				'complete'      => ! empty( $state['backfill']['complete'] ),
				'rows_affected' => 0,
				'frontier'      => null,
			);
		}

		$backfill = $state['backfill'];
		$today    = gmdate( 'Y-m-d' );
		$run_id   = MM_Sync_Log::new_run_id();
		$budget   = self::BACKFILL_WINDOWS_PER_TICK;
		$affected = 0;

		$frontier_before = min( $backfill['cursor'] );

		// Fetch FX once for the whole outstanding span.
		MM_FX::ensure_range( $frontier_before, $today );

		foreach ( $backfill['cursor'] as $account => $from ) {
			while ( $budget > 0 && $from < $today ) {
				$to = gmdate( 'Y-m-d', min( strtotime( $today ), strtotime( $from . ' +1 month -1 day' ) ) );

				$log    = MM_Sync_Log::start( $run_id, 'activities', MM_Accounts::mask( (string) $account ) . ' (backfill)', $from, $to );
				$result = self::pull_activities_window( (string) $account, $from, $to );

				if ( is_wp_error( $result ) ) {
					MM_Sync_Log::finish( $log, 'error', 0, 0, $result->get_error_message() );
					// Stop this account for now; try again next tick from the same cursor.
					$budget = 0;
					break;
				}

				MM_Sync_Log::finish( $log, 'ok', $result['seen'], $result['affected'], sprintf( '%d seen, %d written', $result['seen'], $result['affected'] ) );
				$affected += $result['affected'];

				$from = gmdate( 'Y-m-d', strtotime( $to . ' +1 day' ) );
				$backfill['cursor'][ $account ] = $from;
				--$budget;
			}

			if ( $budget <= 0 ) {
				break;
			}
		}

		// Complete when every cursor has reached today.
		$complete = true;
		foreach ( $backfill['cursor'] as $cursor_date ) {
			if ( $cursor_date < $today ) {
				$complete = false;
				break;
			}
		}

		// Stall guard: if the frontier did not move (an account is stuck erroring),
		// stop after a few ticks rather than rescheduling cron forever.
		$frontier_after = min( $backfill['cursor'] );
		$stalled        = false;
		if ( ! $complete && $frontier_after === $frontier_before ) {
			$backfill['stall_ticks'] = (int) ( $backfill['stall_ticks'] ?? 0 ) + 1;
			if ( $backfill['stall_ticks'] >= self::BACKFILL_MAX_STALLED_TICKS ) {
				$stalled  = true;
				$complete = true;
			}
		} else {
			$backfill['stall_ticks'] = 0;
		}

		$backfill['updated_at'] = time();
		$backfill['complete']   = $complete;
		$backfill['stalled']    = $stalled;
		$backfill['rows_total'] = (int) $backfill['rows_total'] + $affected;
		$state['backfill']      = $backfill;
		self::put_state( $state );

		return array(
			'active'        => true,
			'complete'      => $complete,
			'stalled'       => $stalled,
			'rows_affected' => $affected,
			'frontier'      => $complete ? null : $frontier_after,
		);
	}

	/**
	 * Current backfill progress for the admin screen.
	 *
	 * @return array{active:bool,complete:bool,since:?string,frontier:?string,rows_total:int,updated_at:?int}
	 */
	public static function backfill_status(): array {
		$state = self::get_state();

		if ( empty( $state['backfill'] ) ) {
			return array(
				'active'     => false,
				'complete'   => false,
				'stalled'    => false,
				'since'      => null,
				'frontier'   => null,
				'rows_total' => 0,
				'updated_at' => null,
			);
		}

		$backfill = $state['backfill'];

		return array(
			'active'     => true,
			'complete'   => ! empty( $backfill['complete'] ),
			'stalled'    => ! empty( $backfill['stalled'] ),
			'since'      => $backfill['since'] ?? null,
			'frontier'   => ! empty( $backfill['cursor'] ) ? min( $backfill['cursor'] ) : null,
			'rows_total' => (int) ( $backfill['rows_total'] ?? 0 ),
			'updated_at' => isset( $backfill['updated_at'] ) ? (int) $backfill['updated_at'] : null,
		);
	}

	/* ------------------------------------------------------------------ *
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Pull one date window of activities for one account, chunked to stay under
	 * Questrade's ~31-day-per-call cap, and upsert each.
	 *
	 * @param string $account Full account number.
	 * @param string $from    Y-m-d (inclusive).
	 * @param string $to      Y-m-d (inclusive).
	 * @return array{seen:int,affected:int}|WP_Error
	 */
	private static function pull_activities_window( string $account, string $from, string $to ) {
		$seen     = 0;
		$affected = 0;

		$chunk_start = $from;

		while ( $chunk_start <= $to ) {
			$chunk_end = gmdate( 'Y-m-d', min(
				strtotime( $to ),
				strtotime( $chunk_start . ' +' . ( self::ACTIVITY_CHUNK_DAYS - 1 ) . ' days' )
			) );

			$response = MM_Questrade_Client::request(
				'GET',
				'v1/accounts/' . rawurlencode( $account ) . '/activities',
				array(
					'query' => array(
						'startTime' => self::eastern_iso( $chunk_start, false ),
						'endTime'   => self::eastern_iso( $chunk_end, true ),
					),
				)
			);

			usleep( self::LOOP_PAUSE_US );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$activities = isset( $response['activities'] ) && is_array( $response['activities'] ) ? $response['activities'] : array();

			foreach ( $activities as $activity ) {
				if ( ! is_array( $activity ) ) {
					continue;
				}
				++$seen;
				$outcome = MM_Activities::upsert( $account, $activity );
				if ( 'inserted' === $outcome || 'updated' === $outcome ) {
					++$affected;
				}
			}

			$chunk_start = gmdate( 'Y-m-d', strtotime( $chunk_end . ' +1 day' ) );
		}

		return array(
			'seen'     => $seen,
			'affected' => $affected,
		);
	}

	/**
	 * ISO-8601 timestamp in Questrade's timezone (US Eastern) for a calendar date.
	 *
	 * @param string $date        Y-m-d.
	 * @param bool   $end_of_day   Whether to use 23:59:59 rather than 00:00:00.
	 */
	private static function eastern_iso( string $date, bool $end_of_day ): string {
		try {
			$tz = new DateTimeZone( 'America/Toronto' );
			$dt = new DateTimeImmutable( $date . ( $end_of_day ? ' 23:59:59' : ' 00:00:00' ), $tz );
		} catch ( Exception $e ) {
			return $date;
		}

		return $dt->format( 'c' );
	}

	/**
	 * Standardised failure return + log close.
	 *
	 * @return array{status:string,seen:int,affected:int,message:string}
	 */
	private static function fail( int $log, string $message ): array {
		MM_Sync_Log::finish( $log, 'error', 0, 0, $message );

		return array(
			'status'   => 'error',
			'seen'     => 0,
			'affected' => 0,
			'message'  => $message,
		);
	}

	/**
	 * Read the sync-state option as an array.
	 */
	private static function get_state(): array {
		$state = get_option( self::STATE_OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Persist the sync-state option (autoload no).
	 */
	private static function put_state( array $state ): void {
		if ( false === get_option( self::STATE_OPTION ) ) {
			add_option( self::STATE_OPTION, $state, '', 'no' );
		} else {
			update_option( self::STATE_OPTION, $state, false );
		}
	}

	/**
	 * Best-effort lift of execution limits for a sync request.
	 */
	private static function relax_limits(): void {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	/**
	 * Capability + nonce guard shared by the admin-post handlers.
	 *
	 * @param string $action Nonce action (also the admin-post action name).
	 */
	private static function guard( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'money-maker' ) );
		}

		check_admin_referer( $action );
	}
}
