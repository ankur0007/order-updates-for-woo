<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Endpoints;

use OrderUpdatesForWoo\Admin\Settings\Services\OrderUpdatesSettingsService;
use OrderUpdatesForWoo\API\Concerns\VerifiesAccess;
use OrderUpdatesForWoo\API\Contracts\Registrable;
use OrderUpdatesForWoo\Frontend\OrderUpdates\Services\CustomerOrderUpdatesService;
use OrderUpdatesForWoo\Helpers\AssigneePicker;
use OrderUpdatesForWoo\Helpers\AsyncJob;
use OrderUpdatesForWoo\Helpers\StaffEmailPreference;
use OrderUpdatesForWoo\Helpers\UpdateState;
use OrderUpdatesForWoo\Shared\Attachments\AttachmentService;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Config\Variables;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use OrderUpdatesForWoo\Shared\Updates\UpdateNoteService;
use OrderUpdatesForWoo\Shared\Validation\Validator;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Customer-initiated "write a note" submission.
 *
 * Creates a new update owned by the customer, attaches the message as a
 * customer note, uploads any files to that note, and assigns the update to
 * the configured primary assignee so the store team is notified.
 *
 * Accepts both logged-in customers (by order ownership) and guests (by
 * matching the order key from their notification email).
 */
final class SubmitCustomerUpdateEndpoint implements Registrable {
	use VerifiesAccess;

	private const ROUTE = '/customer-updates';
	private const DEFAULT_COLOR = '#2563eb';

