<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Tests\Unit\Shared\Attachments;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OrderUpdatesForWoo\Shared\Attachments\AttachmentService;
use PHPUnit\Framework\TestCase;

/**
 * MIME allowlist contract — pin the exact set the upload pipeline accepts.
 * Adding or removing a type here is a compatibility-affecting change for
 * stored attachments and any addon that hooks the storage handler filter.
 */
final class AttachmentServiceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Default the admin-configured mime allowlist to "unset" so the code
		// falls back to DEFAULT_ACTIVE_MIMES. Individual tests can override
		// when they need to exercise the configured-list branch.
		Functions\when( 'get_option' )->justReturn( null );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_allowed_mime_types_returns_canonical_set(): void {
		// Default-on set is the smallest safe install: PDF + JPEG + PNG.
		// Docs / spreadsheets / GIF / WEBP / text are admin opt-in via the
		// settings dropdown — not in the canonical default.
		$expected = array(
			'application/pdf',
			'image/jpeg',
			'image/png',
		);

		$this->assertSame( $expected, AttachmentService::allowed_mime_types() );
	}

	public function test_executable_mime_types_are_not_in_allowlist(): void {
		$blocked = array(
			'application/x-php',
			'application/x-httpd-php',
			'text/x-php',
			'application/x-sh',
			'text/html',
			'application/javascript',
		);

		foreach ( $blocked as $mime ) {
			$this->assertNotContains( $mime, AttachmentService::allowed_mime_types(), "{$mime} must never be in allowlist" );
		}
	}
}
