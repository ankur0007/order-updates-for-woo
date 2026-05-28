<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Tests\Unit\Shared\Updates;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\MockInterface;
use OrderUpdatesForWoo\Helpers\AsyncJob;
use OrderUpdatesForWoo\Helpers\ParticipantResolver;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use OrderUpdatesForWoo\Shared\Updates\UpdateNoteService;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WP_User;

final class UpdateNoteServiceTest extends TestCase {

	private OrderUpdatesDb&MockInterface $db;
	private AsyncJob&MockInterface $async_job;
	private UpdateNoteService $service;

	/** @var array<int, array{hook:string, payload:array}> */
	private array $queued_jobs = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->db        = Mockery::mock( OrderUpdatesDb::class );
		$this->async_job = Mockery::mock( AsyncJob::class );
		$this->queued_jobs = array();

		$this->async_job->shouldReceive( 'queue' )->andReturnUsing( function ( $hook, $payload ) {
			$this->queued_jobs[] = array( 'hook' => $hook, 'payload' => $payload );
			return true;
		} );

		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'current_time' )->justReturn( '2026-05-06 12:00:00' );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( '__' )->returnArg();

		$this->service = new UpdateNoteService( $this->db, $this->async_job );
	}

	protected function tearDown(): void {
		Mockery::close();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function arrange_current_user( int $id, string $first = '', string $last = '', string $display = '', string $email = '' ): void {
		$user               = new WP_User();
		$user->ID           = $id;
		$user->display_name = $display;
		$user->user_email   = $email;

		Functions\when( 'wp_get_current_user' )->justReturn( $user );
		Functions\when( 'get_user_meta' )->alias( function ( $user_id, $key ) use ( $first, $last ) {
			return match ( $key ) {
				'first_name' => $first,
				'last_name'  => $last,
				default      => '',
			};
		} );
	}

	// get_current_note_author --------------------------------------------

	public function test_resolves_name_from_first_and_last_when_both_present(): void {
		$this->arrange_current_user( 7, 'Ada', 'Lovelace', 'ada_l', 'ada@example.com' );

		$author = $this->service->get_current_note_author();

		$this->assertSame( 7, $author['id'] );
		$this->assertSame( 'Ada Lovelace', $author['name'] );
	}

	public function test_falls_back_to_display_name_when_first_and_last_blank(): void {
		$this->arrange_current_user( 7, '', '', 'ada_l', 'ada@example.com' );

		$this->assertSame( 'ada_l', $this->service->get_current_note_author()['name'] );
	}

	public function test_falls_back_to_email_when_display_name_also_blank(): void {
		$this->arrange_current_user( 7, '', '', '', 'ada@example.com' );

		$this->assertSame( 'ada@example.com', $this->service->get_current_note_author()['name'] );
	}

	// get_note_author_for_customer_submit --------------------------------

	public function test_logged_in_customer_resolves_via_current_user(): void {
		$this->arrange_current_user( 42, 'Grace', 'Hopper', '', '' );
		Functions\when( 'get_current_user_id' )->justReturn( 42 );

		$order = Mockery::mock( WC_Order::class );

		$author = $this->service->get_note_author_for_customer_submit( $order );

		$this->assertSame( 42, $author['id'] );
		$this->assertSame( 'Grace Hopper', $author['name'] );
	}

	public function test_guest_uses_billing_first_and_last_name(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_billing_first_name' )->andReturn( 'Linus' );
		$order->shouldReceive( 'get_billing_last_name' )->andReturn( 'Torvalds' );

		$author = $this->service->get_note_author_for_customer_submit( $order );

		$this->assertSame( 0, $author['id'] );
		$this->assertSame( 'Linus Torvalds', $author['name'] );
	}

	public function test_guest_falls_back_to_billing_email_when_name_blank(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_billing_first_name' )->andReturn( '' );
		$order->shouldReceive( 'get_billing_last_name' )->andReturn( '' );
		$order->shouldReceive( 'get_billing_email' )->andReturn( 'guest@example.com' );

		$this->assertSame( 'guest@example.com', $this->service->get_note_author_for_customer_submit( $order )['name'] );
	}

	public function test_guest_falls_back_to_translated_you_when_everything_blank(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_billing_first_name' )->andReturn( '' );
		$order->shouldReceive( 'get_billing_last_name' )->andReturn( '' );
		$order->shouldReceive( 'get_billing_email' )->andReturn( '' );

		$this->assertSame( 'You', $this->service->get_note_author_for_customer_submit( $order )['name'] );
	}

	// queue_mention_emails (via create_internal_note) --------------------

	public function test_each_mention_queues_one_internal_mention_job(): void {
		$this->arrange_current_user( 7, 'Ada', 'Lovelace', '', '' );
		$this->db->shouldReceive( 'create_update_note' )->once()->andReturn( 99 );
		$this->db->shouldReceive( 'invalidate_mention_caches' )->once();
		$this->db->shouldReceive( 'get_update' )->andReturn( array( 'id' => 5, 'order_id' => 100 ) );

		$this->service->create_internal_note( 5, 'hey @bob @carol', array( 11, 12 ) );

		$this->assertCount( 2, $this->queued_jobs );
		$this->assertSame( Constants::HOOK_INTERNAL_MENTION, $this->queued_jobs[0]['hook'] );
		$this->assertSame( 11, $this->queued_jobs[0]['payload']['recipient_user_id'] );
		$this->assertSame( 12, $this->queued_jobs[1]['payload']['recipient_user_id'] );
		$this->assertSame( 7, $this->queued_jobs[0]['payload']['mentioned_by_id'] );
	}

	public function test_self_mention_is_skipped_no_email_queued(): void {
		$this->arrange_current_user( 7, 'Ada', 'Lovelace', '', '' );
		$this->db->shouldReceive( 'create_update_note' )->once()->andReturn( 99 );
		$this->db->shouldReceive( 'invalidate_mention_caches' )->once();
		$this->db->shouldReceive( 'get_update' )->andReturn( array( 'id' => 5, 'order_id' => 100 ) );

		// Author is user 7; mentions list contains 7 (self) and 11.
		$this->service->create_internal_note( 5, 'self mention', array( 7, 11 ) );

		$this->assertCount( 1, $this->queued_jobs );
		$this->assertSame( 11, $this->queued_jobs[0]['payload']['recipient_user_id'] );
	}

	public function test_no_mentions_queues_no_jobs(): void {
		$this->arrange_current_user( 7, 'Ada', 'Lovelace', '', '' );
		$this->db->shouldReceive( 'create_update_note' )->once()->andReturn( 99 );

		$this->service->create_internal_note( 5, 'plain note', array() );

		$this->assertCount( 0, $this->queued_jobs );
	}

	// Participant fan-out --------------------------------------------------

	private function build_service_with_participants( array $participant_ids ): UpdateNoteService {
		$resolver = Mockery::mock( ParticipantResolver::class );
		$resolver->shouldReceive( 'ids_for' )->andReturn( $participant_ids );

		// AdminBarNotificationStore::add_participant_reply (called per recipient)
		// touches user_meta + the object cache; StaffEmailPreference::is_muted
		// reads get_option for cache TTL. Stub the side-effect functions only —
		// individual tests are free to arrange their own get_user_meta alias.
		Functions\when( 'update_user_meta' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( '' );

		return new UpdateNoteService( $this->db, $this->async_job, $resolver );
	}

	public function test_internal_note_fans_out_to_participants_minus_actor_and_mentioned(): void {
		$this->arrange_current_user( 7, 'Ada', '', '', '' );
		$this->db->shouldReceive( 'create_update_note' )->once()->andReturn( 99 );
		$this->db->shouldReceive( 'invalidate_mention_caches' )->once();
		$this->db->shouldReceive( 'get_update' )->andReturn( array( 'id' => 5, 'order_id' => 100 ) );

		// Stub the StaffEmailPreference cache layer — no opt-outs.
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'get_user_meta' )->justReturn( '' );

		// Participants: 7 (actor — skipped), 11 (mentioned — skipped), 12 (mentioned — skipped),
		// 21 (creator-or-prior-replier — gets participant email), 22 (likewise).
		$service = $this->build_service_with_participants( array( 7, 11, 12, 21, 22 ) );

		$service->create_internal_note( 5, 'hey @bob @carol', array( 11, 12 ) );

		// Expected: 2 mention jobs (11, 12) + 2 participant jobs (21, 22).
		$participant_jobs = array_values( array_filter( $this->queued_jobs, static fn( $j ) => Constants::HOOK_PARTICIPANT_UPDATE === $j['hook'] ) );
		$this->assertCount( 2, $participant_jobs );
		$this->assertSame( array( 21, 22 ), array_map( static fn( $j ) => $j['payload']['recipient_user_id'], $participant_jobs ) );
		$this->assertSame( Constants::NOTE_TYPE_INTERNAL, $participant_jobs[0]['payload']['note_type'] );
		$this->assertSame( 7, $participant_jobs[0]['payload']['actor_user_id'] );
	}

	public function test_internal_note_without_mentions_still_fans_out_to_participants(): void {
		$this->arrange_current_user( 7, 'Ada', '', '', '' );
		$this->db->shouldReceive( 'create_update_note' )->once()->andReturn( 99 );
		$this->db->shouldReceive( 'get_update' )->andReturn( array( 'id' => 5, 'order_id' => 100 ) );

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'get_user_meta' )->justReturn( '' );

		$service = $this->build_service_with_participants( array( 7, 21 ) );

		$service->create_internal_note( 5, 'plain reply', array() );

		$participant_jobs = array_values( array_filter( $this->queued_jobs, static fn( $j ) => Constants::HOOK_PARTICIPANT_UPDATE === $j['hook'] ) );
		$this->assertCount( 1, $participant_jobs );
		$this->assertSame( 21, $participant_jobs[0]['payload']['recipient_user_id'] );
	}

	public function test_internal_note_skips_participant_who_opted_out(): void {
		$this->arrange_current_user( 7, 'Ada', '', '', '' );
		$this->db->shouldReceive( 'create_update_note' )->once()->andReturn( 99 );
		$this->db->shouldReceive( 'get_update' )->andReturn( array( 'id' => 5, 'order_id' => 100 ) );

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );

		$service = $this->build_service_with_participants( array( 7, 21, 22 ) );

		// User 21 has the mute meta set; user 22 does not. Arrange after the
		// service builder so this alias wins over any defaults it set.
		Functions\when( 'get_user_meta' )->alias( function ( $user_id, $meta_key ) {
			if ( str_starts_with( (string) $meta_key, Constants::STAFF_EMAIL_MUTED_META_PREFIX ) ) {
				return 21 === $user_id ? 'yes' : '';
			}
			return '';
		} );

		$service->create_internal_note( 5, 'note', array() );

		$participant_jobs = array_values( array_filter( $this->queued_jobs, static fn( $j ) => Constants::HOOK_PARTICIPANT_UPDATE === $j['hook'] ) );
		$this->assertCount( 1, $participant_jobs );
		$this->assertSame( 22, $participant_jobs[0]['payload']['recipient_user_id'] );
	}
}
