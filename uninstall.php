<?php
/**
 * Runs only when the plugin is deleted from the admin (not on deactivate).
 * Removes all tables, options, transients, user/order meta, attachments,
 * and scheduled events created by this plugin. Deactivation must NOT
 * delete user data — that's handled in the deactivation hook in the
 * main plugin file.
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// One-off cleanup queries on our own tables; table names are safe, not user input.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$prefix = $wpdb->prefix . 'order_updates_for_woo';

// 1. Drop every table the plugin created.
$tables = array(
	$prefix,                              // updates
	$prefix . '_assignees',
	$prefix . '_internal_notes',
	$prefix . '_customer_notes',
	$prefix . '_customer_note_history',
	$prefix . '_ratings',
	$prefix . '_attachments',
	$prefix . '_analytics_lookup',
);

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// 2. Delete every option whose key starts with our prefix (catches
//    settings, schema versions, analytics generation counters, the
//    round-robin pointer, every value in Constants.php — by definition,
//    we can't miss anything because we match the prefix, not enumerated keys).
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'order_updates_for_woo_' ) . '%'
	)
);

// 3. Delete transients (both the value and timeout rows).
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_' . $wpdb->esc_like( 'order_updates_for_woo_' ) . '%',
		'_transient_timeout_' . $wpdb->esc_like( 'order_updates_for_woo_' ) . '%'
	)
);

// 4. Delete user meta (admin-bar notifications, staff email mute prefs,
//    customer email prefs).
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
		$wpdb->esc_like( 'order_updates_for_woo_' ) . '%',
		$wpdb->esc_like( '_order_updates_for_woo_' ) . '%'
	)
);

// 5. Delete order meta on HPOS-aware sites and classic sites.
$hpos_orders_meta = $wpdb->prefix . 'wc_orders_meta';

if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_orders_meta ) ) === $hpos_orders_meta ) {
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$hpos_orders_meta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
			$wpdb->esc_like( 'order_updates_for_woo_' ) . '%',
			$wpdb->esc_like( '_order_updates_for_woo_' ) . '%'
		)
	);
}

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
		$wpdb->esc_like( 'order_updates_for_woo_' ) . '%',
		$wpdb->esc_like( '_order_updates_for_woo_' ) . '%'
	)
);

// 6. Remove the customer-portal page if one was auto-created.
$page_id = (int) get_option( 'order_updates_for_woo_page_id' );

if ( $page_id ) {
	wp_delete_post( $page_id, true );
}

// 7. Unschedule any cron events. The deactivation hook should already have
//    cleared these, but we run again here so plugins force-deleted via the
//    Plugins screen (which skips deactivation) still leave WP-Cron clean.
wp_clear_scheduled_hook( 'order_updates_for_woo_analytics_warmup' );

// Cancel any queued Action Scheduler jobs (async notification emails) so
// nothing fires after the tables are gone.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	$async_hooks = array(
		'order_updates_for_woo_send_admin_notification',
		'order_updates_for_woo_send_assignee_notification',
		'order_updates_for_woo_send_customer_notification',
		'order_updates_for_woo_send_rating_request',
		'order_updates_for_woo_send_rating_followup',
		'order_updates_for_woo_send_internal_mention',
		'order_updates_for_woo_send_participant_update',
		'order_updates_for_woo_send_shared_link_email',
	);

	foreach ( $async_hooks as $async_hook ) {
		as_unschedule_all_actions( $async_hook );
	}
}

// 8. Remove the attachment directory tree.
$uploads          = wp_upload_dir( null, false );
$attachments_root = trailingslashit( (string) ( $uploads['basedir'] ?? WP_CONTENT_DIR . '/uploads' ) ) . 'order-updates-for-woo';

if ( is_dir( $attachments_root ) ) {
	$delete_recursively = static function ( string $dir ) use ( &$delete_recursively ): void {
		$entries = @scandir( $dir );

		if ( false === $entries ) {
			return;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $dir . '/' . $entry;

			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$delete_recursively( $path );
			} else {
				wp_delete_file( $path );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- uninstall runs at request time; WP_Filesystem may not be initialised here, and rmdir is the only reliable cross-host primitive for emptying our own attachments tree.
		@rmdir( $dir );
	};

	$delete_recursively( $attachments_root );
}
