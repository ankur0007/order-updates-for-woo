<?php
/**
 * WooCommerce HPOS detection helper.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

/**
 * Static helper for detecting whether WooCommerce HPOS is active
 * and resolving the correct screen IDs for order pages.
 */
final class HposHelper {
	/**
	 * Whether WooCommerce HPOS (custom orders table) is enabled.
	 */
	public static function is_enabled(): bool {
		return function_exists( 'wc_get_container' )
			&& class_exists( CustomOrdersTableController::class )
			&& wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled();
	}

	/**
	 * Screen ID for the single order edit page (classic or HPOS).
	 */
	public static function order_edit_screen_id(): string {
		if ( self::is_enabled() && function_exists( 'wc_get_page_screen_id' ) ) {
			return wc_get_page_screen_id( 'shop-order' );
		}

		return 'shop_order';
	}

	/**
	 * Screen ID for the orders list table (classic or HPOS).
	 */
	public static function orders_list_screen_id(): string {
		return self::is_enabled() ? 'woocommerce_page_wc-orders' : 'edit-shop_order';
	}

	/**
	 * Admin URL for the orders list table. HPOS lives at
	 * `admin.php?page=wc-orders`; the classic posts table is still at
	 * `edit.php?post_type=shop_order`. Anywhere we link to "all orders"
	 * should route through this so HPOS-only stores don't 404.
	 */
	public static function orders_list_url(): string {
		return self::is_enabled()
			? admin_url( 'admin.php?page=wc-orders' )
			: admin_url( 'edit.php?post_type=shop_order' );
	}
}
