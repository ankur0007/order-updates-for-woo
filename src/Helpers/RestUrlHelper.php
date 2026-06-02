<?php
/**
 * REST URL builder — one place to build plugin REST URLs so callers and addons
 * never hardcode the namespace.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

use OrderUpdatesForWoo\Shared\Config\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds full URLs for the plugin's REST routes.
 */
final class RestUrlHelper {

	/**
	 * Full URL for a plugin REST route.
	 *
	 * @param string $path Route path under the plugin namespace, e.g. 'updates'.
	 */
	public static function route( string $path = '' ): string {
		$normalized_path = ltrim( $path, '/' );
		$route           = Constants::REST_NAMESPACE;

		if ( '' !== $normalized_path ) {
			$route .= '/' . $normalized_path;
		}

		return rest_url( $route );
	}

	/** Base URL for the updates collection (trailing slash, ready for sub-routes). */
	public static function updates_base(): string {
		return self::route( 'updates/' );
	}

	/** Base URL for the attachments collection (trailing slash, ready for sub-routes). */
	public static function attachments_base(): string {
		return self::route( 'attachments/' );
	}

	/**
	 * Download URL for a single attachment.
	 *
	 * @param int $attachment_id Attachment row id.
	 */
	public static function attachment_download( int $attachment_id ): string {
		return self::route( 'attachments/' . $attachment_id . '/download' );
	}
}
