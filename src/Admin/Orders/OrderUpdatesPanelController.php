<?php
/**
 * Order updates admin panel controller.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OrderUpdatesForWoo\Welcome\Controllers\OnboardingController;
use OrderUpdatesForWoo\Admin\Settings\Services\OrderUpdatesSettingsService;
use OrderUpdatesForWoo\Admin\Orders\Services\OrderEditorPanelService;
use OrderUpdatesForWoo\Frontend\OrderUpdates\CustomerOrderUpdatesController;
use OrderUpdatesForWoo\Helpers\View;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use OrderUpdatesForWoo\Shared\Config\Variables;
use OrderUpdatesForWoo\Shared\Updates\SharedLink;
use OrderUpdatesForWoo\Shared\Updates\UpdateCardVariableParser;

final class OrderUpdatesPanelController {
	public function __construct(
		private OrderEditorPanelService $panel_service,
		private OrderUpdatesSettingsService $settings_service,
		private UpdateCardVariableParser $update_card_variable_parser,
		private OrderUpdatesDb $order_updates_db,
		private OnboardingController $onboarding
	) {}

	public function init(): void {
		add_action('add_meta_boxes', [ $this, 'register_meta_box' ]);
		add_action('admin_enqueue_scripts', [ $this, 'enqueue_assets' ]);
	}

	public function register_meta_box(): void {
		if (wp_doing_ajax()) {
			return;
		}

		$screen_id = $this->panel_service->get_screen_id();

		add_meta_box(
			'order-updates-for-woo-meta-box',
			__( 'Order Updates', 'order-updates-for-woo' ),
			[ $this, 'render' ],
			$screen_id,
			'normal',
			'high'
		);
	}

	public function enqueue_assets(): void {
		if (! $this->panel_service->should_enqueue_assets()) {
			return;
		}

		$this->panel_service->enqueue_assets();
	}

	public function render($order = null): void {
		$order_id = $this->get_order_id($order);
		$order_updates_array = $this->order_updates_db->get_order_updates($order_id, Variables::getUpdatesPageSize(), 0);

		// Pre-warm the rating cache for all updates in a single query so the
		// per-update parse() calls below hit cache instead of hitting the DB N times.
		$this->order_updates_db->prefetch_ratings_for_updates(
			array_map( 'absint', array_column( $order_updates_array, 'id' ) )
		);

		$card_variables_list = array_map(
			[ $this->update_card_variable_parser, 'parse' ],
			$order_updates_array
		);

		// No-login chat link for staff to share. Stateful hash + expiry in
		// order meta — see SharedLink. Changing the expiry from this panel
		// does not change the URL.
		$shared_link  = array();
		$customer_url = '';
		$order_obj    = $order_id ? wc_get_order( $order_id ) : null;
		if ( $order_obj ) {
			$shared_link  = SharedLink::ensure( $order_obj, get_current_user_id() );
			$customer_url = CustomerOrderUpdatesController::get_shared_link_url( $order_id, (string) $shared_link['hash'] );
		}

		View::render(
			'src/Admin/Orders/Views/OrderUpdatesPanelViewModern',
			[
				'settings' => $this->settings_service->get_feature_settings(),
				'order_id' => $order_id,
				'order_updates' => $card_variables_list,
				'order_updates_total' => $this->order_updates_db->count_order_updates($order_id),
				'show_onboarding' => $this->onboarding->should_show(),
				'statuses' => $this->settings_service->get_statuses(),
				'customer_url' => $customer_url,
				'shared_link' => $shared_link,
			]
		);
	}

	private function get_order_id($order): int {
		if (is_object($order) && method_exists($order, 'get_id')) {
			return absint($order->get_id());
		}

		if (is_object($order) && isset($order->ID)) {
			return absint($order->ID);
		}

		return 0;
	}
}
