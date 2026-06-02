<?php
/**
 * Owns the plugin's custom table names and schema creation/migration.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Updates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Direct queries on our own tables. Table names are safe; user input always uses prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.SlowDBQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter

/**
 * Holds the fully-qualified names of the plugin's tables and creates or
 * upgrades their schema via dbDelta on init.
 */
final class UpdatesTable {
	private const VERSION     = '1.0.0';
	private const VERSION_KEY = 'order_updates_for_woo_table_version';

	public string $updates;
	public string $assignees;
	public string $notes;
	public string $customer_notes;
	public string $customer_note_history;
	public string $ratings;

	/** Resolve each table name from the WordPress table prefix. */
	public function __construct() {
		global $wpdb;
		$p = $wpdb->prefix . 'order_updates_for_woo';

		$this->updates               = $p;
		$this->assignees             = $p . '_assignees';
		$this->notes                 = $p . '_internal_notes';
		$this->customer_notes        = $p . '_customer_notes';
		$this->customer_note_history = $p . '_customer_note_history';
		$this->ratings               = $p . '_ratings';
	}

	/** Hook schema creation/upgrade to WordPress init. */
	public function init(): void {
		add_action( 'init', array( $this, 'maybe_create_tables' ) );
	}

	/** Create or upgrade the plugin tables when the version or schema is stale. */
	public function maybe_create_tables(): void {
		global $wpdb;

		$version_matches = (string) get_option( self::VERSION_KEY, '' ) === self::VERSION;
		$schema_intact   = $this->column_exists( $this->customer_notes, 'queued_at' )
			&& $this->table_exists( $this->ratings )
			&& $this->column_exists( $this->notes, 'mentioned_user_ids' )
			&& $this->column_exists( $this->notes, 'edited_at' )
			&& $this->column_exists( $this->customer_notes, 'edited_at' )
			&& $this->column_exists( $this->customer_notes, 'kind' )
			&& $this->column_exists( $this->updates, 'status' )
			&& $this->table_exists( $this->customer_note_history )
			&& $this->column_exists( $this->updates, 'assignee_since_note_id' );

		if ( $version_matches && $schema_intact ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$this->updates} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT(20) UNSIGNED NOT NULL,
			title VARCHAR(191) NOT NULL,
			status VARCHAR(50) NOT NULL DEFAULT '',
			customer_visible TINYINT(1) NOT NULL DEFAULT 0,
			color VARCHAR(20) NOT NULL DEFAULT '#dca100',
			created_by BIGINT(20) UNSIGNED NOT NULL,
			last_updated_by BIGINT(20) UNSIGNED NULL,
			solved_by BIGINT(20) UNSIGNED NULL,
			is_resolved TINYINT(1) NOT NULL DEFAULT 0,
			solved_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			last_updated_at DATETIME NULL,
			assignee_since_note_id BIGINT(20) UNSIGNED NULL,
			previous_assignee_name VARCHAR(100) NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY title (title),
			KEY order_title (order_id, title),
			KEY order_created_at (order_id, created_at),
			KEY resolved_order (is_resolved, order_id),
			KEY created_at (created_at),
			KEY resolved_created_at (is_resolved, created_at)
		) {$c};" 
		);

		dbDelta(
			"CREATE TABLE {$this->assignees} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			update_id BIGINT(20) UNSIGNED NOT NULL,
			assignee_user_id BIGINT(20) UNSIGNED NOT NULL,
			assigned_by BIGINT(20) UNSIGNED NOT NULL,
			assigned_at DATETIME NOT NULL,
			notified_at DATETIME NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			unassigned_at DATETIME NULL,
			unassigned_by BIGINT(20) UNSIGNED NULL,
			last_updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY update_id (update_id),
			KEY assignee_user_id (assignee_user_id),
			KEY is_active (is_active),
			KEY assigned_by (assigned_by),
			KEY update_assignee (update_id, assignee_user_id),
			KEY update_active (update_id, is_active),
			KEY update_assigned_at (update_id, assigned_at)
		) {$c};" 
		);

		dbDelta(
			"CREATE TABLE {$this->notes} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			update_id BIGINT(20) UNSIGNED NOT NULL,
			note VARCHAR(500) NOT NULL,
			mentioned_user_ids VARCHAR(191) NOT NULL DEFAULT '',
			created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_by_name VARCHAR(191) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			edited_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY update_id (update_id)
		) {$c};" 
		);

		dbDelta(
			"CREATE TABLE {$this->ratings} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			update_id BIGINT(20) UNSIGNED NOT NULL,
			order_id BIGINT(20) UNSIGNED NOT NULL,
			stars TINYINT(1) UNSIGNED NULL,
			comment VARCHAR(500) NOT NULL DEFAULT '',
			created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_by_name VARCHAR(191) NOT NULL DEFAULT '',
			requested_at DATETIME NULL,
			request_notified_at DATETIME NULL,
			created_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY update_id (update_id),
			KEY order_id (order_id),
			KEY stars (stars)
		) {$c};" 
		);

		dbDelta(
			"CREATE TABLE {$this->customer_notes} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			update_id BIGINT(20) UNSIGNED NOT NULL,
			note VARCHAR(500) NOT NULL,
			kind VARCHAR(20) NOT NULL DEFAULT 'note',
			queued_at DATETIME NULL,
			notified_at DATETIME NULL,
			created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_by_name VARCHAR(191) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			edited_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY update_id (update_id),
			KEY update_notified (update_id, notified_at)
		) {$c};" 
		);

		// dbDelta only ADDs columns, it doesn't drop them — explicitly remove
		// the legacy `status` column on customer_notes if it survived from an
		// earlier dev migration. Safe to call repeatedly: the SHOW COLUMNS
		// guard makes it a no-op once the drop has run.
		if ( $this->column_exists( $this->customer_notes, 'status' ) ) {
			$wpdb->query( "ALTER TABLE {$this->customer_notes} DROP COLUMN status" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- own-schema table.
		}

		dbDelta(
			"CREATE TABLE {$this->customer_note_history} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			note_id BIGINT(20) UNSIGNED NOT NULL,
			prior_note VARCHAR(500) NOT NULL,
			edited_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			edited_by_name VARCHAR(191) NOT NULL DEFAULT '',
			edited_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY note_id (note_id)
		) {$c};" 
		);

		$this->backfill_status_from_color();
		$this->drop_orphaned_options();

		update_option( self::VERSION_KEY, self::VERSION );
	}

	/**
	 * Delete WP options that the plugin used to register but no longer reads.
	 * Runs as part of the schema migration so a single version bump cleans
	 * the database without admins having to touch anything.
	 */
	private function drop_orphaned_options(): void {
		$orphans = array(
			'order_updates_for_woo_enable_customer_visibility',
		);

		foreach ( $orphans as $option ) {
			delete_option( $option );
		}
	}

	/**
	 * Populate the new `updates.status` column for rows that still have it
	 * empty after a schema upgrade. The lookup uses the seeded default
	 * status list (Constants::STATUS_SEED_DEFAULTS) — every fresh install
	 * starts there, and admins who renamed/recolored statuses already have
	 * matching keys for the colors that were ever written.
	 *
	 * Unmatched colors stay with an empty status; the rendering layer falls
	 * back to the bare color, so nothing breaks visually.
	 */
	private function backfill_status_from_color(): void {
		global $wpdb;

		$has_blank_status = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own-schema table name.
			"SELECT COUNT(*) FROM {$this->updates} WHERE status = '' OR status IS NULL"
		);

		if ( $has_blank_status <= 0 ) {
			return;
		}

		foreach ( \OrderUpdatesForWoo\Shared\Config\Constants::STATUS_SEED_DEFAULTS as $status ) {
			$key   = (string) ( $status['key'] ?? '' );
			$color = strtolower( (string) ( $status['color'] ?? '' ) );

			if ( '' === $key || '' === $color ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->updates}
				SET status = %s
				WHERE ( status = '' OR status IS NULL )
				  AND LOWER( color ) = %s",
					$key,
					$color
				) 
			);
		}
	}

	/**
	 * Whether a column exists on one of our own (allowlisted) tables.
	 *
	 * @param string $table  Table name (must be one of this plugin's tables).
	 * @param string $column Column to look for.
	 */
	private function column_exists( string $table, string $column ): bool {
		global $wpdb;

		// Identifier guard: only one of our own tables may be inspected.
		// `prepare()` cannot parameterise table names, so an allowlist is the
		// safe substitute. Anything outside the list returns false.
		$allowed = array(
			$this->updates,
			$this->assignees,
			$this->notes,
			$this->customer_notes,
			$this->customer_note_history,
			$this->ratings,
		);

		if ( ! in_array( $table, $allowed, true ) ) {
			return false;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $table is allowlisted above; identifier cannot be parameterised.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW COLUMNS FROM `' . $table . '` LIKE %s',
				$column
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return null !== $found && '' !== (string) $found;
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Table name.
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		return null !== $found && '' !== (string) $found;
	}
}
