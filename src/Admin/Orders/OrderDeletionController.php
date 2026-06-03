<?php
/**
 * Cascades update + attachment cleanup when an order is deleted.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OrderUpdatesForWoo\Shared\Attachments\AttachmentService;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;

/**
 * Cascade-deletes plugin data whenever an order is removed.
 *
 * Hooks both the HPOS deletion action and the legacy CPT delete hook so the
 * cleanup runs regardless of which storage WooCommerce is using.
 */
final class OrderDeletionController {
	/**
	 * Inject dependencies.
	 *
	 * @param OrderUpdatesDb    $order_updates_db Injected dependency.
	 * @param AttachmentService $attachment_service Injected dependency.
	 */
	public function __construct(
		private OrderUpdatesDb $order_updates_db,
		private AttachmentService $attachment_service
	) {}

	/**
	 * Register the hooks this section depends on.
	 */
	public function init(): void {
		add_action( 'woocommerce_before_delete_order', array( $this, 'on_woocommerce_delete' ), 10, 1 );
		add_action( 'before_delete_post', array( $this, 'on_post_delete' ), 10, 1 );
	}

	/**
	 * HPOS delete hook — cascade cleanup for the order.
	 *
	 * @param int $order_id Order id being deleted.
	 */
	public function on_woocommerce_delete( int $order_id ): void {
		$this->cascade( $order_id );
	}

	/**
	 * Classic (post) delete hook — cascade cleanup for shop_order posts.
	 *
	 * @param int $post_id Post id being deleted.
	 */
	public function on_post_delete( int $post_id ): void {
		if ( 'shop_order' !== get_post_type( $post_id ) ) {
			return;
		}

		$this->cascade( $post_id );
	}

	/**
	 * Delete every update, note, and attachment tied to an order.
	 *
	 * @param int $order_id Order id.
	 */
	private function cascade( int $order_id ): void {
		if ( ! $order_id ) {
			return;
		}

		$this->order_updates_db->delete_all_for_order( $order_id );
		$this->attachment_service->delete_all_for_order( $order_id );
	}
}
