<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Tests\Unit\Helpers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OrderUpdatesForWoo\Helpers\AttachmentPresenter;
use OrderUpdatesForWoo\Shared\Config\Constants;
use PHPUnit\Framework\TestCase;

/**
 * AttachmentPresenter is the addon contract for attachment data — the shape
 * here is what every consumer (admin card, customer thread, REST response)
 * receives. Renames here are wire-format breaking changes.
 */
final class AttachmentPresenterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'add_query_arg' )->alias( fn( $args, $url ) => $url . '?args' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'fake-nonce' );
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.test/wp-json/' . ltrim( (string) $path, '/' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function row( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'            => 99,
				'original_name' => 'invoice.pdf',
				'mime_type'     => 'application/pdf',
				'file_size'     => 12345,
			),
			$overrides
		);
	}

	public function test_format_one_returns_canonical_keys(): void {
		$out = AttachmentPresenter::format_one( $this->row() );

		$this->assertSame( array( 'id', 'name', 'mime', 'size', 'url', 'is_image' ), array_keys( $out ) );
	}

	public function test_format_one_carries_through_row_values(): void {
		$out = AttachmentPresenter::format_one( $this->row() );

		$this->assertSame( 99, $out['id'] );
		$this->assertSame( 'invoice.pdf', $out['name'] );
		$this->assertSame( 'application/pdf', $out['mime'] );
		$this->assertSame( 12345, $out['size'] );
	}

	public function test_is_image_flag_true_for_image_mime(): void {
		$out = AttachmentPresenter::format_one( $this->row( array( 'mime_type' => 'image/png' ) ) );

		$this->assertTrue( $out['is_image'] );
	}

	public function test_is_image_flag_false_for_non_image_mime(): void {
		$out = AttachmentPresenter::format_one( $this->row( array( 'mime_type' => 'application/pdf' ) ) );

		$this->assertFalse( $out['is_image'] );
	}

	public function test_format_one_coerces_missing_fields_to_safe_defaults(): void {
		$out = AttachmentPresenter::format_one( array() );

		$this->assertSame( 0, $out['id'] );
		$this->assertSame( '', $out['name'] );
		$this->assertSame( '', $out['mime'] );
		$this->assertSame( 0, $out['size'] );
		$this->assertFalse( $out['is_image'] );
	}

	public function test_format_many_maps_each_row(): void {
		$rows = array(
			$this->row( array( 'id' => 1, 'original_name' => 'a.pdf' ) ),
			$this->row( array( 'id' => 2, 'original_name' => 'b.pdf' ) ),
		);

		$out = AttachmentPresenter::format_many( $rows );

		$this->assertCount( 2, $out );
		$this->assertSame( 1, $out[0]['id'] );
		$this->assertSame( 2, $out[1]['id'] );
	}

	public function test_format_many_returns_empty_array_for_empty_input(): void {
		$this->assertSame( array(), AttachmentPresenter::format_many( array() ) );
	}
}
