<?php
/**
 * Icon helper — single source of truth for icon markup.
 *
 * Admin views render WordPress Dashicons (loaded by default in wp-admin, no
 * extra enqueue). Frontend views can opt in by enqueueing the `dashicons`
 * style, or pass their own icon name into a custom partial.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Icons {

	/**
	 * Render a WordPress Dashicon span.
	 *
	 *   echo Icons::dashicon( 'edit' );
	 *   echo Icons::dashicon( 'warning', __( 'Warning', 'order-updates-for-woo' ) );
	 *
	 * Pass a label to set aria-label and title for accessibility. Without one
	 * the icon is marked aria-hidden (decorative).
	 *
	 * @param string $name  Dashicon name without the `dashicons-` prefix.
	 * @param string $label Optional accessible label.
	 */
	public static function dashicon( string $name, string $label = '' ): string {
		$class = 'dashicons dashicons-' . sanitize_html_class( $name );

		if ( '' === $label ) {
			return sprintf(
				'<span class="%s" aria-hidden="true"></span>',
				esc_attr( $class )
			);
		}

		return sprintf(
			'<span class="%s" role="img" aria-label="%s" title="%s"></span>',
			esc_attr( $class ),
			esc_attr( $label ),
			esc_attr( $label )
		);
	}
}
