<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Tests\Unit\Helpers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\MockInterface;
use OrderUpdatesForWoo\Helpers\ParticipantResolver;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use PHPUnit\Framework\TestCase;
use WC_Order;

final class ParticipantResolverTest extends TestCase {

	private OrderUpdatesDb&MockInterface $db;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->db = Mockery::mock( OrderUpdatesDb::class );

		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Mockery::close();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function arrange_order_customer( int $order_id, int $customer_user_id ): void {
		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_customer_id' )->andReturn( $customer_user_id );

		Functions\when( 'wc_get_order' )->alias( static fn( $id ) => (int) $id === $order_id ? $order : null );
	}

	public function test_logged_in_customer_creator_is_excluded_from_participants(): void {
		// Update created by user 99 (the customer who's logged in), assigned to staff 7.
		$this->arrange_order_customer( 500, 99 );

		$this->db->shouldReceive( 'get_update' )->with( 5 )->andReturn(
			array(
				'id'               => 5,
				'order_id'         => 500,
				'created_by'       => 99,
				'assignee_user_id' => 7,
			)
		);
		$this->db->shouldReceive( 'get_update_notes' )->with( 5 )->andReturn( array() );
		$this->db->shouldReceive( 'get_staff_participant_user_ids' )->with( 5 )->andReturn( array( 7, 99 ) );

		$resolver = new ParticipantResolver( $this->db );

		$ids = $resolver->ids_for( 5 );

		$this->assertNotContains( 99, $ids, 'The order customer must not appear in staff participants.' );
		$this->assertContains( 7, $ids, 'The assignee should still be a participant.' );
	}

	public function test_guest_customer_is_naturally_excluded(): void {
		// Guest order: customer_id = 0, customer's note rows store created_by = 0.
		$this->arrange_order_customer( 500, 0 );

		$this->db->shouldReceive( 'get_update' )->with( 5 )->andReturn(
			array(
				'id'               => 5,
				'order_id'         => 500,
				'created_by'       => 0,
				'assignee_user_id' => 7,
			)
		);
		$this->db->shouldReceive( 'get_update_notes' )->with( 5 )->andReturn( array() );
		$this->db->shouldReceive( 'get_staff_participant_user_ids' )->with( 5 )->andReturn( array( 7 ) );

		$resolver = new ParticipantResolver( $this->db );

		$ids = $resolver->ids_for( 5 );

		$this->assertSame( array( 7 ), $ids );
	}

	public function test_staff_creator_is_kept_as_creator_role(): void {
		// Staff (user 12) opens an update on a customer's order (customer = 99).
		$this->arrange_order_customer( 500, 99 );

		$this->db->shouldReceive( 'get_update' )->with( 5 )->andReturn(
			array(
				'id'               => 5,
				'order_id'         => 500,
				'created_by'       => 12,
				'assignee_user_id' => 7,
			)
		);
		$this->db->shouldReceive( 'get_update_notes' )->with( 5 )->andReturn( array() );
		$this->db->shouldReceive( 'get_staff_participant_user_ids' )->with( 5 )->andReturn( array( 7, 12 ) );

		$resolver = new ParticipantResolver( $this->db );

		$ids = $resolver->ids_for( 5 );

		$this->assertContains( 12, $ids );
		$this->assertContains( 7, $ids );
		$this->assertNotContains( 99, $ids );
	}
}
