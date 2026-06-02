<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Language;

final class Labels {
	public static function all(): array {
		$labels = require __DIR__ . '/Labels/strings.php';

		return is_array( $labels ) ? $labels : array();
	}

	public static function get( string $key, string $fallback = '' ): string {
		return (string) ( self::all()[ $key ] ?? $fallback );
	}
}
