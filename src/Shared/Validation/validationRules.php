<?php
/**
 * Validation rules for update payloads.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Local file-scope template variables, not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$update_rules = [
	'update_id' => [
		'type' => 'number',
	],
	'order_id' => [
		'type' => 'order_id',
		'required' => true,
	],
	'title' => [
		'type' => 'text',
		'required' => true,
		'max_length' => 191,
		'label' => __('Update title', 'order-updates-for-woo'),
	],
	'internal_note' => [
		'type' => 'plain_text_note',
		'label' => __('Internal note', 'order-updates-for-woo'),
		'max_length' => 500,
	],
	'customer_note' => [
		'type' => 'plain_text_note',
		'label' => __('Customer note', 'order-updates-for-woo'),
		'max_length' => 500,
	],
	'color' => [
		'type' => 'color',
		'required' => true,
		'label' => __('Color', 'order-updates-for-woo'),
	],
	'assignee_id' => [
		'type' => 'user_id',
	],
];

return apply_filters('order_updates_for_woo_validation_rules', $update_rules);
