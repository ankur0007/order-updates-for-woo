<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Endpoints\Analytics;

use OrderUpdatesForWoo\API\Concerns\ValidatesAnalyticsRequest;
use OrderUpdatesForWoo\API\Contracts\Registrable;
use OrderUpdatesForWoo\Shared\Analytics\AnalyticsLookupDb;
use OrderUpdatesForWoo\Shared\Config\Constants;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class GetAnalyticsAssigneesEndpoint implements Registrable {
	use ValidatesAnalyticsRequest;

	private const ROUTE = '/analytics/assignees';

	public function __construct( private AnalyticsLookupDb $analytics_lookup_db ) {}

	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'analytics_can_access' ),
			)
		);
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$range = $this->parse_analytics_date_range( $request );
		if ( is_wp_error( $range ) ) return $range;
		[ $from, $to ] = $range;

		$response = array( 'assignees' => $this->analytics_lookup_db->by_assignee( $from, $to ) );

		return rest_ensure_response( apply_filters( 'order_updates_for_woo_analytics_assignees_response', $response, $request ) );
	}
}
