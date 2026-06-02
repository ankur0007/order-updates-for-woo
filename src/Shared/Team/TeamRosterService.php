<?php
/**
 * Resolves and caches the internal-team roster (who can see internal notes).
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Team;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the configured team roles, lists matching users, and answers
 * "is this user on the team?" — cached, with cache busting on user changes.
 */
final class TeamRosterService {
	public const ROLES_OPTION     = 'order_updates_for_woo_internal_team_roles';
	public const ROSTER_TRANSIENT = 'order_updates_for_woo_team_roster';
	public const ROSTER_TTL       = 12 * HOUR_IN_SECONDS;
	public const MAX_MEMBERS      = 200;

	public const DEFAULT_ROLES = array( 'administrator', 'shop_manager', 'editor' );

	/** Hook cache busting to the WordPress user lifecycle events. */
	public function init(): void {
		add_action( 'user_register', array( $this, 'flush_cache' ) );
		add_action( 'set_user_role', array( $this, 'flush_cache' ) );
		add_action( 'delete_user', array( $this, 'flush_cache' ) );
		add_action( 'profile_update', array( $this, 'flush_cache' ) );
		add_action( 'add_user_role', array( $this, 'flush_cache' ) );
		add_action( 'remove_user_role', array( $this, 'flush_cache' ) );
		add_action( 'update_option_' . self::ROLES_OPTION, array( $this, 'flush_cache' ) );
	}

	/**
	 * Resolve the configured internal-team role slugs.
	 *
	 * @return string[]
	 */
	public function get_role_slugs(): array {
		$stored = get_option( self::ROLES_OPTION, null );

		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return self::DEFAULT_ROLES;
		}

		$clean = array_values( array_unique( array_filter( array_map( 'sanitize_key', $stored ) ) ) );

		return empty( $clean ) ? self::DEFAULT_ROLES : $clean;
	}

	/**
	 * Cached roster of internal team members.
	 *
	 * @return array<int, array{id:int,name:string,email:string,avatar:string}>
	 */
	public function get_team_members(): array {
		$cached = get_transient( self::ROSTER_TRANSIENT );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$users = get_users(
			array(
				'role__in' => $this->get_role_slugs(),
				'number'   => self::MAX_MEMBERS,
				'orderby'  => 'display_name',
				'order'    => 'ASC',
				'fields'   => array( 'ID', 'display_name', 'user_email' ),
			) 
		);

		$roster = array_map(
			fn( $user ) => array(
				'id'     => absint( $user->ID ),
				'name'   => sanitize_text_field( (string) $user->display_name ),
				'email'  => sanitize_email( (string) $user->user_email ),
				'avatar' => (string) get_avatar_url( $user->ID, array( 'size' => 32 ) ),
			),
			$users
		);

		set_transient( self::ROSTER_TRANSIENT, $roster, self::ROSTER_TTL );

		return $roster;
	}

	/** Drop the cached roster so the next read rebuilds it. */
	public function flush_cache(): void {
		delete_transient( self::ROSTER_TRANSIENT );
	}

	/**
	 * True when the user holds at least one of the configured internal-team
	 * roles. Used to gate internal-note endpoints + UI so a shop_manager not
	 * in the team list cannot see or post internal notes.
	 *
	 * @param int|null $user_id User to check; defaults to the current user.
	 */
	public static function user_is_team_member( ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();

		if ( $user_id <= 0 ) {
			return false;
		}

		$user = get_userdata( $user_id );

		if ( ! $user || empty( $user->roles ) ) {
			return false;
		}

		$stored  = get_option( self::ROLES_OPTION, null );
		$allowed = is_array( $stored )
			? array_values( array_unique( array_filter( array_map( 'sanitize_key', $stored ) ) ) )
			: array();

		if ( empty( $allowed ) ) {
			$allowed = self::DEFAULT_ROLES;
		}

		return (bool) array_intersect( (array) $user->roles, $allowed );
	}
}
