<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Tests\Unit\Shared\Notifications;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use OrderUpdatesForWoo\Admin\Notifications\Emails\AdminOrderUpdateEmail;
use OrderUpdatesForWoo\Admin\Notifications\Emails\AssigneeOrderUpdateEmail;
use OrderUpdatesForWoo\Admin\Notifications\Emails\InternalMentionEmail;
use OrderUpdatesForWoo\Frontend\Notifications\Emails\CustomerOrderUpdateEmail;
use OrderUpdatesForWoo\Frontend\Notifications\Emails\CustomerRatingFollowupEmail;
use OrderUpdatesForWoo\Frontend\Notifications\Emails\CustomerRatingRequestEmail;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Attachments\AttachmentsDb;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use PHPUnit\Framework\TestCase;

/**
 * Email-class contract: the $id field on every email subclass must match the
 * Constants::EMAIL_ID_* value WooCommerce uses to register the class. A drift
 * here means the email exists but never fires (the dispatcher's `get_email()`
 * lookup misses).
 *
 * Bundled into one file because the per-class assertion is identical — each
 * class needs only an instantiation + property check.
 */
final class EmailClassesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'trailingslashit' )->alias( fn( $p ) => rtrim( (string) $p, '/' ) . '/' );
	}

	protected function tearDown(): void {
		Mockery::close();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_admin_order_update_email_id(): void {
		$email = new AdminOrderUpdateEmail(
			Mockery::mock( OrderUpdatesDb::class ),
			Mockery::mock( AttachmentsDb::class )
		);

		$this->assertSame( Constants::EMAIL_ID_ADMIN_UPDATE, $email->id );
	}

	public function test_assignee_order_update_email_id(): void {
		$email = new AssigneeOrderUpdateEmail(
			Mockery::mock( OrderUpdatesDb::class ),
			Mockery::mock( AttachmentsDb::class )
		);

		$this->assertSame( Constants::EMAIL_ID_ASSIGNEE_UPDATE, $email->id );
	}

	public function test_internal_mention_email_id(): void {
		$email = new InternalMentionEmail(
			Mockery::mock( OrderUpdatesDb::class ),
			Mockery::mock( AttachmentsDb::class )
		);

		$this->assertSame( Constants::EMAIL_ID_INTERNAL_MENTION, $email->id );
	}

	public function test_customer_order_update_email_id(): void {
		$email = new CustomerOrderUpdateEmail(
			Mockery::mock( OrderUpdatesDb::class ),
			Mockery::mock( AttachmentsDb::class )
		);

		$this->assertSame( Constants::EMAIL_ID_CUSTOMER_UPDATE, $email->id );
	}

	public function test_customer_rating_request_email_id(): void {
		$email = new CustomerRatingRequestEmail( Mockery::mock( OrderUpdatesDb::class ) );

		$this->assertSame( Constants::EMAIL_ID_CUSTOMER_RATING_REQUEST, $email->id );
	}

	public function test_customer_rating_followup_email_id(): void {
		$email = new CustomerRatingFollowupEmail( Mockery::mock( OrderUpdatesDb::class ) );

		$this->assertSame( Constants::EMAIL_ID_CUSTOMER_RATING_FOLLOWUP, $email->id );
	}
}
