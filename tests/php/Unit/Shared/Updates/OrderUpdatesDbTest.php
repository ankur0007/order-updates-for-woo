<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Tests\Unit\Shared\Updates;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\MockInterface;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use OrderUpdatesForWoo\Shared\Updates\UpdatesTable;
use PHPUnit\Framework\TestCase;
use wpdb;

/**
 * Contract tests for OrderUpdatesDb write paths.
 *
 * Scope: every public write method's argument shape, return value, and
 * early-return guards. Cache-invalidation behaviour is covered separately
 * in OrderUpdatesDbCacheTest.
 */
final class OrderUpdatesDbTest extends TestCase {

	private wpdb&MockInterface $wpdb;
	private OrderUpdatesDb $db;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->wpdb         = Mockery::mock( wpdb::class )->makePartial();
		$this->wpdb->prefix = 'wptests_';
		$GLOBALS['wpdb']    = $this->wpdb;

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'current_time' )->justReturn( '2026-05-06 12:00:00' );
		Functions\when( 'get_option' )->returnArg( 2 );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		// New in this version: sync_assignee writes a system note into the
		// customer thread describing the change. That path passes the new
		// assignee's display name through __() and reads their record via
		// get_userdata — stub both so the test setup doesn't fall through
		// to live WP functions that aren't bootstrapped here.
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'get_userdata' )->justReturn( false );

		$this->db = new OrderUpdatesDb( new UpdatesTable() );
	}

	protected function tearDown(): void {
		Mockery::close();
		Monkey\tearDown();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function update_data( array $overrides = array() ): array {
		return array_merge(
			array(
				'order_id'         => 100,
				'title'            => 'Refund question',
				'customer_visible' => 1,
				'color'            => '#ff8800',
				'created_by'       => 7,
				'created_at'       => '2026-05-06 12:00:00',
			),
			$overrides
		);
	}

	// create_order_update -------------------------------------------------

	public function test_create_order_update_returns_insert_id_on_success(): void {
		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->with( 'wptests_order_updates_for_woo', Mockery::type( 'array' ), Mockery::type( 'array' ) )
			->andReturn( 1 );
		$this->wpdb->insert_id = 42;

		$this->assertSame( 42, $this->db->create_order_update( $this->update_data() ) );
	}

	public function test_create_order_update_returns_zero_when_insert_fails(): void {
		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( false );
		$this->wpdb->insert_id = 0;

		$this->assertSame( 0, $this->db->create_order_update( $this->update_data() ) );
	}

	// edit_order_update ---------------------------------------------------

	public function test_edit_order_update_with_zero_id_returns_false_without_query(): void {
		$this->wpdb->shouldNotReceive( 'update' );

		$this->assertFalse( $this->db->edit_order_update( 0, $this->update_data() ) );
	}

	public function test_edit_order_update_returns_true_on_successful_update(): void {
		$data = $this->update_data( array( 'last_updated_by' => 7, 'last_updated_at' => '2026-05-06 12:00:00' ) );

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with( 'wptests_order_updates_for_woo', Mockery::type( 'array' ), array( 'id' => 5 ), Mockery::type( 'array' ), array( '%d' ) )
			->andReturn( 1 );

		$this->assertTrue( $this->db->edit_order_update( 5, $data ) );
	}

	public function test_edit_order_update_returns_false_when_db_signals_failure(): void {
		$data = $this->update_data( array( 'last_updated_by' => 7, 'last_updated_at' => '2026-05-06 12:00:00' ) );

		$this->wpdb->shouldReceive( 'update' )->once()->andReturn( false );

		$this->assertFalse( $this->db->edit_order_update( 5, $data ) );
	}

	// delete_order_update -------------------------------------------------

	public function test_delete_order_update_with_zero_id_returns_false_without_query(): void {
		$this->wpdb->shouldNotReceive( 'delete' );

		$this->assertFalse( $this->db->delete_order_update( 0 ) );
	}

	public function test_delete_order_update_cascades_to_related_tables(): void {
		// Parent get_update() lookup happens first.
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $q ) => $q );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( array( 'id' => 5, 'order_id' => 100 ) );

		// One delete on the parent updates table, then four cascades.
		$this->wpdb->shouldReceive( 'delete' )
			->with( 'wptests_order_updates_for_woo', array( 'id' => 5 ), array( '%d' ) )
			->once()->andReturn( 1 );
		$this->wpdb->shouldReceive( 'delete' )->with( 'wptests_order_updates_for_woo_assignees', array( 'update_id' => 5 ), array( '%d' ) )->once()->andReturn( 1 );
		$this->wpdb->shouldReceive( 'delete' )->with( 'wptests_order_updates_for_woo_internal_notes', array( 'update_id' => 5 ), array( '%d' ) )->once()->andReturn( 1 );
		$this->wpdb->shouldReceive( 'delete' )->with( 'wptests_order_updates_for_woo_customer_notes', array( 'update_id' => 5 ), array( '%d' ) )->once()->andReturn( 1 );
		$this->wpdb->shouldReceive( 'delete' )->with( 'wptests_order_updates_for_woo_ratings', array( 'update_id' => 5 ), array( '%d' ) )->once()->andReturn( 1 );

		$this->assertTrue( $this->db->delete_order_update( 5 ) );
	}

	public function test_delete_order_update_skips_cascade_when_parent_delete_fails(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $q ) => $q );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( array( 'id' => 5, 'order_id' => 100 ) );
		$this->wpdb->shouldReceive( 'delete' )
			->with( 'wptests_order_updates_for_woo', array( 'id' => 5 ), array( '%d' ) )
			->once()->andReturn( false );
		$this->wpdb->shouldNotReceive( 'delete' )->with( 'wptests_order_updates_for_woo_assignees', Mockery::any(), Mockery::any() );

		$this->assertFalse( $this->db->delete_order_update( 5 ) );
	}

	// create_customer_note ------------------------------------------------

	public function test_create_customer_note_with_zero_update_id_returns_zero(): void {
		$this->wpdb->shouldNotReceive( 'insert' );

		$this->assertSame( 0, $this->db->create_customer_note( 0, 'hello', 7, 'Ada', '2026-05-06 12:00:00' ) );
	}

	public function test_create_customer_note_with_empty_note_returns_zero(): void {
		$this->wpdb->shouldNotReceive( 'insert' );

		$this->assertSame( 0, $this->db->create_customer_note( 5, '', 7, 'Ada', '2026-05-06 12:00:00' ) );
	}

	public function test_create_customer_note_returns_insert_id_on_success(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $q ) => $q );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( array( 'id' => 5, 'order_id' => 100 ) );
		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->with( 'wptests_order_updates_for_woo_customer_notes', Mockery::type( 'array' ), Mockery::type( 'array' ) )
			->andReturn( 1 );
		$this->wpdb->insert_id = 99;

		$this->assertSame( 99, $this->db->create_customer_note( 5, 'hello', 7, 'Ada', '2026-05-06 12:00:00' ) );
	}

	// update_customer_note ------------------------------------------------

	public function test_update_customer_note_with_zero_note_id_returns_false(): void {
		$this->wpdb->shouldNotReceive( 'update' );

		$this->assertFalse( $this->db->update_customer_note( 0, 'edited', '2026-05-06 12:00:00' ) );
	}

	public function test_update_customer_note_with_empty_note_returns_false(): void {
		$this->wpdb->shouldNotReceive( 'update' );

		$this->assertFalse( $this->db->update_customer_note( 99, '', '2026-05-06 12:00:00' ) );
	}

	public function test_update_customer_note_with_empty_edited_at_returns_false(): void {
		$this->wpdb->shouldNotReceive( 'update' );

		$this->assertFalse( $this->db->update_customer_note( 99, 'edited', '' ) );
	}

	public function test_update_customer_note_returns_true_on_success(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $q ) => $q );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( array( 'id' => 99, 'update_id' => 5 ) );
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with( 'wptests_order_updates_for_woo_customer_notes', array( 'note' => 'edited', 'edited_at' => '2026-05-06 12:00:00' ), array( 'id' => 99 ), array( '%s', '%s' ), array( '%d' ) )
			->andReturn( 1 );

		$this->assertTrue( $this->db->update_customer_note( 99, 'edited', '2026-05-06 12:00:00' ) );
	}

	// delete_customer_note ------------------------------------------------

	public function test_delete_customer_note_with_zero_note_id_returns_false(): void {
		$this->wpdb->shouldNotReceive( 'delete' );

		$this->assertFalse( $this->db->delete_customer_note( 0 ) );
	}

	public function test_delete_customer_note_returns_true_on_success(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $q ) => $q );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( array( 'id' => 99, 'update_id' => 5 ) );
		$this->wpdb->shouldReceive( 'delete' )
			->once()
			->with( 'wptests_order_updates_for_woo_customer_notes', array( 'id' => 99 ), array( '%d' ) )
			->andReturn( 1 );

		$this->assertTrue( $this->db->delete_customer_note( 99 ) );
	}

	// mark_customer_note_queued -------------------------------------------

	public function test_mark_customer_note_queued_with_zero_note_id_returns_false(): void {
		$this->wpdb->shouldNotReceive( 'update' );

		$this->assertFalse( $this->db->mark_customer_note_queued( 0, '2026-05-06 12:00:00' ) );
	}

	public function test_mark_customer_note_queued_with_empty_timestamp_returns_false(): void {
		$this->wpdb->shouldNotReceive( 'update' );

		$this->assertFalse( $this->db->mark_customer_note_queued( 99, '' ) );
	}

	public function test_mark_customer_note_queued_writes_queued_at_column(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $q ) => $q );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( array( 'id' => 99, 'update_id' => 5 ) );
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with( 'wptests_order_updates_for_woo_customer_notes', array( 'queued_at' => '2026-05-06 12:00:00' ), array( 'id' => 99 ), array( '%s' ), array( '%d' ) )
			->andReturn( 1 );

		$this->assertTrue( $this->db->mark_customer_note_queued( 99, '2026-05-06 12:00:00' ) );
	}

	// mark_customer_note_notified -----------------------------------------

	public function test_mark_customer_note_notified_with_zero_note_id_returns_false(): void {
		$this->wpdb->shouldNotReceive( 'update' );

		$this->assertFalse( $this->db->mark_customer_note_notified( 0, '2026-05-06 12:00:00' ) );
	}

	public function test_mark_customer_note_notified_with_empty_timestamp_returns_false(): void {
		$this->wpdb->shouldNotReceive( 'update' );

		$this->assertFalse( $this->db->mark_customer_note_notified( 99, '' ) );
	}

	public function test_mark_customer_note_notified_writes_notified_at_column(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $q ) => $q );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( array( 'id' => 99, 'update_id' => 5 ) );
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with( 'wptests_order_updates_for_woo_customer_notes', array( 'notified_at' => '2026-05-06 12:00:00' ), array( 'id' => 99 ), array( '%s' ), array( '%d' ) )
			->andReturn( 1 );

		$this->assertTrue( $this->db->mark_customer_note_notified( 99, '2026-05-06 12:00:00' ) );
	}

	// mark_as_solved ------------------------------------------------------

	public function test_mark_as_solved_with_zero_update_id_returns_false(): void {
		$this->wpdb->shouldNotReceive( 'update' );

		$this->assertFalse( $this->db->mark_as_solved( 0, 7, '2026-05-06 12:00:00' ) );
	}

	public function test_mark_as_solved_with_zero_solver_id_returns_false(): void {
		$this->wpdb->shouldNotReceive( 'update' );

		$this->assertFalse( $this->db->mark_as_solved( 5, 0, '2026-05-06 12:00:00' ) );
	}

	public function test_mark_as_solved_writes_resolution_columns_on_success(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $q ) => $q );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( array( 'id' => 5, 'order_id' => 100 ) );
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array() );
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with(
				'wptests_order_updates_for_woo',
				array( 'is_resolved' => 1, 'solved_by' => 7, 'solved_at' => '2026-05-06 12:00:00', 'last_updated_at' => '2026-05-06 12:00:00' ),
				array( 'id' => 5 ),
				array( '%d', '%d', '%s', '%s' ),
				array( '%d' )
			)
			->andReturn( 1 );

		$this->assertTrue( $this->db->mark_as_solved( 5, 7, '2026-05-06 12:00:00' ) );
	}

	// mark_as_unsolved ----------------------------------------------------

	public function test_mark_as_unsolved_with_zero_update_id_returns_false(): void {
		$this->wpdb->shouldNotReceive( 'update' );

		$this->assertFalse( $this->db->mark_as_unsolved( 0 ) );
	}

	public function test_mark_as_unsolved_clears_resolved_flag_on_success(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $q ) => $q );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( array( 'id' => 5, 'order_id' => 100 ) );
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array() );
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with(
				'wptests_order_updates_for_woo',
				array( 'is_resolved' => 0, 'last_updated_by' => 7, 'last_updated_at' => '2026-05-06 12:00:00' ),
				array( 'id' => 5 ),
				array( '%d', '%s', '%s' ),
				array( '%d' )
			)
			->andReturn( 1 );

		$this->assertTrue( $this->db->mark_as_unsolved( 5, 7 ) );
	}

	// create_assignee -----------------------------------------------------

	public function test_create_assignee_returns_true_on_successful_insert(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $q ) => $q );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( array( 'id' => 5, 'order_id' => 100 ) );
		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->with(
				'wptests_order_updates_for_woo_assignees',
				array( 'update_id' => 5, 'assignee_user_id' => 7, 'assigned_by' => 9, 'assigned_at' => '2026-05-06 12:00:00', 'is_active' => 1, 'last_updated_at' => '2026-05-06 12:00:00' ),
				array( '%d', '%d', '%d', '%s', '%d', '%s' )
			)
			->andReturn( 1 );

		$this->assertTrue( $this->db->create_assignee( 5, 7, 9, '2026-05-06 12:00:00' ) );
	}

	public function test_create_assignee_returns_false_when_insert_fails(): void {
		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( false );

		$this->assertFalse( $this->db->create_assignee( 5, 7, 9, '2026-05-06 12:00:00' ) );
	}

	// sync_assignee -------------------------------------------------------

	public function test_sync_assignee_is_noop_when_assignee_unchanged(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $q ) => $q );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( array( 'id' => 5, 'order_id' => 100, 'assignee_user_id' => 7 ) );
		$this->wpdb->shouldNotReceive( 'insert' );
		$this->wpdb->shouldNotReceive( 'update' );

		$this->assertTrue( $this->db->sync_assignee( 5, 7, 9, '2026-05-06 12:00:00' ) );
	}

	public function test_sync_assignee_unassigns_without_inserting_new_assignee_row(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $q ) => $q );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( array( 'id' => 5, 'order_id' => 100, 'assignee_user_id' => 7, 'assignee_name' => 'Ada' ) );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '0' );
		// Deactivate the existing assignee row on the assignees table — no
		// new row inserted on the assignees table since we're unassigning.
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with( 'wptests_order_updates_for_woo_assignees', Mockery::type( 'array' ), array( 'update_id' => 5, 'is_active' => 1 ), Mockery::type( 'array' ), Mockery::type( 'array' ) )
			->andReturn( 1 );
		// A system row IS inserted into customer_notes describing the
		// unassignment — checked separately so the customer-side timeline
		// reflects the change.
		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->with( 'wptests_order_updates_for_woo_customer_notes', Mockery::on( fn( $row ) => is_array( $row ) && ( $row['kind'] ?? '' ) === 'assignee_change' ), Mockery::type( 'array' ) )
			->andReturn( 1 );

		$this->assertTrue( $this->db->sync_assignee( 5, 0, 9, '2026-05-06 12:00:00' ) );
	}

	public function test_sync_assignee_inserts_new_row_when_previously_unassigned(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $q ) => $q );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( array( 'id' => 5, 'order_id' => 100, 'assignee_user_id' => 0 ) );
		// The system-row insert only happens when we can name the new
		// assignee — otherwise the timeline entry would be blank. Mock
		// get_userdata so the helper can resolve the display name.
		$user             = new \WP_User();
		$user->ID         = 7;
		$user->display_name = 'Bob';
		Functions\when( 'get_userdata' )->justReturn( $user );

		$this->wpdb->shouldReceive( 'insert' )
			->with( 'wptests_order_updates_for_woo_assignees', Mockery::type( 'array' ), Mockery::type( 'array' ) )
			->once()
			->andReturn( 1 );
		$this->wpdb->shouldReceive( 'insert' )
			->with( 'wptests_order_updates_for_woo_customer_notes', Mockery::on( fn( $row ) => is_array( $row ) && ( $row['kind'] ?? '' ) === 'assignee_change' ), Mockery::type( 'array' ) )
			->once()
			->andReturn( 1 );

		$this->assertTrue( $this->db->sync_assignee( 5, 7, 9, '2026-05-06 12:00:00' ) );
	}
}
