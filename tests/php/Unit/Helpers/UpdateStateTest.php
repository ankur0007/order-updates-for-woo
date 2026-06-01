<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Tests\Unit\Helpers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OrderUpdatesForWoo\Helpers\UpdateState;
use PHPUnit\Framework\TestCase;

final class UpdateStateTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		// can_edit() reads the Restricted-features master toggle. Default to
		// 'yes' so existing tests exercise the authorship / customer-initiated
		// branches; the "edit disabled" case is covered by its own test.
		Functions\when( 'get_option' )->justReturn( 'yes' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_is_resolved_reads_truthy_flag(): void {
		$this->assertTrue( UpdateState::is_resolved( array( 'is_resolved' => 1 ) ) );
		$this->assertFalse( UpdateState::is_resolved( array( 'is_resolved' => 0 ) ) );
		$this->assertFalse( UpdateState::is_resolved( array() ) );
	}

	public function test_is_customer_visible_reads_truthy_flag(): void {
		$this->assertTrue( UpdateState::is_customer_visible( array( 'customer_visible' => 1 ) ) );
		$this->assertFalse( UpdateState::is_customer_visible( array( 'customer_visible' => 0 ) ) );
		$this->assertFalse( UpdateState::is_customer_visible( array() ) );
	}

	public function test_has_assignee_true_only_when_user_id_present(): void {
		$this->assertTrue( UpdateState::has_assignee( array( 'assignee_user_id' => 9 ) ) );
		$this->assertFalse( UpdateState::has_assignee( array( 'assignee_user_id' => 0 ) ) );
		$this->assertFalse( UpdateState::has_assignee( array() ) );
	}

	public function test_should_render_edit_ui_uses_current_user_when_no_id_passed(): void {
		// setUp stubs get_current_user_id to return 7. Collaborative model:
		// any signed-in staff can edit any update — creator identity no
		// longer matters. Order-level cap is gated upstream in VerifiesAccess.
		$this->assertTrue( UpdateState::should_render_edit_ui( array( 'created_by' => 7 ) ) );
		$this->assertTrue( UpdateState::should_render_edit_ui( array( 'created_by' => 99 ) ) );
	}

	public function test_should_render_edit_ui_with_explicit_user_id(): void {
		$this->assertTrue( UpdateState::should_render_edit_ui( array( 'created_by' => 42 ), 42 ) );
		// Non-creator can edit too under the collaborative model.
		$this->assertTrue( UpdateState::should_render_edit_ui( array( 'created_by' => 42 ), 7 ) );
	}

	public function test_should_render_edit_ui_returns_false_when_not_signed_in(): void {
		// viewer_id = 0 (no logged-in user) blocks edit regardless of creator.
		$this->assertFalse( UpdateState::should_render_edit_ui( array( 'created_by' => 5 ), 0 ) );
	}
}
