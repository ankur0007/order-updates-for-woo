<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

final class DateHelper {
	public static function format_date( string $date, string $fallback = '' ): string {
		if ( '' === $date ) {
			return $fallback ?: __( 'Unknown date', 'order-updates-for-woo' );
		}

		return get_date_from_gmt( $date, 'M j, g:i A' );
	}
}
