<?php

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
	public function __construct(
		private OrderUpdatesDb $order_updates_db,
		private AttachmentService $attachment_service
	) {}

	public function init(): void {
		add_action( 'woocommerce_before_delete_order', array( $this, 'on_woocommerce_delete' ), 10, 1 );
		add_action( 'before_delete_post', array( $this, 'on_post_delete' ), 10, 1 );
	}

	public function on_woocommerce_delete( int $order_id ): void {
		$this->cascade( $order_id );
	}

	public function on_post_delete( int $post_id ): void {
		if ( 'shop_order' !== get_post_type( $post_id ) ) {
			return;
		}

		$this->cascade( $post_id );
	}

	private function cascade( int $order_id ): void {
		if ( ! $order_id ) {
			return;
		}

		$this->order_updates_db->delete_all_for_order( $order_id );
		$this->attachment_service->delete_all_for_order( $order_id );
	}
}
