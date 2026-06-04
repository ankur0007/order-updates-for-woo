<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Tests\Unit\Frontend\OrderUpdates\Services;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OrderUpdatesForWoo\Frontend\OrderUpdates\Services\CustomerOrderUpdatesService;
use PHPUnit\Framework\TestCase;

/**
 * Pinned classification rules for customer-thread notes. The "is staff?"
 * decision drives the alignment, avatar resolution, and the author label
 * (customer name vs. "By {site_name}"), so a misclassification here cascades
 * into multiple visible bugs at once.
 */
final class CustomerOrderUpdatesServiceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// is_staff_authored_note() classifies by the author's role, not the
		// order's customer id: a note is staff-authored when its author is a team
		// member or can edit orders. Model user 12 as a team member (administrator)
		// and everyone else as a plain customer who cannot edit orders.
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'get_userdata' )->alias(
			static function ( $user_id ) {
				$roles = 12 === (int) $user_id ? array( 'administrator' ) : array( 'customer' );

				return (object) array( 'roles' => $roles );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_guest_writer_is_never_staff_even_on_logged_in_customer_order(): void {
		// Regression pin: a customer with a real account who opens the portal
		// via the order-key URL while LOGGED OUT submits a note that stores
		// created_by = 0. The order's customer_user_id is still > 0. The old
		// check misclassified this as staff and rendered "By {site_name}".
		$note = array( 'created_by' => 0 );

		$this->assertFalse(
			CustomerOrderUpdatesService::is_staff_authored_note( $note ),
			'A guest-written note (created_by = 0) is never staff, even when the order has a logged-in customer.'
		);
	}

	public function test_guest_writer_is_never_staff_on_guest_order(): void {
		$note = array( 'created_by' => 0 );

		$this->assertFalse(
			CustomerOrderUpdatesService::is_staff_authored_note( $note )
		);
	}

	public function test_logged_in_customer_writing_on_their_own_order_is_not_staff(): void {
		$note = array( 'created_by' => 7 );

		$this->assertFalse(
			CustomerOrderUpdatesService::is_staff_authored_note( $note )
		);
	}

	public function test_someone_else_writing_on_a_logged_in_customer_order_is_staff(): void {
		$note = array( 'created_by' => 12 ); // staff user

		$this->assertTrue(
			CustomerOrderUpdatesService::is_staff_authored_note( $note )
		);
	}

	public function test_real_user_writing_on_guest_order_is_staff(): void {
		$note = array( 'created_by' => 12 ); // staff

		$this->assertTrue(
			CustomerOrderUpdatesService::is_staff_authored_note( $note )
		);
	}
}
