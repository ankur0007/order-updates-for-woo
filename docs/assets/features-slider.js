/**
 * Horizontal slider on features.html.
 *
 * Wires up scroll-snap navigation (prev / next buttons + paging dots)
 * for the `.landing__slider` track. Each slide is full-width on the
 * track; clicking a button or dot scrolls one slide width over.
 *
 * The track keeps native scroll behaviour, so touch swipe and trackpad
 * gestures still work — the controls are purely additive.
 */
( function () {
	'use strict';

	function init( sliderRoot ) {
		var track    = sliderRoot.querySelector( '[data-slider-track]' );
		var prevBtn  = sliderRoot.querySelector( '[data-slider-prev]' );
		var nextBtn  = sliderRoot.querySelector( '[data-slider-next]' );
		var dotsWrap = sliderRoot.querySelector( '[data-slider-dots]' );

		// Buttons might live outside the slider root (in the header). Fall
		// back to the nearest section so the header controls still wire up.
		var section = sliderRoot.closest( 'section' );
		if ( ! prevBtn && section ) prevBtn = section.querySelector( '[data-slider-prev]' );
		if ( ! nextBtn && section ) nextBtn = section.querySelector( '[data-slider-next]' );

		if ( ! track ) return;

		var slides = Array.prototype.slice.call( track.children );
		if ( slides.length < 2 ) return;

		// Build paging dots from the count of slides. Each dot scrolls the
		// track to its slide's offset — accurate even after resize because
		// we read offsetLeft live on click.
		if ( dotsWrap ) {
			slides.forEach( function ( _slide, index ) {
				var dot = document.createElement( 'button' );
				dot.type = 'button';
				dot.className = 'landing__slider__dot';
				dot.setAttribute( 'aria-label', 'Go to slide ' + ( index + 1 ) );
				dot.addEventListener( 'click', function () {
					scrollToIndex( index );
				} );
				dotsWrap.appendChild( dot );
			} );
		}

		function getCurrentIndex() {
			// Whichever slide is closest to the left edge of the visible
			// track wins. Works regardless of slide width.
			var trackLeft = track.scrollLeft;
			var closest   = 0;
			var minDist   = Infinity;
			slides.forEach( function ( slide, index ) {
				var dist = Math.abs( slide.offsetLeft - trackLeft );
				if ( dist < minDist ) {
					minDist = dist;
					closest = index;
				}
			} );
			return closest;
		}

		function scrollToIndex( index ) {
			var target = slides[ Math.max( 0, Math.min( slides.length - 1, index ) ) ];
			if ( ! target ) return;
			track.scrollTo( { left: target.offsetLeft, behavior: 'smooth' } );
		}

		if ( prevBtn ) {
			prevBtn.addEventListener( 'click', function () {
				scrollToIndex( getCurrentIndex() - 1 );
			} );
		}
		if ( nextBtn ) {
			nextBtn.addEventListener( 'click', function () {
				scrollToIndex( getCurrentIndex() + 1 );
			} );
		}

		function updateDots() {
			if ( ! dotsWrap ) return;
			var active = getCurrentIndex();
			Array.prototype.forEach.call( dotsWrap.children, function ( dot, index ) {
				dot.classList.toggle( 'is-active', index === active );
			} );
		}

		// Throttle on scroll so we're not thrashing during the smooth-scroll
		// animation. requestAnimationFrame is enough here — we only need to
		// repaint once per frame at most.
		var ticking = false;
		track.addEventListener( 'scroll', function () {
			if ( ticking ) return;
			ticking = true;
			window.requestAnimationFrame( function () {
				updateDots();
				ticking = false;
			} );
		} );

		updateDots();
	}

	function boot() {
		var sliders = document.querySelectorAll( '[data-slider]:not([data-slider-ready])' );
		Array.prototype.forEach.call( sliders, function ( slider ) {
			slider.setAttribute( 'data-slider-ready', '1' );
			init( slider );
		} );
	}

	// Other scripts (testimonials.js) inject slides after DOMContentLoaded
	// and need a way to re-trigger boot. Expose it on window for that.
	window.__awtsBootFeatureSliders = boot;

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
