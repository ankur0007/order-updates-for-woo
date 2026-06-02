<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

// The job hook is a plugin-prefixed name passed in by the caller.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound

final class AsyncJob {
	public const MODE_AUTO       = 'auto';
	public const MODE_IMMEDIATE  = 'immediate';
	public const MODE_BACKGROUND = 'background';
	public const MODE_OPTION     = 'order_updates_for_woo_email_delivery_mode';

	public function __construct( private ?AsyncHealth $health = null ) {}

	/**
	 * Per-request memoisation of (hook + payload) we've already queued.
	 * Same hook + same payload in one request would otherwise produce two
	 * emails (Action Scheduler doesn't dedupe by default), which is the
	 * "received twice" bug pattern users hit when a single UI action goes
	 * through more than one dispatch path.
	 *
	 * @var array<string, true>
	 */
	private array $dispatched = array();

	public function queue( string $hook, array $payload, string $group = 'order-updates-for-woo' ): bool {
		$key = $this->dispatch_key( $hook, $payload );

		if ( isset( $this->dispatched[ $key ] ) ) {
			return true;
		}

		$this->dispatched[ $key ] = true;

		if ( $this->should_send_async() ) {
			if ( $this->dispatch_async( $hook, $payload, $group ) ) {
				return true;
			}
		}

		do_action( $hook, $payload );
		return true;
	}

	private function dispatch_key( string $hook, array $payload ): string {
		return $hook . '|' . md5( serialize( $payload ) );
	}

	private function should_send_async(): bool {
		$mode = (string) get_option( self::MODE_OPTION, self::MODE_AUTO );

		if ( self::MODE_IMMEDIATE === $mode ) {
			return false;
		}

		if ( self::MODE_BACKGROUND === $mode ) {
			return function_exists( 'as_enqueue_async_action' ) || function_exists( 'as_schedule_single_action' );
		}

		return $this->health instanceof AsyncHealth && $this->health->is_async_healthy();
	}

	private function dispatch_async( string $hook, array $payload, string $group ): bool {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$action_id = as_enqueue_async_action( $hook, array( $payload ), $group );

			if ( 0 !== absint( $action_id ) ) {
				return true;
			}
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			$action_id = as_schedule_single_action( time(), $hook, array( $payload ), $group );

			if ( 0 !== absint( $action_id ) ) {
				return true;
			}
		}

		return false;
	}
}
