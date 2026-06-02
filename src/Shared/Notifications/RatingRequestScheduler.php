<?php
/**
 * Queues the rating-request email when an update is solved.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OrderUpdatesForWoo\Admin\Settings\Services\OrderUpdatesSettingsService;
use OrderUpdatesForWoo\Helpers\AsyncJob;
use OrderUpdatesForWoo\Helpers\UpdateState;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;

/**
 * Queue a rating-request email when a customer-visible update is marked
 * solved, provided both the rating feature and the rating-email setting are
 * enabled. Within a single solve cycle this is idempotent — a duplicate
 * solve won't re-queue. The reopen path explicitly clears the request row
 * via {@see OrderUpdatesDb::clear_rating_request()} so a subsequent resolve
 * will re-fire the email. A row whose `created_at` is set (real customer
 * rating) is never overwritten or re-queued.
 */
final class RatingRequestScheduler {
	/**
	 * Inject dependencies.
	 *
	 * @param OrderUpdatesDb              $order_updates_db Injected dependency.
	 * @param OrderUpdatesSettingsService $settings_service Injected dependency.
	 * @param AsyncJob                    $async_job        Injected dependency.
	 */
	public function __construct(
		private OrderUpdatesDb $order_updates_db,
		private OrderUpdatesSettingsService $settings_service,
		private AsyncJob $async_job
	) {}

	/** Hook the rating-request scheduling to the mark-solved event. */
	public function init(): void {
		add_action( 'order_updates_for_woo_after_mark_solved', array( $this, 'maybe_schedule' ), 20, 2 );
	}

	/**
	 * Queue the rating-request email when a solved update qualifies.
	 *
	 * @param int   $update_id Update that was solved.
	 * @param array $update    The solved update row.
	 */
	public function maybe_schedule( int $update_id, array $update ): void {
		if ( ! $update_id || ! UpdateState::is_customer_visible( $update ) ) {
			return;
		}

		$features = $this->settings_service->get_feature_settings();

		if ( empty( $features['enable_customer_rating'] ) || empty( $features['enable_customer_rating_email'] ) ) {
			return;
		}

		$existing = $this->order_updates_db->get_rating_for_update( $update_id );

		if ( ! empty( $existing['requested_at'] ) ) {
			return;
		}

		$order_id     = absint( $update['order_id'] ?? 0 );
		$requested_at = current_time( 'mysql', true );

		if ( ! $order_id ) {
			return;
		}

		$this->order_updates_db->create_rating_request( $update_id, $order_id, $requested_at );

		$this->async_job->queue(
			Constants::HOOK_RATING_REQUEST,
			array( 'update_id' => $update_id )
		);
	}
}
