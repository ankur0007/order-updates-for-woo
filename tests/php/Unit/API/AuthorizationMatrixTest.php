<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Tests\Unit\API;

use OrderUpdatesForWoo\API\Concerns\ValidatesAnalyticsRequest;
use OrderUpdatesForWoo\API\Concerns\VerifiesAccess;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Static guarantee: every REST endpoint class uses the VerifiesAccess trait.
 * Trait-presence is a structural check; the trait's own logic is tested in
 * ValidatesAnalyticsRequestTest. Together they prove no endpoint silently
 * skips nonce verification.
 *
 * Why static and not behavioural: behavioural tests for every endpoint would
 * require constructing each with its full dependency graph, faking a REST
 * request, etc. This pass catches the most common regression — someone
 * forgetting `use VerifiesAccess;` on a new endpoint — at near-zero cost.
 */
final class AuthorizationMatrixTest extends TestCase {

	private const ENDPOINTS_DIR = __DIR__ . '/../../../../src/API/Endpoints';

	/** @return iterable<string, array{0:string}> */
	public static function endpoint_classes(): iterable {
		$dir = realpath( self::ENDPOINTS_DIR );

		if ( ! $dir ) {
			yield 'no endpoints found' => array( '' );
			return;
		}

		// Shell-based discovery — RecursiveDirectoryIterator interacts badly
		// with Patchwork's stream wrapper, which the test runtime has loaded
		// for bypass-finals support.
		$output = trim( (string) shell_exec( 'find ' . escapeshellarg( $dir ) . ' -name "*.php" -type f' ) );
		$paths  = '' === $output ? array() : explode( "\n", $output );

		foreach ( $paths as $path ) {
			$contents = file_get_contents( $path );

			if ( ! preg_match( '/namespace\s+([\\\\\w]+);/', $contents, $ns_match ) ) {
				continue;
			}

			if ( ! preg_match( '/(?:final\s+)?class\s+(\w+)/', $contents, $cls_match ) ) {
				continue;
			}

			$fqn = $ns_match[1] . '\\' . $cls_match[1];
			yield $cls_match[1] => array( $fqn );
		}
	}

	/**
	 * @dataProvider endpoint_classes
	 */
	public function test_endpoint_uses_verifies_access_trait( string $fqn ): void {
		$this->assertTrue( class_exists( $fqn ), "{$fqn} must be loadable" );

		$reflection = new ReflectionClass( $fqn );
		$traits     = $this->all_traits( $reflection );

		// VerifiesAccess provides verify_nonce() directly. ValidatesAnalyticsRequest
		// `uses` VerifiesAccess and adds the analytics-specific cap check, so either
		// is acceptable evidence of nonce verification on the endpoint.
		$has_verification = in_array( VerifiesAccess::class, $traits, true )
			|| in_array( ValidatesAnalyticsRequest::class, $traits, true );

		$this->assertTrue(
			$has_verification,
			sprintf( '%s must `use VerifiesAccess` (or ValidatesAnalyticsRequest, which bundles it) so its can_access() can verify the request nonce.', $fqn )
		);
	}

	/** Walks the inheritance chain and trait-of-trait nesting. */
	private function all_traits( ReflectionClass $reflection ): array {
		$traits = array();

		do {
			foreach ( $reflection->getTraitNames() as $trait ) {
				$this->collect_trait_chain( $trait, $traits );
			}
			$reflection = $reflection->getParentClass();
		} while ( $reflection );

		return array_unique( $traits );
	}

	private function collect_trait_chain( string $trait_name, array &$collected ): void {
		if ( in_array( $trait_name, $collected, true ) ) {
			return;
		}

		$collected[] = $trait_name;

		foreach ( ( new ReflectionClass( $trait_name ) )->getTraitNames() as $nested ) {
			$this->collect_trait_chain( $nested, $collected );
		}
	}
}
