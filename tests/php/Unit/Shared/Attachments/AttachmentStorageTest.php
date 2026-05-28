<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Tests\Unit\Shared\Attachments;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OrderUpdatesForWoo\Shared\Attachments\AttachmentStorage;
use OrderUpdatesForWoo\Shared\Config\Constants;
use PHPUnit\Framework\TestCase;

/**
 * Boundary-check focus: every file/dir delete passes through is_inside_attachments_dir,
 * so a regression there is the difference between deleting plugin files and deleting
 * arbitrary system files. Other behaviours (wp_mkdir_p, .htaccess writes, WP_Filesystem
 * roundtrips) are integration-level and out of scope for unit tests.
 */
final class AttachmentStorageTest extends TestCase {

	private string $tmp_root;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->tmp_root = sys_get_temp_dir() . '/awts-test-' . uniqid();
		mkdir( $this->tmp_root . '/order-updates-for-woo/orders', 0777, true );

		Functions\when( 'wp_upload_dir' )->justReturn( array(
			'basedir' => $this->tmp_root,
			'baseurl' => 'https://example.test/uploads',
		) );
		Functions\when( 'trailingslashit' )->alias( fn( $p ) => rtrim( (string) $p, '/' ) . '/' );
		Functions\when( 'wp_normalize_path' )->alias( fn( $p ) => str_replace( '\\', '/', (string) $p ) );
		Functions\when( 'content_url' )->returnArg();
	}

	protected function tearDown(): void {
		// Best-effort cleanup via shell so the bypass-finals stream wrapper
		// doesn't interfere with PHP's recursive directory walks.
		if ( is_dir( $this->tmp_root ) ) {
			@exec( 'rm -rf ' . escapeshellarg( $this->tmp_root ) );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_path_inside_attachments_root_passes_boundary_check(): void {
		$file = $this->tmp_root . '/' . Constants::ATTACHMENTS_ROOT_DIR . '/orders/test.txt';
		file_put_contents( $file, 'x' );

		$this->assertTrue( AttachmentStorage::is_inside_attachments_dir( $file ) );
	}

	public function test_path_outside_attachments_root_fails_boundary_check(): void {
		$file = $this->tmp_root . '/elsewhere.txt';
		file_put_contents( $file, 'x' );

		$this->assertFalse( AttachmentStorage::is_inside_attachments_dir( $file ) );
	}

	public function test_traversal_attempt_is_resolved_and_rejected(): void {
		$root_dir = $this->tmp_root . '/' . Constants::ATTACHMENTS_ROOT_DIR;
		// realpath resolves ../ ; if the resolved path escapes the root, fail.
		$attempt = $root_dir . '/orders/../../escape';

		$this->assertFalse( AttachmentStorage::is_inside_attachments_dir( $attempt ) );
	}

	public function test_delete_file_rejects_path_outside_root(): void {
		$file = $this->tmp_root . '/elsewhere.txt';
		file_put_contents( $file, 'x' );

		$this->assertFalse( AttachmentStorage::delete_file( $file ) );
		$this->assertFileExists( $file );
	}

	public function test_delete_order_dir_rejects_zero_order_id(): void {
		$this->assertFalse( AttachmentStorage::delete_order_dir( 0 ) );
	}

	public function test_delete_update_dir_rejects_zero_ids(): void {
		$this->assertFalse( AttachmentStorage::delete_update_dir( 0, 5 ) );
		$this->assertFalse( AttachmentStorage::delete_update_dir( 100, 0 ) );
	}

	public function test_path_helpers_compose_correctly(): void {
		$root = $this->tmp_root . '/' . Constants::ATTACHMENTS_ROOT_DIR;

		$this->assertSame( $root . '/orders/100', AttachmentStorage::order_dir( 100 ) );
		$this->assertSame( $root . '/orders/100/5', AttachmentStorage::update_dir( 100, 5 ) );
		$this->assertSame( $root . '/orders/100/5/99', AttachmentStorage::note_dir( 100, 5, 99 ) );
	}
}
