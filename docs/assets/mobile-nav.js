/**
 * Mobile nav — hamburger toggle + slide-out panel.
 *
 * Auto-wires to any `<header class="landing__nav">` on the page. Adds
 * `is-open` class to the body when the menu is open so the CSS can
 * lock scroll + animate the panel in. Closes on Escape, on overlay
 * click, or after the user taps a nav link.
 */
( function () {
	'use strict';

	function boot() {
		var nav = document.querySelector( '.landing__nav' );
		if ( ! nav ) return;

		var toggle = nav.querySelector( '[data-nav-toggle]' );
		var links  = nav.querySelector( '.landing__nav__links' );
		if ( ! toggle || ! links ) return;

		function open() {
			document.body.classList.add( 'nav-is-open' );
			toggle.setAttribute( 'aria-expanded', 'true' );
			toggle.setAttribute( 'aria-label', 'Close menu' );
		}

		function close() {
			document.body.classList.remove( 'nav-is-open' );
			toggle.setAttribute( 'aria-expanded', 'false' );
			toggle.setAttribute( 'aria-label', 'Open menu' );
		}

		toggle.addEventListener( 'click', function () {
			if ( document.body.classList.contains( 'nav-is-open' ) ) {
				close();
			} else {
				open();
			}
		} );

		// Close after tapping any nav link.
		links.addEventListener( 'click', function ( event ) {
			if ( event.target.tagName === 'A' ) {
				close();
			}
		} );

		// Close on Escape.
		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && document.body.classList.contains( 'nav-is-open' ) ) {
				close();
				toggle.focus();
			}
		} );

		// Close if viewport resizes back to desktop while open.
		window.addEventListener( 'resize', function () {
			if ( window.innerWidth > 880 && document.body.classList.contains( 'nav-is-open' ) ) {
				close();
			}
		} );
	}

	// Wait for partials.js to inject the nav (if used), otherwise fall
	// back to DOMContentLoaded for pages that still have inline nav.
	document.addEventListener( 'partials:ready', boot );
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			// If a placeholder is still on the page, wait for partials:ready.
			if ( ! document.querySelector( '[data-include]' ) ) boot();
		} );
	} else if ( ! document.querySelector( '[data-include]' ) ) {
		boot();
	}
} )();
