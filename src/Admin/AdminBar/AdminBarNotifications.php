<?php
/**
 * Admin bar notifications for assigned updates and mentions.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\AdminBar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OrderUpdatesForWoo\Helpers\AssetHelper;
use OrderUpdatesForWoo\Helpers\AdminBarNotificationStore;
use OrderUpdatesForWoo\Helpers\HposHelper;
use OrderUpdatesForWoo\Helpers\StaffEmailPreference;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Team\TeamRosterService;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use WP_Admin_Bar;

/**
 * Admin Bar Notifications.
 */
final class AdminBarNotifications {
	private const NODE_ID                 = 'awts_assigned_updates';
	private const ASSIGNED_HEADER         = 'awts_admin_bar_assigned_header';
	private const MENTIONS_HEADER         = 'awts_admin_bar_mentions_header';
	private const REPLIES_HEADER          = 'awts_admin_bar_replies_header';
	private const DELETED_HEADER          = 'awts_admin_bar_deleted_header';
	private const ASSIGNEE_CHANGED_HEADER = 'awts_admin_bar_assignee_changed_header';
	private const CLEAR_ALL_ROW           = 'awts_admin_bar_clear_all';
	private const SNIPPET_LEN             = 60;

	/**
	 * Inject dependencies.
	 *
	 * @param OrderUpdatesDb $order_updates_db Injected dependency.
	 */
	public function __construct( private OrderUpdatesDb $order_updates_db ) {}

