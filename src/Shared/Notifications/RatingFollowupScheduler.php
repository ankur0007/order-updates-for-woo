<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OrderUpdatesForWoo\Admin\Settings\Services\OrderUpdatesSettingsService;
use OrderUpdatesForWoo\Helpers\AsyncJob;
use OrderUpdatesForWoo\Shared\Config\Constants;

/**
 * Queue a follow-up email after a customer submits a rating, gated on the
 * rating + follow-up settings being enabled. The submit endpoint enforces
 * one rating per update so this fires at most once per update naturally;
 * we don't track a separate "sent" state.
 */
final class RatingFollowupScheduler {
	/**
	 * Inject dependencies.
	 *
	 * @param OrderUpdatesSettingsService $settings_service Injected dependency.
	 * @param AsyncJob                    $async_job        Injected dependency.
	 */
	public function __construct(
		private OrderUpdatesSettingsService $settings_service,
		private AsyncJob $async_job
	) {}

	public function init(): void {
		add_action( 'order_updates_for_woo_after_customer_rating', array( $this, 'maybe_schedule' ), 20, 4 );
	}

	public function maybe_schedule( int $update_id, int $order_id, array $rating, $request ): void {
		if ( ! $update_id || empty( $rating['stars'] ) ) {
			return;
		}

		$features = $this->settings_service->get_feature_settings();

		if ( empty( $features['enable_customer_rating'] ) || empty( $features['enable_customer_rating_followup_email'] ) ) {
			return;
		}

		$this->async_job->queue(
			Constants::HOOK_RATING_FOLLOWUP,
			array( 'update_id' => $update_id )
		);
	}
}
