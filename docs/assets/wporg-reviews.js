/**
 * Reveal the WordPress.org review widget once real reviews exist.
 *
 * Hits `https://api.wordpress.org/plugins/info/1.0/{slug}.json` for the
 * slug declared on the widget element and, if at least one rating has
 * been logged, replaces the placeholder rating / count and unhides the
 * widget. Until the plugin is published or until the first review lands,
 * the widget stays hidden and the "empty-but-honest" copy is what users
 * see.
 *
 * Fails silently — a network error or unpublished plugin returns 404
 * and we just don't reveal anything. No console noise on the home page.
 */
( function () {
	'use strict';

	function boot() {
		var widget = document.querySelector( '[data-wporg-slug]' );
		if ( ! widget ) {
			return;
		}

		var slug = widget.dataset.wporgSlug;
		if ( ! slug ) {
			return;
		}

		var url = 'https://api.wordpress.org/plugins/info/1.0/' + encodeURIComponent( slug ) + '.json';

		fetch( url )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'not published' );
				}
				return response.json();
			} )
			.then( function ( data ) {
				var ratingPercent = parseFloat( data && data.rating );
				var numRatings    = parseInt( ( data && data.num_ratings ) || 0, 10 );

				// `rating` is a 0–100 percentage; convert to a 0–5 star value.
				// Hide entirely if there are no real reviews yet — we don't
				// want to flash "0.0 out of 5" before the first review lands.
				if ( ! numRatings || isNaN( ratingPercent ) ) {
					return;
				}

				var starsOutOfFive = ( ratingPercent / 20 ).toFixed( 1 );
				var ratingEl      = widget.querySelector( '[data-wporg-rating]' );
				var countEl       = widget.querySelector( '[data-wporg-count]' );

				if ( ratingEl ) ratingEl.textContent = starsOutOfFive;
				if ( countEl )  countEl.textContent  = numRatings.toLocaleString();

				widget.hidden = false;
			} )
			.catch( function () {
				// Plugin not on WP.org yet (or network blocked) — leave the
				// widget hidden so only the empty-state message shows.
			} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
