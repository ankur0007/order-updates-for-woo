<?php
/**
 * Shortcodes settings — read-only usage card. No persisted fields.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Settings\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings fields and values for the shortcodes section.
 */
final class ShortcodesSettingsService {
	public const SECTION_ID = 'shortcodes';

	/**
	 * Human-readable section label for the nav.
	 */
	public function label(): string {
		return __( 'Shortcodes', 'order-updates-for-woo' );
	}

	/**
	 * Settings fields for this section.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array {
		return array();
	}

	/**
	 * The shortcodes this plugin registers.
	 *
	 * @return array<int, array{
	 *     tag:string,
	 *     summary:string,
	 *     attributes:array<int, array{name:string, required:bool, description:string}>,
	 *     url_params:array<int, array{name:string, description:string, example:string}>,
	 *     examples:string[]
	 * }>
	 */
	public function shortcodes(): array {
		return array(
			array(
				'tag'        => '[order_updates_portal]',
				'summary'    => __( 'Renders the customer update portal on any page. Works inside Elementor, Divi, Gutenberg, or any builder that supports shortcodes. The order ID is detected from the URL automatically when the customer arrives via a notification email link.', 'order-updates-for-woo' ),
				'attributes' => array(
					array(
						'name'        => 'order_id',
						'required'    => false,
						'description' => __( 'Hard-code the order ID instead of relying on the URL. Useful when embedding on a page tied to a single order.', 'order-updates-for-woo' ),
					),
					array(
						'name'        => 'order_key',
						'required'    => false,
						'description' => __( 'WC order key (e.g. wc_order_abc123). Only needed for guest-customer pages where the visitor isn\'t logged in.', 'order-updates-for-woo' ),
					),
				),
				'url_params' => array(
					array(
						'name'        => 'order_id',
						'description' => __( 'Order ID. Standard form.', 'order-updates-for-woo' ),
						'example'     => '?order_id=123',
					),
					array(
						'name'        => 'order-id',
						'description' => __( 'Same as order_id — supports the dashed alias used by some email-link generators.', 'order-updates-for-woo' ),
						'example'     => '?order-id=123',
					),
					array(
						'name'        => 'key',
						'description' => __( 'WC order key for guest authentication. WC\'s own "View order" links use this.', 'order-updates-for-woo' ),
						'example'     => '?key=wc_order_abc123',
					),
					array(
						'name'        => 'order_key',
						'description' => __( 'Same as key — alternative name supported for explicit clarity.', 'order-updates-for-woo' ),
						'example'     => '?order_key=wc_order_abc123',
					),
				),
				'examples'   => array(
					'[order_updates_portal]',
					'[order_updates_portal order_id="123"]',
					'[order_updates_portal order_id="123" order_key="wc_order_abc123"]',
				),
			),
		);
	}
}
