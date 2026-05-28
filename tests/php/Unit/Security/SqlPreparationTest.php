<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

/**
 * Static scan: every $wpdb->{query|get_*} call site in src/ must either pass
 * a $wpdb->prepare() result OR a string literal with no interpolation. Catches
 * the easiest SQL-injection regression — forgetting prepare() on a path that
 * accepts user input — at build time.
 *
 * Approach: regex over the source. A real AST scan would be more precise but
 * also more code; the regex catches the patterns that actually ship in this
 * codebase. False positives are reviewed manually if they ever appear.
 */
final class SqlPreparationTest extends TestCase {

	private const SRC_DIR = __DIR__ . '/../../../../src';

	/** Methods on $wpdb that take a SQL string and need prepare()-or-literal. */
	private const SQL_METHODS = array( 'query', 'get_results', 'get_row', 'get_var', 'get_col' );

	public function test_every_wpdb_call_uses_prepare_or_safe_literal(): void {
		$violations = array();

		foreach ( $this->source_files() as $path ) {
			$source = file_get_contents( $path );
			$lines  = explode( "\n", $source );

			foreach ( $lines as $line_number => $line ) {
				foreach ( self::SQL_METHODS as $method ) {
					if ( ! preg_match( '/\$wpdb->' . $method . '\s*\(/', $line ) ) {
						continue;
					}

					if ( $this->is_safe_call( $line, $lines, $line_number ) ) {
						continue;
					}

					$violations[] = sprintf(
						'%s:%d  %s',
						basename( $path ),
						$line_number + 1,
						trim( $line )
					);
				}
			}
		}

		$this->assertSame(
			array(),
			$violations,
			"Unprepared \$wpdb calls detected (must wrap user-input args in \$wpdb->prepare()):\n" . implode( "\n", $violations )
		);
	}

	private function is_safe_call( string $line, array $lines, int $line_number ): bool {
		// Window of 4 lines after the call site to catch multi-line prepare() arguments.
		$window = implode( ' ', array_slice( $lines, $line_number, 5 ) );

		// Safe if prepare() appears inside the call.
		if ( str_contains( $window, '$wpdb->prepare' ) ) {
			return true;
		}

		// Safe if the call's argument is a single-quoted or double-quoted literal
		// with no interpolation (no $vars, no {} braces).
		if ( preg_match( '/\$wpdb->\w+\s*\(\s*([\'"])([^$\\\\{1,2}]*?)\1/', $window ) ) {
			return true;
		}

		// Allow phpcs ignore directives (developer has explicitly justified the call).
		if ( false !== strpos( $window, 'phpcs:ignore' ) || false !== strpos( $window, 'PreparedSQL' ) ) {
			return true;
		}

		return false;
	}

	/** @return iterable<string> */
	private function source_files(): iterable {
		$dir = realpath( self::SRC_DIR );

		if ( ! $dir ) {
			return;
		}

		// Shell-based discovery — RecursiveDirectoryIterator interacts badly
		// with Patchwork's stream wrapper loaded for bypass-finals.
		$output = trim( (string) shell_exec( 'find ' . escapeshellarg( $dir ) . ' -name "*.php" -type f' ) );

		if ( '' !== $output ) {
			foreach ( explode( "\n", $output ) as $path ) {
				yield $path;
			}
		}
	}
}
