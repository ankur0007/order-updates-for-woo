<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OrderUpdatesForWoo\Admin\AdminMenuController;
use OrderUpdatesForWoo\Helpers\AdminBarNotificationStore;
use OrderUpdatesForWoo\Helpers\AssetHelper;
use OrderUpdatesForWoo\Helpers\View;
use OrderUpdatesForWoo\Shared\Team\TeamRosterService;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;

// Filter / search / paging reads via $_GET are idempotent listing params,
// sanitised inline. No nonce required for read-only navigation; the bulk
// POST path below is the only mutation surface and carries its own nonce.
// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

/**
 * "Show all" notifications archive page. Hangs off the top-level Order
 * Updates menu and renders a lightweight inbox view (no WP_List_Table —
 * that grid is on its way out of core). Bulk actions Mark-as-read + Delete
 * are processed before the list renders so the redirect leaves a clean URL.
 */
final class NotificationsPageController {

	public const SLUG        = 'order-updates-for-woo-notifications';
	private const NONCE_KEY  = 'awts_notifications_bulk';
	private const BULK_NONCE = 'awts_notifications_bulk_action';
	private const AJAX_ACTION = 'awts_notif_row_action';
	private const AJAX_NONCE  = 'awts_notif_ajax';
	private const PER_PAGE   = 10;

	/** Rows-per-page choices for the footer dropdown; the first is the default. */
	private const PER_PAGE_OPTIONS = array( 10, 20, 50, 100 );

	private const ALLOWED_ACTIONS = array( 'mark_read', 'mark_unread', 'favorite', 'unfavorite', 'archive', 'unarchive', 'delete' );

	// Two-stage retention (configured on the Notifications settings tab):
	// active rows auto-archive after N days, archived rows delete after M days.
	public const OPT_ARCHIVE_AFTER_DAYS = 'order_updates_for_woo_notif_archive_after_days';
	public const OPT_AUTODELETE_DAYS    = 'order_updates_for_woo_notif_autodelete_days';

