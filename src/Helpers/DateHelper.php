<?php
/**
 * Date formatting for note and update timestamps.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

/**
 * Turns stored GMT dates into readable, site-timezone strings.
 */
final class DateHelper {

	/**
	 * Format a stored GMT date for display.
	 *
	 * @param string $date     GMT date string, or '' if none.
	 * @param string $fallback Text to show when $date is empty.
	 */
	public static function format_date( string $date, string $fallback = '' ): string {
		if ( '' === $date ) {
			return '' !== $fallback ? $fallback : __( 'Unknown date', 'order-updates-for-woo' );
		}

		return get_date_from_gmt( $date, 'M j, g:i A' );
	}
}
