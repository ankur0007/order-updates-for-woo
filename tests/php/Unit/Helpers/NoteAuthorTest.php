<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Tests\Unit\Helpers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OrderUpdatesForWoo\Helpers\NoteAuthor;
use PHPUnit\Framework\TestCase;
use stdClass;

final class NoteAuthorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_zero_or_negative_creator_id_is_treated_as_customer(): void {
		$this->assertTrue( NoteAuthor::is_customer( 0 ) );
		$this->assertTrue( NoteAuthor::is_customer( -1 ) );
	}

	public function test_unknown_user_is_treated_as_customer(): void {
		Functions\when( 'get_user_by' )->justReturn( false );

		$this->assertTrue( NoteAuthor::is_customer( 999 ) );
	}

	public function test_user_without_shop_caps_is_customer(): void {
		Functions\when( 'get_user_by' )->justReturn( new stdClass() );
		Functions\when( 'user_can' )->justReturn( false );

		$this->assertTrue( NoteAuthor::is_customer( 7 ) );
	}

	public function test_user_with_manage_woocommerce_is_not_customer(): void {
		Functions\when( 'get_user_by' )->justReturn( new stdClass() );
		Functions\when( 'user_can' )->alias( fn( $u, $cap ) => 'manage_woocommerce' === $cap );

		$this->assertFalse( NoteAuthor::is_customer( 7 ) );
	}

	public function test_user_with_edit_shop_orders_is_not_customer(): void {
		Functions\when( 'get_user_by' )->justReturn( new stdClass() );
		Functions\when( 'user_can' )->alias( fn( $u, $cap ) => 'edit_shop_orders' === $cap );

		$this->assertFalse( NoteAuthor::is_customer( 7 ) );
	}
}
