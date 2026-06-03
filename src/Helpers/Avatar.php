<?php
/**
 * Avatar helper — single source of truth for person avatars.
 *
 * Shows the user's Gravatar when they have one, and a coloured initials disc
 * ("AV" for Ankur Vishwakarma) when they don't. The photo is layered over the
 * initials using a transparent Gravatar fallback (`d=blank`): if a photo
 * exists it covers the disc, otherwise the blank image is see-through and the
 * initials show. No extra request is made to test whether a photo exists.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a person avatar — their Gravatar if they have one, coloured initials if not.
 */
final class Avatar {

	/**
	 * Render an avatar.
	 *
	 * Use it like:
	 *
	 *   echo Avatar::html( $user_id, $display_name );
	 *   echo Avatar::html( 0, 'Guest', 'awts_assignee_avatar', 30 );
	 *
	 * The markup is self-contained on size (width / height / font set inline),
	 * so any view can drop it in; the only shared styling needed is the
	 * `.awts-avatar*` positioning block in that screen's stylesheet.
	 *
	 * @param int    $user_id User to fetch a Gravatar for. 0 renders initials only.
	 * @param string $name    Display name — drives the initials and disc colour.
	 * @param string $extra_class Optional extra class on the wrapper (margins, etc.).
	 * @param int    $size        Avatar diameter in pixels.
	 */
	public static function html( int $user_id, string $name, string $extra_class = '', int $size = 40 ): string {
		[ $initials, $color ] = self::initials_and_color( $name );

		$font = max( 9, (int) round( $size * 0.42 ) );

		// `d=blank` returns the photo when one exists, a transparent image when
		// it doesn't — so a missing photo simply reveals the initials beneath.
		$img = $user_id > 0
			? get_avatar( $user_id, $size, 'blank', $name, array( 'class' => 'awts-avatar__img' ) )
			: '';

		return sprintf(
			'<span class="awts-avatar %1$s" style="width:%2$dpx;height:%2$dpx;font-size:%3$dpx;background:%4$s;">'
				. '<span class="awts-avatar__initials" aria-hidden="true">%5$s</span>%6$s</span>',
			esc_attr( trim( $extra_class ) ),
			$size,
			$font,
			esc_attr( $color ),
			esc_html( $initials ),
			$img // Escaped by get_avatar().
		);
	}

	/**
	 * Initials + a stable colour for a name (no external request).
	 * Initials: first + last for multi-word names, first two chars otherwise.
	 * The colour hue is derived from the name so one person always gets one
	 * colour, run after run.
	 *
	 * @param string $name Display name to derive initials and colour from.
	 * @return array{0:string, 1:string} Initials, then an oklch() colour string.
	 */
	private static function initials_and_color( string $name ): array {
		$name  = trim( $name );
		$parts = '' !== $name ? preg_split( '/\s+/', $name ) : array();

		if ( count( $parts ) >= 2 ) {
			$initials = mb_substr( $parts[0], 0, 1 ) . mb_substr( $parts[ count( $parts ) - 1 ], 0, 1 );
		} elseif ( 1 === count( $parts ) ) {
			$initials = mb_substr( $parts[0], 0, 2 );
		} else {
			$initials = '?';
		}

		$hue    = 0;
		$length = strlen( $name );
		for ( $i = 0; $i < $length; $i++ ) {
			$hue = ( $hue * 31 + ord( $name[ $i ] ) ) % 360;
		}

		return array(
			mb_strtoupper( $initials ),
			sprintf( 'oklch(62%% 0.12 %d)', $hue ),
		);
	}
}
