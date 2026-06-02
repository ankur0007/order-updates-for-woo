<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Endpoints;

use OrderUpdatesForWoo\API\Concerns\VerifiesAccess;
use OrderUpdatesForWoo\API\Contracts\Registrable;
use OrderUpdatesForWoo\Shared\Config\Variables;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Team\TeamRosterService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User_Query;

final class AssigneeSearchEndpoint implements Registrable {
	use VerifiesAccess;

	private const ROUTE = '/assignee-search';

	public function __construct( private ?TeamRosterService $team_roster = null ) {}

	/** Register the REST route. */
	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'can_access' ),
				'args'                => array(
					'query' => array(
						'required'          => true,
						'type'              => 'string',
						'minLength'         => 3,
						'maxLength'         => 50,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_query' ),
					),
				),
			)
		);
	}

	/**
	 * Permission check for the route.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function can_access( WP_REST_Request $request ): bool|WP_Error {
		if ( $error = $this->verify_nonce( $request ) ) {
			return $error;
		}

		if ( $this->is_list_authorized() ) {
			return true;
		}

		return new WP_Error( 'order_updates_for_woo_forbidden', __( 'You are not allowed to view order updates.', 'order-updates-for-woo' ), array( 'status' => 403 ) );
	}

	/**
	 * Handle the request: validate, run the action, and return the response.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$query     = (string) $request->get_param( 'query' );
		$cache_key = 'assignees_' . md5( strtolower( $query ) );
		$cached    = wp_cache_get( $cache_key, Constants::CACHE_GROUP );

		if ( false !== $cached ) {
			return rest_ensure_response( $cached );
		}

		$roster = $this->team_roster ?? new TeamRosterService();

		$user_query = new WP_User_Query(
			array(
				'search'         => '*' . $query . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
				'role__in'       => $roster->get_role_slugs(),
				'number'         => 10,
				'orderby'        => 'display_name',
				'order'          => 'ASC',
				'fields'         => array( 'ID', 'display_name', 'user_email' ),
				'count_total'    => false,
			) 
		);

		$users = $user_query->get_results();

		if ( empty( $users ) ) {
			return rest_ensure_response( apply_filters( 'order_updates_for_woo_assignee_search_response', array(), $query, $request ) );
		}

		$result = array_map( array( $this, 'format_user' ), $users );
		wp_cache_set( $cache_key, $result, Constants::CACHE_GROUP, Variables::getAssigneeSearchCacheTtl() );

		return rest_ensure_response( apply_filters( 'order_updates_for_woo_assignee_search_response', $result, $query, $request ) );
	}

	public function validate_query( $value ): bool {
		return strlen( trim( (string) $value ) ) >= 3;
	}

	private function format_user( object $user ): array {
		return array(
			'id'     => absint( $user->ID ),
			'name'   => sanitize_text_field( $user->display_name ),
			'email'  => sanitize_email( $user->user_email ),
			'avatar' => get_avatar_url( $user->ID, array( 'size' => 32 ) ),
		);
	}
}
