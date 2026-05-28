<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Tests\Unit\Helpers;

use Mockery;
use Mockery\MockInterface;
use OrderUpdatesForWoo\Helpers\UpdateResolver;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class UpdateResolverTest extends TestCase {

	private OrderUpdatesDb&MockInterface $db;

	protected function setUp(): void {
		parent::setUp();

		// Reset the static cache between tests so leakage from one test
		// doesn't mask a regression in another.
		$reflection = new ReflectionClass( UpdateResolver::class );
		$property   = $reflection->getProperty( 'update_cache' );
		$property->setValue( null, array() );

		$this->db = Mockery::mock( OrderUpdatesDb::class );
	}

	protected function tearDown(): void {
		Mockery::close();
		parent::tearDown();
	}

	public function test_get_update_caches_after_first_db_lookup(): void {
		$this->db->shouldReceive( 'get_update' )->once()->with( 5 )->andReturn( array( 'id' => 5 ) );

		$first  = UpdateResolver::get_update( 5, $this->db );
		$second = UpdateResolver::get_update( 5, $this->db );

		$this->assertSame( $first, $second );
	}

	public function test_normalize_passes_array_through_unchanged(): void {
		$update = array( 'id' => 5, 'title' => 'x' );

		$this->assertSame( $update, UpdateResolver::normalize_update( $update ) );
	}

	public function test_normalize_extracts_object_properties(): void {
		$obj         = new \stdClass();
		$obj->id     = 5;
		$obj->title  = 'x';

		$this->assertSame( array( 'id' => 5, 'title' => 'x' ), UpdateResolver::normalize_update( $obj ) );
	}

	public function test_normalize_resolves_int_id_via_db(): void {
		$this->db->shouldReceive( 'get_update' )->once()->with( 5 )->andReturn( array( 'id' => 5 ) );

		$this->assertSame( array( 'id' => 5 ), UpdateResolver::normalize_update( 5, $this->db ) );
	}

	public function test_normalize_returns_empty_array_for_null(): void {
		$this->assertSame( array(), UpdateResolver::normalize_update( null ) );
	}

	public function test_normalize_returns_empty_array_for_int_without_db(): void {
		$this->assertSame( array(), UpdateResolver::normalize_update( 5 ) );
	}
}
