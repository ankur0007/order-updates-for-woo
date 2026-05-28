/**
 * Anchored mega-menu for the top nav.
 *
 * Each `[data-menu-trigger]` button toggles the matching
 * `[data-menu-panel]`. Behaviour:
 *   - Hover (desktop) and click both open the panel
 *   - Click outside closes it
 *   - Escape closes + returns focus to the trigger
 *   - Opening one panel closes any other open one
 *   - On mobile (≤880px) panels collapse into accordions inside the
 *     hamburger drawer — handled by CSS, not JS
 */
( function () {
	'use strict';

	var HOVER_DELAY = 120;

	function boot() {
		var triggers = document.querySelectorAll( '[data-menu-trigger]' );
		if ( ! triggers.length ) return;

		var openTimer  = null;
		var closeTimer = null;
		var activeKey  = null;

		function openPanel( key ) {
			if ( activeKey === key ) return;
			closeAll();
			activeKey = key;

			var trigger = document.querySelector( '[data-menu-trigger="' + key + '"]' );
			var panel   = document.querySelector( '[data-menu-panel="' + key + '"]' );
			if ( trigger ) trigger.setAttribute( 'aria-expanded', 'true' );
			if ( panel )   panel.setAttribute( 'data-open', 'true' );
		}

		function closeAll() {
			triggers.forEach( function ( t ) {
				t.setAttribute( 'aria-expanded', 'false' );
			} );
			document.querySelectorAll( '[data-menu-panel]' ).forEach( function ( p ) {
				p.removeAttribute( 'data-open' );
			} );
			activeKey = null;
		}

		// Hover behaviour — only on devices that actually hover. Touch
		// devices skip this entirely and use the click handler.
		var canHover = window.matchMedia( '(hover: hover)' ).matches;

		triggers.forEach( function ( trigger ) {
			var key  = trigger.dataset.menuTrigger;
			var item = trigger.closest( '[data-menu-item]' );

			trigger.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				if ( activeKey === key ) {
					closeAll();
				} else {
					openPanel( key );
				}
			} );

			if ( canHover && item ) {
				item.addEventListener( 'mouseenter', function () {
					window.clearTimeout( closeTimer );
					openTimer = window.setTimeout( function () { openPanel( key ); }, HOVER_DELAY );
				} );
				item.addEventListener( 'mouseleave', function () {
					window.clearTimeout( openTimer );
					closeTimer = window.setTimeout( closeAll, HOVER_DELAY );
				} );
			}
		} );

		// Click outside any open panel closes everything.
		document.addEventListener( 'click', function ( event ) {
			if ( ! activeKey ) return;
			if ( event.target.closest( '[data-menu-trigger]' ) ) return;
			if ( event.target.closest( '[data-menu-panel]' ) ) return;
			closeAll();
		} );

		// Escape closes + returns focus to the matching trigger.
		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && activeKey ) {
				var trigger = document.querySelector( '[data-menu-trigger="' + activeKey + '"]' );
				closeAll();
				if ( trigger ) trigger.focus();
			}
		} );
	}

	// Wait for partials.js to inject the nav; fall back to DOMContentLoaded
	// for pages without partial placeholders.
	document.addEventListener( 'partials:ready', boot );
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			if ( ! document.querySelector( '[data-include]' ) ) boot();
		} );
	} else if ( ! document.querySelector( '[data-include]' ) ) {
		boot();
	}
} )();
