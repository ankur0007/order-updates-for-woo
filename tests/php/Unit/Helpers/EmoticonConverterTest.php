<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Tests\Unit\Helpers;

use OrderUpdatesForWoo\Helpers\EmoticonConverter;
use PHPUnit\Framework\TestCase;

/**
 * Covers the URL-skip behaviour added to keep `https://` from being read
 * as `:/` and replaced with the confused-face emoji.
 */
final class EmoticonConverterTest extends TestCase {

	public function test_plain_smiley_is_converted(): void {
		$this->assertSame( "Hello \u{1F642}", EmoticonConverter::convert( 'Hello :)' ) );
	}

	public function test_url_is_left_alone(): void {
		$this->assertSame(
			'Visit https://example.com',
			EmoticonConverter::convert( 'Visit https://example.com' )
		);
	}

	public function test_emoticon_outside_a_url_still_converts(): void {
		$this->assertSame(
			"Check https://x.com \u{1F615} for info",
			EmoticonConverter::convert( 'Check https://x.com :/ for info' )
		);
	}

	public function test_multiple_emoticons_around_a_url(): void {
		$this->assertSame(
			"Both http://a.com \u{1F642} and bye \u{1F641}",
			EmoticonConverter::convert( 'Both http://a.com :) and bye :(' )
		);
	}

	public function test_url_with_port_is_kept(): void {
		$this->assertSame(
			'See http://example.com:8080/path now',
			EmoticonConverter::convert( 'See http://example.com:8080/path now' )
		);
	}

	public function test_empty_string_returns_empty(): void {
		$this->assertSame( '', EmoticonConverter::convert( '' ) );
	}
}
