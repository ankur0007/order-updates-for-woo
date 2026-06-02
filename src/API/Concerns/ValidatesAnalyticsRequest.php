<?php
/**
 * Shared access check and date-range parsing for analytics endpoints.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Concerns;

use WP_Error;
use WP_REST_Request;

/**
 * Shared access check and date-range parsing for analytics endpoints.
 */
trait ValidatesAnalyticsRequest {
	use VerifiesAccess;

	/**
	 * Permission check for analytics routes — nonce + manage_woocommerce.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function analytics_can_access( WP_REST_Request $request ): bool|WP_Error {
		if ( $error = $this->verify_nonce( $request ) ) {
			return $error;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error(
				'order_updates_for_woo_forbidden',
				__( 'You are not allowed to view analytics.', 'order-updates-for-woo' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Parse and validate the from/to date range (YYYY-MM-DD, from ≤ to).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array{0:string,1:string}|WP_Error
	 */
	protected function parse_analytics_date_range( WP_REST_Request $request ): array|WP_Error {
		$from = sanitize_text_field( (string) $request->get_param( 'from' ) );
		$to   = sanitize_text_field( (string) $request->get_param( 'to' ) );

		$valid = static fn( string $d ) => (bool) \DateTime::createFromFormat( 'Y-m-d', $d );

		if ( ! $from || ! $to || ! $valid( $from ) || ! $valid( $to ) || $from > $to ) {
			return new WP_Error(
				'order_updates_for_woo_invalid_params',
				__( 'A valid date range is required (YYYY-MM-DD, from ≤ to).', 'order-updates-for-woo' ),
				array( 'status' => 400 )
			);
		}

		return array( $from, $to );
	}
}
