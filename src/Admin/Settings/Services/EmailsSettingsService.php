<?php
/**
 * Emails settings — delivery mode + per-email directory.
 *
 * Per-email subject/template/recipient still live in WooCommerce →
 * Settings → Emails (the standard WC pattern). This tab surfaces just
 * the plugin's own emails as a focused list with edit + toggle links so
 * an admin doesn't have to scroll through every WC email type to find
 * ours.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Settings\Services;

use OrderUpdatesForWoo\Helpers\AsyncJob;
use OrderUpdatesForWoo\Shared\Config\Constants;
use WC_Email;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings fields and values for the emails section.
 */
final class EmailsSettingsService {
	public const SECTION_ID = 'emails';

	/**
	 * Human-readable section label for the nav.
	 */
	public function label(): string {
		return __( 'Emails', 'order-updates-for-woo' );
	}

	/**
	 * Settings fields for this section.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array {
		return array(
			array(
				'name' => __( 'Email delivery', 'order-updates-for-woo' ),
				'type' => 'title',
				'id'   => 'order_updates_for_woo_emails_section',
			),
			array(
				'name'    => __( 'Delivery mode', 'order-updates-for-woo' ),
				'desc'    => __( 'How customer notification emails are sent. "Automatic" detects whether your host can deliver emails in the background and falls back to immediate sending if it cannot. Switch to "Send immediately" if customers report missing emails. Choose "Send in background" only if your host has reliable scheduled tasks and you want admin pages to load faster.', 'order-updates-for-woo' ),
				'id'      => AsyncJob::MODE_OPTION,
				'type'    => 'select',
				'default' => AsyncJob::MODE_AUTO,
				'options' => array(
					AsyncJob::MODE_AUTO       => __( 'Automatic (recommended)', 'order-updates-for-woo' ),
					AsyncJob::MODE_IMMEDIATE  => __( 'Send immediately', 'order-updates-for-woo' ),
					AsyncJob::MODE_BACKGROUND => __( 'Send in background', 'order-updates-for-woo' ),
				),
			),
			array(
				'name'    => __( 'Show "Powered by" credit in email footers', 'order-updates-for-woo' ),
				'desc'    => __( 'Off by default. Tick to add a small "Powered by Order Updates for Woo · Rate the plugin" line at the bottom of staff-facing notification emails. Customer-facing emails are unaffected.', 'order-updates-for-woo' ),
				'id'      => Constants::SHOW_EMAIL_FOOTER_CREDIT_OPTION,
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'order_updates_for_woo_emails_section',
			),
		);
	}

	/**
	 * Build the directory of plugin emails for the view layer. Each row
	 * exposes the title + description that the email subclass already
	 * carries, the current enabled state, and the URL to open the email's
	 * full settings page in WC.
	 *
	 * @return array<int, array{id:string, title:string, description:string, enabled:bool, edit_url:string}>
	 */
	public function emails(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			return array();
		}

		$plugin_email_ids = $this->plugin_email_ids();
		$wc_emails        = (array) WC()->mailer()->get_emails();
		$rows             = array();

		foreach ( $wc_emails as $email ) {
			if ( ! $email instanceof WC_Email ) {
				continue;
			}

			$email_id = (string) $email->id;

			if ( ! in_array( $email_id, $plugin_email_ids, true ) ) {
				continue;
			}

			$rows[] = array(
				'id'          => $email_id,
				'title'       => (string) $email->get_title(),
				'description' => (string) ( $email->get_description() ? $email->get_description() : $email->description ),
				'enabled'     => (bool) $email->is_enabled(),
				'edit_url'    => $this->edit_url_for( $email ),
			);
		}

		return $rows;
	}

	/**
	 * Toggle the persisted `enabled` flag on a single email's settings
	 * option. Reads the existing array so per-email subject/recipient
	 * customisations survive the toggle.
	 *
	 * @param string $email_id Email identifier.
	 */
	public function toggle_email_enabled( string $email_id ): bool {
		if ( ! in_array( $email_id, $this->plugin_email_ids(), true ) ) {
			return false;
		}

		$option_key = 'woocommerce_' . $email_id . '_settings';
		$settings   = get_option( $option_key, array() );
		$settings   = is_array( $settings ) ? $settings : array();

		$current             = ( $settings['enabled'] ?? 'yes' ) === 'yes';
		$settings['enabled'] = $current ? 'no' : 'yes';

		update_option( $option_key, $settings );

		return ! $current;
	}

	/**
	 * IDs of the plugin's WooCommerce emails.
	 *
	 * @return string[]
	 */
	private function plugin_email_ids(): array {
		return array(
			Constants::EMAIL_ID_ADMIN_UPDATE,
			Constants::EMAIL_ID_ASSIGNEE_UPDATE,
			Constants::EMAIL_ID_INTERNAL_MENTION,
			Constants::EMAIL_ID_PARTICIPANT_UPDATE,
			Constants::EMAIL_ID_CREATOR_UPDATE_DELETED,
			Constants::EMAIL_ID_CUSTOMER_UPDATE,
			Constants::EMAIL_ID_CUSTOMER_UPDATE_DELETED,
			Constants::EMAIL_ID_CUSTOMER_RATING_REQUEST,
			Constants::EMAIL_ID_CUSTOMER_RATING_FOLLOWUP,
		);
	}

	/**
	 * Modern WC (5.x+) matches the `?section=` URL param against either
	 * the lowercased fully-qualified class name OR the email's `->id`
	 * property. We use the id because namespaced classes don't survive
	 * WC's `sanitize_title` round-trip on the URL param — the backslashes
	 * get stripped and the comparison fails.
	 *
	 * @param WC_Email $email Email object.
	 */
	private function edit_url_for( WC_Email $email ): string {
		return add_query_arg(
			array(
				'page'    => 'wc-settings',
				'tab'     => 'email',
				'section' => (string) $email->id,
			),
			admin_url( 'admin.php' )
		);
	}
}
