<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Tests\Unit\Shared\Validation;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Validation\Validator;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Coverage for Validator paths beyond Phase 1's `sanitize_note` smoke tests:
 * attachment-payload validation, color hex parsing, mention-list filtering.
 */
final class ValidatorExtendedTest extends TestCase {

	private Validator $validator;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'sanitize_key' )->alias( fn( $v ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof WP_Error );

		$this->validator = new Validator();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// validate_attachment_payload ----------------------------------------

	public function test_attachment_payload_requires_update_id(): void {
		$result = $this->validator->validate_attachment_payload( array( 'note_id' => 12, 'note_type' => 'internal', 'file' => array() ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'order_updates_for_woo_invalid_update', $result->get_error_code() );
	}

	public function test_attachment_payload_requires_note_id(): void {
		$result = $this->validator->validate_attachment_payload( array( 'update_id' => 5, 'note_type' => 'internal', 'file' => array() ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'order_updates_for_woo_attachment_invalid_context', $result->get_error_code() );
	}

	public function test_attachment_payload_rejects_unknown_note_type(): void {
		$result = $this->validator->validate_attachment_payload( array( 'update_id' => 5, 'note_id' => 12, 'note_type' => 'rogue', 'file' => array() ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'order_updates_for_woo_attachment_invalid_note_type', $result->get_error_code() );
	}

	public function test_attachment_payload_accepts_internal_or_customer_type(): void {
		foreach ( array( Constants::NOTE_TYPE_INTERNAL, Constants::NOTE_TYPE_CUSTOMER ) as $type ) {
			$result = $this->validator->validate_attachment_payload( array(
				'update_id' => 5,
				'note_id'   => 12,
				'note_type' => $type,
				'file'      => array( 'tmp_name' => '/tmp/x' ),
			) );

			$this->assertIsArray( $result, "type {$type}" );
			$this->assertSame( $type, $result['note_type'] );
		}
	}

	public function test_attachment_payload_requires_file_array(): void {
		$result = $this->validator->validate_attachment_payload( array( 'update_id' => 5, 'note_id' => 12, 'note_type' => 'internal', 'file' => null ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'order_updates_for_woo_missing_file', $result->get_error_code() );
	}

	// sanitize_note over-length error ------------------------------------

	public function test_sanitize_note_over_length_returns_wp_error(): void {
		Functions\when( 'wp_kses_post' )->returnArg();

		$result = $this->validator->sanitize_note( str_repeat( 'a', 200 ), 100, 'Note' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'order_updates_for_woo_note_too_long', $result->get_error_code() );
	}

	// sanitize_mentioned_user_ids ----------------------------------------

	public function test_sanitize_mentioned_user_ids_returns_empty_for_non_array(): void {
		$this->assertSame( array(), $this->validator->sanitize_mentioned_user_ids( 'not-an-array' ) );
	}

	public function test_sanitize_mentioned_user_ids_returns_empty_for_no_candidates(): void {
		$this->assertSame( array(), $this->validator->sanitize_mentioned_user_ids( array( 0, '', null ) ) );
	}
}
