<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OrderUpdatesForWoo\Shared\Config\Constants;

// Hook names are plugin-prefixed constants; the checker just can't read their value.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound

final class NotificationDispatcher {
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

	public function send_assignee_notification( array $payload ): void {
		$email = $this->get_email( Constants::EMAIL_ID_ASSIGNEE_UPDATE );

		if ( ! $email || ! method_exists( $email, 'trigger' ) ) {
			return;
		}

		$update_id = absint( $payload['update_id'] ?? 0 );
		$assignee_user_id = absint( $payload['assignee_user_id'] ?? 0 );
		$context = (string) ( $payload['context'] ?? 'assigned' );
		$note_id = absint( $payload['note_id'] ?? 0 );
		$note_type = (string) ( $payload['note_type'] ?? '' );
		$actor_user_id = absint( $payload['actor_user_id'] ?? 0 );
		$sent = $email->trigger( $update_id, $assignee_user_id, $context, $note_id, $note_type, $actor_user_id );

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

	public function send_customer_notification( array $payload ): void {
		$email = $this->get_email( Constants::EMAIL_ID_CUSTOMER_UPDATE );

		if ( ! $email || ! method_exists( $email, 'trigger' ) ) {
			return;
		}

		$update_id = absint( $payload['update_id'] ?? 0 );
		$note_id = absint( $payload['note_id'] ?? 0 );
		$context = (string) ( $payload['context'] ?? '' );
		$sent = $email->trigger( $update_id, $note_id, $context );

		if ( $sent ) {
			do_action(
				Constants::HOOK_CUSTOMER_SENT,
				$update_id,
				$note_id,
				current_time( 'mysql', true )
			);
		}
	}

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

	public function send_rating_followup( array $payload ): void {
		$email = $this->get_email( Constants::EMAIL_ID_CUSTOMER_RATING_FOLLOWUP );

		if ( ! $email || ! method_exists( $email, 'trigger' ) ) {
			return;
		}

		$update_id = absint( $payload['update_id'] ?? 0 );
		$email->trigger( $update_id );
	}

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

	public function send_shared_link_email( array $payload ): void {
		$email = $this->get_email( Constants::EMAIL_ID_CUSTOMER_SHARED_LINK );

		if ( ! $email || ! method_exists( $email, 'trigger' ) ) {
			return;
		}

		$email->trigger( absint( $payload['order_id'] ?? 0 ) );
	}

	private function get_email( string $email_id ): ?object {
		if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			return null;
		}

		$emails = WC()->mailer()->get_emails();

		return $emails[ $email_id ] ?? null;
	}
}
