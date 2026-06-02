<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AsyncHealth {
	public const HEARTBEAT_OPTION = 'order_updates_for_woo_async_heartbeat';
	public const HEARTBEAT_HOOK   = 'order_updates_for_woo_async_heartbeat_tick';
	private const STALE_AFTER     = 15 * MINUTE_IN_SECONDS;
	private const INTERVAL        = 5 * MINUTE_IN_SECONDS;

	public function init(): void {
		add_action( self::HEARTBEAT_HOOK, array( $this, 'tick' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ) );
	}

	public function maybe_schedule(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( as_has_scheduled_action( self::HEARTBEAT_HOOK ) ) {
			return;
		}

		as_schedule_recurring_action( time(), self::INTERVAL, self::HEARTBEAT_HOOK, array(), 'order-updates-for-woo' );
	}

	public function tick(): void {
		update_option( self::HEARTBEAT_OPTION, time(), false );
	}

	public function is_async_healthy(): bool {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}

		$last = (int) get_option( self::HEARTBEAT_OPTION, 0 );

		if ( $last <= 0 ) {
			return false;
		}

		return ( time() - $last ) < self::STALE_AFTER;
	}
}
