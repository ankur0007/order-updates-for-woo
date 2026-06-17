<?php
/**
 * Schema + creation for the attachments table.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Attachments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Schema management: a dbDelta CREATE statement + a direct table-existence
// check on our own table. The `CREATE TABLE {$this->...}` string is interpolated
// per the dbDelta convention; the existence check binds its value via prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

/**
 * Holds the attachments table name and creates it via dbDelta when needed.
 */
final class AttachmentsTable {
	private const VERSION     = '1.0.0';
	private const VERSION_KEY = 'order_updates_for_woo_attachments_table_version';

	/**
	 * Fully-qualified attachments table name.
	 *
	 * @var string
	 */
	public string $attachments;

	/** Resolve the prefixed table name. */
	public function __construct() {
		global $wpdb;
		$this->attachments = $wpdb->prefix . 'order_updates_for_woo_attachments';
	}

	/** Hook table creation onto `init`. */
	public function init(): void {
		add_action( 'init', array( $this, 'maybe_create_tables' ) );
	}

	/** Create or upgrade the attachments table when the schema is missing or stale. */
	public function maybe_create_tables(): void {
		global $wpdb;

		// Belt-and-braces: the version key tells us nothing if the option got
		// out of sync (e.g. table dropped manually for debugging). Re-run dbDelta
		// when either signal says the schema is missing.
		$version_matches = (string) get_option( self::VERSION_KEY, '' ) === self::VERSION;
		$schema_intact   = $this->table_exists( $this->attachments );

		if ( $version_matches && $schema_intact ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$this->attachments} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT(20) UNSIGNED NOT NULL,
			update_id BIGINT(20) UNSIGNED NOT NULL,
			note_id BIGINT(20) UNSIGNED NOT NULL,
			note_type VARCHAR(20) NOT NULL DEFAULT 'internal',
			file_name VARCHAR(255) NOT NULL,
			original_name VARCHAR(255) NOT NULL,
			mime_type VARCHAR(100) NOT NULL,
			file_size BIGINT(20) UNSIGNED NOT NULL,
			uploaded_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			uploaded_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY update_id (update_id),
			KEY note_lookup (note_id, note_type)
		) {$c};" 
		);

		update_option( self::VERSION_KEY, self::VERSION );
	}

	/**
	 * True when the given table exists in the database.
	 *
	 * @param string $table Fully-qualified table name.
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;

		return (string) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		) === $table;
	}
}
