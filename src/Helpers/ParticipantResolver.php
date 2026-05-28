<?php
/**
 * Resolves the participant list for an order update.
 *
 * A "participant" is any staff user who has a stake in the thread and should
 * therefore receive notifications by default (admin-bar + email). The list is
 * derived on the fly from the update row + its notes — there's no separate
 * storage. Per-user opt-out (Get notifications switch) is layered on top via
 * StaffEmailPreference / AdminBarNotificationStore.
 *
 * Sources, in role priority order:
 *   1. Creator        — the staff member who opened the update.
 *   2. Assignee       — whoever is currently assigned (old assignees drop off
 *                       unless they were also @mentioned or have posted).
 *   3. Tagged         — every user @mentioned in any internal note, ever.
 *   4. Joined         — every staff user who has authored an internal or
 *                       customer note on this update (Zendesk-style: replying
 *                       pulls you into the thread).
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;

class ParticipantResolver {
	public const ROLE_CREATOR  = 'creator';
	public const ROLE_ASSIGNEE = 'assignee';
	public const ROLE_TAGGED   = 'tagged';
	public const ROLE_JOINED   = 'joined';

	public function __construct( private OrderUpdatesDb $order_updates_db ) {}

	/**
	 * Flat list of participant user IDs for notification fan-out.
	 *
	 * @return int[]
	 */
	public function ids_for( int $update_id ): array {
		return array_keys( $this->primary_role_map( $update_id ) );
	}

	/**
	 * Participant rows for the Participants tab UI. Each row carries the
	 * primary role chip plus the data needed to render an avatar + name.
	 *
	 * Returns rows sorted by role priority (creator → assignee → tagged →
	 * joined), then alphabetically by display name within each role.
	 *
	 * @return array<int, array{user_id:int, name:string, email:string, avatar_url:string, role:string, role_label:string}>
	 */
	public function rows_for( int $update_id ): array {
		$role_map = $this->primary_role_map( $update_id );

		if ( empty( $role_map ) ) {
			return array();
		}

		$rows = array();

		foreach ( $role_map as $user_id => $role ) {
			$user = get_user_by( 'id', $user_id );

			if ( ! $user ) {
				continue;
			}

			$name = trim( (string) $user->display_name );

			if ( '' === $name ) {
				$first = (string) get_user_meta( $user_id, 'first_name', true );
				$last  = (string) get_user_meta( $user_id, 'last_name', true );
				$name  = trim( $first . ' ' . $last );
			}

			if ( '' === $name ) {
				$name = (string) $user->user_email;
			}

			$rows[] = array(
				'user_id'    => $user_id,
				'name'       => $name,
				'email'      => (string) $user->user_email,
				'avatar_url' => (string) get_avatar_url( $user_id, array( 'size' => 56 ) ),
				'role'       => $role,
				'role_label' => self::role_label( $role ),
			);
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$priority = array(
					self::ROLE_CREATOR  => 0,
					self::ROLE_ASSIGNEE => 1,
					self::ROLE_TAGGED   => 2,
					self::ROLE_JOINED   => 3,
				);

				$pa = $priority[ $a['role'] ] ?? 99;
				$pb = $priority[ $b['role'] ] ?? 99;

				if ( $pa !== $pb ) {
					return $pa <=> $pb;
				}

				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return $rows;
	}

	/**
	 * user_id => primary role (highest-priority role that user holds).
	 *
	 * Customers (the order's `customer_user` on logged-in orders) are excluded
	 * even when they're the update's creator. Otherwise an admin reply on an
	 * internal note would email the customer the internal thread — the bug
	 * this guard exists to prevent. Guest customers store `created_by = 0`
	 * and are already filtered by the `> 0` checks below.
	 *
	 * @return array<int, string>
	 */
	private function primary_role_map( int $update_id ): array {
		if ( $update_id <= 0 ) {
			return array();
		}

		$update = $this->order_updates_db->get_update( $update_id );

		if ( empty( $update ) ) {
			return array();
		}

		$order_customer_id = $this->resolve_order_customer_id( absint( $update['order_id'] ?? 0 ) );

		$role_map = array();

		$creator_id = absint( $update['created_by'] ?? 0 );

		if ( $creator_id > 0 && $creator_id !== $order_customer_id ) {
			$role_map[ $creator_id ] = self::ROLE_CREATOR;
		}

		$assignee_id = absint( $update['assignee_user_id'] ?? 0 );

		if ( $assignee_id > 0 && $assignee_id !== $order_customer_id && ! isset( $role_map[ $assignee_id ] ) ) {
			$role_map[ $assignee_id ] = self::ROLE_ASSIGNEE;
		}

		foreach ( $this->collect_mentioned_ids( $update_id ) as $tagged_id ) {
			if ( $tagged_id > 0 && $tagged_id !== $order_customer_id && ! isset( $role_map[ $tagged_id ] ) ) {
				$role_map[ $tagged_id ] = self::ROLE_TAGGED;
			}
		}

		foreach ( $this->order_updates_db->get_staff_participant_user_ids( $update_id ) as $joined_id ) {
			$joined_id = absint( $joined_id );
			if ( $joined_id > 0 && $joined_id !== $order_customer_id && ! isset( $role_map[ $joined_id ] ) ) {
				$role_map[ $joined_id ] = self::ROLE_JOINED;
			}
		}

		return $role_map;
	}

	/**
	 * Return the WordPress user id of the order's customer, or 0 for guest
	 * orders (and missing orders). Used to filter customer-as-creator out of
	 * staff participant lists — the customer is not a follower, they're the
	 * subject of the thread.
	 */
	private function resolve_order_customer_id( int $order_id ): int {
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return 0;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return 0;
		}

		return absint( $order->get_customer_id() );
	}

	/**
	 * @return int[]
	 */
	private function collect_mentioned_ids( int $update_id ): array {
		$ids = array();

		foreach ( $this->order_updates_db->get_update_notes( $update_id ) as $note ) {
			foreach ( (array) ( $note['mentioned_user_ids'] ?? array() ) as $uid ) {
				$uid = absint( $uid );
				if ( $uid > 0 ) {
					$ids[ $uid ] = true;
				}
			}
		}

		return array_keys( $ids );
	}

	private static function role_label( string $role ): string {
		return match ( $role ) {
			self::ROLE_CREATOR  => __( 'Creator', 'order-updates-for-woo' ),
			self::ROLE_ASSIGNEE => __( 'Assignee', 'order-updates-for-woo' ),
			self::ROLE_TAGGED   => __( 'Tagged', 'order-updates-for-woo' ),
			default             => __( 'Joined', 'order-updates-for-woo' ),
		};
	}
}
