<?php
/**
 * Persistent log of deleted update history attached to a WC order.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WC_Order;

/**
 * Stores deletion records as a single order-meta array so the audit lives
 * in one well-known place, survives plugin updates, and is HPOS-compatible
 * via WC's meta API.
 *
 * Records are append-only via this class — no UI surface deletes them. A
 * determined admin with DB access can still clear them; we trade absolute
 * forensic guarantee for the much simpler "no in-UI delete option."
 */
final class DeletedUpdatesLog {
	public const META_KEY = '_awts_deleted_updates_log';

	/**
	 * Append a deletion record to the order. Returns true on save success.
	 *
	 * @param array{update_id:int,title:string,deleted_at:string,deleted_by_id:int,deleted_by_name:string,events:array<int,array<string,mixed>>} $record
	 */
	public static function record( WC_Order $order, array $record ): bool {
		$existing = $order->get_meta( self::META_KEY, true );
		$log      = is_array( $existing ) ? $existing : array();
		$log[]    = $record;

		$order->update_meta_data( self::META_KEY, $log );
		$order->save();

		return true;
	}

	/**
	 * Read all deletion records for an order, oldest-first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_for_order( WC_Order $order ): array {
		$log = $order->get_meta( self::META_KEY, true );

		return is_array( $log ) ? $log : array();
	}
}
