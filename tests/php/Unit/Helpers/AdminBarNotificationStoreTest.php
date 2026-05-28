<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Tests\Unit\Helpers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OrderUpdatesForWoo\Helpers\AdminBarNotificationStore;
use PHPUnit\Framework\TestCase;

/**
 * AdminBarNotificationStore is a static class backed by user meta + object
 * cache. Tests simulate both layers via in-memory arrays so reads see writes
 * and cache hits / misses are observable.
 */
final class AdminBarNotificationStoreTest extends TestCase {

	private const USER_ID  = 7;
	private const META_KEY = 'order_updates_for_woo_notifications';

	/** @var array<int, array<string, mixed>> [user_id => [meta_key => value]] */
	private array $user_meta = array();

	/** @var array<string, mixed> object cache */
	private array $cache = array();

	/** @var int incremented every time get_user_meta is called — proxy for "DB hit" */
	private int $get_user_meta_calls = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->user_meta           = array();
		$this->cache               = array();
		$this->get_user_meta_calls = 0;

		Functions\when( 'get_user_meta' )->alias( function ( $user_id, $meta_key, $single = false ) {
			$this->get_user_meta_calls++;
			return $this->user_meta[ $user_id ][ $meta_key ] ?? '';
		} );
		Functions\when( 'update_user_meta' )->alias( function ( $user_id, $meta_key, $value ) {
			$this->user_meta[ $user_id ][ $meta_key ] = $value;
			return true;
		} );
		Functions\when( 'wp_cache_get' )->alias( fn( $key ) => $this->cache[ $key ] ?? false );
		Functions\when( 'wp_cache_set' )->alias( function ( $key, $value ) {
			$this->cache[ $key ] = $value;
			return true;
		} );
		Functions\when( 'wp_cache_delete' )->alias( function ( $key ) {
			unset( $this->cache[ $key ] );
			return true;
		} );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function stored(): array {
		return $this->user_meta[ self::USER_ID ][ self::META_KEY ] ?? array();
	}

	// add_* — shape and validation ---------------------------------------

