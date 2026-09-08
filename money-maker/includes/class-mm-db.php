<?php
/**
 * Custom database tables and schema migration (Milestone 2a).
 *
 * The plugin stores financial data in real tables, never in post meta — query
 * shape and row counts rule out the CPT route. This class owns:
 *   - the CREATE TABLE statements for every mm_* table
 *   - running them through dbDelta() on activation and on version bump
 *   - the mm_db_version option that drives "has the schema changed?"
 *
 * Tables (all prefixed {$wpdb->prefix}mm_):
 *   - accounts             one row per Questrade account, registered-type flag
 *   - activities           normalised transactions + dedup hash (unique) + raw JSON
 *   - positions_snapshots  dated snapshot of open positions per account (M2e)
 *   - fx_rates             daily CAD/USD (and future pairs) from the Bank of Canada
 *   - manual_adjustments   user-entered ACB corrections for corporate actions (M3 UI)
 *   - sync_log             one row per endpoint per sync run
 *
 * Static utility with a single hook (the version check), so it exposes a static
 * register() and is required directly by money-maker.php.
 *
 * @package MoneyMaker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Schema definition + migration runner.
 */
final class MM_DB {

	/**
	 * Schema version. Bump whenever a CREATE TABLE below changes; maybe_upgrade()
	 * re-runs dbDelta() when the stored mm_db_version differs from this.
	 */
	const DB_VERSION = '1';

	/** Option holding the installed schema version. */
	const VERSION_OPTION = 'mm_db_version';

	/** Unprefixed table keys, in dependency-free order. */
	const TABLES = array(
		'accounts',
		'activities',
		'positions_snapshots',
		'fx_rates',
		'manual_adjustments',
		'sync_log',
	);

	/**
	 * Register WordPress hooks. Called once from mm_bootstrap().
	 */
	public static function register(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
	}

	/**
	 * Fully-qualified name of one of our tables.
	 *
	 * @param string $key Unprefixed key, e.g. 'activities'. One of self::TABLES.
	 * @return string e.g. 'wp_mm_activities'.
	 */
	public static function table( string $key ): string {
		global $wpdb;

		return $wpdb->prefix . 'mm_' . $key;
	}