	public function __construct( private OrderUpdatesDb $order_updates_db ) {}

	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_process_bulk' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_action' ) );
	}

	/**
	 * Instant row action over AJAX — same dispatch as the no-JS link path,
	 * but returns fresh tab counts so the page can update without a reload.
	 */
	public function ajax_action(): void {
		check_ajax_referer( self::AJAX_NONCE );

		$user_id = get_current_user_id();
		if ( ! $user_id || ! TeamRosterService::user_is_team_member() ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'order-updates-for-woo' ) ), 403 );
		}

		$action = isset( $_POST['notif_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['notif_action'] ) ) : '';
		$key    = isset( $_POST['notif_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['notif_key'] ) ) : '';

		if ( '' === $key || ! in_array( $action, self::ALLOWED_ACTIONS, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Bad request.', 'order-updates-for-woo' ) ), 400 );
		}

		$this->dispatch_action( $action, array( $key ), $user_id );

		wp_send_json_success( array( 'counts' => $this->bucket_counts( AdminBarNotificationStore::get_all( $user_id ) ) ) );
	}

	/**
	 * Tab counts. Archived is its own bucket; the other tabs exclude it.
	 *
	 * @return array<string,int>
	 */
	private function bucket_counts( array $all ): array {
		return array(
			'all'      => count( array_filter( $all, static fn( array $n ) => empty( $n['archived'] ) ) ),
			'unread'   => count( array_filter( $all, static fn( array $n ) => empty( $n['dismissed'] ) && empty( $n['archived'] ) ) ),
			'favorite' => count( array_filter( $all, static fn( array $n ) => ! empty( $n['favorited'] ) && empty( $n['archived'] ) ) ),
			'archived' => count( array_filter( $all, static fn( array $n ) => ! empty( $n['archived'] ) ) ),
		);
	}

	/**
	 * Archive any active (non-archived) notification whose target update — or
	 * internal note — no longer exists, so dead links never linger in the
	 * active tabs, whatever route deleted them. Bounded by the per-user cap
	 * (<=50) and backed by cached update lookups.
	 */
	private function archive_dead_targets( int $user_id ): void {
		foreach ( AdminBarNotificationStore::get_all( $user_id ) as $n ) {
			if ( ! empty( $n['archived'] ) ) {
				continue;
			}
			if ( $this->target_is_gone( $n ) ) {
				AdminBarNotificationStore::archive_as_deleted( (string) ( $n['key'] ?? '' ), $user_id );
			}
		}
	}

	/** True when the notification points at an update (or internal note) that's been deleted. */
	private function target_is_gone( array $n ): bool {
		$update_id = (int) ( $n['update_id'] ?? 0 );
		if ( ! $update_id ) {
			return false; // No target to chase — leave it alone.
		}

		// Order gone (order deleted) — its updates/notes go with it.
		$order_id = (int) ( $n['order_id'] ?? 0 );
		if ( $order_id && function_exists( 'wc_get_order' ) && ! wc_get_order( $order_id ) ) {
			return true;
		}

		if ( empty( $this->order_updates_db->get_update( $update_id )['id'] ) ) {
			return true; // The whole update is gone.
		}

		// Internal notes can be deleted on their own; customer notes can't, so
		// we only verify the internal kind (mention / internal participant reply).
		$note_id          = (int) ( $n['note_id'] ?? 0 );
		$type             = (string) ( $n['type'] ?? '' );
		$is_internal_note = 'mention' === $type
			|| ( 'participant_reply' === $type && 'internal' === ( $n['note_type'] ?? '' ) );

		if ( $note_id > 0 && $is_internal_note && empty( $this->order_updates_db->get_update_note_by_id( $note_id )['id'] ) ) {
			return true;
		}

		return false;
	}

	public function register_page(): void {
		$title = __( 'Notifications', 'order-updates-for-woo' );

		// Append WP's native count bubble when the user has unread items.
		$unread     = AdminBarNotificationStore::unread_count( get_current_user_id() );
		$menu_title = $unread > 0
			? $title . ' <span class="awaiting-mod"><span class="pending-count">' . esc_html( number_format_i18n( $unread ) ) . '</span></span>'
			: $title;

		add_submenu_page(
			AdminMenuController::PARENT_SLUG,
			$title,
			$menu_title,
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Handle Mark-as-read / Delete actions from the bulk-actions dropdown
	 * AND from per-row inline links. Runs on admin_init so the redirect
	 * happens before any output and the URL settles back to a clean state.
	 */
	public function maybe_process_bulk(): void {
		if ( ! isset( $_GET['page'] ) || self::SLUG !== sanitize_key( wp_unslash( (string) $_GET['page'] ) ) ) {
			return;
		}

		// Per-row links carry ?row_action=<action>&notif_key=…
		// the bulk form posts ?action=<action>&notif_keys[]=…
		$inline_action = isset( $_GET['row_action'] ) ? sanitize_key( wp_unslash( (string) $_GET['row_action'] ) ) : '';
		$bulk_action   = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : '';
		$bulk_action_2 = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action2'] ) ) : '';
		$action        = '' !== $inline_action ? $inline_action : ( '-1' !== $bulk_action ? $bulk_action : $bulk_action_2 );

		$allowed = array( 'mark_read', 'mark_unread', 'favorite', 'unfavorite', 'archive', 'unarchive', 'delete' );
		if ( ! in_array( $action, $allowed, true ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id || ! TeamRosterService::user_is_team_member() ) {
			return;
		}

		$keys = array();
		if ( '' !== $inline_action ) {
			check_admin_referer( self::NONCE_KEY );
			$single = isset( $_GET['notif_key'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['notif_key'] ) ) : '';
			if ( '' !== $single ) {
				$keys = array( $single );
			}
		} else {
			// Bulk path — the inbox form carries our own nonce field.
			check_admin_referer( self::BULK_NONCE );
			$raw = isset( $_REQUEST['notif_keys'] ) ? (array) wp_unslash( $_REQUEST['notif_keys'] ) : array();
			$keys = array_values( array_filter( array_map( 'sanitize_text_field', $raw ) ) );
		}

		if ( empty( $keys ) ) {
			return;
		}

		$this->dispatch_action( $action, $keys, $user_id );

		// Redirect back to a clean URL (strip the action params) so a refresh
		// doesn't re-run the bulk action against an empty selection.
		$redirect = remove_query_arg( array( 'action', 'action2', 'row_action', 'notif_key', 'notif_keys', '_wpnonce', '_wp_http_referer' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	/** Route an action over the selected keys to the store. */
	private function dispatch_action( string $action, array $keys, int $user_id ): void {
		switch ( $action ) {
			case 'mark_read':
				AdminBarNotificationStore::dismiss_many( $keys, $user_id );
				break;
			case 'delete':
				AdminBarNotificationStore::delete_many( $keys, $user_id );
				break;
			case 'archive':
				AdminBarNotificationStore::archive_many( $keys, $user_id );
				break;
			case 'mark_unread':
				foreach ( $keys as $k ) {
					AdminBarNotificationStore::set_read( $k, $user_id, false );
				}
				break;
			case 'favorite':
			case 'unfavorite':
				$on = 'favorite' === $action;
				foreach ( $keys as $k ) {
					AdminBarNotificationStore::set_favorite( $k, $user_id, $on );
				}
				break;
			case 'unarchive':
				foreach ( $keys as $k ) {
					AdminBarNotificationStore::set_archived( $k, $user_id, false );
				}
				break;
		}
	}

	public function render(): void {
		if ( ! TeamRosterService::user_is_team_member() ) {
			wp_die( esc_html__( 'You are not allowed to view notifications.', 'order-updates-for-woo' ), 403 );
		}

		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style(
			'order-updates-for-woo-notifications',
			AssetHelper::url( 'assets/Admin/css/notifications.css' ),
			array(),
			AssetHelper::version( 'assets/Admin/css/notifications.css' )
		);
		wp_enqueue_script(
			'order-updates-for-woo-notifications',
			AssetHelper::url( 'assets/Admin/js/notifications.js' ),
			array( 'jquery' ),
			AssetHelper::version( 'assets/Admin/js/notifications.js' ),
			true
		);

		$user_id = get_current_user_id();

		// Self-heal: archive any active notification whose update/note is gone,
		// so dead links never linger in the active tabs (whatever deleted them).
		$this->archive_dead_targets( $user_id );

		$all    = AdminBarNotificationStore::get_all( $user_id );
		$counts = $this->bucket_counts( $all );

		// Listing params (read-only navigation, no nonce needed).
		$status    = isset( $_GET['filter_status'] ) ? sanitize_key( wp_unslash( (string) $_GET['filter_status'] ) ) : '';
		$order_id  = isset( $_GET['filter_order_id'] ) ? absint( wp_unslash( (string) $_GET['filter_order_id'] ) ) : 0;
		$update_id = isset( $_GET['filter_update_id'] ) ? absint( wp_unslash( (string) $_GET['filter_update_id'] ) ) : 0;
		$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';

		// Hand the AJAX path its nonce, the active tab (so JS can drop rows
		// that leave it), and the toggle labels/icons used when re-rendering.
		wp_localize_script(
			'order-updates-for-woo-notifications',
			'awtsNotif',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'nonce'   => wp_create_nonce( self::AJAX_NONCE ),
				'status'  => $status,
				'tips'    => array(
					'markRead'   => __( 'Mark as read', 'order-updates-for-woo' ),
					'markUnread' => __( 'Mark as unread', 'order-updates-for-woo' ),
					'favorite'   => __( 'Favorite', 'order-updates-for-woo' ),
					'unfavorite' => __( 'Remove favorite', 'order-updates-for-woo' ),
				),
				/* translators: %d: number of selected notifications */
				'selectedFmt' => __( '%d selected', 'order-updates-for-woo' ),
			)
		);

		$filtered = $this->filter_rows( $all, $status, $order_id, $update_id, $search );

		// Newest first — an inbox reads top-down by recency.
		usort( $filtered, static fn( array $a, array $b ): int => (int) ( $b['time'] ?? 0 ) <=> (int) ( $a['time'] ?? 0 ) );

		$per_page    = $this->resolve_per_page();
		$total       = count( $filtered );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$paged       = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( (string) $_GET['paged'] ) ) ) : 1;
		$paged       = min( $paged, $total_pages );
		$offset      = ( $paged - 1 ) * $per_page;
		$page_items  = array_slice( $filtered, $offset, $per_page );

		View::render(
			'src/Admin/Notifications/Views/NotificationsView',
			array(
				'rows'             => array_map( array( $this, 'to_view_row' ), $page_items ),
				'tabs'             => $this->build_tabs( $status, $counts ),
				'search'           => $search,
				'filter_order'     => $order_id,
				'filter_update'    => $update_id,
				'filter_status'    => $status,
				'slug'             => self::SLUG,
				'form_action'      => $this->current_url(),
				'bulk_nonce'       => wp_nonce_field( self::BULK_NONCE, '_wpnonce', true, false ),
				'has_filters'      => ( '' !== $status || $order_id > 0 || $update_id > 0 || '' !== $search ),
				'is_archived'      => ( 'archived' === $status ),
				'auto_archive_days' => (int) get_option( self::OPT_ARCHIVE_AFTER_DAYS, 30 ),
				'auto_delete_days'  => (int) get_option( self::OPT_AUTODELETE_DAYS, 30 ),
				'per_page'         => $per_page,
				'per_page_options' => self::PER_PAGE_OPTIONS,
				'pagination'       => $this->build_pagination( $paged, $total_pages, $total, $offset, $per_page ),
			)
		);
	}

	/** Per-page choice from the dropdown, clamped to the allowed set. */
	private function resolve_per_page(): int {
		$value = isset( $_GET['per_page'] ) ? absint( wp_unslash( (string) $_GET['per_page'] ) ) : self::PER_PAGE;

		return in_array( $value, self::PER_PAGE_OPTIONS, true ) ? $value : self::PER_PAGE;
	}

	/**
	 * Everything the footer pager needs: range text, first/prev/next/last
	 * links and a windowed list of numbered page links.
	 *
	 * @return array<string,mixed>
	 */
	private function build_pagination( int $paged, int $total_pages, int $total, int $offset, int $per_page ): array {
		$page_url = static fn( int $page ): string => esc_url_raw( add_query_arg( 'paged', $page ) );

		// Windowed numbers — show all when few, else 1 … current±1 … last.
		$links = array();
		$push  = static function ( int $page ) use ( &$links, $paged, $page_url ): void {
			$links[] = array(
				'page'    => $page,
				'url'     => $page_url( $page ),
				'current' => $page === $paged,
			);
		};

		if ( $total_pages <= 7 ) {
			for ( $i = 1; $i <= $total_pages; $i++ ) {
				$push( $i );
			}
		} else {
			$from = max( 2, $paged - 1 );
			$to   = min( $total_pages - 1, $paged + 1 );
			$push( 1 );
			if ( $from > 2 ) {
				$links[] = array( 'ellipsis' => true );
			}
			for ( $i = $from; $i <= $to; $i++ ) {
				$push( $i );
			}
			if ( $to < $total_pages - 1 ) {
				$links[] = array( 'ellipsis' => true );
			}
			$push( $total_pages );
		}

		return array(
			'total'       => $total,
			'page'        => $paged,
			'total_pages' => $total_pages,
			'range_from'  => $total > 0 ? $offset + 1 : 0,
			'range_to'    => min( $offset + $per_page, $total ),
			'first_url'   => $paged > 1 ? $page_url( 1 ) : '',
			'prev_url'    => $paged > 1 ? $page_url( $paged - 1 ) : '',
			'next_url'    => $paged < $total_pages ? $page_url( $paged + 1 ) : '',
			'last_url'    => $paged < $total_pages ? $page_url( $total_pages ) : '',
			'links'       => $links,
		);
	}

	/** Apply the tab / order / update / text filters to the raw notification set. */
	private function filter_rows( array $rows, string $status, int $order_id, int $update_id, string $search ): array {
		// Archived sits in its own tab; every other tab hides archived rows.
		if ( 'archived' === $status ) {
			$rows = array_filter( $rows, static fn( array $n ) => ! empty( $n['archived'] ) );
		} elseif ( 'unread' === $status ) {
			$rows = array_filter( $rows, static fn( array $n ) => empty( $n['dismissed'] ) && empty( $n['archived'] ) );
		} elseif ( 'favorite' === $status ) {
			$rows = array_filter( $rows, static fn( array $n ) => ! empty( $n['favorited'] ) && empty( $n['archived'] ) );
		} else {
			$rows = array_filter( $rows, static fn( array $n ) => empty( $n['archived'] ) );
		}
		if ( $order_id > 0 ) {
			$rows = array_filter( $rows, static fn( array $n ) => (int) ( $n['order_id'] ?? 0 ) === $order_id );
		}
		if ( $update_id > 0 ) {
			$rows = array_filter( $rows, static fn( array $n ) => (int) ( $n['update_id'] ?? 0 ) === $update_id );
		}
		if ( '' !== $search ) {
			$needle = strtolower( $search );
			$rows   = array_filter(
				$rows,
				static fn( array $n ) => false !== strpos( strtolower( (string) ( $n['title'] ?? '' ) ), $needle )
			);
		}

		return array_values( $rows );
	}

	/** Turn one stored notification into the flat shape the view renders. */
	private function to_view_row( array $n ): array {
		$type      = (string) ( $n['type'] ?? '' );
		$key       = (string) ( $n['key'] ?? '' );
		$time      = (int) ( $n['time'] ?? 0 );
		$unread    = empty( $n['dismissed'] );
		$favorited = ! empty( $n['favorited'] );
		$archived  = ! empty( $n['archived'] );
		$deep_url  = $this->deep_link_for( $n );

		return array(
			'key'         => $key,
			'unread'      => $unread,
			'favorited'   => $favorited,
			'archived'    => $archived,
			'icon'        => self::icon_for_type( $type ),
			'kind'        => self::kind_for_type( $type, (string) ( $n['note_type'] ?? '' ) ),
			'label'       => self::label_for_type( $type ),
			'snippet'     => trim( (string) ( $n['title'] ?? '' ) ),
			'actor'       => trim( (string) ( $n['actor'] ?? '' ) ),
			'context'     => self::context_label( $type, (string) ( $n['note_type'] ?? '' ) ),
			// Red "Deleted" tag when the target was deleted (the dedicated
			// "deleted" notice type, or a self-healed dead-target row).
			'deleted'     => ( 'deleted' === $type ) || ! empty( $n['target_deleted'] ),
			'order_id'    => (int) ( $n['order_id'] ?? 0 ),
			'update_id'   => (int) ( $n['update_id'] ?? 0 ),
			'note_id'     => (int) ( $n['note_id'] ?? 0 ),
			'deep_url'    => $deep_url,
			/* translators: %s: human-readable time difference, e.g. "2 hours" */
			'time'        => $time > 0 ? sprintf( __( '%s ago', 'order-updates-for-woo' ), human_time_diff( $time, time() ) ) : '',
			// Each action link flips to its opposite based on current state.
			'read_url'    => self::row_action_url( $unread ? 'mark_read' : 'mark_unread', $key ),
			'fav_url'     => self::row_action_url( $favorited ? 'unfavorite' : 'favorite', $key ),
			'archive_url' => self::row_action_url( $archived ? 'unarchive' : 'archive', $key ),
			'delete_url'  => self::row_action_url( 'delete', $key ),
			'reply_url'   => ( self::is_replyable( $type ) && '' !== $deep_url ) ? $deep_url : '',
		);
	}

	/**
	 * Tabs for the inbox header: All / Unread / Favorite / Archived with
	 * live counts.
	 *
	 * @param array<string,int> $counts
	 */
	private function build_tabs( string $current, array $counts ): array {
		$base = remove_query_arg( array( 'filter_status', 'paged' ) );

		$make = static function ( string $key, string $label, int $count ) use ( $current, $base ): array {
			return array(
				'label'  => $label,
				'count'  => $count,
				'active' => $key === $current,
				'url'    => esc_url_raw( '' === $key ? $base : add_query_arg( 'filter_status', $key, $base ) ),
			);
		};

		return array(
			$make( '', __( 'All', 'order-updates-for-woo' ), (int) ( $counts['all'] ?? 0 ) ),
			$make( 'unread', __( 'Unread', 'order-updates-for-woo' ), (int) ( $counts['unread'] ?? 0 ) ),
			$make( 'favorite', __( 'Favorite', 'order-updates-for-woo' ), (int) ( $counts['favorite'] ?? 0 ) ),
			$make( 'archived', __( 'Archived', 'order-updates-for-woo' ), (int) ( $counts['archived'] ?? 0 ) ),
		);
	}

	/** Current page URL with its filters intact — the bulk form posts back here. */
	private function current_url(): string {
		return esc_url_raw( add_query_arg( array() ) );
	}

	/**
	 * Per-row action URL for inline Mark-as-read / Delete (nonce'd GET link).
	 * Static so the controller can build links without an instance.
	 */
	public static function row_action_url( string $action, string $key ): string {
		$base = add_query_arg(
			array(
				'page'       => self::SLUG,
				'row_action' => $action,
				'notif_key'  => rawurlencode( $key ),
			),
			admin_url( 'admin.php' )
		);

		return wp_nonce_url( $base, self::NONCE_KEY );
	}

	private function deep_link_for( array $item ): string {
		$order_id  = (int) ( $item['order_id'] ?? 0 );
		$update_id = (int) ( $item['update_id'] ?? 0 );
		$note_id   = (int) ( $item['note_id'] ?? 0 );

		// Nothing to open when the target is gone — keep the row non-clickable.
		if ( ! $order_id || ! $update_id || 'deleted' === (string) ( $item['type'] ?? '' ) || ! empty( $item['target_deleted'] ) ) {
			return '';
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order ) {
			return '';
		}

		$base = strtok( (string) $order->get_edit_order_url(), '#' );

		// Prefer the stored note_type (customer / internal) so the link opens
		// the exact tab; fall back to the type's default for older rows.
		$note_type = (string) ( $item['note_type'] ?? '' );
		$tab       = in_array( $note_type, array( 'internal', 'customer' ), true )
			? $note_type
			: self::tab_for_type( (string) ( $item['type'] ?? '' ) );

		if ( $note_id > 0 ) {
			$suffix = '' !== $tab ? '-' . $tab . '-note-' . $note_id : '-note-' . $note_id;
		} else {
			$suffix = '';
		}

		return $base . '#awts-update-' . $update_id . $suffix;
	}

	private static function tab_for_type( string $type ): string {
		return match ( $type ) {
			'mention'           => 'internal',
			'customer_reply',
			'staff_reply'        => 'customer',
			default              => '',
		};
	}

	private static function label_for_type( string $type ): string {
		return match ( $type ) {
			'assigned'         => __( 'Assigned to you', 'order-updates-for-woo' ),
			'unassigned'       => __( 'Unassigned', 'order-updates-for-woo' ),
			'assignee_changed' => __( 'Assignee changed', 'order-updates-for-woo' ),
			'deleted'          => __( 'Update deleted', 'order-updates-for-woo' ),
			'customer_reply'   => __( 'Customer replied', 'order-updates-for-woo' ),
			'staff_reply'      => __( 'Staff replied', 'order-updates-for-woo' ),
			'participant_reply'=> __( 'New reply', 'order-updates-for-woo' ),
			'mention'          => __( 'You were mentioned', 'order-updates-for-woo' ),
			default            => __( 'Notification', 'order-updates-for-woo' ),
		};
	}

	/** Where the note lives — shown after "By {name} ·" so it's clear how it reached you. */
	private static function context_label( string $type, string $note_type ): string {
		return match ( $type ) {
			'deleted'                       => '', // The red "Deleted" tag carries this instead.
			'customer_reply', 'staff_reply' => __( 'Customer note', 'order-updates-for-woo' ),
			'mention'                       => __( 'Internal note · tagged you', 'order-updates-for-woo' ),
			'participant_reply'             => 'customer' === $note_type
				? __( 'Customer note', 'order-updates-for-woo' )
				: __( 'Internal note', 'order-updates-for-woo' ),
			default                         => '',
		};
	}

	/**
	 * Note-type family that colours the row's avatar tile and type tag:
	 * customer-thread activity vs internal/staff activity. Assignment and
	 * system events fall back to the internal (slate) treatment.
	 */
	private static function kind_for_type( string $type, string $note_type ): string {
		return match ( $type ) {
			'customer_reply', 'staff_reply' => 'customer',
			'participant_reply'             => 'customer' === $note_type ? 'customer' : 'internal',
			default                         => 'internal',
		};
	}

	/** Reply only makes sense on note/reply rows, where there's a thread to answer. */
	private static function is_replyable( string $type ): bool {
		return in_array( $type, array( 'customer_reply', 'staff_reply', 'participant_reply', 'mention' ), true );
	}

	private static function icon_for_type( string $type ): string {
		return match ( $type ) {
			'assigned'          => 'dashicons-admin-users',
			'unassigned'        => 'dashicons-remove',
			'assignee_changed'  => 'dashicons-randomize',
			'deleted'           => 'dashicons-trash',
			'customer_reply'    => 'dashicons-format-chat',
			'staff_reply'       => 'dashicons-businessperson',
			'participant_reply' => 'dashicons-format-chat',
			'mention'           => 'dashicons-admin-comments',
			default             => 'dashicons-bell',
		};
	}
}
