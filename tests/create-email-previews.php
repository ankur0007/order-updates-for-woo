<?php

use OrderUpdatesForWoo\Shared\Notifications\NotificationDispatcher;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use OrderUpdatesForWoo\Shared\Updates\UpdatesTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$args     = isset( $args ) && is_array( $args ) ? $args : array();
$order_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
$limit    = isset( $args[1] ) ? max( 1, absint( $args[1] ) ) : 1;

if ( ! $order_id || ! wc_get_order( $order_id ) ) {
	echo "Provide a valid WooCommerce order ID.\n";
	echo "Example: wp eval-file wp-content/plugins/order-updates-for-woo/tests/create-email-previews.php 123\n";
	return;
}

$updates_table    = new UpdatesTable();
$order_updates_db = new OrderUpdatesDb( $updates_table );
$order_updates    = $order_updates_db->get_order_updates( $order_id, $limit, 0 );

if ( empty( $order_updates ) ) {
	echo "No updates found for order #{$order_id}.\n";
	return;
}

$preview_root = WP_CONTENT_DIR . '/plugins/order-updates-for-woo/tests/output/email-previews';
$preview_dir  = trailingslashit( $preview_root ) . gmdate( 'Ymd-His' );

wp_mkdir_p( $preview_dir );

$captured_files       = array();
$current_preview_type = 'email-preview';

add_filter(
	'woocommerce_mail_callback',
	static function () use ( &$captured_files, &$current_preview_type, $preview_dir ) {
		return static function ( $to, $subject, $message, $headers, $attachments ) use ( &$captured_files, &$current_preview_type, $preview_dir ) {
			unset( $to, $headers, $attachments );

			$filename     = $current_preview_type . '.html';
			$file_path    = trailingslashit( $preview_dir ) . $filename;
			$preview_html = '<!doctype html><html><head><meta charset="utf-8"><title>' .
				esc_html( (string) $subject ) .
				'</title></head><body style="margin:0; background:#f6f7f7;">' .
				(string) $message .
				'</body></html>';

			file_put_contents( $file_path, $preview_html );
			$captured_files[] = $file_path;

			return true;
		};
	}
);

add_filter(
	'woocommerce_email_enabled_order_updates_for_woo_admin_update',
	'__return_true'
);
add_filter(
	'woocommerce_email_enabled_order_updates_for_woo_assignee_update',
	'__return_true'
);
add_filter(
	'woocommerce_email_enabled_order_updates_for_woo_customer_update',
	'__return_true'
);

if ( function_exists( 'WC' ) && WC()->mailer() ) {
	WC()->mailer()->get_emails();
}

$dispatcher = new NotificationDispatcher();

foreach ( $order_updates as $order_update ) {
	$update_id = absint( $order_update['id'] ?? 0 );

	if ( ! $update_id ) {
		continue;
	}

	$current_preview_type = 'Owner-preview';
	$dispatcher->send_admin_notification(
		array(
			'update_id' => $update_id,
		)
	);

	if ( ! empty( $order_update['assignee_user_id'] ) ) {
		$assignee_user_id = absint( $order_update['assignee_user_id'] );

		// Check if there was a previous assignee who was unassigned.
		global $wpdb;
		$prev_assignee_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT assignee_user_id FROM {$wpdb->prefix}order_updates_for_woo_assignees
				WHERE update_id = %d AND is_active = 0
				ORDER BY unassigned_at DESC LIMIT 1",
				$update_id
			)
		);

		$has_prev_assignee = $prev_assignee_id > 0;

		$current_preview_type = 'Assignee-assigned-preview';
		$dispatcher->send_assignee_notification(
			array(
				'update_id'        => $update_id,
				'assignee_user_id' => $assignee_user_id,
				'context'          => 'assigned',
			)
		);

		// Reassigned: current assignee gets this when they replaced someone.
		$current_preview_type = 'Assignee-reassigned-preview';
		$dispatcher->send_assignee_notification(
			array(
				'update_id'        => $update_id,
				'assignee_user_id' => $assignee_user_id,
				'context'          => 'reassigned',
			)
		);

		// Unassigned: goes to the previous assignee if one exists, otherwise current.
		$current_preview_type = 'Assignee-unassigned-preview';
		$dispatcher->send_assignee_notification(
			array(
				'update_id'        => $update_id,
				'assignee_user_id' => $has_prev_assignee ? $prev_assignee_id : $assignee_user_id,
				'context'          => 'unassigned',
			)
		);
	}

	if ( ! empty( $order_update['customer_visible'] ) && ! empty( $order_update['customer_note'] ) ) {
		$current_preview_type = 'Customer-preview';
		$dispatcher->send_customer_notification(
			array(
				'update_id' => $update_id,
			)
		);

		// Simulate a note update — same email, different subject line context.
		$current_preview_type = 'Customer-note-updated-preview';
		add_filter(
			'order_updates_for_woo_customer_email_detail_rows',
			static function ( $rows ) {
				$rows[] = array(
					'label' => 'Note updated',
					'value' => 'Yes',
				);
				return $rows;
			}
		);
		$dispatcher->send_customer_notification(
			array(
				'update_id' => $update_id,
			)
		);
	}
}

if ( empty( $captured_files ) ) {
	echo "No email previews were generated.\n";
	return;
}

echo 'Using latest ' . count( $order_updates ) . ' update(s) from order #' . $order_id . ".\n";
echo 'Generated ' . count( $captured_files ) . " email preview files.\n";

foreach ( $captured_files as $captured_file ) {
	echo $captured_file . "\n";
}
