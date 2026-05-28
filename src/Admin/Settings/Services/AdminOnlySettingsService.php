<?php
/**
 * Admin only settings — toggles that admins choose for themselves, either
 * for their own inbox volume (notify-on-X switches) or for granting / denying
 * other users' ability to edit and delete content (restricted features).
 *
 * Customer-facing copy and customer-experience feature switches live on the
 * General tab.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Settings\Services;

use OrderUpdatesForWoo\Shared\Config\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AdminOnlySettingsService {
	public const SECTION_ID = 'admin_only';

	public function label(): string {
		return __( 'Admin Only', 'order-updates-for-woo' );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array {
		return array(
			// Group 1 — Admin inbox toggles. Only affect the site admin's email.
			// Assignees are always notified regardless of these toggles.
			array(
				'name' => __( 'Admin notifications', 'order-updates-for-woo' ),
				'type' => 'title',
				'desc' => __( 'Control which events copy the site admin (the user whose email is set at Settings → General → Administration Email Address). These toggles only affect the admin\'s own inbox.', 'order-updates-for-woo' ),
				'id'   => 'order_updates_for_woo_admin_notifications_section',
			),
			array(
				'name'    => __( 'Email site admin when a customer opens a new update', 'order-updates-for-woo' ),
				'desc'    => __( 'When off, only the assigned staff member is notified.', 'order-updates-for-woo' ),
				'id'      => Constants::NOTIFY_ADMIN_ON_CUSTOMER_CREATE_OPTION,
				'default' => 'no',
				'type'    => 'checkbox',
			),
			array(
				'name'    => __( 'Email site admin on low ratings (1-3 stars)', 'order-updates-for-woo' ),
				'desc'    => __( 'When on, the admin gets a heads-up email whenever a customer leaves a 1, 2 or 3 star rating. Useful for spotting detractors early.', 'order-updates-for-woo' ),
				'id'      => Constants::NOTIFY_ADMIN_ON_DETRACTOR_RATING_OPTION,
				'default' => 'yes',
				'type'    => 'checkbox',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'order_updates_for_woo_admin_notifications_section',
			),

			// Group 2 — Restricted features. Admin policy decisions about what
			// staff/customer can edit or delete. Default off. Even when on,
			// NoteActionPolicy still enforces the latest-only rule, so authors
			// can only modify the most recent note in each thread.
			array(
				'name' => __( 'Restricted features', 'order-updates-for-woo' ),
				'type' => 'title',
				'desc' => __( 'Allowing edits and deletes can weaken the audit trail of customer conversations. As a guardrail, only the most recent message in each thread can be changed — the moment a new message is posted, all previous notes lock permanently.', 'order-updates-for-woo' ),
				'id'   => 'order_updates_for_woo_restricted_section',
			),
			array(
				'name'    => __( 'Allow editing notes', 'order-updates-for-woo' ),
				'desc'    => __( 'Let authors edit their own latest note (within the edit window). Older notes stay locked.', 'order-updates-for-woo' ),
				'id'      => Constants::ALLOW_NOTE_EDIT_OPTION,
				'default' => 'no',
				'type'    => 'checkbox',
			),
			array(
				'name'    => __( 'Allow deleting notes', 'order-updates-for-woo' ),
				'desc'    => __( 'Let authors delete their own latest note (within the edit window). Older notes stay locked.', 'order-updates-for-woo' ),
				'id'      => Constants::ALLOW_NOTE_DELETE_OPTION,
				'default' => 'no',
				'type'    => 'checkbox',
			),
			array(
				'name'    => __( 'Allow deleting updates', 'order-updates-for-woo' ),
				'desc'    => __( 'Allow update records to be deleted instead of kept as history. Deleting an update removes the whole thread permanently.', 'order-updates-for-woo' ),
				'id'      => 'order_updates_for_woo_allow_deletion',
				'default' => 'no',
				'type'    => 'checkbox',
			),
			array(
				'name'    => __( 'Allow members to delete own internal notes', 'order-updates-for-woo' ),
				'desc'    => __( 'Sub-toggle for "Allow deleting notes" — when off, only customer-side delete applies. Customer-facing notes are never deletable; they keep an edit history instead.', 'order-updates-for-woo' ),
				'id'      => 'order_updates_for_woo_allow_member_note_delete',
				'default' => 'no',
				'type'    => 'checkbox',
			),
			array(
				'name'              => __( 'Note edit window (minutes)', 'order-updates-for-woo' ),
				'desc'              => __( 'How long after posting an author can still edit or delete their latest note. Default 1 minute — a typo escape hatch, not a rewrite window.', 'order-updates-for-woo' ),
				'id'                => Constants::NOTE_EDIT_WINDOW_OPTION,
				'type'              => 'number',
				'default'           => 1,
				'desc_tip'          => true,
				'custom_attributes' => array( 'min' => 1, 'max' => 1440, 'step' => 1 ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'order_updates_for_woo_restricted_section',
			),

			array(
				'name' => __( 'Order edit lock', 'order-updates-for-woo' ),
				'type' => 'title',
				'desc' => __(
					'Order Updates replaces WooCommerce&rsquo;s blocking &ldquo;X is currently editing this order&rdquo; modal with a non-blocking warning banner. Team members can keep posting notes while another member holds the edit lock. Order fields (status, billing, items, etc.) stay locked until someone clicks &ldquo;Take over&rdquo; &mdash; same data safety as WooCommerce&rsquo;s default, just without the screen-wide interruption.',
					'order-updates-for-woo'
				),
				'id'   => 'order_updates_for_woo_order_lock_section',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'order_updates_for_woo_order_lock_section',
			),

		);
	}
}
