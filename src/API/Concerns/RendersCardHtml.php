<?php
/**
 * Renders an update card to an HTML string for REST responses.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Concerns;

use OrderUpdatesForWoo\Helpers\View;

/**
 * Shared by endpoints that return fresh card markup after a write.
 */
trait RendersCardHtml {

	/**
	 * Render one update's card to an HTML string.
	 *
	 * @param array $order_update Update row to render.
	 */
	private function render_card_html( array $order_update ): string {
		ob_start();

		// Card view needs both the list and a color-keyed lookup; without them
		// the status picker doesn't render.
		$statuses               = $this->settings_service->get_statuses();
		$status_lookup_by_color = array();
		foreach ( $statuses as $status ) {
			$color = isset( $status['color'] ) ? strtolower( (string) $status['color'] ) : '';
			if ( '' !== $color ) {
				$status_lookup_by_color[ $color ] = $status;
			}
		}

		View::render(
			'src/Admin/Orders/Views/OrderUpdateCardViewModern',
			array(
				'settings'               => $this->settings_service->get_feature_settings(),
				'card_variables'         => $this->update_card_variable_parser->parse( $order_update ),
				'statuses'               => $statuses,
				'status_lookup_by_color' => $status_lookup_by_color,
			)
		);

		return (string) ob_get_clean();
	}
}
