<?php
/**
 * Picks the right asset path (.js or .min.js) based on SCRIPT_DEBUG.
 *
 * In production (default), serves the minified file. With SCRIPT_DEBUG on,
 * serves the unminified source so devs see readable code in the browser.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AssetHelper {

	/**
	 * Return a URL pointing at either the .min or the unminified asset.
	 *
	 * Pass a path relative to the plugin root, e.g. `assets/Frontend/js/customer-order-updates.js`.
	 * Returns the full URL with the right `.min.js` / `.min.css` suffix applied.
	 *
	 * Falls back to the original path if the .min variant doesn't exist on disk.
	 */
	public static function url( string $relative_path ): string {
		$min_path  = self::min_path_for( $relative_path );
		$min_file  = ORDER_UPDATES_FOR_WOO_PATH . $min_path;
		$want_min  = ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG );

		if ( $want_min && file_exists( $min_file ) ) {
			return ORDER_UPDATES_FOR_WOO_URL . $min_path;
		}

		return ORDER_UPDATES_FOR_WOO_URL . $relative_path;
	}

	/**
	 * Cache-busting version string — uses the served file's mtime so a deploy
	 * that updates only the source/min still bumps the version.
	 */
	public static function version( string $relative_path ): string {
		$min_path = self::min_path_for( $relative_path );
		$want_min = ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG );

		$served = ( $want_min && file_exists( ORDER_UPDATES_FOR_WOO_PATH . $min_path ) )
			? ORDER_UPDATES_FOR_WOO_PATH . $min_path
			: ORDER_UPDATES_FOR_WOO_PATH . $relative_path;

		return file_exists( $served ) ? (string) filemtime( $served ) : '1.0.0';
	}

	private static function min_path_for( string $relative_path ): string {
		// `.js` → `.min.js`, `.css` → `.min.css`; leave already-minified paths alone.
		if ( str_ends_with( $relative_path, '.min.js' ) || str_ends_with( $relative_path, '.min.css' ) ) {
			return $relative_path;
		}
		return (string) preg_replace( '/\.(js|css)$/', '.min.$1', $relative_path );
	}
}
