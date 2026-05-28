/**
 * Tiny client-side partials loader.
 *
 * Each `<div data-include="partials/nav.html"></div>` placeholder gets
 * replaced with the fetched HTML at page load. Once all partials are
 * injected, a `partials:ready` event fires on the document so dependent
 * scripts (mobile-nav, mega-menu) can wire their behaviour against the
 * freshly-rendered DOM instead of guessing on DOMContentLoaded.
 *
 * This keeps a single source of truth for the nav + footer markup —
 * change one file, every page picks it up on next load.
 */
( function () {
	'use strict';

	function boot() {
		var placeholders = document.querySelectorAll( '[data-include]' );
		if ( ! placeholders.length ) {
			emitReady();
			return;
		}

		var pending = placeholders.length;

		placeholders.forEach( function ( el ) {
			var src = el.getAttribute( 'data-include' );
			if ( ! src ) {
				pending -= 1;
				if ( ! pending ) emitReady();
				return;
			}

			fetch( src, { cache: 'no-store' } )
				.then( function ( r ) {
					if ( ! r.ok ) throw new Error( 'partial ' + src + ' failed' );
					return r.text();
				} )
				.then( function ( html ) {
					// Replace the placeholder with the fetched markup. Using
					// outerHTML keeps the document tree clean — no leftover
					// wrapper div around the injected nav/footer.
					var template = document.createElement( 'template' );
					template.innerHTML = html.trim();
					el.replaceWith( template.content );

					// Mark the currently active nav link via [data-active].
					var active = el.getAttribute( 'data-active' );
					if ( active ) markActive( active );
				} )
				.catch( function () {
					// Leave the placeholder empty on failure — better than
					// crashing the page.
					el.remove();
				} )
				.finally( function () {
					pending -= 1;
					if ( ! pending ) emitReady();
				} );
		} );
	}

	function markActive( key ) {
		// Look for a nav link tagged with `data-nav-key="<key>"` and add
		// the active class. Lets us keep the markup identical across
		// pages while highlighting the current page.
		var link = document.querySelector( '[data-nav-key="' + key + '"]' );
		if ( link ) {
			link.classList.add( 'is-active' );
			link.setAttribute( 'aria-current', 'page' );
		}
	}

	function emitReady() {
		document.dispatchEvent( new CustomEvent( 'partials:ready' ) );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
