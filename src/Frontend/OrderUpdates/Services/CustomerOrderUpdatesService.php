<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Frontend\OrderUpdates\Services;

use OrderUpdatesForWoo\Admin\Settings\Services\OrderUpdatesSettingsService;
use OrderUpdatesForWoo\Helpers\AttachmentPresenter;
use OrderUpdatesForWoo\Helpers\DateHelper;
use OrderUpdatesForWoo\Helpers\UpdateState;
use OrderUpdatesForWoo\Shared\Attachments\AttachmentsDb;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Updates\NoteActionPolicy;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use WC_Order;

/**
 * Builds the customer-side data for the order-updates page.
 *
 * Resolves whether the current viewer is allowed to see the page, fetches
 * the customer-visible updates and notes for an order, and shapes them
 * into the structure the view template renders. Sits on top of
 * OrderUpdatesDb / AttachmentsDb — no SQL of its own.
 */
final class CustomerOrderUpdatesService {
	public function __construct(
		private OrderUpdatesDb $order_updates_db,
		private AttachmentsDb $attachments_db,
		private OrderUpdatesSettingsService $settings_service,
		private NoteActionPolicy $note_action_policy
	) {}

	/**
	 * View-ready config for the customer rating UI. Cheap to call repeatedly.
	 *
	 * @return array{enabled:bool, comment_enabled:bool}
	 */
	public function get_rating_config(): array {
		$features = $this->settings_service->get_feature_settings();

		return array(
			'enabled'         => ! empty( $features['enable_customer_rating'] ),
			'comment_enabled' => ! empty( $features['enable_customer_rating_comment'] ),
		);
	}

	public const VIEW_ALLOWED    = '';
	public const VIEW_EXPIRED    = 'expired';
	public const VIEW_RESTRICTED = 'restricted';
	public const VIEW_INVALID    = 'invalid';

	/**
	 * Whether the current viewer may see customer-visible updates on $order_id.
	 *
	 * Site administrators are allowed (so they can preview / debug). Other
	 * staff (shop managers, etc.) are not — they have the admin order screen.
	 * Customers qualify by being logged in as the order owner or by providing
	 * the matching order_key (guest links, email recipients).
	 */
	public function can_view_order( int $order_id, ?string $order_key = null ): bool {
		return self::VIEW_ALLOWED === $this->resolve_view_status( $order_id, $order_key );
	}

	/**
	 * Resolve the access status for a viewer, separating "the order is gone"
	 * from "you're not allowed" from "the link is wrong" so the caller can
	 * surface a relevant message instead of a single generic error.
	 *
	 * Returns one of the VIEW_* constants. Empty string means allowed.
	 */
	public function resolve_view_status( int $order_id, ?string $order_key = null ): string {
		if ( current_user_can( 'manage_options' ) ) {
			return self::VIEW_ALLOWED;
		}

		if ( ! $order_id ) {
			return self::VIEW_EXPIRED;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return self::VIEW_EXPIRED;
		}

		// Non-admin staff: they can configure access via the admin order screen,
		// not by visiting the customer page.
		if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_shop_orders' ) ) {
			return self::VIEW_RESTRICTED;
		}

		$user_id     = get_current_user_id();
		$customer_id = (int) $order->get_customer_id();

		if ( $user_id ) {
			return ( $customer_id > 0 && $customer_id === $user_id )
				? self::VIEW_ALLOWED
				: self::VIEW_RESTRICTED;
		}

		if ( null !== $order_key && '' !== $order_key && hash_equals( (string) $order->get_order_key(), (string) $order_key ) ) {
			return self::VIEW_ALLOWED;
		}

