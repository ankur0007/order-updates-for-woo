<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Attachments;

use OrderUpdatesForWoo\Shared\Config\Variables;

/**
 * HMAC signer for attachment download URLs exposed to customers/guests.
 *
 * The standard REST nonce flow requires the logged-in admin session, so it
 * can't authorize customer or guest browsers loading <img> tags. Instead we
 * mint a short-lived signed token tied to the attachment id and an expiry;
 * the serve endpoint validates it independently of the REST nonce.
 */
final class AttachmentSigner {
	public static function sign( int $attachment_id, ?int $ttl = null ): array {
		$expires = time() + ( $ttl ?? Variables::getSignedUrlTtl() );

		return array(
			'expires' => $expires,
			'token'   => self::make_token( $attachment_id, $expires ),
		);
	}

	public static function verify( int $attachment_id, int $expires, string $token ): bool {
		if ( ! $attachment_id || ! $expires || '' === $token ) {
			return false;
		}

		if ( $expires < time() ) {
			return false;
		}

		$expected = self::make_token( $attachment_id, $expires );

		return hash_equals( $expected, $token );
	}

	private static function make_token( int $attachment_id, int $expires ): string {
		return hash_hmac( 'sha256', $attachment_id . '|' . $expires, wp_salt( 'nonce' ) );
	}
}
