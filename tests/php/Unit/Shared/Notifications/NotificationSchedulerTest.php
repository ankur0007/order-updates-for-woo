<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Tests\Unit\Shared\Notifications;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\MockInterface;
use OrderUpdatesForWoo\Helpers\AsyncJob;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Notifications\NotificationScheduler;
use PHPUnit\Framework\TestCase;
use stdClass;
use WP_REST_Request;

final class NotificationSchedulerTest extends TestCase {

	private AsyncJob&MockInterface $async_job;
	private NotificationScheduler $scheduler;

	/** @var array<int, array{hook:string, payload:array}> */
	private array $queued = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->async_job = Mockery::mock( AsyncJob::class );
		$this->queued    = array();

		$this->async_job->shouldReceive( 'queue' )->andReturnUsing( function ( $hook, $payload ) {
			$this->queued[] = array( 'hook' => $hook, 'payload' => $payload );
			return true;
		} );

		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'get_option' )->justReturn( 'admin@example.test' );
		// By default, no users are muted; per-test can override.
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		// NotificationScheduler now also writes admin-bar entries (for old
		// assignee + creator on reassign) — stub the user-meta + cache
		// write helpers so AdminBarNotificationStore can run without WP.
		Functions\when( 'update_user_meta' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		// Acting-user identification — scheduler stamps the actor onto every
		// queued notification so the recipient can see who triggered the
		// alert. Default to 0 (no logged-in user) for the unit context;
		// tests that care can override.
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		$this->scheduler = new NotificationScheduler( $this->async_job );
	}

	protected function tearDown(): void {
		Mockery::close();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function admin_user( int $id, string $email ): object {
		$u             = new stdClass();
		$u->ID         = $id;
		$u->user_email = $email;
		return $u;
	}

	public function test_create_path_does_not_email_admin_or_creator(): void {
		// Per project_email_flow.md: on create, the actor (creator) never gets
		// emailed about their own action, and the site admin gets no automatic
		// notice either — assignee + customer cover the create case. Only
		// edits trigger the admin/creator branches.
		Functions\when( 'get_user_by' )->justReturn( $this->admin_user( 1, 'admin@example.test' ) );
		Functions\when( 'get_userdata' )->justReturn( false );

		$this->scheduler->schedule_notifications( 5, array( 'assignee_id' => 0 ), array( 'created_by' => 0 ), new WP_REST_Request(), array() );

		$admin_jobs = array_filter( $this->queued, fn( $j ) => Constants::HOOK_ADMIN_NOTIFICATION === $j['hook'] );
		$this->assertCount( 0, $admin_jobs );
	}

	public function test_edit_with_no_assignee_change_uses_updated_context(): void {
		Functions\when( 'get_user_by' )->justReturn( $this->admin_user( 1, 'admin@example.test' ) );
		Functions\when( 'get_userdata' )->justReturn( false );

		$this->scheduler->schedule_notifications(
			5,
			array( 'assignee_id' => 7 ),
			array( 'created_by' => 0 ),
			new WP_REST_Request(),
			array( 'assignee_user_id' => 7 ) // existing → is_edit
		);

		$this->assertSame( 'updated', $this->queued[0]['payload']['context'] );
	}

	public function test_assignee_change_queues_assigned_and_unassigned_for_both_users(): void {
		Functions\when( 'get_user_by' )->justReturn( $this->admin_user( 1, 'admin@example.test' ) );
		Functions\when( 'get_userdata' )->justReturn( false );

		$this->scheduler->schedule_notifications(
			5,
			array( 'assignee_id' => 9 ),
			array( 'created_by' => 0 ),
			new WP_REST_Request(),
			array( 'assignee_user_id' => 7 )
		);

		$assignee_jobs = array_filter( $this->queued, fn( $j ) => Constants::HOOK_ASSIGNEE_NOTIFICATION === $j['hook'] );

		$this->assertCount( 2, $assignee_jobs );
		$contexts = array_map( fn( $j ) => $j['payload']['context'], $assignee_jobs );
		$this->assertContains( 'reassigned', $contexts );
		$this->assertContains( 'unassigned', $contexts );
	}

	public function test_edit_with_separate_creator_emails_both_admin_and_creator(): void {
		// Edits still notify both the site admin and the creator (when their
		// email differs) — staff need a heads-up on changes they didn't
		// directly perform. Create-path skips both branches; this asserts
		// the edit branch still works.
		Functions\when( 'get_user_by' )->justReturn( $this->admin_user( 1, 'admin@example.test' ) );
		$creator             = new stdClass();
		$creator->ID         = 7;
		$creator->user_email = 'creator@example.test';
		Functions\when( 'get_userdata' )->justReturn( $creator );

		$this->scheduler->schedule_notifications(
			5,
			array( 'assignee_id' => 0 ),
			array( 'created_by' => 7 ),
			new WP_REST_Request(),
			array( 'assignee_user_id' => 0 ) // existing → is_edit
		);

		$admin_jobs = array_filter( $this->queued, fn( $j ) => Constants::HOOK_ADMIN_NOTIFICATION === $j['hook'] );
		$this->assertCount( 2, $admin_jobs );
	}

	public function test_edit_with_creator_matching_admin_email_dedupes(): void {
		Functions\when( 'get_user_by' )->justReturn( $this->admin_user( 1, 'admin@example.test' ) );
		$creator             = new stdClass();
		$creator->ID         = 7;
		$creator->user_email = 'admin@example.test';
		Functions\when( 'get_userdata' )->justReturn( $creator );

		$this->scheduler->schedule_notifications(
			5,
			array( 'assignee_id' => 0 ),
			array( 'created_by' => 7 ),
			new WP_REST_Request(),
			array( 'assignee_user_id' => 0 )
		);

		$admin_jobs = array_filter( $this->queued, fn( $j ) => Constants::HOOK_ADMIN_NOTIFICATION === $j['hook'] );
		$this->assertCount( 1, $admin_jobs );
	}

	public function test_edit_with_customer_as_creator_skips_creator_email(): void {
		// Regression: customer-opened updates store the customer's user id in
		// `created_by`. Without the customer-exclusion guard, every staff edit
		// would email the customer the AdminOrderUpdateEmail — which renders
		// internal note bodies. Hard requirement: never email the customer a
		// staff-targeted message.
		Functions\when( 'get_user_by' )->justReturn( $this->admin_user( 1, 'admin@example.test' ) );
		$creator             = new stdClass();
		$creator->ID         = 99;
		$creator->user_email = 'customer@example.test';
		Functions\when( 'get_userdata' )->justReturn( $creator );

		// Mock wc_get_order to return an order whose customer is user 99 (the
		// "creator" of this update — the customer who opened it from the
		// portal). Lookup runs only when function_exists( 'wc_get_order' ),
		// which Brain Monkey's `when` arranges automatically.
		$order = Mockery::mock();
		$order->shouldReceive( 'get_customer_id' )->andReturn( 99 );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->scheduler->schedule_notifications(
			5,
			array( 'assignee_id' => 0 ),
			array( 'created_by' => 99, 'order_id' => 500 ),
			new WP_REST_Request(),
			array( 'assignee_user_id' => 0, 'order_id' => 500 ) // existing → is_edit
		);

		$creator_jobs = array_filter(
			$this->queued,
			static fn( $job ) => Constants::HOOK_ADMIN_NOTIFICATION === $job['hook']
				&& 99 === ( $job['payload']['recipient_user_id'] ?? 0 )
		);
		$this->assertCount( 0, $creator_jobs, 'Customer-as-creator must NEVER receive the admin notification email.' );
	}

	public function test_muted_recipient_skips_their_admin_notification_on_edit(): void {
		Functions\when( 'get_user_by' )->justReturn( $this->admin_user( 1, 'admin@example.test' ) );
		Functions\when( 'get_userdata' )->justReturn( false );
		// Admin (user 1) is muted on update 5.
		Functions\when( 'get_user_meta' )->alias( fn( $uid, $key ) => ( 1 === $uid && Constants::STAFF_EMAIL_MUTED_META_PREFIX . '5' === $key ) ? 'yes' : '' );

		$this->scheduler->schedule_notifications(
			5,
			array( 'assignee_id' => 0 ),
			array( 'created_by' => 0 ),
			new WP_REST_Request(),
			array( 'assignee_user_id' => 0 )
		);

		$admin_jobs = array_filter( $this->queued, fn( $j ) => Constants::HOOK_ADMIN_NOTIFICATION === $j['hook'] );
		$this->assertCount( 0, $admin_jobs );
	}
}
