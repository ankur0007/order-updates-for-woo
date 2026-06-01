<?php
/**
 * Notifications settings — two-stage retention for staff notifications.
 *
 * Active rows move to Archived after N days, then archived rows are deleted
 * after a further M days. The scheduled cleanup reads these option values.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Settings\Services;

use OrderUpdatesForWoo\Admin\Notifications\NotificationsPageController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NotificationsSettingsService {
	public const SECTION_ID = 'notifications';

	public function label(): string {
		return __( 'Notifications', 'order-updates-for-woo' );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array {
		return array(
			array(
				'name' => __( 'Notifications', 'order-updates-for-woo' ),
				'type' => 'title',
				'desc' => __( 'How long staff notifications are kept. They move to Archived on their own, then drop off after a further period. Favourited notifications are never auto-archived or deleted.', 'order-updates-for-woo' ),
				'id'   => 'order_updates_for_woo_notifications_section',
			),
			array(
				'name'              => __( 'Move to Archived after (days)', 'order-updates-for-woo' ),
				'desc'              => __( 'Days an active notification waits before it moves to the Archived tab. Set to 0 to never auto-archive.', 'order-updates-for-woo' ),
				'id'                => NotificationsPageController::OPT_ARCHIVE_AFTER_DAYS,
				'type'              => 'number',
				'default'           => 30,
				'desc_tip'          => true,
				'custom_attributes' => array( 'min' => 0, 'max' => 365, 'step' => 1 ),
			),
			array(
				'name'              => __( 'Delete from Archived after (days)', 'order-updates-for-woo' ),
				'desc'              => __( 'Days an archived notification is kept before it is removed for good. Set to 0 to keep archived notifications indefinitely.', 'order-updates-for-woo' ),
				'id'                => NotificationsPageController::OPT_AUTODELETE_DAYS,
				'type'              => 'number',
				'default'           => 30,
				'desc_tip'          => true,
				'custom_attributes' => array( 'min' => 0, 'max' => 365, 'step' => 1 ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'order_updates_for_woo_notifications_section',
			),
		);
	}
}
