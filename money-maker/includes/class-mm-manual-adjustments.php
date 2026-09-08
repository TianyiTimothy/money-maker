<?php
/**
 * Manual ACB adjustments repository + editor handlers (Milestone 3a).
 *
 * Questrade's activities feed does not represent corporate actions — stock
 * splits, mergers, return of capital, reinvested / "phantom" distributions. The
 * pooled-ACB engine (Module C / M3b) is only trustworthy once those are entered
 * by hand, so this comes first.
 *
 * Each row is a dated delta applied to one (account, symbol) pool while the ACB
 * engine walks activities chronologically:
 *   - quantity_delta  change in share count (splits, mergers)
 *   - acb_delta        change in the pooled cost base, in CAD (return of capital
 *                      is negative; reinvested distribution is positive)
 *
 * Passive class: exposes register() (adds the two admin-post handlers), called
 * from mm_bootstrap(). The screen that renders the form/table lives in MM_Admin.
 *
 * @package MoneyMaker
 */

defined( 'ABSPATH' ) || exit;

/**
 * mm_manual_adjustments persistence + the add/edit/delete form handlers.
 */
final class MM_Manual_Adjustments {

	const CAPABILITY = 'manage_options';

	/** admin-post actions. */
	const ACTION_SAVE   = 'mm_adjustment_save';
	const ACTION_DELETE = 'mm_adjustment_delete';

	/**
	 * Adjustment kinds. Keys are stored in the `kind` column; the labels are the
	 * only thing shown in the UI. `other` is the catch-all.
	 *
	 * @var array<string,string>
	 */
	const KINDS = array(
		'split'                   => 'Stock split / consolidation',
		'merger'                  => 'Merger / acquisition / spin-off',
		'return_of_capital'       => 'Return of capital',
		'reinvested_distribution' => 'Reinvested ("phantom") distribution',
		'transfer_in_acb'         => 'Transfer-in cost base',
		'other'                   => 'Other',
	);

	/**
	 * Register WordPress hooks. Called once from mm_bootstrap().
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION_SAVE, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_DELETE, array( __CLASS__, 'handle_delete' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Reads
	 * ------------------------------------------------------------------ */