	/**
	 * Create / update every table and record the schema version. Safe to run
	 * repeatedly — dbDelta() only applies differences. Called from activation and
	 * from maybe_upgrade().
	 */
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::schema_statements() as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Re-run install() when the code ships a newer schema than what is installed.
	 * Hooked to admin_init so an upgraded plugin migrates on the next admin load.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Whether every expected table exists. Cheap sanity check for the sync layer
	 * and the admin status card.
	 */
	public static function is_installed(): bool {
		global $wpdb;

		foreach ( self::TABLES as $key ) {
			$name = self::table( $key );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
			if ( $found !== $name ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Drop every table and forget the schema version. Uninstall only.
	 */
	public static function drop_all(): void {
		global $wpdb;

		foreach ( self::TABLES as $key ) {
			$name = self::table( $key );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$name}`" );
		}

		delete_option( self::VERSION_OPTION );
	}

	/**
	 * All CREATE TABLE statements, formatted for dbDelta() (two spaces before the
	 * key definitions, one field per line, KEY not INDEX).
	 *
	 * @return string[]
	 */
	private static function schema_statements(): array {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$accounts            = self::table( 'accounts' );
		$activities          = self::table( 'activities' );
		$positions_snapshots = self::table( 'positions_snapshots' );
		$fx_rates            = self::table( 'fx_rates' );
		$manual_adjustments  = self::table( 'manual_adjustments' );
		$sync_log            = self::table( 'sync_log' );

		$statements = array();

		// One row per Questrade account. account_number is the natural key used by
		// every other table; it is stored in full and only ever masked for display.
		$statements[] = "CREATE TABLE {$accounts} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			account_number varchar(32) NOT NULL,
			type varchar(32) NOT NULL DEFAULT '',
			is_registered tinyint(1) NOT NULL DEFAULT 0,
			status varchar(32) NOT NULL DEFAULT '',
			is_primary tinyint(1) NOT NULL DEFAULT 0,
			is_billing tinyint(1) NOT NULL DEFAULT 0,
			client_account_type varchar(64) NOT NULL DEFAULT '',
			currency varchar(3) DEFAULT NULL,
			raw_json longtext,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY account_number (account_number),
			KEY is_registered (is_registered)
		) {$charset_collate};";

		// Normalised transactions. dedup_hash is a deterministic digest of the
		// business-identifying fields (Questrade activities have no stable id) and
		// is UNIQUE so syncs upsert instead of duplicating on overlapping windows.
		// Money columns are stored in their native currency; *_cad columns hold the
		// CAD conversion once an FX rate is resolved.
		$statements[] = "CREATE TABLE {$activities} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			account_number varchar(32) NOT NULL,
			dedup_hash char(64) NOT NULL,
			trade_date date DEFAULT NULL,
			settlement_date date DEFAULT NULL,
			transaction_at datetime DEFAULT NULL,
			action varchar(32) NOT NULL DEFAULT '',
			type varchar(64) NOT NULL DEFAULT '',
			symbol varchar(32) NOT NULL DEFAULT '',
			symbol_id bigint(20) unsigned NOT NULL DEFAULT 0,
			description varchar(255) NOT NULL DEFAULT '',
			quantity decimal(20,8) NOT NULL DEFAULT 0,
			price decimal(20,8) NOT NULL DEFAULT 0,
			gross_amount decimal(20,4) NOT NULL DEFAULT 0,
			commission decimal(20,4) NOT NULL DEFAULT 0,
			net_amount decimal(20,4) NOT NULL DEFAULT 0,
			currency varchar(3) NOT NULL DEFAULT 'CAD',
			fx_rate decimal(18,8) DEFAULT NULL,
			fx_rate_date date DEFAULT NULL,
			net_amount_cad decimal(20,4) DEFAULT NULL,
			raw_json longtext,
			synced_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY dedup_hash (dedup_hash),
			KEY account_settlement (account_number,settlement_date),
			KEY symbol (symbol),
			KEY type (type)
		) {$charset_collate};";

		// Dated snapshot of open positions (M2e). One row per account/date/symbol;
		// re-running a sync on the same day overwrites that day's snapshot.
		$statements[] = "CREATE TABLE {$positions_snapshots} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			account_number varchar(32) NOT NULL,
			snapshot_date date NOT NULL,
			snapshot_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			symbol varchar(32) NOT NULL DEFAULT '',
			symbol_id bigint(20) unsigned NOT NULL DEFAULT 0,
			open_quantity decimal(20,8) NOT NULL DEFAULT 0,
			current_price decimal(20,8) DEFAULT NULL,
			current_market_value decimal(20,4) DEFAULT NULL,
			average_entry_price decimal(20,8) DEFAULT NULL,
			total_cost decimal(20,4) DEFAULT NULL,
			open_pnl decimal(20,4) DEFAULT NULL,
			currency varchar(3) DEFAULT NULL,
			raw_json longtext,
			PRIMARY KEY  (id),
			UNIQUE KEY account_date_symbol (account_number,snapshot_date,symbol),
			KEY snapshot_date (snapshot_date)
		) {$charset_collate};";

		// Daily exchange rates. rate = units of quote_currency per 1 base_currency
		// (USD->CAD from Bank of Canada series FXUSDCAD). Weekend/holiday lookups
		// fall back to the most recent prior row in MM_FX.
		$statements[] = "CREATE TABLE {$fx_rates} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			rate_date date NOT NULL,
			base_currency varchar(3) NOT NULL DEFAULT 'USD',
			quote_currency varchar(3) NOT NULL DEFAULT 'CAD',
			rate decimal(18,8) NOT NULL,
			source varchar(32) NOT NULL DEFAULT 'boc-valet',
			series varchar(32) NOT NULL DEFAULT '',
			fetched_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY rate_pair_date (rate_date,base_currency,quote_currency),
			KEY pair (base_currency,quote_currency)
		) {$charset_collate};";

		// User-entered ACB / quantity corrections for corporate actions the
		// activities feed does not represent (splits, mergers, return of capital,
		// reinvested distributions). Schema only in M2; the editing UI is M3.
		$statements[] = "CREATE TABLE {$manual_adjustments} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			account_number varchar(32) NOT NULL,
			symbol varchar(32) NOT NULL DEFAULT '',
			symbol_id bigint(20) unsigned NOT NULL DEFAULT 0,
			adjustment_date date NOT NULL,
			kind varchar(32) NOT NULL DEFAULT 'other',
			quantity_delta decimal(20,8) NOT NULL DEFAULT 0,
			acb_delta decimal(20,4) NOT NULL DEFAULT 0,
			note text,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY account_symbol (account_number,symbol),
			KEY adjustment_date (adjustment_date)
		) {$charset_collate};";

		// One row per endpoint per sync run. run_id groups the endpoints touched by
		// a single "Sync now" / cron pass so the admin screen can show them together.
		$statements[] = "CREATE TABLE {$sync_log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id char(36) NOT NULL DEFAULT '',
			endpoint varchar(64) NOT NULL DEFAULT '',
			scope varchar(64) NOT NULL DEFAULT '',
			range_start date DEFAULT NULL,
			range_end date DEFAULT NULL,
			status varchar(16) NOT NULL DEFAULT 'running',
			rows_seen int(11) NOT NULL DEFAULT 0,
			rows_affected int(11) NOT NULL DEFAULT 0,
			message text,
			started_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			finished_at datetime DEFAULT NULL,
			duration_ms int(11) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY run_id (run_id),
			KEY endpoint_started (endpoint,started_at),
			KEY started_at (started_at)
		) {$charset_collate};";

		return $statements;
	}
}
