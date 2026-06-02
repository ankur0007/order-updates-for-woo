<?php
/**
 * Text-emoticon to Unicode-emoji converter.
 *
 * Runs at note-save time on every note (internal + customer) so the stored
 * text always carries real emoji glyphs. Every downstream surface — initial
 * PHP render, polling JS, emails — reads the converted text from the DB
 * instead of doing its own conversion pass. The admin meta-box JS still
 * does a runtime conversion for the textarea preview and for old notes
 * written before this helper landed.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EmoticonConverter {
	/**
	 * Pattern => replacement glyph. Mirrors the smileys block of the
	 * `emoticonMap` in assets/Admin/js/update-meta-box.js. Keep both in sync
	 * if the list ever changes — the JS still handles unconverted legacy
	 * notes and the textarea preview before save.
	 *
	 * @var array<string, string>
	 */
	private const MAP = array(
		'/:-?\)/u'  => "\u{1F642}",
		'/:-?\(/u'  => "\u{1F641}",
		'/:-?D/u'   => "\u{1F600}",
		'/;-?\)/u'  => "\u{1F609}",
		'/:-?P/iu'  => "\u{1F61B}",
		'/:-?\//u'  => "\u{1F615}",
		'/:-?\|/u'  => "\u{1F610}",
		'/:-?O/iu'  => "\u{1F62E}",
		'/:\'?\(/u' => "\u{1F622}",
		'/:-?\*/u'  => "\u{1F618}",
		'/B-?\)/u'  => "\u{1F60E}",
		'/<3/u'     => "\u{2764}\u{FE0F}",
		'/\^\^/u'   => "\u{1F60A}",
	);

	public static function convert( string $text ): string {
		if ( '' === $text ) {
			return $text;
		}

		// Split on URLs first so emoticon patterns don't fire inside them.
		// Otherwise `https://example.com` would get its `:/` turned into 😕.
		$parts = preg_split(
			'/(\bhttps?:\/\/\S+)/u',
			$text,
			-1,
			PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
		);
		if ( ! is_array( $parts ) ) {
			return $text;
		}

		$converted = '';
		foreach ( $parts as $part ) {
			if ( preg_match( '/^https?:\/\//u', $part ) ) {
				$converted .= $part;
				continue;
			}
			$swapped    = preg_replace( array_keys( self::MAP ), array_values( self::MAP ), $part );
			$converted .= is_string( $swapped ) ? $swapped : $part;
		}

		return $converted;
	}
}
