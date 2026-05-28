<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Tests\Unit\Validation;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OrderUpdatesForWoo\Shared\Validation\Validator;
use PHPUnit\Framework\TestCase;
use WP_Error;

final class ValidatorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_sanitize_note_strips_disallowed_html(): void {
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_kses_post' )->alias( fn( $v ) => strip_tags( $v, '<p><strong><em><a>' ) );

		$result = ( new Validator() )->sanitize_note( '<script>alert(1)</script><p>Hello</p>' );

		$this->assertIsString( $result );
		$this->assertStringNotContainsString( '<script>', $result );
		$this->assertStringContainsString( '<p>Hello</p>', $result );
	}

	public function test_sanitize_note_empty_string_returns_empty(): void {
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();

		$this->assertSame( '', ( new Validator() )->sanitize_note( '' ) );
	}

	public function test_sanitize_note_over_max_length_returns_wp_error(): void {
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( '__' )->returnArg();

		$result = ( new Validator() )->sanitize_note( str_repeat( 'a', 501 ), 500, 'Note' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'order_updates_for_woo_note_too_long', $result->get_error_code() );
	}
}