	/**
	 * Register the hooks this section depends on.
	 */
	public function init(): void {
		add_action( 'admin_bar_menu', array( $this, 'add_nodes' ), 999 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_filter( 'heartbeat_received', array( $this, 'handle_heartbeat' ), 10, 2 );
		add_action( 'wp_ajax_' . Constants::ADMIN_BAR_DISMISS_ACTION, array( $this, 'handle_dismiss' ) );
		add_action( 'wp_ajax_' . Constants::ADMIN_BAR_DISMISS_FOR_UPDATE_ACTION, array( $this, 'handle_dismiss_for_update' ) );
		add_action( 'wp_ajax_' . Constants::ADMIN_BAR_DISMISS_ALL_ACTION, array( $this, 'handle_dismiss_all' ) );

		// Create admin bar notifications on assignment, mention, and customer reply.
		add_action( 'order_updates_for_woo_admin_bar_assigned', array( $this, 'on_assigned' ), 10, 4 );
		add_action( 'order_updates_for_woo_admin_bar_mention', array( $this, 'on_mention' ), 10, 5 );
		add_action( 'order_updates_for_woo_after_customer_submit', array( $this, 'on_customer_submit' ), 10, 3 );
		add_action( 'order_updates_for_woo_admin_bar_staff_reply', array( $this, 'on_staff_reply' ), 10, 6 );

		// Cascade — when an update is deleted, sweep every user's admin-bar
		// entries referencing it. Otherwise the bar shows orphan rows
		// pointing at a row that no longer exists.
		add_action( 'order_updates_for_woo_update_deleted', array( $this, 'on_update_deleted' ), 10, 1 );

		// State changes notify the update's owners (creator + assignee). Each
		// listener takes only the update id (accepted_args = 1); the actor is
		// the current user, so they exclude themselves.
		add_action( 'order_updates_for_woo_status_changed', array( $this, 'on_status_changed' ), 10, 1 );
		add_action( 'order_updates_for_woo_title_changed', array( $this, 'on_title_changed' ), 10, 1 );
		add_action( 'order_updates_for_woo_after_mark_solved', array( $this, 'on_marked_solved' ), 10, 1 );
		add_action( 'order_updates_for_woo_after_reopen_update', array( $this, 'on_reopened' ), 10, 1 );
	}

	/**
	 * Archive every user's admin-bar rows for a deleted update.
	 *
	 * @param int $update_id Update that was deleted.
	 */
	public function on_update_deleted( int $update_id ): void {
		AdminBarNotificationStore::archive_for_update_for_all_users( $update_id );
	}

	/**
	 * Notify owners that the status changed.
	 *
	 * @param int $update_id Update id.
	 */
	public function on_status_changed( int $update_id ): void {
		$this->notify_owners_of_state_change( $update_id, 'status_changed' );
	}

	/**
	 * Notify owners that the title was renamed.
	 *
	 * @param int $update_id Update id.
	 */
	public function on_title_changed( int $update_id ): void {
		$this->notify_owners_of_state_change( $update_id, 'title_changed' );
	}

	/**
	 * Notify owners that the update was marked solved.
	 *
	 * @param int $update_id Update id.
	 */
	public function on_marked_solved( int $update_id ): void {
		$this->notify_owners_of_state_change( $update_id, 'solved' );
	}

	/**
	 * Notify owners that the update was reopened.
	 *
	 * @param int $update_id Update id.
	 */
	public function on_reopened( int $update_id ): void {
		$this->notify_owners_of_state_change( $update_id, 'reopened' );
	}

	/**
	 * Notify an update's owners — its creator and current assignee — that its
	 * state changed. Excludes whoever made the change (the current user), skips
	 * non-staff (the customer on a customer-opened update is never an admin-bar
	 * recipient) and anyone who muted the update.
	 *
	 * @param int    $update_id Update id.
	 * @param string $type      State-change type for the notification row.
	 */
	private function notify_owners_of_state_change( int $update_id, string $type ): void {
		$update = $this->order_updates_db->get_update( $update_id );
		if ( empty( $update ) ) {
			return;
		}

		$order_id = (int) ( $update['order_id'] ?? 0 );
		$actor_id = get_current_user_id();

		$recipients = array_unique(
			array_filter(
				array(
					(int) ( $update['created_by'] ?? 0 ),
					(int) ( $update['assignee_user_id'] ?? 0 ),
				)
			)
		);

		// Drop the actor (they made the change) and anyone who can't edit orders.
		$recipients = array_filter(
			$recipients,
			static fn( int $id ): bool => $id !== $actor_id
				&& ( user_can( $id, 'edit_shop_orders' ) || user_can( $id, 'manage_woocommerce' ) )
		);

		$recipients = $this->prune_admin_bar_recipients( $recipients, $update_id, $order_id );

		$title = (string) ( $update['title'] ?? '' );
		$actor = $this->current_actor_name();

		foreach ( $recipients as $recipient_user_id ) {
			AdminBarNotificationStore::add_update_change( $type, $update_id, $order_id, $title, (int) $recipient_user_id, $actor );
		}
	}

	/**
	 * Human row label for an "Update activity" notification, shared by the
	 * server-rendered admin bar and the heartbeat-rebuilt rows.
	 *
	 * @param string $type  Notification type (unassigned / assignee_changed / status_changed / title_changed / solved / reopened).
	 * @param string $label Update title, already defaulted to "(untitled)".
	 */
	private function state_change_row_title( string $type, string $label ): string {
		switch ( $type ) {
			case 'unassigned':
				/* translators: %s: update title. */
				return sprintf( __( 'You were unassigned from "%s"', 'order-updates-for-woo' ), $label );
			case 'status_changed':
				/* translators: %s: update title. */
				return sprintf( __( 'Status changed on "%s"', 'order-updates-for-woo' ), $label );
			case 'title_changed':
				/* translators: %s: update title. */
				return sprintf( __( 'Renamed to "%s"', 'order-updates-for-woo' ), $label );
			case 'solved':
				/* translators: %s: update title. */
				return sprintf( __( '"%s" was marked solved', 'order-updates-for-woo' ), $label );
			case 'reopened':
				/* translators: %s: update title. */
				return sprintf( __( '"%s" was reopened', 'order-updates-for-woo' ), $label );
			default:
				/* translators: %s: update title. */
				return sprintf( __( 'Assignee changed on "%s"', 'order-updates-for-woo' ), $label );
		}
	}

	/**
	 * Queue an "assigned to you" admin-bar notification.
	 *
	 * @param int    $update_id Update id.
	 * @param int    $order_id  Order id.
	 * @param string $title     Update title.
	 * @param int    $user_id   Assignee to notify.
	 */
	public function on_assigned( int $update_id, int $order_id, string $title, int $user_id ): void {
		AdminBarNotificationStore::add_assigned( $update_id, $order_id, $title, $user_id, $this->current_actor_name() );
	}

	/**
	 * Queue a "you were mentioned" admin-bar notification.
	 *
	 * @param int    $update_id Update id.
	 * @param int    $order_id  Order id.
	 * @param int    $note_id   Note containing the mention.
	 * @param string $snippet   Short note preview.
	 * @param int    $user_id   Tagged user to notify.
	 */
	public function on_mention( int $update_id, int $order_id, int $note_id, string $snippet, int $user_id ): void {
		AdminBarNotificationStore::add_mention( $update_id, $order_id, $note_id, $snippet, $user_id, $this->current_actor_name() );
	}

	/** Display name of whoever triggered the current action — the "By …" line on a notification row. */
	private function current_actor_name(): string {
		$user = wp_get_current_user();

		return $user && $user->exists() ? (string) $user->display_name : '';
	}

	/**
	 * Listen for customer-initiated submissions and queue admin-bar
	 * "Customer replied" notifications for the assignee and update owner.
	 *
	 * Skips this entire path when a staff member submitted on behalf of a
	 * customer — those go through on_staff_reply() instead so the recipients
	 * see "Bob replied" rather than "Customer replied".
	 *
	 * @param int   $update_id Update id.
	 * @param int   $note_id   Customer note id.
	 * @param array $context   Submission context (author, order, assignee, …).
	 */
	public function on_customer_submit( int $update_id, int $note_id, array $context ): void {
		$note_author_id = (int) ( $context['note_author']['id'] ?? 0 );

		if ( $note_author_id && user_can( $note_author_id, 'edit_shop_orders' ) ) {
			return;
		}

		$order_id      = (int) ( $context['order_id'] ?? 0 );
		$assignee_id   = (int) ( $context['assignee_id'] ?? 0 );
		$owner_user_id = (int) ( $context['owner_user_id'] ?? 0 );
		$update        = $this->order_updates_db->get_update( $update_id );
		$customer_name = (string) ( $context['note_author']['name'] ?? '' );

		// Show the customer's actual message in the notification, not the
		// update title — falls back to the title only when the note has no
		// text (e.g. an attachment-only reply).
		$note    = $this->order_updates_db->get_customer_note_by_id( $note_id );
		$message = trim( (string) ( $note['note'] ?? '' ) );
		$title   = '' !== $message ? $message : (string) ( $update['title'] ?? '' );

		// Pull in everyone who has previously commented on this update so the
		// whole staff thread sees the new reply, not just the current assignee
		// + creator. Without this, members who collaborated earlier on the
		// thread silently miss the customer's response.
		$participants = $this->order_updates_db->get_staff_participant_user_ids( $update_id );

		$recipients = array_values(
			array_unique(
				array_filter(
					array_merge( array( $assignee_id, $owner_user_id ), $participants )
				) 
			) 
		);

		$recipients = $this->prune_admin_bar_recipients( $recipients, $update_id, $order_id );

		/**
		 * Filters the list of staff user ids that receive an admin-bar
		 * "Customer replied" notification. Addons can prune (e.g. mute on
		 * vacation) or extend (e.g. team subscriptions) the list.
		 */
		$recipients = (array) apply_filters(
			'order_updates_for_woo_customer_reply_admin_bar_recipients',
			$recipients,
			$update_id,
			$context
		);

		foreach ( $recipients as $recipient_user_id ) {
			AdminBarNotificationStore::add_customer_reply( $update_id, $order_id, $note_id, $title, (int) $recipient_user_id, $customer_name );
		}
	}

	/**
	 * Queue "staff replied" admin-bar notifications for a staff-sent reply.
	 *
	 * @param int    $update_id       Update id.
	 * @param int    $order_id        Order id.
	 * @param int    $note_id         Customer note id.
	 * @param string $staff_name      Name of the staff member who replied.
	 * @param int    $sender_user_id  Sender (excluded from recipients).
	 * @param int[]  $notify_user_ids Base recipients before pruning.
	 */
	public function on_staff_reply( int $update_id, int $order_id, int $note_id, string $staff_name, int $sender_user_id, array $notify_user_ids ): void {
		// Same broadening as on_customer_submit — staff who participated in
		// the thread should know when one of their teammates replies on the
		// customer's behalf.
		$participants = $this->order_updates_db->get_staff_participant_user_ids( $update_id );

		$recipients = array_unique( array_filter( array_merge( array_map( 'intval', $notify_user_ids ), $participants ) ) );

		$recipients = $this->prune_admin_bar_recipients( $recipients, $update_id, $order_id );

		$recipients = (array) apply_filters(
			'order_updates_for_woo_staff_reply_admin_bar_recipients',
			$recipients,
			$update_id,
			$sender_user_id
		);

		// Store the staff member's message so the notification row shows what
		// was said, not just who said it.
		$note    = $this->order_updates_db->get_customer_note_by_id( $note_id );
		$message = trim( (string) ( $note['note'] ?? '' ) );

		foreach ( $recipients as $user_id ) {
			$user_id = (int) $user_id;
			if ( ! $user_id || $user_id === $sender_user_id ) {
				continue;
			}
			AdminBarNotificationStore::add_staff_reply( $update_id, $order_id, $note_id, $staff_name, $user_id, $message );
		}
	}

	/**
	 * Drop the order's customer (admin-bar is staff-only — customer would
	 * never see the row, it'd just pollute their user_meta) and anyone who
	 * switched Get-notifications off for this update (the mute toggles both
	 * email AND admin-bar — the participant fan-out path already honours this
	 * via UpdateNoteService; this method does the same for the customer-reply
	 * and staff-reply paths that take a different route).
	 *
	 * @param int[] $recipients Candidate recipient user ids.
	 * @param int   $update_id  Update id.
	 * @param int   $order_id   Order id.
	 * @return int[]
	 */
	private function prune_admin_bar_recipients( array $recipients, int $update_id, int $order_id ): array {
		$order_customer_id = 0;
		if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order_customer_id = absint( $order->get_customer_id() );
			}
		}

