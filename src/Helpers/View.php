<?php
/**
 * Plugin view helper.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class View {

	/**
	 * Render a view file relative to the plugin root.
	 *
	 *   View::render( 'src/Admin/Orders/Views/OrderUpdatesPanelViewModern' );
	 *
	 * When the path contains a `Templates/` segment, the file is treated as a
	 * theme-overridable template (WooCommerce-style). Lookup order:
	 *
	 *   1. {child_theme}/order-updates-for-woo/{slug}.php
	 *   2. {parent_theme}/order-updates-for-woo/{slug}.php
	 *   3. {plugin}/{view}.php
	 *
	 * The `{slug}` is derived by lowercasing the part of the path *before*
	 * `Templates/` and concatenating it with the part *after*. So:
	 *
	 *   src/Admin/Orders/Templates/card/header
	 *     → theme: order-updates-for-woo/admin/orders/card/header.php
	 *
	 * Pass the variables your template needs in `$context`. Inside the
	 * template they are available as `$view_data`.
	 */
	public static function render( string $view = '', array $context = array() ): void {
		$base_path = dirname( __DIR__, 2 );

		if ( '' === $view ) {
			return;
		}

		$theme_override = self::resolve_theme_override( $view );

		if ( '' !== $theme_override ) {
			$view_data = $context;
			require $theme_override;
			return;
		}

		$view_file = realpath( $base_path . '/' . ltrim( $view, '/' ) . '.php' );

		if ( ! $view_file ) {
			return;
		}

		// Stop path traversal — the resolved file must live inside the plugin.
		if ( 0 !== strpos( $view_file, $base_path . '/' ) ) {
			return;
		}

		$view_data = $context;
		require $view_file;
	}

	/**
	 * Find a theme override for an overridable template, or return '' if the
	 * caller's path is not overridable or no override exists.
	 *
	 * Overridable paths contain a `Templates/` segment. Anything else (e.g.
	 * a private internal view) falls through to plugin-only resolution.
	 */
	private static function resolve_theme_override( string $view ): string {
		// Match `{anything}/Templates/{slug}` once, capturing both halves.
		if ( ! preg_match( '#^(?P<before>.+?)/Templates/(?P<slug>.+)$#', ltrim( $view, '/' ), $matches ) ) {
			return '';
		}

		$prefix = strtolower( ltrim( $matches['before'], '/' ) );
		// Strip the conventional `src/` root from the override path so themes
		// don't have to mirror the PHP-side directory layout.
		$prefix = preg_replace( '#^src/#', '', $prefix );
		$slug   = trim( $matches['slug'], '/' );

		if ( '' === $slug ) {
			return '';
		}

		$relative = 'order-updates-for-woo/' . ( '' !== $prefix ? $prefix . '/' : '' ) . $slug . '.php';

		$candidates = array(
			trailingslashit( get_stylesheet_directory() ) . $relative,
			trailingslashit( get_template_directory() ) . $relative,
		);

		/**
		 * Filters the list of candidate paths checked for a theme override of
		 * a plugin template. Return a different list to redirect overrides to
		 * a custom directory (e.g. an addon-supplied template pack).
		 *
		 * @param string[] $candidates Absolute paths checked in order.
		 * @param string   $view       Original view path passed to View::render.
		 * @param string   $relative   Resolved theme-relative path (e.g. order-updates-for-woo/admin/orders/card/header.php).
		 */
		$candidates = (array) apply_filters( 'order_updates_for_woo_template_override_candidates', $candidates, $view, $relative );

		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && '' !== $candidate && is_readable( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}
}
