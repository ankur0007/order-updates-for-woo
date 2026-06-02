<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Assignments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OrderUpdatesForWoo\Admin\AdminMenuController;
use OrderUpdatesForWoo\Helpers\AssetHelper;
use OrderUpdatesForWoo\Helpers\DateHelper;
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
	private const PER_PAGE = 20;

	// SLA "waiting on a reply" colour bands, in seconds: green ≤ 30 min,
	// blue ≤ 2 h, amber ≤ 4 h, red beyond.
	private const SLA_GREEN_MAX = 1800;
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
		$badge = $this->sla_badge();
		if ( $badge['total'] < 1 ) {
			return '';
		}

		$tooltip = sprintf(
			/* translators: 1: urgent count (4h+ wait), 2: medium count (2-4h), 3: low count (under 2h) */
			__( '%1$d urgent (4h+), %2$d medium (2-4h), %3$d low (under 2h)', 'order-updates-for-woo' ),
			$badge['urgent'],
			$badge['medium'],
			$badge['low']
		);

		return ' <span class="awaiting-mod" title="' . esc_attr( $tooltip ) . '"><span class="pending-count">'
			. esc_html( number_format_i18n( $badge['total'] ) )
			. '</span></span>';
	}

	/**
	 * Open updates that are waiting on a staff reply (the customer spoke last),
	 * tallied by wait band. Scoped to the viewer (own / all) and cached briefly
	 * since it runs on every admin page via the menu.
	 *
	 * @return array{total:int, urgent:int, medium:int, low:int}
	 */
	private function sla_badge(): array {
		$scope     = $this->sees_all() ? 0 : get_current_user_id();
		$cache_key = 'awts_assignee_sla_' . ( 0 === $scope ? 'all' : $scope );
		$cached    = wp_cache_get( $cache_key, Constants::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$ids    = $this->order_updates_db->get_open_update_ids_for_assignee( $scope );
		$latest = $this->order_updates_db->get_latest_customer_messages( $ids );

		$urgent = 0;
		$medium = 0;
		$low    = 0;

		foreach ( $ids as $id ) {
			$msg = $latest[ $id ] ?? null;
			if ( null === $msg || TeamRosterService::user_is_team_member( (int) $msg['created_by'] ) ) {
				continue; // No customer message, or staff replied last — nothing owed.
			}

			$timestamp = '' !== $msg['created_at'] ? (int) strtotime( $msg['created_at'] . ' UTC' ) : 0;
			if ( $timestamp <= 0 ) {
				continue;
			}

			$waited = time() - $timestamp;
			if ( $waited > self::SLA_AMBER_MAX ) {
				$urgent++;
			} elseif ( $waited > self::SLA_BLUE_MAX ) {
				$medium++;
			} else {
				$low++;
			}
		}

		$result = array(
			'total'  => $urgent + $medium + $low,
			'urgent' => $urgent,
			'medium' => $medium,
			'low'    => $low,
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
			'order-updates-for-woo-notifications',
			AssetHelper::url( 'assets/Admin/css/notifications.css' ),
			array(),
			AssetHelper::version( 'assets/Admin/css/notifications.css' )
		);

		$sees_all = $this->sees_all();
		$user_id  = get_current_user_id();

		$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';
		$status   = in_array( $status, array( 'open', 'solved' ), true ) ? $status : '';
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
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

		// One batched lookup of who spoke last per visible update — feeds the
		// SLA "waiting" badge without a query per row.
		$update_ids = array_map( static fn( array $r ): int => (int) ( $r['id'] ?? 0 ), $data['rows'] );
		$latest     = $this->order_updates_db->get_latest_customer_messages( $update_ids );

		View::render(
			'src/Admin/Assignments/Views/AssigneePageView',
			array(
				'rows'        => array_map( fn( array $row ): array => $this->to_view_row( $row, $latest ), $data['rows'] ),
				'sees_all'    => $sees_all,
				'status'      => $status,
				'search'      => $search,
				'assignee'    => $assignee_id,
				'team'        => $sees_all ? $this->team_roster->get_team_members() : array(),
				'slug'        => self::SLUG,
				'has_filters' => ( '' !== $status || '' !== $search || $req_assignee > 0 ),
				'total'       => $total,
				'page'        => $paged,
				'total_pages' => $total_pages,
				'prev_url'    => $paged > 1 ? esc_url_raw( add_query_arg( 'paged', $paged - 1 ) ) : '',
				'next_url'    => $paged < $total_pages ? esc_url_raw( add_query_arg( 'paged', $paged + 1 ) ) : '',
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
	 * for the SLA badge.
	 *
	 * @param array<int, array{created_at:string, created_by:int}> $latest
	 */
	private function to_view_row( array $row, array $latest = array() ): array {
		$update_id = (int) ( $row['id'] ?? 0 );
		$order_id  = (int) ( $row['order_id'] ?? 0 );
		$order     = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		$resolved  = ! empty( $row['is_resolved'] );
		$sla       = $this->sla_for( $resolved, $latest[ $update_id ] ?? null );

		$status = trim( (string) ( $row['status'] ?? '' ) );
		if ( '' === $status ) {
			$status = $resolved ? __( 'Solved', 'order-updates-for-woo' ) : __( 'Open', 'order-updates-for-woo' );
		}

		return array(
			'update_id'    => $update_id,
			'order_id'     => $order_id,
			'order_no'     => $order ? (string) $order->get_order_number() : (string) $order_id,
			'customer'     => $order ? trim( (string) $order->get_formatted_billing_full_name() ) : '',
			'title'        => (string) ( $row['title'] ?? '' ),
			'resolved'     => $resolved,
			'status'       => $status,
			'status_color' => $this->safe_color( (string) ( $row['color'] ?? '' ) ),
			'created_by'   => (string) ( $row['created_by_name'] ?? '' ),
			'created_date' => '' !== (string) ( $row['created_at'] ?? '' ) ? DateHelper::format_date( (string) $row['created_at'] ) : '',
			'assignee'     => (string) ( $row['assignee_name'] ?? '' ),
			'edit_url'     => $order ? strtok( (string) $order->get_edit_order_url(), '#' ) . '#awts-update-' . $update_id : '',
			'time'         => $this->time_ago( (string) ( $row['last_updated_at'] ?? '' ) ),
			'sla_label'    => $sla['label'],
			'sla_class'    => $sla['class'],
		);
	}

	/** Only allow a hex colour through to an inline style; fall back to a neutral grey. */
	private function safe_color( string $color ): string {
		return preg_match( '/^#[0-9a-fA-F]{3,8}$/', $color ) ? $color : '#646970';
	}

	/**
	 * SLA badge for an update: only shown when it's open AND the customer
	 * spoke last (so staff owes a reply). Colour bands by how long they've
	 * waited. Returns empty label/class when no reply is owed.
	 *
	 * @param array{created_at:string, created_by:int}|null $last_message
	 * @return array{label:string, class:string}
	 */
	private function sla_for( bool $resolved, ?array $last_message ): array {
		$none = array( 'label' => '', 'class' => '' );

		if ( $resolved || null === $last_message ) {
			return $none;
		}

		// Staff (a team member) spoke last → nothing owed. A guest (id 0) or a
		// non-team user is the customer → the clock is on staff.
		if ( TeamRosterService::user_is_team_member( (int) $last_message['created_by'] ) ) {
			return $none;
		}

		$timestamp = '' !== $last_message['created_at'] ? (int) strtotime( $last_message['created_at'] . ' UTC' ) : 0;
		if ( $timestamp <= 0 ) {
			return $none;
		}

		$waited = max( 0, time() - $timestamp );
		if ( $waited <= self::SLA_GREEN_MAX ) {
			$class = 'is-green';
		} elseif ( $waited <= self::SLA_BLUE_MAX ) {
			$class = 'is-blue';
		} elseif ( $waited <= self::SLA_AMBER_MAX ) {
			$class = 'is-amber';
		} else {
			$class = 'is-red';
		}

		return array(
			/* translators: %s: human-readable time difference, e.g. "2 hours" */
			'label' => sprintf( __( 'Waiting %s', 'order-updates-for-woo' ), human_time_diff( $timestamp, time() ) ),
			'class' => $class,
		);
	}

	/** "2 hours ago" from a UTC datetime, or '' when missing. */
	private function time_ago( string $mysql_utc ): string {
		$timestamp = '' !== $mysql_utc ? (int) strtotime( $mysql_utc . ' UTC' ) : 0;

		/* translators: %s: human-readable time difference, e.g. "2 hours" */
		return $timestamp > 0 ? sprintf( __( '%s ago', 'order-updates-for-woo' ), human_time_diff( $timestamp, time() ) ) : '';
	}
}