	/**
	 * Every adjustment, newest date first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			'SELECT * FROM ' . MM_DB::table( 'manual_adjustments' ) . ' ORDER BY adjustment_date DESC, id DESC',
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One adjustment by id, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . MM_DB::table( 'manual_adjustments' ) . ' WHERE id = %d', $id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Adjustments for one (account, symbol) pool, oldest first — the order the
	 * ACB engine applies them in.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_symbol( string $account_number, string $symbol ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . MM_DB::table( 'manual_adjustments' ) . '
				 WHERE account_number = %s AND symbol = %s
				 ORDER BY adjustment_date ASC, id ASC',
				$account_number,
				strtoupper( trim( $symbol ) )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many adjustments are stored, and the newest updated_at — together they
	 * form part of the ACB cache fingerprint.
	 *
	 * @return array{count:int,updated_at:?string}
	 */
	public static function fingerprint_parts(): array {
		global $wpdb;

		$table = MM_DB::table( 'manual_adjustments' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( "SELECT COUNT(*) AS c, MAX(updated_at) AS u FROM {$table}", ARRAY_A );

		return array(
			'count'      => $row ? (int) $row['c'] : 0,
			'updated_at' => $row && $row['u'] ? (string) $row['u'] : null,
		);
	}

	/* ------------------------------------------------------------------ *
	 * Writes
	 * ------------------------------------------------------------------ */

	/**
	 * Insert or update one adjustment.
	 *
	 * @param array<string,mixed> $input Raw form values. `id` > 0 updates.
	 * @return int|WP_Error New/updated row id, or a validation error.
	 */
	public static function save( array $input ) {
		global $wpdb;

		$id      = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$account = isset( $input['account_number'] ) ? sanitize_text_field( (string) $input['account_number'] ) : '';
		$symbol  = isset( $input['symbol'] ) ? strtoupper( trim( sanitize_text_field( (string) $input['symbol'] ) ) ) : '';
		$date    = isset( $input['adjustment_date'] ) ? trim( sanitize_text_field( (string) $input['adjustment_date'] ) ) : '';
		$kind    = isset( $input['kind'] ) ? sanitize_key( (string) $input['kind'] ) : '';
		$note    = isset( $input['note'] ) ? sanitize_textarea_field( (string) $input['note'] ) : '';

		$qty_delta = isset( $input['quantity_delta'] ) ? (float) $input['quantity_delta'] : 0.0;
		$acb_delta = isset( $input['acb_delta'] ) ? (float) $input['acb_delta'] : 0.0;

		if ( '' === $symbol ) {
			return new WP_Error( 'mm_adj_symbol', __( 'Enter the security symbol the adjustment applies to.', 'money-maker' ) );
		}

		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || false === strtotime( $date ) ) {
			return new WP_Error( 'mm_adj_date', __( 'Enter a valid adjustment date (YYYY-MM-DD).', 'money-maker' ) );
		}

		if ( ! array_key_exists( $kind, self::KINDS ) ) {
			return new WP_Error( 'mm_adj_kind', __( 'Choose an adjustment kind.', 'money-maker' ) );
		}

		if ( ! in_array( $account, MM_Accounts::numbers(), true ) ) {
			return new WP_Error( 'mm_adj_account', __( 'Choose one of your synced accounts.', 'money-maker' ) );
		}

		if ( 0.0 === $qty_delta && 0.0 === $acb_delta ) {
			return new WP_Error( 'mm_adj_empty', __( 'An adjustment needs a quantity change, a cost-base change, or both.', 'money-maker' ) );
		}

		$table = MM_DB::table( 'manual_adjustments' );
		$now   = current_time( 'mysql', true );

		$data = array(
			'account_number' => $account,
			'symbol'         => $symbol,
			'symbol_id'      => isset( $input['symbol_id'] ) ? absint( $input['symbol_id'] ) : 0,
			'adjustment_date' => $date,
			'kind'           => $kind,
			'quantity_delta' => $qty_delta,
			'acb_delta'      => $acb_delta,
			'note'           => $note,
			'updated_at'     => $now,
		);
		$formats = array( '%s', '%s', '%d', '%s', '%s', '%f', '%f', '%s', '%s' );

		if ( $id > 0 && null !== self::get( $id ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, $data, array( 'id' => $id ), $formats, array( '%d' ) );
			self::after_write();
			return $id;
		}

		$data['created_at'] = $now;
		$formats[]          = '%s';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( $table, $data, $formats );

		if ( ! $ok ) {
			return new WP_Error( 'mm_adj_db', __( 'Could not save the adjustment.', 'money-maker' ) );
		}

		self::after_write();
		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete one adjustment. Returns true if a row went away.
	 */
	public static function delete( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deleted = $wpdb->delete( MM_DB::table( 'manual_adjustments' ), array( 'id' => $id ), array( '%d' ) );

		if ( $deleted ) {
			self::after_write();
		}

		return (bool) $deleted;
	}

	/* ------------------------------------------------------------------ *
	 * admin-post handlers
	 * ------------------------------------------------------------------ */

	/**
	 * Handle the add / edit form submission.
	 */
	public static function handle_save(): void {
		self::guard( self::ACTION_SAVE );

		$result = self::save( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification -- guard() checks it.

		if ( is_wp_error( $result ) ) {
			add_settings_error( 'mm_adjustments', $result->get_error_code(), $result->get_error_message(), 'error' );
		} else {
			add_settings_error(
				'mm_adjustments',
				'mm_adjustment_saved',
				__( 'Adjustment saved.', 'money-maker' ),
				'updated'
			);
		}

		MM_Admin::redirect_with_notices( MM_Admin::ADJUSTMENTS_SLUG );
	}

	/**
	 * Handle a delete submission.
	 */
	public static function handle_delete(): void {
		self::guard( self::ACTION_DELETE );

		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;

		if ( $id > 0 && self::delete( $id ) ) {
			add_settings_error( 'mm_adjustments', 'mm_adjustment_deleted', __( 'Adjustment deleted.', 'money-maker' ), 'updated' );
		} else {
			add_settings_error( 'mm_adjustments', 'mm_adjustment_delete_failed', __( 'That adjustment no longer exists.', 'money-maker' ), 'error' );
		}

		MM_Admin::redirect_with_notices( MM_Admin::ADJUSTMENTS_SLUG );
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Human label for a stored `kind` value.
	 */
	public static function kind_label( string $kind ): string {
		$labels = self::kind_labels();

		return $labels[ $kind ] ?? $kind;
	}

	/**
	 * Translated kind labels, keyed by stored value.
	 *
	 * @return array<string,string>
	 */
	public static function kind_labels(): array {
		return array(
			'split'                   => __( 'Stock split / consolidation', 'money-maker' ),
			'merger'                  => __( 'Merger / acquisition / spin-off', 'money-maker' ),
			'return_of_capital'       => __( 'Return of capital', 'money-maker' ),
			'reinvested_distribution' => __( 'Reinvested ("phantom") distribution', 'money-maker' ),
			'transfer_in_acb'         => __( 'Transfer-in cost base', 'money-maker' ),
			'other'                   => __( 'Other', 'money-maker' ),
		);
	}

	/**
	 * Bust the ACB cache after any write.
	 */
	private static function after_write(): void {
		if ( class_exists( 'MM_Tax_ACB' ) ) {
			MM_Tax_ACB::flush();
		}
	}

	/**
	 * Capability + nonce guard shared by the handlers.
	 */
	private static function guard( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'money-maker' ) );
		}

		check_admin_referer( $action );
	}
}
