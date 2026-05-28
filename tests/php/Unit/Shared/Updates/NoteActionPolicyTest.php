<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Tests\Unit\Shared\Updates;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\MockInterface;
use OrderUpdatesForWoo\Admin\Settings\Services\OrderUpdatesSettingsService;
use OrderUpdatesForWoo\Shared\Updates\NoteActionPolicy;
use PHPUnit\Framework\TestCase;

final class NoteActionPolicyTest extends TestCase {

	private OrderUpdatesSettingsService&MockInterface $settings;
	private NoteActionPolicy $policy;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
			define( 'MINUTE_IN_SECONDS', 60 );
		}

		$this->settings = Mockery::mock( OrderUpdatesSettingsService::class );

		// Default both master toggles ON so each test exercises the rest of
		// the policy (authorship + latest + window). The few tests that need
		// the master toggle OFF override these expectations locally.
		$this->settings->shouldReceive( 'allow_note_edit' )->andReturn( true )->byDefault();
		$this->settings->shouldReceive( 'allow_note_delete' )->andReturn( true )->byDefault();

		$this->policy = new NoteActionPolicy( $this->settings );

		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'get_option' )->returnArg( 2 );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
	}

	protected function tearDown(): void {
		Mockery::close();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function fresh_note( int $created_by = 7 ): array {
		return array(
			'created_by' => $created_by,
			'created_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ), // 1 min ago
		);
	}

	private function expired_note( int $created_by = 7 ): array {
		return array(
			'created_by' => $created_by,
			'created_at' => gmdate( 'Y-m-d H:i:s', time() - 7200 ), // 2h ago
		);
	}

	// edit_window option clamping ----------------------------------------

	public function test_get_edit_window_returns_default_for_zero_or_negative_option(): void {
		Functions\when( 'get_option' )->justReturn( 0 );

		// 1-minute default is the "typo escape hatch" framing — short enough
		// that edits feel like quick corrections, not history rewrites.
		$this->assertSame( 1, $this->policy->get_edit_window_minutes() );
	}

	public function test_get_edit_window_clamps_at_24_hours(): void {
		Functions\when( 'get_option' )->justReturn( 99999 );

		$this->assertSame( 1440, $this->policy->get_edit_window_minutes() );
	}

	// can_edit_internal_note ---------------------------------------------

	public function test_can_edit_internal_note_when_author_and_within_window(): void {
		$this->assertTrue( $this->policy->can_edit_internal_note( $this->fresh_note() ) );
	}

	public function test_cannot_edit_internal_note_when_not_author(): void {
		$this->assertFalse( $this->policy->can_edit_internal_note( $this->fresh_note( 999 ) ) );
	}

	public function test_cannot_edit_internal_note_after_edit_window_expires(): void {
		$this->assertFalse( $this->policy->can_edit_internal_note( $this->expired_note() ) );
	}

	// can_delete_internal_note (gated by setting) ------------------------

	public function test_can_delete_internal_note_only_when_setting_enabled(): void {
		$this->settings->shouldReceive( 'allow_member_note_delete' )->andReturn( true );

		$this->assertTrue( $this->policy->can_delete_internal_note( $this->fresh_note() ) );
	}

	public function test_cannot_delete_internal_note_when_setting_disabled(): void {
		$this->settings->shouldReceive( 'allow_member_note_delete' )->andReturn( false );

		$this->assertFalse( $this->policy->can_delete_internal_note( $this->fresh_note() ) );
	}

	// can_edit_member_customer_note (latest-only rule) -------------------

	public function test_member_can_edit_customer_note_when_within_window(): void {
		$note       = $this->fresh_note();
		$note['id'] = 42;

		// latest_note_id = 0 means caller skipped the latest gate.
		$this->assertTrue( $this->policy->can_edit_member_customer_note( $note ) );
	}

	public function test_member_cannot_edit_customer_note_when_newer_note_exists(): void {
		$note       = $this->fresh_note();
		$note['id'] = 42;

		// Pass a latest id that isn't this note → latest-only check fails.
		$this->assertFalse( $this->policy->can_edit_member_customer_note( $note, 99 ) );
	}

	public function test_member_can_edit_customer_note_when_it_is_the_latest(): void {
		$note       = $this->fresh_note();
		$note['id'] = 42;

		$this->assertTrue( $this->policy->can_edit_member_customer_note( $note, 42 ) );
	}

	public function test_member_cannot_edit_customer_note_when_master_toggle_off(): void {
		$this->settings->shouldReceive( 'allow_note_edit' )->andReturn( false );

		$note       = $this->fresh_note();
		$note['id'] = 42;

		$this->assertFalse( $this->policy->can_edit_member_customer_note( $note, 42 ) );
	}

	// can_edit_customer_authored_note (logged-in vs guest) ---------------

	public function test_logged_in_customer_can_edit_their_own_note(): void {
		$note = array(
			'created_by' => 7,
			'created_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
		);

		$this->assertTrue( $this->policy->can_edit_customer_authored_note( $note, 7, false ) );
	}

	public function test_logged_in_customer_cannot_edit_other_customers_note(): void {
		$note = array(
			'created_by' => 99,
			'created_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
		);

		$this->assertFalse( $this->policy->can_edit_customer_authored_note( $note, 99, false ) );
	}

	public function test_guest_can_edit_their_own_guest_authored_note(): void {
		$note = array(
			'created_by' => 0,
			'created_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
		);

		$this->assertTrue( $this->policy->can_edit_customer_authored_note( $note, 0, true ) );
	}

	public function test_guest_cannot_edit_a_logged_in_users_note(): void {
		$note = array(
			'created_by' => 42,
			'created_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
		);

		$this->assertFalse( $this->policy->can_edit_customer_authored_note( $note, 42, true ) );
	}

	public function test_blank_created_at_is_treated_as_expired(): void {
		$note = array( 'created_by' => 7, 'created_at' => '' );

		$this->assertFalse( $this->policy->can_edit_internal_note( $note ) );
	}
}
