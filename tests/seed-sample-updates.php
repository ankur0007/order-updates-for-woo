<?php
/**
 * Seed sample order updates for a WooCommerce order.
 *
 * Usage:
 * wp eval-file wp-content/plugins/order-updates-for-woo/tests/seed-sample-updates.php 123 [assignee_user_id] [acting_user_id]
 *
 * @package OrderUpdatesForWoo
 */

use OrderUpdatesForWoo\API\Endpoints\SaveUpdateEndpoint;
use OrderUpdatesForWoo\Shared\Updates\UpdatesTable;
use OrderUpdatesForWoo\Shared\Validation\Validator;

if (! defined('ABSPATH')) {
	exit;
}

$args = isset($args) && is_array($args) ? $args : [];
$order_id = isset($args[0]) ? absint($args[0]) : 0;
$assignee_id = isset($args[1]) ? absint($args[1]) : 0;
$acting_user_id = isset($args[2]) ? absint($args[2]) : 0;

if (! $order_id || ! wc_get_order($order_id)) {
	echo "Provide a valid WooCommerce order ID.\n";
	echo "Example: wp eval-file wp-content/plugins/order-updates-for-woo/tests/seed-sample-updates.php 123\n";
	return;
}

if (! $acting_user_id) {
	$admin_ids = get_users(
		[
			'role__in' => [ 'administrator', 'shop_manager' ],
			'number' => 1,
			'fields' => 'ids',
		]
	);

	$acting_user_id = ! empty($admin_ids) ? absint($admin_ids[0]) : get_current_user_id();
}

if (! $acting_user_id || ! get_user_by('id', $acting_user_id)) {
	echo "Could not find an acting user to create updates.\n";
	return;
}

wp_set_current_user($acting_user_id);

$sample_updates = [
	[
		'title' => 'Reviewing by backend team',
		'internal_note' => 'Waiting for backend review before replying to the customer.',
		'customer_visible' => true,
		'customer_note' => 'We are reviewing your order and will update you shortly.',
		'color' => '#DCA100',
	],
	[
		'title' => 'Assigned for verification',
		'internal_note' => 'Assigned this order for payment and order data verification.',
		'customer_visible' => false,
		'customer_note' => '',
		'color' => '#2271B1',
	],
	[
		'title' => 'Stock confirmed',
		'internal_note' => 'Warehouse confirmed stock availability for this order.',
		'customer_visible' => true,
		'customer_note' => 'The items in your order are confirmed and being prepared.',
		'color' => '#2E8B57',
	],
	[
		'title' => 'Packing in progress',
		'internal_note' => 'Packing has started and labels are being prepared.',
		'customer_visible' => true,
		'customer_note' => 'Your order is currently being packed.',
		'color' => '#8C6C1F',
	],
	[
		'title' => 'Address reviewed',
		'internal_note' => 'Shipping address reviewed and verified by support.',
		'customer_visible' => false,
		'customer_note' => '',
		'color' => '#556B2F',
	],
	[
		'title' => 'Priority review',
		'internal_note' => 'Order flagged for faster operational review.',
		'customer_visible' => true,
		'customer_note' => 'Your order has been prioritized for quicker handling.',
		'color' => '#D63638',
	],
	[
		'title' => 'Handed to shipping team',
		'internal_note' => 'Shipping team has taken over this order for dispatch prep.',
		'customer_visible' => true,
		'customer_note' => 'Your order has moved to our shipping team.',
		'color' => '#3C78D8',
	],
	[
		'title' => 'Awaiting courier pickup',
		'internal_note' => 'Packed and waiting for courier handoff window.',
		'customer_visible' => true,
		'customer_note' => 'Your order is packed and waiting for courier pickup.',
		'color' => '#6AA84F',
	],
	[
		'title' => 'Internal follow-up',
		'internal_note' => 'Follow-up requested with warehouse because pickup window was delayed.',
		'customer_visible' => false,
		'customer_note' => '',
		'color' => '#674EA7',
	],
	[
		'title' => 'Final dispatch review',
		'internal_note' => 'Final check before dispatch confirmation is sent.',
		'customer_visible' => true,
		'customer_note' => 'Final checks are in progress before dispatch.',
		'color' => '#0B8043',
	],
];

$save_update_endpoint = new SaveUpdateEndpoint(new UpdatesTable(), new Validator());
$created_count = 0;

foreach ($sample_updates as $sample_update) {
	$request = new WP_REST_Request('POST', '/order-updates-for-woo/v1/updates');
	$request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
	$request->set_param('order_id', $order_id);
	$request->set_param('title', $sample_update['title']);
	$request->set_param('internal_note', $sample_update['internal_note']);
	$request->set_param('customer_visible', $sample_update['customer_visible']);
	$request->set_param('customer_note', $sample_update['customer_note']);
	$request->set_param('color', $sample_update['color']);
	$request->set_param('assignee_id', $assignee_id);

	$can_access = $save_update_endpoint->can_access($request);

	if (is_wp_error($can_access)) {
		echo 'Permission error: ' . $can_access->get_error_message() . "\n";
		return;
	}

	$response = $save_update_endpoint->handle($request);

	if (is_wp_error($response)) {
		echo 'Could not create sample update "' . $sample_update['title'] . '": ' . $response->get_error_message() . "\n";
		continue;
	}

	$response_data = $response->get_data();
	$created_count++;

	echo 'Created update #' . absint($response_data['updateId'] ?? 0) . ': ' . $sample_update['title'] . "\n";
}

echo "Created {$created_count} sample updates for order #{$order_id}.\n";
