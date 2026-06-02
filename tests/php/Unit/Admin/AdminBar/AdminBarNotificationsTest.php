<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Tests\Unit\Admin\AdminBar;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\MockInterface;
use OrderUpdatesForWoo\Admin\AdminBar\AdminBarNotifications;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use PHPUnit\Framework\TestCase;

/**
 * AdminBarNotifications turns plugin actions into per-user admin bar entries
 * via the AdminBarNotificationStore. The store itself is tested in file 4 —
 * here we cover the routing logic: who gets notified, when staff actors are
 * skipped, and how the on_staff_reply self-skip works.
 *
 * The test simulates user meta (the store's persistence) so the controller's
 * effects are observable without mocking AdminBarNotificationStore directly.
 */
final class AdminBarNotificationsTest extends TestCase {

	private OrderUpdatesDb&MockInterface $db;
	private AdminBarNotifications $controller;

	/** @var array<int, array<string, mixed>> [user_id => [meta_key => value]] */
	private array $user_meta = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->user_meta = array();
		$this->db        = Mockery::mock( OrderUpdatesDb::class );

		// The customer-submit / staff-reply paths fan out to every staff
		// participant on the thread. Default to "no other participants" so
		// each test exercises only the recipients it cares about; individual
		// tests can override this expectation when participant fan-out is
		// the thing under test.
		$this->db->shouldReceive( 'get_staff_participant_user_ids' )->andReturn( array() )->byDefault();

		// Customer-reply / staff-reply rows embed the note's text. Default to an
		// empty note so the title falls back to the update title; tests that
		// care about the message body set their own expectation.
		$this->db->shouldReceive( 'get_customer_note_by_id' )->andReturn( array() )->byDefault();

		$this->controller = new AdminBarNotifications( $this->db );

		Functions\when( 'get_user_meta' )->alias( fn( $uid, $key ) => $this->user_meta[ $uid ][ $key ] ?? '' );
		Functions\when( 'update_user_meta' )->alias( function ( $uid, $key, $value ) {
			$this->user_meta[ $uid ][ $key ] = $value;
			return true;
		} );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		// StaffEmailPreference::is_muted (called by prune_admin_bar_recipients)
		// reads get_option for the cache TTL.
		Functions\when( 'get_option' )->justReturn( '' );

		// on_assigned / on_mention stamp the actor's display name onto the row.
		Functions\when( 'wp_get_current_user' )->justReturn(
			new class() {
				public string $display_name = 'Tester';
				public function exists(): bool {
					return true;
				}
			}
		);
	}

	protected function tearDown(): void {
		Mockery::close();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function notifications_for( int $user_id ): array {
		return $this->user_meta[ $user_id ]['order_updates_for_woo_notifications'] ?? array();
	}

	public function test_on_assigned_creates_assigned_notification_for_user(): void {
		$this->controller->on_assigned( 5, 100, 'Refund', 7 );

		$rows = $this->notifications_for( 7 );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'assigned', $rows[0]['type'] );
		$this->assertSame( 'Refund', $rows[0]['title'] );
	}

	public function test_on_mention_creates_mention_notification_with_snippet(): void {
		$this->controller->on_mention( 5, 100, 12, 'Hey @ada review this', 7 );

		$rows = $this->notifications_for( 7 );
		$this->assertSame( 'mention', $rows[0]['type'] );
		$this->assertSame( 'Hey @ada review this', $rows[0]['title'] );
	}

	public function test_on_customer_submit_skips_when_note_author_has_shop_caps(): void {
		Functions\when( 'user_can' )->justReturn( true ); // staff actor

		$context = array(
			'note_author'   => array( 'id' => 7 ),
			'order_id'      => 100,
			'assignee_id'   => 9,
			'owner_user_id' => 11,
		);

		$this->controller->on_customer_submit( 5, 12, $context );

		$this->assertSame( array(), $this->notifications_for( 9 ) );
		$this->assertSame( array(), $this->notifications_for( 11 ) );
	}

	public function test_on_customer_submit_notifies_assignee_and_owner_when_actor_is_customer(): void {
		Functions\when( 'user_can' )->justReturn( false );
		$this->db->shouldReceive( 'get_update' )->andReturn( array( 'title' => 'Refund' ) );

		$context = array(
			'note_author'   => array( 'id' => 0 ), // guest
			'order_id'      => 100,
			'assignee_id'   => 9,
			'owner_user_id' => 11,
		);

		$this->controller->on_customer_submit( 5, 12, $context );

		$this->assertCount( 1, $this->notifications_for( 9 ) );
		$this->assertCount( 1, $this->notifications_for( 11 ) );
	}

	public function test_on_customer_submit_skips_muted_recipient(): void {
		// Regression: admin (user 9) flipped "Get notifications" off on
		// update 5. When the customer replies, on_customer_submit was still
		// adding an admin-bar row for them — the toggle should mute BOTH
		// email and admin-bar, not just email.
		Functions\when( 'user_can' )->justReturn( false );
		$this->db->shouldReceive( 'get_update' )->andReturn( array( 'title' => 'Refund' ) );

		// Pre-seed the mute meta for user 9.
		$this->user_meta[9][ Constants::STAFF_EMAIL_MUTED_META_PREFIX . '5' ] = 'yes';

		$context = array(
			'note_author'   => array( 'id' => 0 ),
			'order_id'      => 100,
			'assignee_id'   => 9, // muted
			'owner_user_id' => 11, // not muted
		);

		$this->controller->on_customer_submit( 5, 12, $context );

		$this->assertSame( array(), $this->notifications_for( 9 ), 'Muted user must not receive admin-bar entry.' );
		$this->assertCount( 1, $this->notifications_for( 11 ) );
	}

	public function test_on_customer_submit_dedupes_when_assignee_equals_owner(): void {
		Functions\when( 'user_can' )->justReturn( false );
		$this->db->shouldReceive( 'get_update' )->andReturn( array( 'title' => 'x' ) );

		$context = array(
			'note_author'   => array( 'id' => 0 ),
			'order_id'      => 100,
			'assignee_id'   => 9,
			'owner_user_id' => 9,
		);

		$this->controller->on_customer_submit( 5, 12, $context );

		$this->assertCount( 1, $this->notifications_for( 9 ) );
	}

	public function test_on_staff_reply_skips_the_sender(): void {
		$this->controller->on_staff_reply( 5, 100, 12, 'Bob', 7, array( 7, 9, 11 ) );

		$this->assertSame( array(), $this->notifications_for( 7 ) ); // sender skipped
		$this->assertCount( 1, $this->notifications_for( 9 ) );
		$this->assertCount( 1, $this->notifications_for( 11 ) );
	}

	public function test_on_staff_reply_stores_staff_name_in_title(): void {
		$this->controller->on_staff_reply( 5, 100, 12, 'Bob', 7, array( 9 ) );

		$this->assertSame( 'Bob', $this->notifications_for( 9 )[0]['title'] );
		$this->assertSame( 'staff_reply', $this->notifications_for( 9 )[0]['type'] );
	}
}
