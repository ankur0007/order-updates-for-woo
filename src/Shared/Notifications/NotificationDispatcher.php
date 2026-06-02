<?php
/**
 * Turns queued notification hooks into the matching WooCommerce emails.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OrderUpdatesForWoo\Shared\Config\Constants;

// Hook names are plugin-prefixed constants; the checker just can't read their value.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound

/**
 * Each notification hook maps to one WC_Email subclass; this fires it and, on
 * success, emits the matching "sent" event so timestamps get stamped.
 */
final class NotificationDispatcher {

	/** Wire every notification hook to its sender. */
	public function init(): void {
		add_action( Constants::HOOK_ADMIN_NOTIFICATION, array( $this, 'send_admin_notification' ), 10, 1 );
		add_action( Constants::HOOK_ASSIGNEE_NOTIFICATION, array( $this, 'send_assignee_notification' ), 10, 1 );
		add_action( Constants::HOOK_CUSTOMER_NOTIFICATION, array( $this, 'send_customer_notification' ), 10, 1 );
		add_action( Constants::HOOK_RATING_REQUEST, array( $this, 'send_rating_request' ), 10, 1 );
		add_action( Constants::HOOK_RATING_FOLLOWUP, array( $this, 'send_rating_followup' ), 10, 1 );
		add_action( Constants::HOOK_INTERNAL_MENTION, array( $this, 'send_internal_mention' ), 10, 1 );
		add_action( Constants::HOOK_PARTICIPANT_UPDATE, array( $this, 'send_participant_update' ), 10, 1 );
		add_action( Constants::HOOK_SHARED_LINK_EMAIL, array( $this, 'send_shared_link_email' ), 10, 1 );
	}

	/**
	 * Send the admin / creator update email.
	 *
	 * @param array $payload Queued job payload.
	 */
	public function send_admin_notification( array $payload ): void {
		$email = $this->get_email( Constants::EMAIL_ID_ADMIN_UPDATE );

		if ( ! $email || ! method_exists( $email, 'trigger' ) ) {
			return;
		}

		$email->trigger(
			absint( $payload['update_id'] ?? 0 ),
			absint( $payload['recipient_user_id'] ?? 0 ),
			(string) ( $payload['context'] ?? 'created' ),
			absint( $payload['note_id'] ?? 0 ),
			(string) ( $payload['note_type'] ?? '' )
		);
	}

	/**
	 * Send the assignee update email and stamp it sent.
	 *
	 * @param array $payload Queued job payload.
	 */
	public function send_assignee_notification( array $payload ): void {
		$email = $this->get_email( Constants::EMAIL_ID_ASSIGNEE_UPDATE );

		if ( ! $email || ! method_exists( $email, 'trigger' ) ) {
			return;
		}

		$update_id        = absint( $payload['update_id'] ?? 0 );
		$assignee_user_id = absint( $payload['assignee_user_id'] ?? 0 );
		$context          = (string) ( $payload['context'] ?? 'assigned' );
		$note_id          = absint( $payload['note_id'] ?? 0 );
		$note_type        = (string) ( $payload['note_type'] ?? '' );
		$actor_user_id    = absint( $payload['actor_user_id'] ?? 0 );
		$sent             = $email->trigger( $update_id, $assignee_user_id, $context, $note_id, $note_type, $actor_user_id );

		if ( $sent ) {
			do_action(
				Constants::HOOK_ASSIGNEE_SENT,
				$update_id,
				$assignee_user_id,
				$context,
				current_time( 'mysql', true )
			);
		}
	}

	/**
	 * Send the customer update email and stamp it sent.
	 *
	 * @param array $payload Queued job payload.
	 */
	public function send_customer_notification( array $payload ): void {
		$email = $this->get_email( Constants::EMAIL_ID_CUSTOMER_UPDATE );

		if ( ! $email || ! method_exists( $email, 'trigger' ) ) {
			return;
		}

		$update_id = absint( $payload['update_id'] ?? 0 );
		$note_id   = absint( $payload['note_id'] ?? 0 );
		$context   = (string) ( $payload['context'] ?? '' );
		$sent      = $email->trigger( $update_id, $note_id, $context );

		if ( $sent ) {
			do_action(
				Constants::HOOK_CUSTOMER_SENT,
				$update_id,
				$note_id,
				current_time( 'mysql', true )
			);
		}
	}