	public function test_add_assigned_stores_notification_with_expected_shape(): void {
		AdminBarNotificationStore::add_assigned( 5, 100, 'Refund question', self::USER_ID );

		$rows = $this->stored();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'assigned_5', $rows[0]['key'] );
		$this->assertSame( 'assigned', $rows[0]['type'] );
		$this->assertSame( 5, $rows[0]['update_id'] );
		$this->assertSame( 100, $rows[0]['order_id'] );
		$this->assertSame( 0, $rows[0]['note_id'] );
		$this->assertSame( 'Refund question', $rows[0]['title'] );
	}

	public function test_add_assigned_is_noop_when_update_id_is_zero(): void {
		AdminBarNotificationStore::add_assigned( 0, 100, 'x', self::USER_ID );

		$this->assertSame( array(), $this->stored() );
	}

	public function test_add_customer_reply_requires_note_id(): void {
		AdminBarNotificationStore::add_customer_reply( 5, 100, 0, 'x', self::USER_ID );

		$this->assertSame( array(), $this->stored() );
	}

	public function test_add_staff_reply_rejects_whitespace_only_staff_name(): void {
		AdminBarNotificationStore::add_staff_reply( 5, 100, 12, '   ', self::USER_ID );

		$this->assertSame( array(), $this->stored() );
	}

	public function test_add_staff_reply_stores_name_in_title_field(): void {
		AdminBarNotificationStore::add_staff_reply( 5, 100, 12, 'Bob', self::USER_ID );

		$rows = $this->stored();
		$this->assertSame( 'staff_reply_12', $rows[0]['key'] );
		$this->assertSame( 'staff_reply', $rows[0]['type'] );
		$this->assertSame( 'Bob', $rows[0]['title'] );
	}

	public function test_add_mention_stores_snippet_in_title_field(): void {
		AdminBarNotificationStore::add_mention( 5, 100, 12, 'Hey @ada take a look', self::USER_ID );

		$rows = $this->stored();
		$this->assertSame( 'mention_12', $rows[0]['key'] );
		$this->assertSame( 'mention', $rows[0]['type'] );
		$this->assertSame( 'Hey @ada take a look', $rows[0]['title'] );
	}

	// Duplicate key + cap -------------------------------------------------

	public function test_duplicate_key_is_silently_ignored(): void {
		AdminBarNotificationStore::add_assigned( 5, 100, 'first', self::USER_ID );
		AdminBarNotificationStore::add_assigned( 5, 100, 'second', self::USER_ID );

		$rows = $this->stored();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'first', $rows[0]['title'] );
	}

	public function test_store_caps_at_fifty_keeping_the_most_recent(): void {
		for ( $i = 1; $i <= 55; $i++ ) {
			AdminBarNotificationStore::add_assigned( $i, 100, "row {$i}", self::USER_ID );
		}

		$rows = $this->stored();
		$this->assertCount( 50, $rows );
		// First five (rows 1-5) should have been dropped — slice keeps tail.
		$this->assertSame( 6, $rows[0]['update_id'] );
		$this->assertSame( 55, $rows[ array_key_last( $rows ) ]['update_id'] );
	}

	// get_active ----------------------------------------------------------

	public function test_get_active_returns_only_non_dismissed_rows(): void {
		AdminBarNotificationStore::add_assigned( 5, 100, 'keep', self::USER_ID );
		AdminBarNotificationStore::add_assigned( 6, 100, 'gone', self::USER_ID );
		AdminBarNotificationStore::dismiss( 'assigned_6', self::USER_ID );

		$active = AdminBarNotificationStore::get_active( self::USER_ID );

		$this->assertCount( 1, $active );
		$this->assertSame( 'assigned_5', $active[0]['key'] );
	}

	public function test_get_active_hits_cache_on_second_call_without_reading_user_meta(): void {
		AdminBarNotificationStore::add_assigned( 5, 100, 'x', self::USER_ID );
		AdminBarNotificationStore::get_active( self::USER_ID ); // primes cache
		$baseline = $this->get_user_meta_calls;

		AdminBarNotificationStore::get_active( self::USER_ID );

		$this->assertSame( $baseline, $this->get_user_meta_calls, 'second get_active should hit cache, not user meta' );
	}

	// dismiss + dismiss_for_update ---------------------------------------

	public function test_dismiss_marks_only_the_matching_key(): void {
		AdminBarNotificationStore::add_assigned( 5, 100, 'a', self::USER_ID );
		AdminBarNotificationStore::add_mention( 5, 100, 12, 'b', self::USER_ID );

		AdminBarNotificationStore::dismiss( 'assigned_5', self::USER_ID );

		$rows = $this->stored();
		$this->assertTrue( $rows[0]['dismissed'] );
		$this->assertArrayNotHasKey( 'dismissed', $rows[1] );
	}

	public function test_dismiss_for_update_marks_every_row_with_matching_update_id(): void {
		AdminBarNotificationStore::add_assigned( 5, 100, 'a', self::USER_ID );
		AdminBarNotificationStore::add_mention( 5, 100, 12, 'b', self::USER_ID );
		AdminBarNotificationStore::add_customer_reply( 5, 100, 13, 'c', self::USER_ID );
		AdminBarNotificationStore::add_assigned( 99, 200, 'untouched', self::USER_ID );

		AdminBarNotificationStore::dismiss_for_update( 5, self::USER_ID );

		$rows = $this->stored();
		$this->assertTrue( $rows[0]['dismissed'] );
		$this->assertTrue( $rows[1]['dismissed'] );
		$this->assertTrue( $rows[2]['dismissed'] );
		$this->assertArrayNotHasKey( 'dismissed', $rows[3] );
	}
}
