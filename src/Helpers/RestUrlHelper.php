<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

use OrderUpdatesForWoo\Shared\Config\Constants;

final class RestUrlHelper {
	public static function route( string $path = '' ): string {
		$normalized_path = ltrim( $path, '/' );
		$route           = Constants::REST_NAMESPACE;

		if ( '' !== $normalized_path ) {
			$route .= '/' . $normalized_path;
		}

		return rest_url( $route );
	}

	public static function updates_base(): string {
		return self::route( 'updates/' );
	}

	public static function attachments_base(): string {
		return self::route( 'attachments/' );
	}

	public static function attachment_download( int $attachment_id ): string {
		return self::route( 'attachments/' . $attachment_id . '/download' );
	}
}
