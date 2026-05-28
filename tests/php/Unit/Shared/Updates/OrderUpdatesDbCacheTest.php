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
 * Cache-invalidation tests for OrderUpdatesDb. Each public write method should
 * fan out to the right set of cache keys — narrow bust on edits that only
 * touch update-row columns, full bust on writes that change notes/history/state.
 *
 * The privates (`invalidate_update_row_cache`, `invalidate_for_update`,
 * `increment_customer_notes_cache_version`) are exercised through the public
 * methods that call them — testing through the public surface so refactors
 * inside the privates don't break these tests.
 */
final class OrderUpdatesDbCacheTest extends TestCase {

	private wpdb&MockInterface $wpdb;
	private OrderUpdatesDb $db;

	/** @var string[] keys passed to wp_cache_delete during the test body */
	private array $deleted_keys = array();

	/** @var array<string, mixed> latest value passed to wp_cache_set per key */
	private array $set_values = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->wpdb         = Mockery::mock( wpdb::class )->makePartial();
		$this->wpdb->prefix = 'wptests_';
		$GLOBALS['wpdb']    = $this->wpdb;

		$this->deleted_keys = array();
		$this->set_values   = array();

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->alias( function ( $key, $value ) {
			$this->set_values[ $key ] = $value;
			return true;
		} );
		Functions\when( 'wp_cache_delete' )->alias( function ( $key ) {
			$this->deleted_keys[] = $key;
			return true;
		} );
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'current_time' )->justReturn( '2026-05-06 12:00:00' );
		Functions\when( 'get_option' )->returnArg( 2 );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$this->db = new OrderUpdatesDb( new UpdatesTable() );
	}

	protected function tearDown(): void {
		Mockery::close();
		Monkey\tearDown();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function arrange_update_lookup( int $update_id, int $order_id ): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $q ) => $q );
		// Tests below call edit_order_update with title='x'. Return the same
		// title from the mock so edit_order_update sees no change — the
		// narrow-bust tests assume notes/history caches stay untouched. The
		// title-change path is exercised by its own dedicated test.
		$this->wpdb->shouldReceive( 'get_row' )->andReturn(
			array( 'id' => $update_id, 'order_id' => $order_id, 'title' => 'x' )
		);
	}

	// edit_order_update — narrow bust ------------------------------------

	public function test_edit_busts_only_the_update_row_and_order_summary_caches(): void {
		$this->arrange_update_lookup( 5, 100 );
		$this->wpdb->shouldReceive( 'update' )->andReturn( 1 );

		$this->db->edit_order_update(
			5,
			array(
				'order_id'         => 100,
				'title'            => 'x',
				'customer_visible' => 1,
				'color'            => '#000',
				'last_updated_by'  => 7,
				'last_updated_at'  => '2026-05-06 12:00:00',
			)
		);

		$this->assertContains( 'update_5', $this->deleted_keys );
		$this->assertContains( 'summary_100', $this->deleted_keys );
		$this->assertContains( 'count_100', $this->deleted_keys );
		$this->assertContains( 'unsolved_order_ids', $this->deleted_keys );
	}

	public function test_edit_does_not_bust_note_history_or_rating_caches(): void {
		$this->arrange_update_lookup( 5, 100 );
		$this->wpdb->shouldReceive( 'update' )->andReturn( 1 );

		$this->db->edit_order_update(
			5,
			array(
				'order_id'         => 100,
				'title'            => 'x',
				'customer_visible' => 1,
				'color'            => '#000',
				'last_updated_by'  => 7,
				'last_updated_at'  => '2026-05-06 12:00:00',
			)
		);

		$this->assertNotContains( 'notes_5', $this->deleted_keys );
		$this->assertNotContains( 'customer_notes_5', $this->deleted_keys );
		$this->assertNotContains( 'history_5', $this->deleted_keys );
		$this->assertNotContains( 'rating_5', $this->deleted_keys );
		$this->assertArrayNotHasKey( 'customer_notes_ver_5', $this->set_values );
	}

	// delete_order_update — full bust ------------------------------------

	public function test_delete_busts_full_set_of_update_caches(): void {
		$this->arrange_update_lookup( 5, 100 );
		$this->wpdb->shouldReceive( 'delete' )->andReturn( 1 );

		$this->db->delete_order_update( 5 );

		foreach ( array( 'update_5', 'notes_5', 'customer_notes_5', 'history_5', 'rating_5' ) as $key ) {
			$this->assertContains( $key, $this->deleted_keys, "expected {$key} to be busted" );
		}
		$this->assertContains( 'summary_100', $this->deleted_keys );
		$this->assertContains( 'unsolved_order_ids', $this->deleted_keys );
	}

	public function test_delete_increments_customer_notes_cache_version(): void {
		$this->arrange_update_lookup( 5, 100 );
		$this->wpdb->shouldReceive( 'delete' )->andReturn( 1 );

		$this->db->delete_order_update( 5 );

		$this->assertSame( 1, $this->set_values['customer_notes_ver_5'] ?? null );
	}

	public function test_delete_increments_order_updates_cache_version(): void {
		$this->arrange_update_lookup( 5, 100 );
		$this->wpdb->shouldReceive( 'delete' )->andReturn( 1 );

		$this->db->delete_order_update( 5 );

		$this->assertSame( 1, $this->set_values['order_updates_ver_100'] ?? null );
	}

	// Customer-note writes — per-note key bust ---------------------------

	public function test_update_customer_note_busts_per_note_cache_key(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $q ) => $q );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn(
			array( 'id' => 99, 'update_id' => 5, 'order_id' => 100 )
		);
		$this->wpdb->shouldReceive( 'update' )->andReturn( 1 );

		$this->db->update_customer_note( 99, 'edited', '2026-05-06 12:00:00' );

		$this->assertContains( 'customer_note_99', $this->deleted_keys );
	}

	public function test_delete_customer_note_busts_per_note_cache_key(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $q ) => $q );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn(
			array( 'id' => 99, 'update_id' => 5, 'order_id' => 100 )
		);
		$this->wpdb->shouldReceive( 'delete' )->andReturn( 1 );

		$this->db->delete_customer_note( 99 );

		$this->assertContains( 'customer_note_99', $this->deleted_keys );
	}

	public function test_mark_customer_note_queued_busts_per_note_cache_key(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $q ) => $q );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn(
			array( 'id' => 99, 'update_id' => 5, 'order_id' => 100 )
		);
		$this->wpdb->shouldReceive( 'update' )->andReturn( 1 );

		$this->db->mark_customer_note_queued( 99, '2026-05-06 12:00:00' );

		$this->assertContains( 'customer_note_99', $this->deleted_keys );
	}

	public function test_mark_customer_note_notified_busts_per_note_cache_key(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $q ) => $q );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn(
			array( 'id' => 99, 'update_id' => 5, 'order_id' => 100 )
		);
		$this->wpdb->shouldReceive( 'update' )->andReturn( 1 );

		$this->db->mark_customer_note_notified( 99, '2026-05-06 12:00:00' );

		$this->assertContains( 'customer_note_99', $this->deleted_keys );
	}

	// Customer-note write bumps the parent update's notes-version --------

	public function test_create_customer_note_increments_customer_notes_cache_version(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $q ) => $q );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn(
			array( 'id' => 5, 'order_id' => 100 )
		);
		$this->wpdb->shouldReceive( 'insert' )->andReturn( 1 );
		$this->wpdb->insert_id = 99;

		$this->db->create_customer_note( 5, 'hello', 7, 'Ada', '2026-05-06 12:00:00' );

		$this->assertSame( 1, $this->set_values['customer_notes_ver_5'] ?? null );
	}

	// Mark-solved triggers full bust including notes-version --------------

	public function test_mark_as_solved_full_busts_and_increments_notes_version(): void {
		$this->arrange_update_lookup( 5, 100 );
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array() );
		$this->wpdb->shouldReceive( 'update' )->andReturn( 1 );

		$this->db->mark_as_solved( 5, 7, '2026-05-06 12:00:00' );

		$this->assertContains( 'update_5', $this->deleted_keys );
		$this->assertContains( 'notes_5', $this->deleted_keys );
		$this->assertContains( 'history_5', $this->deleted_keys );
		$this->assertContains( 'rating_5', $this->deleted_keys );
		$this->assertSame( 1, $this->set_values['customer_notes_ver_5'] ?? null );
	}
}