	public function __construct(
		private OrderUpdatesDb $order_updates_db,
		private UpdateNoteService $update_note_service,
		private CustomerOrderUpdatesService $viewer_service,
		private OrderUpdatesSettingsService $settings_service,
		private AttachmentService $attachment_service,
		private AsyncJob $async_job,
		private Validator $validator
	) {}

	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'can_access' ),
			)
		);
	}

	public function can_access( WP_REST_Request $request ): bool|WP_Error {
		if ( $error = $this->verify_nonce( $request ) ) {
			return $error;
		}

		$order_id  = absint( $request->get_param( 'order_id' ) );
		$order_key = (string) $request->get_param( 'order_key' );
		$order_key = '' !== $order_key ? sanitize_text_field( wp_unslash( $order_key ) ) : null;

		if ( ! $this->viewer_service->is_acting_as_customer( $order_id, $order_key ) ) {
			return new WP_Error(
				'order_updates_for_woo_forbidden',
				__( 'You are not allowed to submit a note for this order.', 'order-updates-for-woo' ),
				array( 'status' => 403 )
			);
		}

		$is_new_update = ! absint( $request->get_param( 'update_id' ) );
		$is_guest      = ! get_current_user_id() && null !== $order_key;

		// Gate on opening NEW threads — replies stay open either way. Runs
		// before the guest rate limiter so the response is "disabled" rather
		// than a confusing rate-limit error.
		if ( $is_new_update && ! $this->settings_service->allow_customer_create_update() ) {
			return new WP_Error(
				'order_updates_for_woo_customer_create_disabled',
				__( 'Creating new updates from the order page is disabled.', 'order-updates-for-woo' ),
				array( 'status' => 403 )
			);
		}

		if ( $is_new_update && $is_guest ) {
			$rate_key   = 'awts_guest_rate_' . $order_id;
			$window_key = 'awts_guest_rate_exp_' . $order_id;
			$count      = (int) get_transient( $rate_key );

			if ( $count >= 5 ) {
				return new WP_Error(
					'order_updates_for_woo_rate_limited',
					__( 'You have reached the maximum number of new updates allowed per hour. Please try again later.', 'order-updates-for-woo' ),
					array( 'status' => 429 )
				);
			}

			if ( 0 === $count ) {
				// First submission in this window — open a fresh 1-hour window.
				set_transient( $rate_key, 1, HOUR_IN_SECONDS );
				set_transient( $window_key, time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS );
			} else {
				// Increment within the existing window, preserving the original expiry.
				$remaining = max( 1, (int) get_transient( $window_key ) - time() );
				set_transient( $rate_key, $count + 1, $remaining );
			}
		}

		/**
		 * Fires after a guest passes the rate-limit check, before the update is created.
		 * Addons can inspect the request or throw a WP_Error to block the submission.
		 *
		 * @param int         $order_id
		 * @param string|null $order_key
		 */
		do_action( 'order_updates_for_woo_before_guest_create_access', $order_id, $order_key );

		return true;
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$order_id = absint( $request->get_param( 'order_id' ) );
		$order    = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return $this->order_not_found_error();
		}

		$reply_update_id = absint( $request->get_param( 'update_id' ) );
		$is_reply        = $reply_update_id > 0;

		$message_label = __( 'Message', 'order-updates-for-woo' );
		$subject_label = __( 'Subject', 'order-updates-for-woo' );

		$message = $this->validator->sanitize_note(
			(string) $request->get_param( 'message' ),
			500,
			$message_label
		);

		if ( is_wp_error( $message ) ) {
			return $message;
		}

		if ( '' === $message ) {
			return new WP_Error(
				'order_updates_for_woo_missing_message',
				sprintf( /* translators: %s: field name */ __( '%s is required.', 'order-updates-for-woo' ), $message_label ),
				array( 'status' => 400 )
			);
		}

		$title = '';

		if ( ! $is_reply ) {
			$title = mb_substr(
				sanitize_text_field( wp_unslash( (string) $request->get_param( 'title' ) ) ),
				0,
				191
			);

			if ( '' === $title ) {
				return new WP_Error(
					'order_updates_for_woo_missing_subject',
					sprintf( /* translators: %s: field name */ __( '%s is required.', 'order-updates-for-woo' ), $subject_label ),
					array( 'status' => 400 )
				);
			}
		}

		$files = $this->collect_upload_files( $request );

		if ( count( $files ) > Variables::getMaxAttachmentFiles() ) {
			return new WP_Error(
				'order_updates_for_woo_too_many_files',
				sprintf( /* translators: %d: max number of files */ __( 'You can attach up to %d files.', 'order-updates-for-woo' ), Variables::getMaxAttachmentFiles() ),
				array( 'status' => 400 )
			);
		}

		$note_author = $this->update_note_service->get_note_author_for_customer_submit( $order );
		$now         = current_time( 'mysql', true );

		// Always rotate through the configured assignee priority list.
		// AssigneePicker internally falls back to the first administrator
		// when the list is empty, so a customer submission never lands
		// without an owner.
		$assignee_id = AssigneePicker::next();

		/**
		 * Fires before a customer-initiated update or reply is saved.
		 *
		 * @param int             $order_id
		 * @param WC_Order        $order
		 * @param array           $note_author  { id:int, name:string } — id is 0 for guests
		 * @param bool            $is_reply     true when adding to an existing update, false when creating a new one
		 * @param WP_REST_Request $request
		 */
		do_action( 'order_updates_for_woo_before_customer_submit', $order_id, $order, $note_author, $is_reply, $request );

		if ( $is_reply ) {
			$existing = $this->order_updates_db->get_update( $reply_update_id );

			if ( empty( $existing ) || (int) ( $existing['order_id'] ?? 0 ) !== $order_id || ! UpdateState::is_customer_visible( $existing ) ) {
				return new WP_Error( 'order_updates_for_woo_invalid_update', __( 'You are not allowed to submit a note for this order.', 'order-updates-for-woo' ), array( 'status' => 404 ) );
			}

			if ( UpdateState::is_resolved( $existing ) ) {
				$existing_rating = $this->order_updates_db->get_rating_for_update( $reply_update_id );

				if ( ! empty( $existing_rating['created_at'] ) ) {
					return new WP_Error(
						'order_updates_for_woo_thread_closed',
						__( 'This update has already been rated. Please open a new update if you need further help.', 'order-updates-for-woo' ),
						array( 'status' => 409 )
					);
				}
			}

			$update_id = $reply_update_id;
		} else {
			// Customers don't see the status picker — every customer-initiated
			// update inherits the admin-configured default status (key + color).
			// Storing both keeps the footer pill resolution working: the lookup
			// happens by key, with the color cached on the row as a fallback.
			$default_status = $this->settings_service->default_customer_status();
			$status_color   = (string) ( $default_status['color'] ?? self::DEFAULT_COLOR );
			$status_key     = (string) ( $default_status['key'] ?? '' );

			$update_id = $this->order_updates_db->create_order_update( array(
				'order_id'         => $order_id,
				'title'            => $title,
				'customer_visible' => 1,
				'status'           => $status_key,
				'color'            => $status_color,
				'created_by'       => $note_author['id'],
				'created_at'       => $now,
			) );

			if ( ! $update_id ) {
				return new WP_Error( 'order_updates_for_woo_save_failed', __( 'Could not save your note. Please try again.', 'order-updates-for-woo' ), array( 'status' => 500 ) );
			}
		}

		$note_id = $this->order_updates_db->create_customer_note(
			$update_id,
			$message,
			$note_author['id'],
			$note_author['name'],
			$now
		);

		if ( ! $note_id ) {
			return new WP_Error( 'order_updates_for_woo_save_failed', __( 'Could not save your note. Please try again.', 'order-updates-for-woo' ), array( 'status' => 500 ) );
		}

		if ( ! $is_reply && $assignee_id ) {
			$this->order_updates_db->sync_assignee( $update_id, $assignee_id, $note_author['id'] ?: $assignee_id, $now );
		}

		$uploaded_attachments = array();

		foreach ( $files as $file ) {
			$stored = $this->attachment_service->store_upload( $file, array(
				'order_id'    => $order_id,
				'update_id'   => $update_id,
				'note_id'     => $note_id,
				'note_type'   => Constants::NOTE_TYPE_CUSTOMER,
				'uploaded_by' => $note_author['id'],
			) );

			if ( is_wp_error( $stored ) ) {
				// Roll back the note + any partial uploads. Otherwise every
				// failed retry would leave an orphan note in the thread.
				foreach ( $uploaded_attachments as $partial ) {
					$partial_id = (int) ( $partial['id'] ?? 0 );
					if ( $partial_id ) {
						$this->attachment_service->delete( $partial_id );
					}
				}
				$this->order_updates_db->delete_customer_note( $note_id );
				return $stored;
			}

			$uploaded_attachments[] = $stored;
		}

		$notification_context = $is_reply ? 'customer_reply' : 'customer_submitted';

		// "Owner" = the user who originally opened the update. On a reply
		// it's the existing update's creator; on a new submission it's the
		// customer (so id=0 and no staff owner to email yet).
		$owner_user_id = $is_reply
			? (int) ( $existing['created_by'] ?? 0 )
			: 0;

		$notify_assignee_id = $is_reply
			? (int) ( $existing['assignee_user_id'] ?? 0 )
			: (int) $assignee_id;

		// Don't auto-assign on a customer reply to an unassigned update —
		// the creator (owner) is the natural responder and gets emailed below.
		// The rotation pool is only for customer-opened NEW updates.

		$notified_user_ids = array();

		// The order's customer is never a staff-email recipient. Used as a
		// hard skip before queueing any staff-targeted hook below.
		$order_customer_id = absint( $order->get_customer_id() );

		// On a customer reply, prefer ASSIGNEE_NOTIFICATION when the same
		// person is both owner AND assignee — the "you have a reply on the
		// update assigned to you" framing fits the moment better than the
		// generic admin notification.
		if ( $is_reply && $notify_assignee_id > 0 && $notify_assignee_id !== $order_customer_id && $notify_assignee_id !== $note_author['id'] && ! StaffEmailPreference::is_muted( $update_id, $notify_assignee_id ) ) {
			$this->async_job->queue(
				Constants::HOOK_ASSIGNEE_NOTIFICATION,
				array(
					'update_id'        => $update_id,
					'assignee_user_id' => $notify_assignee_id,
					'context'          => $notification_context,
					'note_id'          => (int) $note_id,
					'note_type'        => 'customer',
				)
			);
			$notified_user_ids[] = $notify_assignee_id;
		}

		if ( $owner_user_id > 0 && $owner_user_id !== $order_customer_id && $owner_user_id !== $note_author['id'] && ! in_array( $owner_user_id, $notified_user_ids, true ) && ! StaffEmailPreference::is_muted( $update_id, $owner_user_id ) ) {
			$this->async_job->queue(
				Constants::HOOK_ADMIN_NOTIFICATION,
				array(
					'update_id'         => $update_id,
					'recipient_user_id' => $owner_user_id,
					'context'           => $notification_context,
					// Pin the email to THIS customer note so a follow-up
					// submission queued before this one runs doesn't shift
					// the rendered body to the newer note (race condition).
					'note_id'           => (int) $note_id,
					'note_type'         => 'customer',
				)
			);
			$notified_user_ids[] = $owner_user_id;
		} elseif ( ! $is_reply && $this->settings_service->notify_admin_on_customer_create() ) {
			// Site admin opt-in — gated by "Email site admin when a customer
			// opens a new update". When on, the user matched by `admin_email`
			// gets a heads-up email.
			$admin_user    = get_user_by( 'email', (string) get_option( 'admin_email' ) );
			$admin_user_id = $admin_user ? (int) $admin_user->ID : 0;

			if ( $admin_user_id > 0 && ! StaffEmailPreference::is_muted( $update_id, $admin_user_id ) ) {
				$this->async_job->queue(
					Constants::HOOK_ADMIN_NOTIFICATION,
					array(
						'update_id' => $update_id,
						'context'   => $notification_context,
						'note_id'   => (int) $note_id,
						'note_type' => 'customer',
					)
				);
				// Record so the assignee branch below doesn't double-email
				// the same person (common: assignee == site admin in small
				// stores, or when admin is the configured primary assignee).
				$notified_user_ids[] = $admin_user_id;
			}
		}

		// Receipt email for new customer-opened updates only. Replies are part
		// of an existing thread and don't need a fresh receipt. The customer's
		// email preference still applies.
		if ( ! $is_reply ) {
			$customer_id_for_pref = (int) $order->get_customer_id();
			if ( \OrderUpdatesForWoo\Helpers\CustomerEmailPreference::get( $order_id, $customer_id_for_pref ) ) {
				$this->async_job->queue(
					Constants::HOOK_CUSTOMER_NOTIFICATION,
					array(
						'update_id' => $update_id,
						'note_id'   => (int) $note_id,
						'context'   => 'customer_submitted',
					)
				);
			}
		}

		// New-submission assignee branch — replies already dispatched above.
		if ( ! $is_reply && $notify_assignee_id > 0 && $notify_assignee_id !== $order_customer_id && ! in_array( $notify_assignee_id, $notified_user_ids, true ) && $notify_assignee_id !== $note_author['id'] && ! StaffEmailPreference::is_muted( $update_id, $notify_assignee_id ) ) {
			$this->async_job->queue(
				Constants::HOOK_ASSIGNEE_NOTIFICATION,
				array(
					'update_id'        => $update_id,
					'assignee_user_id' => $notify_assignee_id,
					'context'          => $notification_context,
					'note_id'          => (int) $note_id,
					'note_type'        => 'customer',
				)
			);
		}

		if ( current_user_can( 'edit_shop_orders' ) ) {
			/**
			 * Fires when a staff member submits a note via the customer-facing endpoint.
			 *
			 * @param int    $update_id
			 * @param int    $order_id
			 * @param int    $note_id
			 * @param string $staff_name      Display name of the staff member.
			 * @param int    $sender_user_id  Staff user ID — excluded from notifications.
			 * @param int[]  $notify_user_ids User IDs to notify (assignee + update owner).
			 */
			do_action(
				'order_updates_for_woo_admin_bar_staff_reply',
				$update_id,
				$order_id,
				$note_id,
				$note_author['name'],
				(int) $note_author['id'],
				array_values( array_filter( array( $notify_assignee_id, $owner_user_id ) ) )
			);
		}

		/**
		 * Fires after a customer-initiated update or reply has been saved.
		 *
		 * @param int   $update_id
		 * @param int   $note_id
		 * @param array $context {
		 *     @type int       $order_id       Order this update belongs to.
		 *     @type WC_Order  $order
		 *     @type array     $note_author    { id:int, name:string } — id is 0 for guests.
		 *     @type array     $attachments    Stored attachment records for this note.
		 *     @type int       $assignee_id    Assignee notified for this submission (0 if none).
		 *     @type int       $owner_user_id  Original creator of the update (0 for new customer-created updates).
		 *     @type bool      $is_reply       true when adding to an existing update.
		 * }
		 */
		do_action(
			'order_updates_for_woo_after_customer_submit',
			$update_id,
			$note_id,
			array(
				'order_id'      => $order_id,
				'order'         => $order,
				'note_author'   => $note_author,
				'attachments'   => $uploaded_attachments,
				'assignee_id'   => $notify_assignee_id,
				'owner_user_id' => $owner_user_id,
				'is_reply'      => $is_reply,
			)
		);

		// Fetch the saved note row from DB so format_customer_thread_note can
		// include the properly stored attachments and canonical field values.
		$note_row    = $this->order_updates_db->get_customer_note_by_id( $note_id );
		$customer_id = (int) $order->get_customer_id();

		$response = array(
			'message'  => $is_reply
				? __( 'Your reply has been sent.', 'order-updates-for-woo' )
				: __( 'Thanks! Your note has been sent to our team.', 'order-updates-for-woo' ),
			'updateId' => $update_id,
			'noteId'   => $note_id,
			'isReply'  => $is_reply,
			'note'     => ! empty( $note_row )
				// The just-posted note IS the latest in the thread, so its own
			// id is the latest-id anchor — without this the formatter
			// returns can_edit=false, the JS skips rendering the edit
			// button, and Up-arrow on the textarea jumps two notes back.
			? $this->viewer_service->format_customer_thread_note( $note_row, $customer_id, (int) $note_id )
				: array(),
		);

		return rest_ensure_response( apply_filters( 'order_updates_for_woo_customer_submit_response', $response, $request ) );
	}

	/**
	 * Build a list of PHP-style $_FILES entries from the request's `files[]`
	 * multipart payload, dropping empties so callers don't re-check them.
	 *
	 * @return array<int,array{name:string,tmp_name:string,type:string,size:int,error:int}>
	 */
	private function collect_upload_files( WP_REST_Request $request ): array {
		$raw = $request->get_file_params();
		$files = array();

		if ( isset( $raw['files'] ) && is_array( $raw['files']['name'] ?? null ) ) {
			$count = count( $raw['files']['name'] );

			for ( $i = 0; $i < $count; $i++ ) {
				$error = (int) ( $raw['files']['error'][ $i ] ?? UPLOAD_ERR_NO_FILE );

				if ( UPLOAD_ERR_NO_FILE === $error ) {
					continue;
				}

				$files[] = array(
					'name'     => (string) ( $raw['files']['name'][ $i ] ?? '' ),
					'tmp_name' => (string) ( $raw['files']['tmp_name'][ $i ] ?? '' ),
					'type'     => (string) ( $raw['files']['type'][ $i ] ?? '' ),
					'size'     => (int) ( $raw['files']['size'][ $i ] ?? 0 ),
					'error'    => $error,
				);
			}
		}

		return $files;
	}

}