		return self::VIEW_INVALID;
	}

	/**
	 * Whether the current request is the order's actual customer — either
	 * logged in and matching the order's customer_id, or a guest providing
	 * the correct order_key. Unlike {@see can_view_order()}, this does NOT
	 * grant access to admins/shop managers, so it is the right gate for
	 * customer-authored actions (ratings, "write a note", reply submissions).
	 */
	public function is_acting_as_customer( int $order_id, ?string $order_key = null ): bool {
		if ( ! $order_id ) {
			return false;
		}

		// Staff are never customers — even if they happen to know the order_key
		// (it's visible to them in the admin), they should not be able to submit
		// customer-authored actions like ratings or notes.
		if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_shop_orders' ) ) {
			return false;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		$user_id     = get_current_user_id();
		$customer_id = (int) $order->get_customer_id();

		// Logged-in users are matched by identity, not by order key. A valid
		// order_key (forwarded email, copied URL) won't let them view someone
		// else's order while signed in.
		if ( $user_id ) {
			return $customer_id > 0 && $customer_id === $user_id;
		}

		if ( null !== $order_key && '' !== $order_key && hash_equals( (string) $order->get_order_key(), (string) $order_key ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether $order_id has any customer-visible updates worth surfacing.
	 */
	public function has_customer_visible_updates( int $order_id ): bool {
		if ( ! $order_id ) {
			return false;
		}

		$summary = $this->order_updates_db->get_order_update_summary( $order_id );

		return ! empty( $summary['has_customer_visible'] );
	}

	/**
	 * Return the customer-visible updates for an order with customer notes
	 * and their attachments pre-formatted for the view.
	 */
	public function get_updates_for_order( int $order_id ): array {
		if ( ! $order_id ) {
			return array();
		}

		$order            = wc_get_order( $order_id );
		$customer_user_id = $order instanceof WC_Order ? (int) $order->get_customer_id() : 0;
		$update_rows      = $this->order_updates_db->get_order_updates( $order_id, PHP_INT_MAX, 0 );
		$customer_visible = array();

		foreach ( $update_rows as $update_row ) {
			if ( ! UpdateState::is_customer_visible( $update_row ) ) {
				continue;
			}

			$customer_visible[] = $this->format_update( $update_row, $customer_user_id );
		}

		return $customer_visible;
	}

	/**
	 * Whether a customer-visible note was authored by someone other than the
	 * order's customer (i.e. a staff member, including guest-order staff replies).
	 */
	public static function is_staff_authored_note( array $note, int $customer_user_id ): bool {
		$created_by = (int) ( $note['created_by'] ?? 0 );

		// Guest writers (created_by = 0) are never staff. Catches customers
		// who reply via the order-key URL while signed out.
		if ( 0 === $created_by ) {
			return false;
		}

		if ( $customer_user_id > 0 ) {
			return $created_by !== $customer_user_id;
		}

		// Guest order: customer is anonymous (no WP user); anything with a
		// real user id is staff (handled by the guest-writer guard above
		// already returning false for created_by = 0).
		return true;
	}

	/**
	 * Format a single customer-thread note row into a view-ready shape.
	 * Shared between the initial page render and the poll endpoint so both
	 * surfaces return identical structures.
	 *
	 * $latest_note_id is the id of the newest note in the thread the caller
	 * has visibility on; pass 0 for paths where the note is definitionally
	 * not the latest (e.g. load-prev). Customer notes are editable only when
	 * the note's id matches this value — once any newer note arrives the
	 * message is part of the historical conversation.
	 */
	public function format_customer_thread_note( array $note, int $customer_user_id, int $latest_note_id = 0 ): array {
		$is_staff = self::is_staff_authored_note( $note, $customer_user_id );
		$is_guest = 0 === get_current_user_id();

		$identity     = $this->resolve_staff_identity( $note, $is_staff );
		$author_label = $is_staff ? $identity['name'] : (string) $note['created_by_name'];

		$author_display = (string) apply_filters(
			'order_updates_for_woo_customer_note_author_label',
			$author_label,
			$note,
			$is_staff,
			$customer_user_id
		);

		$note_id   = (int) $note['id'];
		$is_latest = $latest_note_id > 0 && $note_id === $latest_note_id;
		// Pass $latest_note_id so the policy applies the same latest-only
		// rule as the JS. The local $is_latest below is the fallback when
		// the caller didn't resolve a latest id.
		$within_edit = ! $is_staff && $this->note_action_policy->can_edit_customer_authored_note( $note, $customer_user_id, $is_guest, $latest_note_id );

		// Rows tagged with a non-default `kind` are system events — render
		// as a compact marker rather than a full bubble.
		$kind      = (string) ( $note['kind'] ?? 'note' );
		$is_system = '' !== $kind && 'note' !== $kind;

		return array(
			'id'              => $note_id,
			'update_id'       => (int) ( $note['update_id'] ?? 0 ),
			'note'            => (string) $note['note'],
			'kind'            => $kind,
			'is_system'       => $is_system,
			'created_by_name' => (string) $note['created_by_name'],
			'is_staff'        => $is_staff,
			'author_display'  => $author_display,
			'avatar_url'      => $identity['avatar_url'],
			'created_at'      => DateHelper::format_date( (string) $note['created_at'] ),
			'edited_at'       => ! empty( $note['edited_at'] )
				? DateHelper::format_date( (string) $note['edited_at'] )
				: null,
			'notified_at'     => ! empty( $note['notified_at'] )
				? DateHelper::format_date( (string) $note['notified_at'] )
				: null,
			'can_edit'        => ! $is_system && $is_latest && $within_edit,
			'attachments'     => ! $is_system
				? AttachmentPresenter::format_many(
					$this->attachments_db->get_for_note( (int) $note['id'], Constants::NOTE_TYPE_CUSTOMER ),
					Constants::ATTACHMENT_CONTEXT_CUSTOMER
				)
				: array(),
		);
	}

	/**
	 * Resolve the name and avatar shown to customers for a staff-authored
	 * note. When the "Show assignee to customers" setting is on, surface
	 * the real staff display name + gravatar so customers see who they're
	 * talking to. When off (default), use the store name and no avatar so
	 * internal identities aren't leaked.
	 *
	 * @return array{name:string, avatar_url:string}
	 */
	private function resolve_staff_identity( array $note, bool $is_staff ): array {
		if ( ! $is_staff ) {
			return array(
				'name'       => '',
				'avatar_url' => '',
			);
		}

		$features   = $this->settings_service->get_feature_settings();
		$reveal     = ! empty( $features['show_assignee_to_customers'] );
		$store_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		if ( ! $reveal ) {
			return array(
				/* translators: %s: site name */
				'name'       => sprintf( __( 'By %s', 'order-updates-for-woo' ), $store_name ),
				'avatar_url' => '',
			);
		}

		$created_by = (int) ( $note['created_by'] ?? 0 );
		$user       = $created_by > 0 ? get_userdata( $created_by ) : null;

		if ( ! $user ) {
			return array(
				/* translators: %s: site name */
				'name'       => sprintf( __( 'By %s', 'order-updates-for-woo' ), $store_name ),
				'avatar_url' => '',
			);
		}

		// Never show user_login to customers — it's half of the login surface,
		// and WP defaults `display_name` to user_login for staff who haven't
		// set a profile name. Use first + last name only.
		$first     = (string) get_user_meta( $user->ID, 'first_name', true );
		$last      = (string) get_user_meta( $user->ID, 'last_name', true );
		$real_name = trim( $first . ' ' . $last );

		if ( '' === $real_name ) {
			return array(
				/* translators: %s: site name */
				'name'       => sprintf( __( 'By %s', 'order-updates-for-woo' ), $store_name ),
				'avatar_url' => '',
			);
		}

		return array(
			'name'       => $real_name,
			'avatar_url' => (string) get_avatar_url( $user->ID, array( 'size' => 56 ) ),
		);
	}

	/**
	 * Build a compact order summary the customer can glance at while writing
	 * an update — items, totals, and current status. The same data already
	 * lives in the order email; surfacing it here saves a tab-switch.
	 *
	 * Returns an empty array when the order can't be loaded so the caller
	 * can skip rendering without further checks.
	 *
	 * @return array{
	 *     order_number:string,
	 *     status_label:string,
	 *     status_slug:string,
	 *     placed_at:string,
	 *     items:array<int, array{name:string, qty:int, line_total:string}>,
	 *     subtotal:string,
	 *     shipping_total:string,
	 *     tax_total:string,
	 *     total:string,
	 *     currency:string
	 * }|array{}
	 */
	public function get_order_summary( int $order_id ): array {
		if ( ! $order_id ) {
			return array();
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return array();
		}

		$items = array();

		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'name'       => (string) $item->get_name(),
				'qty'        => (int) $item->get_quantity(),
				'line_total' => (string) wc_price( (float) $item->get_total(), array( 'currency' => $order->get_currency() ) ),
			);
		}

		$status_slug = (string) $order->get_status();
		$currency    = $order->get_currency();
		$price_args  = array( 'currency' => $currency );

		$summary = array(
			'order_number'   => (string) $order->get_order_number(),
			'status_label'   => wc_get_order_status_name( $status_slug ),
			'status_slug'    => $status_slug,
			'placed_at'      => $order->get_date_created()
				? DateHelper::format_date( $order->get_date_created()->date( 'Y-m-d H:i:s' ) )
				: '',
			'items'          => $items,
			'subtotal'       => (string) wc_price( (float) $order->get_subtotal(), $price_args ),
			'shipping_total' => (string) wc_price( (float) $order->get_shipping_total(), $price_args ),
			'tax_total'      => (string) wc_price( (float) $order->get_total_tax(), $price_args ),
			'total'          => (string) wc_price( (float) $order->get_total(), $price_args ),
			'currency'       => (string) $currency,
		);

		/**
		 * Filter the customer-facing order summary payload before it is rendered.
		 * Addons can add fields (tracking numbers, custom meta) or override values.
		 *
		 * @param array    $summary Order summary, see method docblock for shape.
		 * @param WC_Order $order   The order object.
		 */
		return (array) apply_filters( 'order_updates_for_woo_customer_order_summary', $summary, $order );
	}

	private function format_update( array $row, int $customer_user_id ): array {
		$update_id        = (int) $row['id'];
		$assignee_user_id = (int) ( $row['assignee_user_id'] ?? 0 );

		$assignee_first_name = '';
		if ( $assignee_user_id > 0 ) {
			$user_data           = get_userdata( $assignee_user_id );
			$assignee_first_name = $user_data ? (string) $user_data->first_name : '';
			if ( '' === $assignee_first_name ) {
				$parts               = explode( ' ', (string) ( $row['assignee_name'] ?? '' ) );
				$assignee_first_name = $parts[0] ?? '';
			}
		}

		$paged_notes = $this->order_updates_db->get_customer_notes_paged( $update_id, Constants::CUSTOMER_NOTES_PAGE_SIZE );

		// Highest note id in the visible set is the most recent — used by
		// format_customer_thread_note to lock edits on older notes.
		// Don't reuse $row; it holds the update row needed below.
		$latest_note_id = 0;
		foreach ( $paged_notes['notes'] as $note_row ) {
			$rid = (int) ( $note_row['id'] ?? 0 );
			if ( $rid > $latest_note_id ) {
				$latest_note_id = $rid;
			}
		}

		$formatted_notes = array_map(
			fn( array $note ) => $this->format_customer_thread_note( $note, $customer_user_id, $latest_note_id ),
			$paged_notes['notes']
		);

		$rating         = $this->order_updates_db->get_rating_for_update( $update_id );
		$rating_payload = array();

		if ( ! empty( $rating ) ) {
			$rating_payload = array(
				'stars'      => isset( $rating['stars'] ) ? (int) $rating['stars'] : 0,
				'comment'    => (string) ( $rating['comment'] ?? '' ),
				'created_at' => ! empty( $rating['created_at'] )
					? DateHelper::format_date( (string) $rating['created_at'] )
					: null,
			);
		}

		return array(
			'id'                     => $update_id,
			'title'                  => (string) $row['title'],
			'color'                  => (string) ( $row['color'] ?? '' ),
			'is_resolved'            => (bool) ( $row['is_resolved'] ?? false ),
			'created_at'             => DateHelper::format_date( (string) $row['created_at'] ),
			'solved_at'              => ! empty( $row['solved_at'] )
				? DateHelper::format_date( (string) $row['solved_at'] )
				: null,
			'notified_at'            => ! empty( $row['notified_customer_at'] )
				? DateHelper::format_date( (string) $row['notified_customer_at'] )
				: null,
			'notes'                  => $formatted_notes,
			'has_more_notes'         => $paged_notes['has_more'],
			'assignee_name'          => (string) ( $row['assignee_name'] ?? '' ),
			'assignee_first_name'    => $assignee_first_name,
			'assignee_since_note_id' => isset( $row['assignee_since_note_id'] ) ? (int) $row['assignee_since_note_id'] : 0,
			'rating'                 => $rating_payload,
		);
	}
}
