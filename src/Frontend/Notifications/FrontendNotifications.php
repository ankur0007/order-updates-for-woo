<?php
/**
 * Register customer-facing WooCommerce emails.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Frontend\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OrderUpdatesForWoo\Frontend\Notifications\Emails\CustomerOrderUpdateEmail;
use OrderUpdatesForWoo\Frontend\Notifications\Emails\CustomerRatingFollowupEmail;
use OrderUpdatesForWoo\Frontend\Notifications\Emails\CustomerRatingRequestEmail;
use OrderUpdatesForWoo\Frontend\Notifications\Emails\CustomerSharedLinkEmail;
use OrderUpdatesForWoo\Frontend\Notifications\Emails\CustomerUpdateDeletedEmail;
use OrderUpdatesForWoo\Shared\Attachments\AttachmentsDb;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;

final class FrontendNotifications {
	public function __construct(
		private OrderUpdatesDb $order_updates_db,
		private AttachmentsDb $attachments_db
	) {}

	public function init(): void {
		add_filter( 'woocommerce_email_classes', array( $this, 'register_email_classes' ) );
	}

	public function register_email_classes( array $emails ): array {
		$emails[ Constants::EMAIL_ID_CUSTOMER_UPDATE ]          = new CustomerOrderUpdateEmail( $this->order_updates_db, $this->attachments_db );
		$emails[ Constants::EMAIL_ID_CUSTOMER_UPDATE_DELETED ]  = new CustomerUpdateDeletedEmail( $this->order_updates_db );
		$emails[ Constants::EMAIL_ID_CUSTOMER_RATING_REQUEST ]  = new CustomerRatingRequestEmail( $this->order_updates_db );
		$emails[ Constants::EMAIL_ID_CUSTOMER_RATING_FOLLOWUP ] = new CustomerRatingFollowupEmail( $this->order_updates_db );
		$emails[ Constants::EMAIL_ID_CUSTOMER_SHARED_LINK ]     = new CustomerSharedLinkEmail( $this->order_updates_db );

		return $emails;
	}
}
