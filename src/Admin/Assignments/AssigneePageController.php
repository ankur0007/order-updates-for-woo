<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Assignments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OrderUpdatesForWoo\Admin\AdminMenuController;
use OrderUpdatesForWoo\Helpers\AssetHelper;
use OrderUpdatesForWoo\Helpers\View;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Team\TeamRosterService;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;

// Filter / search / paging reads via $_GET are idempotent listing params,
// sanitised inline. No nonce for read-only navigation; access is gated by
// capability + team membership, and a non-admin can only ever see their own.
// phpcs:disable WordPress.Security.NonceVerification.Recommended

/**
 * "Assignments" page — the list of order updates by assignee. Team members
 * see the updates assigned to them; store managers see everyone's, with an
 * assignee filter. Rendered in the same lightweight inbox style as the
 * Notifications page. Data + view are kept separate so a future
 * customer/assignee front-end page can reuse the same view.
 */
final class AssigneePageController {

	public const SLUG      = 'order-updates-for-woo-assignments';
	private const PER_PAGE = 10;

	// Wait-time tiers (seconds) for the summary: low < 2 h, medium 2–4 h, urgent > 4 h.
	private const SLA_BLUE_MAX  = 7200;
	private const SLA_AMBER_MAX = 14400;

	public function __construct(
		private OrderUpdatesDb $order_updates_db,
		private TeamRosterService $team_roster
	) {}

	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
	}

	public function register_page(): void {
		// Hide the menu item from anyone who can't see any assignments.
		if ( ! $this->can_view() ) {
			return;
		}

		$title      = __( 'Assignments', 'order-updates-for-woo' );
		$menu_title = $title . $this->badge_html();

		// 'read' so team members without manage_woocommerce still reach it;
		// render() re-checks access.
		add_submenu_page(
			AdminMenuController::PARENT_SLUG,
			$title,
			$menu_title,
			'read',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Count bubble for the menu — open updates waiting on a staff reply, with a
	 * hover tooltip breaking them into urgent / medium / low by wait time.
	 * Empty string when nothing is waiting.
	 */
	private function badge_html(): string {
		$summary = $this->summary( $this->sees_all() ? 0 : get_current_user_id() );
		if ( $summary['waiting'] < 1 ) {
			return '';
		}

		$tooltip = sprintf(
			/* translators: 1: urgent count (4h+ wait), 2: medium count (2-4h), 3: low count (under 2h) */
			__( '%1$d urgent (4h+), %2$d medium (2-4h), %3$d low (under 2h)', 'order-updates-for-woo' ),
			$summary['urgent'],
			$summary['medium'],
			$summary['low']
		);

		return ' <span class="awaiting-mod" title="' . esc_attr( $tooltip ) . '"><span class="pending-count">'
			. esc_html( number_format_i18n( $summary['waiting'] ) )
			. '</span></span>';
	}

	/**
	 * Scope-level stats for the summary cards + menu badge: total / resolved
	 * counts, the waiting-on-staff tally (with urgency tiers) and the longest
	 * current wait. Cached briefly since the menu badge runs on every page.
	 *
	 * @return array{total:int, resolved:int, waiting:int, urgent:int, medium:int, low:int, longest_label:string}
	 */
	private function summary( int $scope ): array {
		$cache_key = 'awts_assignee_summary_' . ( 0 === $scope ? 'all' : $scope );
		$cached    = wp_cache_get( $cache_key, Constants::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$counts  = $this->order_updates_db->get_assignee_counts( $scope );
		$ids     = $this->order_updates_db->get_open_update_ids_for_assignee( $scope );
		$latest  = $this->order_updates_db->get_latest_customer_messages( $ids );

		$urgent  = 0;
		$medium  = 0;
		$low     = 0;
		$longest = 0;

		foreach ( $ids as $id ) {
			$waited = $this->waiting_seconds( false, $latest[ $id ] ?? null );
			if ( $waited <= 0 ) {
				continue;
			}
			$longest = max( $longest, $waited );
			if ( $waited > self::SLA_AMBER_MAX ) {
				$urgent++;
			} elseif ( $waited > self::SLA_BLUE_MAX ) {
				$medium++;
			} else {
				$low++;
			}
		}

		$result = array(
			'total'         => (int) $counts['total'],
			'resolved'      => (int) $counts['resolved'],
			'waiting'       => $urgent + $medium + $low,
			'urgent'        => $urgent,
			'medium'        => $medium,
			'low'           => $low,
			'longest_label' => $longest > 0 ? $this->compact_duration( $longest ) : '',
		);

		wp_cache_set( $cache_key, $result, Constants::CACHE_GROUP, 60 );

		return $result;
	}

	public function render(): void {
		if ( ! $this->can_view() ) {
			wp_die( esc_html__( 'You are not allowed to view assignments.', 'order-updates-for-woo' ), 403 );
		}

		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style(
			'order-updates-for-woo-assignments',
			AssetHelper::url( 'assets/Admin/css/assignments.css' ),
			array(),
			AssetHelper::version( 'assets/Admin/css/assignments.css' )
		);
		wp_enqueue_script(
			'order-updates-for-woo-assignments',
			AssetHelper::url( 'assets/Admin/js/assignments.js' ),
			array( 'jquery' ),
			AssetHelper::version( 'assets/Admin/js/assignments.js' ),
			true
		);

		$sees_all = $this->sees_all();
		$user_id  = get_current_user_id();

		$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';
		$status   = in_array( $status, array( 'open', 'solved' ), true ) ? $status : '';
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		$orderby  = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( (string) $_GET['orderby'] ) ) : 'newest';
		$orderby  = in_array( $orderby, array( 'newest', 'oldest', 'assignee' ), true ) ? $orderby : 'newest';
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( (string) $_GET['paged'] ) ) ) : 1;
		$req_assignee = isset( $_GET['assignee'] ) ? absint( wp_unslash( (string) $_GET['assignee'] ) ) : 0;

		// Security: only a store manager may filter by (or see) other people's
		// assignments. Everyone else is pinned to their own id server-side, so
		// a hand-edited ?assignee= can't surface another user's updates.
		$assignee_id = $sees_all ? $req_assignee : $user_id;

		$args = apply_filters(
			'order_updates_for_woo_assignee_page_query_args',
			array(
				'assignee_id' => $assignee_id,
				'status'      => $status,
				'search'      => $search,
				'orderby'     => $orderby,
				'per_page'    => self::PER_PAGE,
				'paged'       => $paged,
			),
			$sees_all,
			$user_id
		);

		$data        = $this->order_updates_db->get_assignee_page_updates( $args );
		$total       = (int) $data['total'];
		$total_pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged       = min( $paged, $total_pages );
		$offset      = ( $paged - 1 ) * self::PER_PAGE;

		// One batched lookup of who spoke last per visible update — feeds each
		// row's SLA state without a query per row.
		$update_ids = array_map( static fn( array $r ): int => (int) ( $r['id'] ?? 0 ), $data['rows'] );
		$latest     = $this->order_updates_db->get_latest_customer_messages( $update_ids );

		View::render(
			'src/Admin/Assignments/Views/AssigneePageView',
			array(
				'rows'        => array_map( fn( array $row ): array => $this->to_view_row( $row, $latest ), $data['rows'] ),
				'summary'     => $this->summary( $assignee_id ),
				'sees_all'    => $sees_all,
				'status'      => $status,
				'search'      => $search,
				'orderby'     => $orderby,
				'assignee'    => $assignee_id,
				'team'        => $sees_all ? $this->team_roster->get_team_members() : array(),
				'slug'        => self::SLUG,
				'has_filters' => ( '' !== $status || '' !== $search || $req_assignee > 0 ),
				'total'       => $total,
				'page'        => $paged,
				'total_pages' => $total_pages,
				'range_from'  => $total > 0 ? $offset + 1 : 0,
				'range_to'    => $offset + count( $data['rows'] ),
				'prev_url'    => $paged > 1 ? esc_url_raw( add_query_arg( 'paged', $paged - 1 ) ) : '',
				'next_url'    => $paged < $total_pages ? esc_url_raw( add_query_arg( 'paged', $paged + 1 ) ) : '',
				'page_url'    => esc_url_raw( remove_query_arg( 'paged' ) ),
			)
		);
	}

	/** Anyone on the team — or a store manager — may open the page. */
	private function can_view(): bool {
		return current_user_can( 'manage_woocommerce' ) || TeamRosterService::user_is_team_member();
	}

	/** Store managers see every assignee; plain team members see only their own. */
	private function sees_all(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Flatten one DB row into the shape the view renders (escaped at output
	 * there). $latest maps update_id → the last customer-thread message, used
	 * to decide the SLA "waiting" state.
	 *
	 * @param array<int, array{created_at:string, created_by:int}> $latest
	 */
	private function to_view_row( array $row, array $latest = array() ): array {
		$update_id = (int) ( $row['id'] ?? 0 );
		$order_id  = (int) ( $row['order_id'] ?? 0 );
		$order     = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		$resolved  = ! empty( $row['is_resolved'] );
		$waited    = $this->waiting_seconds( $resolved, $latest[ $update_id ] ?? null );

		$status = trim( (string) ( $row['status'] ?? '' ) );
		if ( '' === $status ) {
			$status = $resolved ? __( 'Resolved', 'order-updates-for-woo' ) : __( 'Open', 'order-updates-for-woo' );
		}

		$created_by = (string) ( $row['created_by_name'] ?? '' );
		$assignee   = (string) ( $row['assignee_name'] ?? '' );

		return array(
			'update_id'       => $update_id,
			'order_no'        => $order ? (string) $order->get_order_number() : (string) $order_id,
			'title'           => (string) ( $row['title'] ?? '' ),
			'edit_url'        => $order ? strtok( (string) $order->get_edit_order_url(), '#' ) . '#awts-update-' . $update_id : '',
			'state'           => $resolved ? 'resolved' : ( $waited > 0 ? 'waiting' : 'open' ),
			'resolved'        => $resolved,
			'status'          => $status,
			'status_color'    => $this->safe_color( (string) ( $row['color'] ?? '' ) ),
			'created_by'      => $created_by,
			'created_avatar'  => $this->avatar( $created_by ),
			'created_date'    => $this->datetime_label( (string) ( $row['created_at'] ?? '' ) ),
			'assignee'        => $assignee,
			'assignee_avatar' => '' !== $assignee ? $this->avatar( $assignee ) : array( 'initials' => '', 'color' => '' ),
			'waiting'         => $waited > 0,
			'waiting_label'   => $waited > 0 ? $this->compact_duration( $waited ) : '',
		);
	}

	/** Only allow a hex colour through to an inline style; fall back to a neutral grey. */
	private function safe_color( string $color ): string {
		return preg_match( '/^#[0-9a-fA-F]{3,8}$/', $color ) ? $color : '#646970';
	}

	/**
	 * Seconds an open update has waited on a staff reply (customer spoke last).
	 * 0 when resolved, when staff replied last, or when there's no message.
	 *
	 * @param array{created_at:string, created_by:int}|null $last_message
	 */
	private function waiting_seconds( bool $resolved, ?array $last_message ): int {
		if ( $resolved || null === $last_message ) {
			return 0;
		}

		// A team member spoke last → nothing owed. A guest (id 0) or non-team
		// user is the customer → the clock is on staff.
		if ( TeamRosterService::user_is_team_member( (int) $last_message['created_by'] ) ) {
			return 0;
		}

		$timestamp = '' !== $last_message['created_at'] ? (int) strtotime( $last_message['created_at'] . ' UTC' ) : 0;

		return $timestamp > 0 ? max( 0, time() - $timestamp ) : 0;
	}

	/** Compact wait label — "16h" or "45m". */
	private function compact_duration( int $seconds ): string {
		$hours = (int) floor( $seconds / HOUR_IN_SECONDS );
		if ( $hours >= 1 ) {
			return number_format_i18n( $hours ) . 'h';
		}

		return number_format_i18n( max( 1, (int) floor( $seconds / MINUTE_IN_SECONDS ) ) ) . 'm';
	}

	/**
	 * Initials + a stable oklch colour for a name avatar (no external request).
	 * Initials: first+last for multi-word names, first two chars otherwise.
	 */
	private function avatar( string $name ): array {
		$name  = trim( $name );
		$parts = '' !== $name ? preg_split( '/\s+/', $name ) : array();

		if ( count( $parts ) >= 2 ) {
			$initials = mb_substr( $parts[0], 0, 1 ) . mb_substr( $parts[ count( $parts ) - 1 ], 0, 1 );
		} elseif ( 1 === count( $parts ) ) {
			$initials = mb_substr( $parts[0], 0, 2 );
		} else {
			$initials = '?';
		}

		// Deterministic hue per name so the same person always gets one colour.
		$hue    = 0;
		$length = strlen( $name );
		for ( $i = 0; $i < $length; $i++ ) {
			$hue = ( $hue * 31 + ord( $name[ $i ] ) ) % 360;
		}

		return array(
			'initials' => mb_strtoupper( $initials ),
			'color'    => sprintf( 'oklch(62%% 0.12 %d)', $hue ),
		);
	}

	/** "May 25, 2:06 PM" in the site timezone, or '' when missing. */
	private function datetime_label( string $mysql_utc ): string {
		$timestamp = '' !== $mysql_utc ? (int) strtotime( $mysql_utc . ' UTC' ) : 0;

		return $timestamp > 0 ? wp_date( 'M j, g:i A', $timestamp ) : '';
	}
}
