/**
 * Mobile sidebar toggle for the docs site.
 * No framework — plain DOM. Loaded with `defer` from every page so the
 * shared markup wires itself up without page-specific JS.
 */
( function () {
	'use strict';

	function init() {
		var sidebar  = document.querySelector( '.docs-sidebar' );
		var toggle   = document.querySelector( '.docs-sidebar__toggle' );
		var backdrop = document.querySelector( '.docs-sidebar__backdrop' );

		if ( ! sidebar || ! toggle ) {
			return;
		}

		function open() {
			sidebar.classList.add( 'is-open' );
			if ( backdrop ) { backdrop.classList.add( 'is-visible' ); }
		}

		function close() {
			sidebar.classList.remove( 'is-open' );
			if ( backdrop ) { backdrop.classList.remove( 'is-visible' ); }
		}

		toggle.addEventListener( 'click', function () {
			if ( sidebar.classList.contains( 'is-open' ) ) {
				close();
			} else {
				open();
			}
		} );

		if ( backdrop ) {
			backdrop.addEventListener( 'click', close );
		}

		// Esc closes the sidebar.
		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && sidebar.classList.contains( 'is-open' ) ) {
				close();
			}
		} );

		// Scroll the active link into view inside the sidebar, so the section
		// containing the current page is visible on load instead of staying
		// stuck at the top.
		var activeLink = sidebar.querySelector( '.docs-sidebar__nav a.is-active' );
		if ( activeLink ) {
			var linkRect = activeLink.getBoundingClientRect();
			var barRect  = sidebar.getBoundingClientRect();
			if ( linkRect.top < barRect.top || linkRect.bottom > barRect.bottom ) {
				activeLink.scrollIntoView( { block: 'center', inline: 'nearest' } );
			}
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
