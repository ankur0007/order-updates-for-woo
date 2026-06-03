<?php
/**
 * Accessor for the JS-bound UI strings map.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Language;

/**
 * Loads and reads the translatable strings used by the front-end JS.
 */
final class Labels {
	/** The full key => string map. */
	public static function all(): array {
		$labels = require __DIR__ . '/Labels/strings.php';

		return is_array( $labels ) ? $labels : array();
	}

	/**
	 * One string by key, or the fallback when the key is missing.
	 *
	 * @param string $key      String key.
	 * @param string $fallback Value to return if the key is absent.
	 */
	public static function get( string $key, string $fallback = '' ): string {
		return (string) ( self::all()[ $key ] ?? $fallback );
	}
}
