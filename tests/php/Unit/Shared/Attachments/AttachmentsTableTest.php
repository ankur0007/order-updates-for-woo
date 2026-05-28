<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Tests\Unit\Shared\Attachments;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\MockInterface;
use OrderUpdatesForWoo\Shared\Attachments\AttachmentsTable;
use PHPUnit\Framework\TestCase;
use wpdb;

/**
 * AttachmentsTable migration logic. Belt-and-braces: dbDelta runs when
 * either the version option is stale OR the table is missing — covers
 * the dropped-for-debug case where the option says "current" but the
 * table no longer exists.
 */
final class AttachmentsTableTest extends TestCase {

	private wpdb&MockInterface $wpdb;

	/** Tracks whether dbDelta() was called during the test run. */
	private bool $dbdelta_called = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->wpdb         = Mockery::mock( wpdb::class )->makePartial();
		$this->wpdb->prefix = 'wptests_';
		$GLOBALS['wpdb']    = $this->wpdb;

		$this->dbdelta_called = false;

		Functions\when( 'dbDelta' )->alias( function () {
			$this->dbdelta_called = true;
			return array();
		} );
		Functions\when( 'update_option' )->justReturn( true );

		$this->wpdb->shouldReceive( 'get_charset_collate' )->andReturn( '' );
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $q, $arg ) => str_replace( '%s', "'$arg'", $q ) );

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', sys_get_temp_dir() . '/' );
		}
		// Stub the require_once target with a no-op file.
		$upgrade_path = ABSPATH . 'wp-admin/includes/upgrade.php';
		if ( ! file_exists( $upgrade_path ) ) {
			@mkdir( dirname( $upgrade_path ), 0777, true );
			file_put_contents( $upgrade_path, '<?php' );
		}
	}

	protected function tearDown(): void {
		Mockery::close();
		Monkey\tearDown();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_skips_migration_when_version_matches_and_table_exists(): void {
		Functions\when( 'get_option' )->justReturn( '1.0.0' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( 'wptests_order_updates_for_woo_attachments' );

		( new AttachmentsTable() )->maybe_create_tables();

		$this->assertFalse( $this->dbdelta_called );
	}

	public function test_runs_migration_when_version_mismatches(): void {
		Functions\when( 'get_option' )->justReturn( '0.9.0' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( 'wptests_order_updates_for_woo_attachments' );

		( new AttachmentsTable() )->maybe_create_tables();

		$this->assertTrue( $this->dbdelta_called );
	}

	public function test_runs_migration_when_table_missing_even_if_version_matches(): void {
		Functions\when( 'get_option' )->justReturn( '1.0.0' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

		( new AttachmentsTable() )->maybe_create_tables();

		$this->assertTrue( $this->dbdelta_called );
	}

	public function test_constructor_resolves_table_name_with_wpdb_prefix(): void {
		$table = new AttachmentsTable();

		$this->assertSame( 'wptests_order_updates_for_woo_attachments', $table->attachments );
	}
}