		$pruned = array();

		foreach ( $recipients as $user_id ) {
			$user_id = (int) $user_id;

			if ( $user_id <= 0 ) {
				continue;
			}

			if ( $user_id === $order_customer_id ) {
				continue;
			}

			if ( StaffEmailPreference::is_muted( $update_id, $user_id ) ) {
				continue;
			}

			$pruned[] = $user_id;
		}

		return array_values( array_unique( $pruned ) );
	}

	// ----- Admin bar nodes -----

	/**
	 * Render the plugin's admin-bar notification nodes.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar The admin bar instance.
	 */
	public function add_nodes( WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! is_admin_bar_showing() || ! is_user_logged_in() ) {
			return;
		}

		$user_id       = get_current_user_id();
		$notifications = AdminBarNotificationStore::get_active( $user_id );
		$total         = count( $notifications );

		// Always render root node so heartbeat JS has a container to update.
		$wp_admin_bar->add_node(
			array(
				'id'    => self::NODE_ID,
				'title' => sprintf(
					'<span class="awts-ab-label">%s</span><span class="awts-ab-count" aria-hidden="%s">%s</span>',
					esc_html__( 'Order Updates', 'order-updates-for-woo' ),
					$total ? 'false' : 'true',
					$total ? (string) $total : ''
				),
				'href'  => HposHelper::orders_list_url(),
				'meta'  => array( 'class' => $total ? 'awts-has-notifications' : 'awts-no-notifications' ),
			)
		);

		// Always add a hidden placeholder child so WordPress renders the
		// .ab-sub-wrapper and .ab-submenu containers with proper event bindings.
		// JS rebuilds the submenu contents when heartbeat brings new items.
		$wp_admin_bar->add_node(
			array(
				'parent' => self::NODE_ID,
				'id'     => 'awts_ab_placeholder',
				'title'  => '',
				'meta'   => array( 'class' => 'awts-ab-placeholder' ),
			)
		);

		if ( ! $total ) {
			return;
		}

		$assigned      = array_values( array_filter( $notifications, fn( $notification ) => 'assigned' === $notification['type'] ) );
		$mentions      = array_values( array_filter( $notifications, fn( $notification ) => 'mention' === $notification['type'] ) );
		$replies       = array_values( array_filter( $notifications, fn( $notification ) => in_array( $notification['type'], array( 'customer_reply', 'staff_reply', 'participant_reply' ), true ) ) );
		$deleted       = array_values( array_filter( $notifications, fn( $notification ) => 'deleted' === $notification['type'] ) );
		$state_changes = array_values( array_filter( $notifications, fn( $notification ) => in_array( $notification['type'], array( 'unassigned', 'assignee_changed', 'status_changed', 'title_changed', 'solved', 'reopened' ), true ) ) );

		if ( ! empty( $assigned ) ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => self::NODE_ID,
					'id'     => self::ASSIGNED_HEADER,
					'title'  => esc_html__( 'Assigned to you', 'order-updates-for-woo' ),
					'meta'   => array( 'class' => 'awts-ab-section-header' ),
				)
			);

			foreach ( $assigned as $notification ) {
				$order = function_exists( 'wc_get_order' ) ? wc_get_order( $notification['order_id'] ) : null;
				if ( ! $order ) {
					continue;
				}

				$time_ago  = $this->format_time_ago( (int) $notification['time'] );
				$css_class = $this->row_item_classes( $notification );

				$wp_admin_bar->add_node(
					array(
						'parent' => self::NODE_ID,
						'id'     => 'awts_notif_' . sanitize_key( $notification['key'] ),
						'title'  => sprintf(
							'<span class="awts-ab-row"><span class="awts-ab-row-title">%s</span><span class="awts-ab-row-meta">%s</span></span>',
							esc_html( '' !== $notification['title'] ? $notification['title'] : __( '(no title)', 'order-updates-for-woo' ) ),
							esc_html( sprintf( /* translators: %s: order number */ __( 'Order #%s', 'order-updates-for-woo' ), (string) $order->get_order_number() ) )
								. ' &middot; ' . esc_html( $time_ago )
						),
						'href'   => $this->build_deep_link( $order->get_edit_order_url(), (int) $notification['update_id'], (int) ( $notification['note_id'] ?? 0 ), $this->tab_for_notification( $notification ) ),
						'meta'   => array( 'class' => $css_class ),
					)
				);
			}
		}

		if ( ! empty( $mentions ) ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => self::NODE_ID,
					'id'     => self::MENTIONS_HEADER,
					'title'  => esc_html__( 'You were tagged', 'order-updates-for-woo' ),
					'meta'   => array( 'class' => 'awts-ab-section-header' ),
				)
			);

			foreach ( $mentions as $notification ) {
				$order = function_exists( 'wc_get_order' ) ? wc_get_order( $notification['order_id'] ) : null;
				if ( ! $order ) {
					continue;
				}

				$time_ago  = $this->format_time_ago( (int) $notification['time'] );
				$css_class = $this->row_item_classes( $notification );

				$wp_admin_bar->add_node(
					array(
						'parent' => self::NODE_ID,
						'id'     => 'awts_notif_' . sanitize_key( $notification['key'] ),
						'title'  => sprintf(
							'<span class="awts-ab-row"><span class="awts-ab-row-title">%s</span><span class="awts-ab-row-meta">%s</span></span>',
							esc_html( $this->snippet( $notification['title'] ) ),
							esc_html( sprintf( /* translators: %s: order number */ __( 'Order #%s', 'order-updates-for-woo' ), (string) $order->get_order_number() ) )
								. ' &middot; ' . esc_html( $time_ago )
						),
						'href'   => $this->build_deep_link( $order->get_edit_order_url(), (int) $notification['update_id'], (int) ( $notification['note_id'] ?? 0 ), $this->tab_for_notification( $notification ) ),
						'meta'   => array( 'class' => $css_class ),
					)
				);
			}
		}

		if ( ! empty( $replies ) ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => self::NODE_ID,
					'id'     => self::REPLIES_HEADER,
					'title'  => esc_html__( 'Replies', 'order-updates-for-woo' ),
					'meta'   => array( 'class' => 'awts-ab-section-header' ),
				)
			);

			foreach ( $replies as $notification ) {
				$order = function_exists( 'wc_get_order' ) ? wc_get_order( $notification['order_id'] ) : null;
				if ( ! $order ) {
					continue;
				}

				$time_ago  = $this->format_time_ago( (int) $notification['time'] );
				$css_class = $this->row_item_classes( $notification );

				$row_title = 'staff_reply' === $notification['type']
					? sprintf( /* translators: %s: staff member display name */ __( '%s replied', 'order-updates-for-woo' ), $notification['title'] )
					: ( '' !== $notification['title'] ? $notification['title'] : __( '(no title)', 'order-updates-for-woo' ) );

				$wp_admin_bar->add_node(
					array(
						'parent' => self::NODE_ID,
						'id'     => 'awts_notif_' . sanitize_key( $notification['key'] ),
						'title'  => sprintf(
							'<span class="awts-ab-row"><span class="awts-ab-row-title">%s</span><span class="awts-ab-row-meta">%s</span></span>',
							esc_html( $row_title ),
							esc_html( sprintf( /* translators: %s: order number */ __( 'Order #%s', 'order-updates-for-woo' ), (string) $order->get_order_number() ) )
								. ' &middot; ' . esc_html( $time_ago )
						),
						'href'   => $this->build_deep_link( $order->get_edit_order_url(), (int) $notification['update_id'], (int) ( $notification['note_id'] ?? 0 ), $this->tab_for_notification( $notification ) ),
						'meta'   => array( 'class' => $css_class ),
					)
				);
			}
		}

		if ( ! empty( $deleted ) ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => self::NODE_ID,
					'id'     => self::DELETED_HEADER,
					'title'  => esc_html__( 'Updates you created — deleted', 'order-updates-for-woo' ),
					'meta'   => array( 'class' => 'awts-ab-section-header' ),
				)
			);

			foreach ( $deleted as $notification ) {
				$order = function_exists( 'wc_get_order' ) ? wc_get_order( $notification['order_id'] ) : null;
				if ( ! $order ) {
					continue;
				}

				$time_ago  = $this->format_time_ago( (int) $notification['time'] );
				$css_class = $this->row_item_classes( $notification );
				$row_title = '' !== $notification['title']
					? sprintf( /* translators: %s: deleted update title. */ __( '"%s" was deleted', 'order-updates-for-woo' ), $notification['title'] )
					: __( 'An update you created was deleted', 'order-updates-for-woo' );

				$wp_admin_bar->add_node(
					array(
						'parent' => self::NODE_ID,
						'id'     => 'awts_notif_' . sanitize_key( $notification['key'] ),
						'title'  => sprintf(
							'<span class="awts-ab-row"><span class="awts-ab-row-title">%s</span><span class="awts-ab-row-meta">%s</span></span>',
							esc_html( $row_title ),
							esc_html( sprintf( /* translators: %s: order number */ __( 'Order #%s', 'order-updates-for-woo' ), (string) $order->get_order_number() ) )
								. ' &middot; ' . esc_html( $time_ago )
						),
						// Deleted update — link to the order edit page (no hash;
						// the update row is gone). The DeletedUpdatesMetaBox on
						// that page shows the audit log.
						'href'   => $order->get_edit_order_url(),
						'meta'   => array( 'class' => $css_class ),
					)
				);
			}
		}

		if ( ! empty( $state_changes ) ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => self::NODE_ID,
					'id'     => self::ASSIGNEE_CHANGED_HEADER,
					'title'  => esc_html__( 'Update activity', 'order-updates-for-woo' ),
					'meta'   => array( 'class' => 'awts-ab-section-header' ),
				)
			);

			foreach ( $state_changes as $notification ) {
				$order = function_exists( 'wc_get_order' ) ? wc_get_order( $notification['order_id'] ) : null;
				if ( ! $order ) {
					continue;
				}

				$time_ago  = $this->format_time_ago( (int) $notification['time'] );
				$css_class = $this->row_item_classes( $notification );

				$row_label = '' !== $notification['title'] ? $notification['title'] : __( '(untitled)', 'order-updates-for-woo' );
				$row_title = $this->state_change_row_title( (string) $notification['type'], $row_label );

				$wp_admin_bar->add_node(
					array(
						'parent' => self::NODE_ID,
						'id'     => 'awts_notif_' . sanitize_key( $notification['key'] ),
						'title'  => sprintf(
							'<span class="awts-ab-row"><span class="awts-ab-row-title">%s</span><span class="awts-ab-row-meta">%s</span></span>',
							esc_html( $row_title ),
							esc_html( sprintf( /* translators: %s: order number */ __( 'Order #%s', 'order-updates-for-woo' ), (string) $order->get_order_number() ) )
								. ' &middot; ' . esc_html( $time_ago )
						),
						'href'   => $this->build_deep_link( $order->get_edit_order_url(), (int) $notification['update_id'] ),
						'meta'   => array( 'class' => $css_class ),
					)
				);
			}
		}

		// Footer action — wipes every active row in one click. JS picks the
		// row up by its `awts-ab-clear-all` class and POSTs to the matching
		// AJAX action. `href="#"` keeps it keyboard-focusable while the
		// click handler runs preventDefault().
		$wp_admin_bar->add_node(
			array(
				'parent' => self::NODE_ID,
				'id'     => self::CLEAR_ALL_ROW,
				'title'  => esc_html__( 'Clear', 'order-updates-for-woo' ),
				'href'   => '#',
				'meta'   => array( 'class' => 'awts-ab-clear-all' ),
			)
		);

		// "Show all" — opens the full notifications history page (active +
		// dismissed) where bulk Mark-as-read / Delete with filters lives.
		$wp_admin_bar->add_node(
			array(
				'parent' => self::NODE_ID,
				'id'     => 'awts_admin_bar_show_all',
				'title'  => esc_html__( 'Show all', 'order-updates-for-woo' ),
				'href'   => admin_url( 'admin.php?page=' . \OrderUpdatesForWoo\Admin\Notifications\NotificationsPageController::SLUG ),
				'meta'   => array( 'class' => 'awts-ab-show-all' ),
			)
		);
	}

	// ----- Asset enqueue -----

	/** Enqueue the admin-bar CSS/JS and localize the heartbeat config. */
	public function enqueue_assets(): void {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		$css_file    = ORDER_UPDATES_FOR_WOO_PATH . 'assets/Admin/css/admin-bar.css';
		$css_version = file_exists( $css_file ) ? (string) filemtime( $css_file ) : '1.0.0';
		wp_enqueue_style( 'order-updates-for-woo-admin-bar', AssetHelper::url( 'assets/Admin/css/admin-bar.css' ), array(), $css_version );

		$js_file    = ORDER_UPDATES_FOR_WOO_PATH . 'assets/Admin/js/admin-bar.js';
		$js_version = file_exists( $js_file ) ? (string) filemtime( $js_file ) : '1.0.0';
		wp_enqueue_script( 'order-updates-for-woo-admin-bar', AssetHelper::url( 'assets/Admin/js/admin-bar.js' ), array( 'jquery', 'heartbeat' ), $js_version, true );

		wp_localize_script(
			'order-updates-for-woo-admin-bar',
			'awtsAdminBarData',
			array(
				'heartbeatKey'   => Constants::HEARTBEAT_ADMIN_BAR_KEY,
				'nodeId'         => 'wp-admin-bar-' . self::NODE_ID,
				'viewAllLabel'   => __( 'View all order updates →', 'order-updates-for-woo' ),
				'showAllLabel'   => __( 'Show all', 'order-updates-for-woo' ),
				'clearAllLabel'  => __( 'Clear', 'order-updates-for-woo' ),
				'clearAllAction' => Constants::ADMIN_BAR_DISMISS_ALL_ACTION,
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'dismissNonce'   => wp_create_nonce( Constants::ADMIN_BAR_DISMISS_NONCE ),
			)
		);
	}

	/** Enqueue just the admin-bar CSS on the front end. */
	public function enqueue_styles(): void {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		$css_file = ORDER_UPDATES_FOR_WOO_PATH . 'assets/Admin/css/admin-bar.css';
		wp_enqueue_style( 'order-updates-for-woo-admin-bar', AssetHelper::url( 'assets/Admin/css/admin-bar.css' ), array(), file_exists( $css_file ) ? (string) filemtime( $css_file ) : '1.0.0' );
	}

	// ----- Heartbeat -----

	/**
	 * Attach the current notification count + items to the heartbeat response.
	 *
	 * @param array $response Heartbeat response so far.
	 * @param array $data     Heartbeat request data from the browser.
	 */
	public function handle_heartbeat( array $response, array $data ): array {
		if ( empty( $data[ Constants::HEARTBEAT_ADMIN_BAR_KEY ] ) || ! is_user_logged_in() ) {
			return $response;
		}

		$user_id       = get_current_user_id();
		$notifications = AdminBarNotificationStore::get_active( $user_id );

		$response[ Constants::HEARTBEAT_ADMIN_BAR_KEY ] = apply_filters(
			'order_updates_for_woo_heartbeat_admin_bar_response',
			array(
				'count' => count( $notifications ),
				'items' => $this->build_items_for_js( $notifications ),
			),
			$user_id
		);

		return $response;
	}

	// ----- AJAX dismiss -----

	/** AJAX: dismiss one admin-bar notification for the current user. */
	public function handle_dismiss(): void {
		check_ajax_referer( Constants::ADMIN_BAR_DISMISS_NONCE, 'nonce' );

		if ( ! TeamRosterService::user_is_team_member() ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}

		$key     = sanitize_text_field( wp_unslash( (string) ( $_POST['notif_key'] ?? '' ) ) );
		$user_id = get_current_user_id();

		if ( '' === $key || ! $user_id ) {
			wp_die( '', '', array( 'response' => 400 ) );
		}

		AdminBarNotificationStore::dismiss( $key, $user_id );

		wp_die( '', '', array( 'response' => 204 ) );
	}

	/** AJAX: dismiss all admin-bar notifications for the current user. */
	public function handle_dismiss_all(): void {
		check_ajax_referer( Constants::ADMIN_BAR_DISMISS_NONCE, 'nonce' );

		if ( ! TeamRosterService::user_is_team_member() ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_die( '', '', array( 'response' => 400 ) );
		}

		AdminBarNotificationStore::dismiss_all( $user_id );

		wp_die( '', '', array( 'response' => 204 ) );
	}

	/** AJAX: dismiss every admin-bar notification tied to one update. */
	public function handle_dismiss_for_update(): void {
		check_ajax_referer( Constants::ADMIN_BAR_DISMISS_NONCE, 'nonce' );

		if ( ! TeamRosterService::user_is_team_member() ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}

		// absint() coerces the unslashed POST value to a non-negative int,
		// which is both the sanitization AND the real validation for an id.
		$update_id = isset( $_POST['update_id'] ) ? absint( wp_unslash( $_POST['update_id'] ) ) : 0;
		$user_id   = get_current_user_id();

		if ( ! $update_id || ! $user_id ) {
			wp_die( '', '', array( 'response' => 400 ) );
		}

		AdminBarNotificationStore::dismiss_for_update( $update_id, $user_id );

		wp_die( '', '', array( 'response' => 204 ) );
	}

	// ----- Helpers -----

	/**
	 * Group active notifications into the per-section shape the heartbeat JS renders.
	 *
	 * @param array<int, array{key:string,type:string,update_id:int,order_id:int,note_id:int,title:string,time:int}> $notifications Active notifications.
	 * @return array<int, array{type:string, ...}>
	 */
	private function build_items_for_js( array $notifications ): array {
		$assigned_items         = array();
		$mention_items          = array();
		$reply_items            = array();
		$deleted_items          = array();
		$assignee_changed_items = array();

		foreach ( $notifications as $notification ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $notification['order_id'] ) : null;
			if ( ! $order ) {
				continue;
			}

			$time_ago = $this->format_time_ago( (int) $notification['time'] );
			$item     = array(
				'type'      => 'item',
				'notif_key' => $notification['key'],
				'update_id' => (int) $notification['update_id'],
				'note_id'   => (int) ( $notification['note_id'] ?? 0 ),
				'tab'       => $this->tab_for_notification( $notification ),
				'title'     => $notification['title'],
				'meta'      => sprintf( /* translators: %s: order number */ __( 'Order #%s', 'order-updates-for-woo' ), (string) $order->get_order_number() ),
				'time_ago'  => $time_ago,
				'url'       => $this->build_deep_link( $order->get_edit_order_url(), (int) $notification['update_id'], (int) ( $notification['note_id'] ?? 0 ), $this->tab_for_notification( $notification ) ),
			);

			if ( 'assigned' === $notification['type'] ) {
				$assigned_items[] = $item;
			} elseif ( in_array( $notification['type'], array( 'customer_reply', 'staff_reply', 'participant_reply' ), true ) ) {
				if ( 'staff_reply' === $notification['type'] ) {
					$item['title'] = sprintf( /* translators: %s: staff member display name */ __( '%s replied', 'order-updates-for-woo' ), $notification['title'] );
				}
				$reply_items[] = $item;
			} elseif ( 'deleted' === $notification['type'] ) {
				$item['title'] = '' !== $notification['title']
					? sprintf( /* translators: %s: deleted update title. */ __( '"%s" was deleted', 'order-updates-for-woo' ), $notification['title'] )
					: __( 'An update you created was deleted', 'order-updates-for-woo' );
				// Update row is gone, so no deep-link hash — land on the order
				// edit page where DeletedUpdatesMetaBox surfaces the audit.
				$item['url']     = $order->get_edit_order_url();
				$deleted_items[] = $item;
			} elseif ( in_array( $notification['type'], array( 'unassigned', 'assignee_changed', 'status_changed', 'title_changed', 'solved', 'reopened' ), true ) ) {
				$label                    = '' !== $notification['title'] ? $notification['title'] : __( '(untitled)', 'order-updates-for-woo' );
				$item['title']            = $this->state_change_row_title( (string) $notification['type'], $label );
				$assignee_changed_items[] = $item;
			} else {
				$mention_items[] = $item;
			}
		}

		$result = array();

		if ( ! empty( $assigned_items ) ) {
			$result[] = array(
				'type'  => 'header',
				'label' => __( 'Assigned to you', 'order-updates-for-woo' ),
			);
			foreach ( $assigned_items as $item ) {
				$result[] = $item;
			}
		}

		if ( ! empty( $mention_items ) ) {
			$result[] = array(
				'type'  => 'header',
				'label' => __( 'You were tagged', 'order-updates-for-woo' ),
			);
			foreach ( $mention_items as $item ) {
				$result[] = $item;
			}
		}

		if ( ! empty( $reply_items ) ) {
			$result[] = array(
				'type'  => 'header',
				'label' => __( 'Replies', 'order-updates-for-woo' ),
			);
			foreach ( $reply_items as $item ) {
				$result[] = $item;
			}
		}

		if ( ! empty( $deleted_items ) ) {
			$result[] = array(
				'type'  => 'header',
				'label' => __( 'Updates you created — deleted', 'order-updates-for-woo' ),
			);
			foreach ( $deleted_items as $item ) {
				$result[] = $item;
			}
		}

		if ( ! empty( $assignee_changed_items ) ) {
			$result[] = array(
				'type'  => 'header',
				'label' => __( 'Update activity', 'order-updates-for-woo' ),
			);
			foreach ( $assignee_changed_items as $item ) {
				$result[] = $item;
			}
		}

		// Footer rows — only when there's at least one notification. "Show all"
		// opens the full history page; "Clear" just wipes the bar (doesn't
		// delete). Kept here too so they survive the JS heartbeat rebuild.
		if ( ! empty( $result ) ) {
			$result[] = array(
				'type' => 'show-all',
				'url'  => admin_url( 'admin.php?page=' . \OrderUpdatesForWoo\Admin\Notifications\NotificationsPageController::SLUG ),
			);
			$result[] = array( 'type' => 'clear-all' );
		}

		return $result;
	}

	/**
	 * Class list for an admin-bar row item. The `awts-ab-update-{id}` and
	 * `awts-ab-note-{id}` classes are how the JS click-handler decides
	 * whether the target is already on the page (in-page focus) vs missing
	 * (full reload). Note id may be 0 for assignments — class is omitted
	 * in that case so the selector check fails as expected.
	 *
	 * @param array{key:string, update_id:int, note_id?:int} $notification Notification row.
	 */
	private function row_item_classes( array $notification ): string {
		$classes = array(
			'awts-ab-row-item',
			'awts-ab-notif-' . sanitize_html_class( (string) $notification['key'] ),
			'awts-ab-update-' . (int) $notification['update_id'],
		);

		$note_id = (int) ( $notification['note_id'] ?? 0 );
		if ( $note_id > 0 ) {
			$classes[] = 'awts-ab-note-' . $note_id;
		}

		// Tab the note lives in (internal | customer) so the click handler can
		// switch to it. PHP rows carry it as a class; JS-rebuilt rows carry it
		// as data-awts-tab — getRowTab() reads either.
		$tab = $this->tab_for_notification( $notification );
		if ( '' !== $tab ) {
			$classes[] = 'awts-ab-tab-' . $tab;
		}

		// Marker class used by admin-bar.js — when this row is clicked, the
		// page is forced to reload (instead of doing a same-URL no-op nav)
		// so the now-deleted update disappears from the order edit screen.
		if ( 'deleted' === ( $notification['type'] ?? '' ) ) {
			$classes[] = 'awts-ab-deleted-row';
		}

		return implode( ' ', $classes );
	}

	/**
	 * Append the update/note hash to an order-edit URL for an in-page jump.
	 *
	 * @param string $url       Base order-edit URL.
	 * @param int    $update_id Update id.
	 * @param int    $note_id   Note id to focus, if any.
	 * @param string $note_type Note type (internal / customer), if known.
	 */
	private function build_deep_link( string $url, int $update_id, int $note_id = 0, string $note_type = '' ): string {
		if ( ! $url || ! $update_id ) {
			return $url;
		}

		$hash = '#awts-update-' . $update_id;

		if ( $note_id > 0 ) {
			// Encode the note's tab into the hash so the meta-box JS can
			// switch to the right tab on landing — Internal vs Customer.
			// Empty $note_type falls through to the legacy `-note-N` shape
			// (which lands the user on whichever tab is currently open).
			$tab   = in_array( $note_type, array( 'internal', 'customer' ), true ) ? $note_type : '';
			$hash .= '' !== $tab
				? '-' . $tab . '-note-' . $note_id
				: '-note-' . $note_id;
		}

		return strtok( $url, '#' ) . $hash;
	}

	/**
	 * Resolve which note-tab the deep link should open based on the
	 * notification type. Returns '' for notification types that don't
	 * carry a note (assigned, deleted, assignee_changed) — the deep
	 * link falls back to "no tab override."
	 *
	 * @param array $notification Notification row.
	 */
	private function tab_for_notification( array $notification ): string {
		$type = (string) ( $notification['type'] ?? '' );

		switch ( $type ) {
			case 'mention':
				return 'internal';
			case 'customer_reply':
			case 'staff_reply':
				return 'customer';
			case 'participant_reply':
				$stored = (string) ( $notification['note_type'] ?? '' );
				return in_array( $stored, array( 'internal', 'customer' ), true ) ? $stored : '';
			default:
				return '';
		}
	}

	/**
	 * "N ago" relative time label, or '' when no timestamp.
	 *
	 * @param int $timestamp Unix timestamp.
	 */
	private function format_time_ago( int $timestamp ): string {
		if ( ! $timestamp ) {
			return '';
		}

		return sprintf(
			/* translators: %s: human-readable time difference */
			__( '%s ago', 'order-updates-for-woo' ),
			human_time_diff( $timestamp )
		);
	}

	/**
	 * Trim text to the snippet length, stripping tags.
	 *
	 * @param string $text Raw text.
	 */
	private function snippet( string $text ): string {
		$text = trim( wp_strip_all_tags( $text ) );

		if ( '' === $text ) {
			return '';
		}

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > self::SNIPPET_LEN ) {
			return mb_substr( $text, 0, self::SNIPPET_LEN - 1 ) . '…';
		}

		if ( strlen( $text ) > self::SNIPPET_LEN ) {
			return substr( $text, 0, self::SNIPPET_LEN - 1 ) . '…';
		}

		return $text;
	}
}
