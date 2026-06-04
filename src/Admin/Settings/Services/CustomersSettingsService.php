<?php
/**
 * Customers settings — everything that affects what the order's customer
 * sees or can do on their order page (and the email flow that follows).
 *
 * Update-form feature toggles (which fields admins see when creating an
 * update) live on the General tab. Admin-only toggles (notify-me-on-X, edit /
 * delete policies) live on the Admin Only tab.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Settings\Services;

use OrderUpdatesForWoo\Shared\Config\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings fields and values for the customers section.
 */
final class CustomersSettingsService {
	public const SECTION_ID = 'customers';

	/**
	 * Human-readable section label for the nav.
	 */
	public function label(): string {
		return __( 'Customers', 'order-updates-for-woo' );
	}

	/**
	 * Settings fields for this section.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array {
		return array(
			array(
				'name' => __( 'Customer experience', 'order-updates-for-woo' ),
				'type' => 'title',
				'desc' => __( 'Control what customers see on their order page and how they interact with the team.', 'order-updates-for-woo' ),
				'id'   => 'order_updates_for_woo_customer_section',
			),
			array(
				'name'    => __( 'Show assignee to customers', 'order-updates-for-woo' ),
				'desc'    => __( 'Display the assigned team member\'s name on the customer-facing update page.', 'order-updates-for-woo' ),
				'id'      => 'order_updates_for_woo_show_assignee_to_customers',
				'default' => 'no',
				'type'    => 'checkbox',
			),
			array(
				'name'    => __( 'Allow customers to create updates', 'order-updates-for-woo' ),
				'desc'    => __( 'Let customers open new update threads from their order page. Replies to existing updates are always allowed.', 'order-updates-for-woo' ),
				'id'      => Constants::ALLOW_CUSTOMER_CREATE_UPDATE_OPTION,
				'default' => 'no',
				'type'    => 'checkbox',
			),
			array(
				'name'    => __( 'Enable customer rating', 'order-updates-for-woo' ),
				'desc'    => __( 'Show a 5-star rating form on resolved updates so customers can rate the experience.', 'order-updates-for-woo' ),
				'id'      => 'order_updates_for_woo_enable_customer_rating',
				'default' => 'yes',
				'type'    => 'checkbox',
			),
			array(
				'name'    => __( 'Allow rating comment', 'order-updates-for-woo' ),
				'desc'    => __( 'Show an optional comment box alongside the rating.', 'order-updates-for-woo' ),
				'id'      => 'order_updates_for_woo_enable_customer_rating_comment',
				'default' => 'yes',
				'type'    => 'checkbox',
			),
			array(
				'name'    => __( 'Email customer for rating', 'order-updates-for-woo' ),
				'desc'    => __( 'When an update is marked solved, email the customer with a link to leave a rating.', 'order-updates-for-woo' ),
				'id'      => 'order_updates_for_woo_enable_customer_rating_email',
				'default' => 'yes',
				'type'    => 'checkbox',
			),
			array(
				'name'    => __( 'Follow-up email after rating', 'order-updates-for-woo' ),
				'desc'    => __( 'After a rating is submitted, send the customer a follow-up: a thank-you with share buttons for high ratings, or an "we\'ll do better" reply prompt for low ratings.', 'order-updates-for-woo' ),
				'id'      => 'order_updates_for_woo_enable_customer_rating_followup_email',
				'default' => 'yes',
				'type'    => 'checkbox',
			),
			array(
				'name'    => __( 'Share text for promoter emails', 'order-updates-for-woo' ),
				'desc'    => __( 'The message pre-filled in social share links sent to happy customers. Available tokens: {site_name}, {site_url}.', 'order-updates-for-woo' ),
				'id'      => Constants::PROMOTER_SHARE_TEXT_OPTION,
				'type'    => 'textarea',
				'default' => Constants::PROMOTER_SHARE_TEXT_DEFAULT,
				'css'     => 'width:100%; min-height:60px;',
			),
			array(
				'name'    => __( 'Follow-up message for low ratings (1-3 stars)', 'order-updates-for-woo' ),
				'desc'    => __( 'Shown to customers who leave a low rating, in the follow-up email. A button back to their update is added automatically below this text.', 'order-updates-for-woo' ),
				'id'      => Constants::DETRACTOR_FOLLOWUP_TEXT_OPTION,
				'type'    => 'textarea',
				'default' => Constants::DETRACTOR_FOLLOWUP_TEXT_DEFAULT,
				'css'     => 'width:100%; min-height:80px;',
			),
			array(
				'name'              => __( 'Customer link expiry (days)', 'order-updates-for-woo' ),
				'desc'              => __( 'How long a link in a customer email stays valid. After this many days the link shows a "log in or contact us" page instead of the update.', 'order-updates-for-woo' ),
				'id'                => Constants::CUSTOMER_LINK_EXPIRY_DAYS_OPTION,
				'type'              => 'number',
				'default'           => 30,
				'custom_attributes' => array(
					'min'  => 1,
					'max'  => 365,
					'step' => 1,
				),
				'css'               => 'width:90px;',
			),
			array(
				'name'        => __( 'Support contact email', 'order-updates-for-woo' ),
				'desc'        => __( 'Shown to customers who click an expired link and are not logged in. Leave blank to use the site admin email.', 'order-updates-for-woo' ),
				'id'          => Constants::SUPPORT_CONTACT_EMAIL_OPTION,
				'type'        => 'email',
				'default'     => '',
				'placeholder' => (string) get_option( 'admin_email', '' ),
				'css'         => 'width:320px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'order_updates_for_woo_customer_section',
			),
		);
	}
}