	/**
	 * Send the rating-request email and stamp it sent.
	 *
	 * @param array $payload Queued job payload.
	 */
	public function send_rating_request( array $payload ): void {
		$email = $this->get_email( Constants::EMAIL_ID_CUSTOMER_RATING_REQUEST );

		if ( ! $email || ! method_exists( $email, 'trigger' ) ) {
			return;
		}

		$update_id = absint( $payload['update_id'] ?? 0 );
		$sent      = $email->trigger( $update_id );

		if ( $sent ) {
			do_action(
				Constants::HOOK_RATING_REQUEST_SENT,
				$update_id,
				current_time( 'mysql', true )
			);
		}
	}

	/**
	 * Send the rating follow-up email.
	 *
	 * @param array $payload Queued job payload.
	 */
	public function send_rating_followup( array $payload ): void {
		$email = $this->get_email( Constants::EMAIL_ID_CUSTOMER_RATING_FOLLOWUP );

		if ( ! $email || ! method_exists( $email, 'trigger' ) ) {
			return;
		}

		$update_id = absint( $payload['update_id'] ?? 0 );
		$email->trigger( $update_id );
	}

	/**
	 * Send the @mention email and stamp it sent.
	 *
	 * @param array $payload Queued job payload.
	 */
	public function send_internal_mention( array $payload ): void {
		$email = $this->get_email( Constants::EMAIL_ID_INTERNAL_MENTION );

		if ( ! $email || ! method_exists( $email, 'trigger' ) ) {
			return;
		}

		$update_id         = absint( $payload['update_id'] ?? 0 );
		$note_id           = absint( $payload['note_id'] ?? 0 );
		$recipient_user_id = absint( $payload['recipient_user_id'] ?? 0 );
		$mentioned_by_name = (string) ( $payload['mentioned_by_name'] ?? '' );

		$sent = $email->trigger( $update_id, $note_id, $recipient_user_id, $mentioned_by_name );

		if ( $sent ) {
			do_action(
				Constants::HOOK_INTERNAL_MENTION_SENT,
				$update_id,
				$note_id,
				$recipient_user_id,
				current_time( 'mysql', true )
			);
		}
	}

	/**
	 * Send the participant update email and stamp it sent.
	 *
	 * @param array $payload Queued job payload.
	 */
	public function send_participant_update( array $payload ): void {
		$email = $this->get_email( Constants::EMAIL_ID_PARTICIPANT_UPDATE );

		if ( ! $email || ! method_exists( $email, 'trigger' ) ) {
			return;
		}

		$update_id         = absint( $payload['update_id'] ?? 0 );
		$recipient_user_id = absint( $payload['recipient_user_id'] ?? 0 );
		$note_id           = absint( $payload['note_id'] ?? 0 );
		$note_type         = (string) ( $payload['note_type'] ?? '' );
		$actor_user_id     = absint( $payload['actor_user_id'] ?? 0 );
		$actor_name        = (string) ( $payload['actor_name'] ?? '' );

		$sent = $email->trigger( $update_id, $recipient_user_id, $note_id, $note_type, $actor_user_id, $actor_name );

		if ( $sent ) {
			do_action(
				Constants::HOOK_PARTICIPANT_UPDATE_SENT,
				$update_id,
				$note_id,
				$recipient_user_id,
				current_time( 'mysql', true )
			);
		}
	}

	/**
	 * Send the refreshed no-login chat-link email.
	 *
	 * @param array $payload Queued job payload.
	 */
	public function send_shared_link_email( array $payload ): void {
		$email = $this->get_email( Constants::EMAIL_ID_CUSTOMER_SHARED_LINK );

		if ( ! $email || ! method_exists( $email, 'trigger' ) ) {
			return;
		}

		$email->trigger( absint( $payload['order_id'] ?? 0 ) );
	}

	/**
	 * Fetch a registered WC_Email by its id, or null when unavailable.
	 *
	 * @param string $email_id WC email id.
	 */
	private function get_email( string $email_id ): ?object {
		if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			return null;
		}

		$emails = WC()->mailer()->get_emails();

		return $emails[ $email_id ] ?? null;
	}
}
