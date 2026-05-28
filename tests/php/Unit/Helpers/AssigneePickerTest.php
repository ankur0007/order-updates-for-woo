<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Tests\Unit\Helpers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OrderUpdatesForWoo\Helpers\AssigneePicker;
use OrderUpdatesForWoo\Shared\Config\Constants;
use PHPUnit\Framework\TestCase;

final class AssigneePickerTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array(
			Constants::ASSIGNEE_PRIORITY_LIST_OPTION    => array(),
			Constants::ASSIGNEE_ROTATION_POINTER_OPTION => 0,
		);

		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $this->options[ $key ] ?? $default );
		Functions\when( 'update_option' )->alias( function ( $key, $value ) {
			$this->options[ $key ] = $value;
			return true;
		} );
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'ID' => 1 ) ); // user exists
		// AssigneePicker::next() falls back to first_admin_user_id() when
		// the pool is empty or the saved user vanished. That helper calls
		// get_users with `fields => ID`, which returns a flat int array (not
		// WP_User objects). Match that shape so the cast at the call site is
		// happy.
		Functions\when( 'get_users' )->justReturn( array( 1 ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_empty_pool_returns_zero_when_no_admins_either(): void {
		// Empty pool now falls back to the first admin. The "returns zero"
		// guarantee only holds when there's also no admin to fall back to.
		Functions\when( 'get_users' )->justReturn( array() );

		$this->assertSame( 0, AssigneePicker::next() );
	}

	public function test_empty_pool_falls_back_to_first_admin(): void {
		// Default get_users stub yields one admin (ID = 1) — the round-robin
		// helper picks them so an unconfigured pool still routes work to
		// someone instead of dropping it on the floor.
		$this->assertSame( 1, AssigneePicker::next() );
	}

	public function test_single_member_pool_always_returns_that_member(): void {
		$this->options[ Constants::ASSIGNEE_PRIORITY_LIST_OPTION ] = array( 7 );

		$this->assertSame( 7, AssigneePicker::next() );
		$this->assertSame( 7, AssigneePicker::next() );
		$this->assertSame( 7, AssigneePicker::next() );
	}

	public function test_pool_cycles_through_each_member_then_wraps(): void {
		$this->options[ Constants::ASSIGNEE_PRIORITY_LIST_OPTION ] = array( 7, 8, 9 );

		$this->assertSame( 7, AssigneePicker::next() );
		$this->assertSame( 8, AssigneePicker::next() );
		$this->assertSame( 9, AssigneePicker::next() );
		$this->assertSame( 7, AssigneePicker::next() );
	}

	public function test_pointer_advances_in_options_after_each_call(): void {
		$this->options[ Constants::ASSIGNEE_PRIORITY_LIST_OPTION ] = array( 7, 8 );

		AssigneePicker::next();
		$this->assertSame( 1, $this->options[ Constants::ASSIGNEE_ROTATION_POINTER_OPTION ] );

		AssigneePicker::next();
		$this->assertSame( 2, $this->options[ Constants::ASSIGNEE_ROTATION_POINTER_OPTION ] );
	}

	public function test_returns_zero_when_selected_user_and_admin_fallback_both_missing(): void {
		// Pool user vanished AND no admin to fall back to → returns 0.
		// (When an admin is present, the helper picks them instead — that
		// path is exercised by test_empty_pool_falls_back_to_first_admin.)
		$this->options[ Constants::ASSIGNEE_PRIORITY_LIST_OPTION ] = array( 7 );
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'get_users' )->justReturn( array() );

		$this->assertSame( 0, AssigneePicker::next() );
	}

	public function test_pool_changed_between_calls_picks_from_new_pool(): void {
		$this->options[ Constants::ASSIGNEE_PRIORITY_LIST_OPTION ] = array( 7, 8, 9 );
		AssigneePicker::next(); // 7, pointer→1
		AssigneePicker::next(); // 8, pointer→2

		// Pool shrinks to one member; pointer 2 % 1 = 0, so we get index 0.
		$this->options[ Constants::ASSIGNEE_PRIORITY_LIST_OPTION ] = array( 42 );

		$this->assertSame( 42, AssigneePicker::next() );
	}
}
