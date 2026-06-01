<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Notifications;

use OrderUpdatesForWoo\Admin\Notifications\NotificationsPageController;
use OrderUpdatesForWoo\Helpers\AdminBarNotificationStore;
use OrderUpdatesForWoo\Shared\Config\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two-stage notification retention, run daily and chunked per batch so a large
 * staff never produces one heavy pass. Stage one moves aged active rows to
 * Archived; stage two deletes archived rows past their keep window. The day
 * windows come from the Notifications settings tab (0 disables that stage).
 */
final class NotificationRetentionScheduler {

	private const USERS_PER_BATCH = 20;
	private const GROUP           = 'order-updates-for-woo';

	public function init(): void {
		add_action( 'admin_init', array( $this, 'maybe_schedule' ) );
		add_action( Constants::NOTIFICATIONS_CLEANUP_HOOK, array( $this, 'start_run' ) );
		add_action( Constants::NOTIFICATIONS_CLEANUP_BATCH_HOOK, array( $this, 'run_batch' ), 10, 1 );
	}

	/** Make sure the daily recurring cleanup is on the schedule. */
	public function maybe_schedule(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( ! as_has_scheduled_action( Constants::NOTIFICATIONS_CLEANUP_HOOK, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, Constants::NOTIFICATIONS_CLEANUP_HOOK, array(), self::GROUP );
		}
	}

	/** Daily entry point — kick off the first chunk when retention is on. */
	public function start_run(): void {
		if ( $this->is_enabled() ) {
			$this->queue_batch( 0 );
		}
	}

	/** Process one chunk of users, then queue the next while users remain. */
	public function run_batch( int $offset = 0 ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$archive_days = $this->archive_days();
		$delete_days  = $this->delete_days();

		$user_ids = AdminBarNotificationStore::user_ids_with_notifications();

		foreach ( array_slice( $user_ids, $offset, self::USERS_PER_BATCH ) as $user_id ) {
			if ( $archive_days > 0 ) {
				AdminBarNotificationStore::archive_aged( $user_id, $archive_days );
			}
			if ( $delete_days > 0 ) {
				AdminBarNotificationStore::purge_expired( $user_id, array( 'archived' ), $delete_days );
			}
		}

		if ( count( $user_ids ) > $offset + self::USERS_PER_BATCH ) {
			$this->queue_batch( $offset + self::USERS_PER_BATCH );
		}
	}

	/** Queue the next chunk asynchronously; fall back to inline if AS is absent. */
	private function queue_batch( int $offset ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( Constants::NOTIFICATIONS_CLEANUP_BATCH_HOOK, array( $offset ), self::GROUP );
			return;
		}

		$this->run_batch( $offset );
	}

	private function is_enabled(): bool {
		return $this->archive_days() > 0 || $this->delete_days() > 0;
	}

	private function archive_days(): int {
		return max( 0, (int) get_option( NotificationsPageController::OPT_ARCHIVE_AFTER_DAYS, 30 ) );
	}

	private function delete_days(): int {
		return max( 0, (int) get_option( NotificationsPageController::OPT_AUTODELETE_DAYS, 30 ) );
	}
}
