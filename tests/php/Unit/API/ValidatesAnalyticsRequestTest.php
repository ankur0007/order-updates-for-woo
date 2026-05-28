<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Tests\Unit\API;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OrderUpdatesForWoo\API\Concerns\ValidatesAnalyticsRequest;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

/**
 * Trait test pattern: instantiate an anonymous class that uses the trait and
 * exposes its protected methods via thin public delegates. Avoids needing a
 * real endpoint subclass and isolates the trait's logic.
 */
final class ValidatesAnalyticsRequestTest extends TestCase {

	private object $host;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( '__' )->returnArg();

		$this->host = new class {
			use ValidatesAnalyticsRequest;

			public function call_can_access( WP_REST_Request $request ) {
				return $this->analytics_can_access( $request );
			}

			public function call_parse( WP_REST_Request $request ) {
				return $this->parse_analytics_date_range( $request );
			}
		};
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function request( array $params, string $nonce_header = 'good' ): WP_REST_Request {
		$r = new class extends WP_REST_Request {
			public string $nonce = '';
			public array  $p     = array();
			public function get_header( string $key ): ?string { return 'X-WP-Nonce' === $key ? $this->nonce : null; }
			public function get_param( string $key ): mixed { return $this->p[ $key ] ?? null; }
		};
		$r->nonce = $nonce_header;
		$r->p     = $params;
		return $r;
	}

	// analytics_can_access -----------------------------------------------

	public function test_can_access_returns_wp_error_when_nonce_missing(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$result = $this->host->call_can_access( $this->request( array(), '' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'order_updates_for_woo_invalid_nonce', $result->get_error_code() );
	}

	public function test_can_access_returns_wp_error_when_nonce_invalid(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$result = $this->host->call_can_access( $this->request( array() ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'order_updates_for_woo_invalid_nonce', $result->get_error_code() );
	}

	public function test_can_access_returns_wp_error_when_user_lacks_capability(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );

		$result = $this->host->call_can_access( $this->request( array() ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'order_updates_for_woo_forbidden', $result->get_error_code() );
	}

	public function test_can_access_returns_true_when_nonce_and_capability_pass(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertTrue( $this->host->call_can_access( $this->request( array() ) ) );
	}

	// parse_analytics_date_range -----------------------------------------

	public function test_parse_returns_wp_error_when_from_missing(): void {
		$result = $this->host->call_parse( $this->request( array( 'to' => '2026-05-06' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'order_updates_for_woo_invalid_params', $result->get_error_code() );
	}

	public function test_parse_returns_wp_error_when_to_missing(): void {
		$result = $this->host->call_parse( $this->request( array( 'from' => '2026-05-06' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_parse_returns_wp_error_when_dates_not_ymd(): void {
		$result = $this->host->call_parse( $this->request( array( 'from' => '2026/05/06', 'to' => '2026-05-07' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_parse_returns_wp_error_when_from_greater_than_to(): void {
		$result = $this->host->call_parse( $this->request( array( 'from' => '2026-05-10', 'to' => '2026-05-01' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_parse_returns_tuple_for_valid_range(): void {
		$result = $this->host->call_parse( $this->request( array( 'from' => '2026-05-01', 'to' => '2026-05-06' ) ) );

		$this->assertSame( array( '2026-05-01', '2026-05-06' ), $result );
	}
}
