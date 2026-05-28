/**
 * Auto-rotating "How it works" stepper.
 *
 * Cycles through the 3 step panels every AUTO_MS milliseconds. Clicking
 * a numbered dot jumps to that step and pauses autoplay for a few
 * seconds so the user can read without the panel swapping under them.
 * Hovering over the stepper also pauses; mouseout resumes.
 */
( function () {
	'use strict';

	var AUTO_MS    = 5500;
	var RESUME_MS  = 12000;

	function boot() {
		var stepper = document.querySelector( '[data-stepper]' );
		if ( ! stepper ) return;

		var dots   = stepper.querySelectorAll( '[data-stepper-dot]' );
		var panels = stepper.querySelectorAll( '[data-stepper-panel]' );
		if ( ! dots.length || ! panels.length ) return;

		var current  = 1;
		var timer    = null;
		var paused   = false;

		function show( step ) {
			current = step;

			dots.forEach( function ( dot ) {
				var active = parseInt( dot.dataset.stepperDot, 10 ) === step;
				dot.classList.toggle( 'is-active', active );
				dot.setAttribute( 'aria-selected', active ? 'true' : 'false' );
			} );

			panels.forEach( function ( panel ) {
				var active = parseInt( panel.dataset.stepperPanel, 10 ) === step;
				panel.classList.toggle( 'is-active', active );
				if ( active ) {
					panel.removeAttribute( 'hidden' );
				} else {
					panel.setAttribute( 'hidden', '' );
				}
			} );
		}

		function next() {
			show( current >= panels.length ? 1 : current + 1 );
		}

		function start() {
			if ( timer ) return;
			timer = window.setInterval( function () {
				if ( ! paused ) next();
			}, AUTO_MS );
		}

		function stop() {
			window.clearInterval( timer );
			timer = null;
		}

		dots.forEach( function ( dot ) {
			dot.addEventListener( 'click', function () {
				var step = parseInt( dot.dataset.stepperDot, 10 );
				show( step );
				// Pause autoplay briefly so the chosen step stays on screen.
				paused = true;
				window.setTimeout( function () { paused = false; }, RESUME_MS );
			} );
		} );

		// Pause when the user is hovering or focusing the stepper region.
		stepper.addEventListener( 'mouseenter', function () { paused = true; } );
		stepper.addEventListener( 'mouseleave', function () { paused = false; } );
		stepper.addEventListener( 'focusin',    function () { paused = true; } );
		stepper.addEventListener( 'focusout',   function () { paused = false; } );

		// Respect users who've asked for reduced motion — no auto-cycling.
		var prefersReduced = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
		if ( ! prefersReduced ) {
			start();
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
