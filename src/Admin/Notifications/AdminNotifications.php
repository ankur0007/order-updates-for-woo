<?php
/**
 * Register admin-side WooCommerce emails.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OrderUpdatesForWoo\Admin\Notifications\Emails\AdminOrderUpdateEmail;
use OrderUpdatesForWoo\Admin\Notifications\Emails\AssigneeOrderUpdateEmail;
use OrderUpdatesForWoo\Admin\Notifications\Emails\CreatorUpdateDeletedEmail;
use OrderUpdatesForWoo\Admin\Notifications\Emails\InternalMentionEmail;
use OrderUpdatesForWoo\Admin\Notifications\Emails\ParticipantUpdateEmail;
use OrderUpdatesForWoo\Shared\Attachments\AttachmentsDb;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;

final class AdminNotifications {
	public function __construct(
		private OrderUpdatesDb $order_updates_db,
		private AttachmentsDb $attachments_db
	) {}

	public function init(): void {
		add_filter( 'woocommerce_email_classes', array( $this, 'register_email_classes' ) );
	}

	public function register_email_classes( array $emails ): array {
		$emails[ Constants::EMAIL_ID_ADMIN_UPDATE ]           = new AdminOrderUpdateEmail( $this->order_updates_db, $this->attachments_db );
		$emails[ Constants::EMAIL_ID_ASSIGNEE_UPDATE ]        = new AssigneeOrderUpdateEmail( $this->order_updates_db, $this->attachments_db );
		$emails[ Constants::EMAIL_ID_INTERNAL_MENTION ]       = new InternalMentionEmail( $this->order_updates_db, $this->attachments_db );
		$emails[ Constants::EMAIL_ID_PARTICIPANT_UPDATE ]     = new ParticipantUpdateEmail( $this->order_updates_db, $this->attachments_db );
		$emails[ Constants::EMAIL_ID_CREATOR_UPDATE_DELETED ] = new CreatorUpdateDeletedEmail( $this->order_updates_db );

		return $emails;
	}
}
