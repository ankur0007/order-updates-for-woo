/**
 * Fetch curated testimonials from `testimonials.json` and render them.
 *
 * Two render modes:
 *   - Home page: `[data-testimonials-grid]` element renders the latest
 *     N published entries (default 10).
 *   - Reviews page: `[data-testimonials-all]` element renders every
 *     published entry, newest first.
 *
 * Each testimonial carries a public verification link so a sceptical
 * reader is one click away from confirming the person is real. Entries
 * with `published: false` are skipped — they're queued for review.
 */
( function () {
	'use strict';

	var STARS_FULL  = '★';
	var STARS_EMPTY = '☆';
	var HOME_LIMIT  = 10;

	function boot() {
		var grid    = document.querySelector( '[data-testimonials-grid]' );
		var allGrid = document.querySelector( '[data-testimonials-all]' );
		var counter = document.querySelector( '[data-testimonials-count]' );
		var seeAll  = document.querySelector( '[data-testimonials-see-all]' );

		if ( ! grid && ! allGrid ) {
			return;
		}

		// `testimonials.json` lives at the docs root. Try absolute first,
		// then fall back to a relative path so the page works on file://
		// previews and any sub-path deployment.
		fetchWithFallback( [ '/testimonials.json', 'testimonials.json' ] )
			.then( function ( response ) {
				if ( ! response || ! response.ok ) {
					throw new Error( 'no testimonials' );
				}
				return response.json();
			} )
			.then( function ( data ) {
				var entries = ( data && Array.isArray( data.testimonials ) ) ? data.testimonials : [];

				// Only show published entries. Pending/rejected ones stay
				// in the JSON for moderation history but never render.
				entries = entries.filter( function ( entry ) {
					return entry && entry.published === true;
				} );

				// Newest first — sort by ISO date string, fall back to
				// array order if dates are missing.
				entries.sort( function ( a, b ) {
					return String( b.date || '' ).localeCompare( String( a.date || '' ) );
				} );

				if ( counter ) {
					counter.textContent = String( entries.length );
				}

				if ( ! entries.length ) {
					return;
				}

				// Home slider — capped at HOME_LIMIT. "See all" link reveals
				// only when there are more entries than the home cap. The
				// parent slider wrapper hides until we have at least one
				// entry to show.
				if ( grid ) {
					var slice = entries.slice( 0, HOME_LIMIT );
					grid.hidden = false;
					var wrapper = grid.closest( '[data-testimonials-wrapper]' );
					if ( wrapper ) {
						wrapper.hidden = false;
					}
					slice.forEach( function ( entry ) {
						grid.appendChild( renderCard( entry ) );
					} );
					if ( seeAll && entries.length > HOME_LIMIT ) {
						seeAll.hidden = false;
					}

					// Re-init the slider now that slides are in the DOM.
					// features-slider.js marks each slider with `data-slider-ready`
					// on its first pass — clear that flag here so our re-boot
					// actually wires up the prev/next/dots against the new slides.
					if ( wrapper ) {
						wrapper.removeAttribute( 'data-slider-ready' );
					}
					if ( typeof window.__awtsBootFeatureSliders === 'function' ) {
						window.__awtsBootFeatureSliders();
					}
				}

				// Reviews page — full list + sidebar breakdown + sort/filter.
				if ( allGrid ) {
					initReviewsPage( entries, allGrid );
				}
			} )
			.catch( function () {
				// No testimonials.json yet — sections stay in their empty
				// state, which is the correct behaviour.
			} );
	}

	/**
	 * Try each URL in order; return the first successful (HTTP-200) response.
	 * Both 404s and network errors trigger the next candidate, so a stale
	 * absolute path won't silently fail — we retry as relative.
	 */
	function fetchWithFallback( urls ) {
		return urls.reduce( function ( chain, url ) {
			return chain.then( function ( prev ) {
				if ( prev && prev.ok ) {
					return prev;
				}
				return fetch( url, { cache: 'no-store' } ).catch( function () {
					return null;
				} );
			} );
		}, Promise.resolve( null ) );
	}

	function renderCard( entry ) {
		var card = document.createElement( 'figure' );
		card.className = 'landing__testimonial';

		var rating = Math.max( 0, Math.min( 5, parseInt( entry.rating, 10 ) || 0 ) );
		var stars  = STARS_FULL.repeat( rating ) + STARS_EMPTY.repeat( 5 - rating );

		var sourceLabel = sourceBadge( entry.source );
		var initials    = avatarInitials( entry.name );
		card.dataset.accent = String( colourIndex( entry.name ) );

		// Build innerHTML — escape every interpolated string so a bad
		// testimonials.json entry can't inject markup into the page.
		var replyHtml = entry.reply
			? '<div class="landing__testimonial__reply">' +
			      '<div class="landing__testimonial__reply__pip" aria-hidden="true">' +
			          '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/></svg>' +
			      '</div>' +
			      '<div>' +
			          '<span class="landing__testimonial__reply__label">' + esc( entry.reply_label || 'Reply from the maker' ) + '</span>' +
			          '<p>' + esc( entry.reply ) + '</p>' +
			      '</div>' +
			  '</div>'
			: '';

		var verifyHtml = entry.link
			? '<a class="landing__testimonial__verify" href="' + esc( entry.link ) + '" target="_blank" rel="noopener" title="Verify this reviewer">' +
			      '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg>' +
			      esc( entry.link_label || 'Verify' ) +
			  '</a>'
			: '';

		var sourceHtml = sourceLabel
			? '<span class="landing__testimonial__source">' + sourceLabel + '</span>'
			: '';

		card.innerHTML =
			'<div class="landing__testimonial__stars" aria-label="' + rating + ' out of 5">' + stars + '</div>' +
			'<blockquote class="landing__testimonial__quote">' + esc( entry.quote || '' ) + '</blockquote>' +
			replyHtml +
			'<figcaption class="landing__testimonial__meta">' +
				'<span class="landing__testimonial__avatar" aria-hidden="true">' + esc( initials ) + '</span>' +
				'<span class="landing__testimonial__who">' +
					'<strong>' + esc( entry.name || 'Anonymous' ) + '</strong>' +
					( entry.role ? '<span class="landing__testimonial__role">' + esc( entry.role ) + '</span>' : '' ) +
				'</span>' +
				verifyHtml +
				sourceHtml +
			'</figcaption>';

		return card;
	}

	/** "Ankur Vishwakarma" → "AV". One-word names use the first two letters. */
	function avatarInitials( name ) {
		if ( ! name ) return '–';
		var parts = String( name ).trim().split( /\s+/ );
		if ( parts.length === 1 ) {
			return parts[0].substring( 0, 2 ).toUpperCase();
		}
		return ( parts[0][0] + parts[ parts.length - 1 ][0] ).toUpperCase();
	}

	/** Stable accent colour per reviewer — same person, same colour. */
	function colourIndex( name ) {
		var s = String( name || '' );
		var h = 0;
		for ( var i = 0; i < s.length; i++ ) {
			h = ( ( h << 5 ) - h + s.charCodeAt( i ) ) | 0;
		}
		return Math.abs( h ) % 6;
	}

	/* ---------- Reviews page (reviews.html) — breakdown + filter + sort. ---------- */

	function initReviewsPage( entries, listEl ) {
		var layout      = document.querySelector( '[data-reviews-layout]' );
		var empty       = document.querySelector( '[data-testimonials-empty]' );
		var avgEl       = document.querySelector( '[data-breakdown-avg]' );
		var starsEl     = document.querySelector( '[data-breakdown-stars]' );
		var totalEl     = document.querySelector( '[data-breakdown-total]' );
		var breakdown   = document.querySelector( '[data-breakdown-list]' );
		var sortSelect  = document.querySelector( '[data-reviews-sort]' );
		var listCount   = document.querySelector( '[data-list-count]' );
		var listTotal   = document.querySelector( '[data-list-total]' );
		var noMatch     = document.querySelector( '[data-no-match]' );
		var clearBtn    = document.querySelector( '[data-clear-filter]' );

		if ( ! layout || ! entries.length ) {
			return;
		}

		// Reveal the page chrome + hide the empty state.
		layout.hidden    = false;
		listEl.hidden    = false;
		if ( empty ) empty.hidden = true;

		var state = { filterStar: 0, sort: 'newest' };

		// Overall + per-star counts (always computed on the full list, not the filtered view).
		var total     = entries.length;
		var sum       = entries.reduce( function ( s, e ) { return s + ( parseInt( e.rating, 10 ) || 0 ); }, 0 );
		var avg       = ( sum / total ).toFixed( 1 );
		var perStar   = { 5: 0, 4: 0, 3: 0, 2: 0, 1: 0 };
		entries.forEach( function ( e ) {
			var r = Math.max( 1, Math.min( 5, parseInt( e.rating, 10 ) || 0 ) );
			perStar[ r ] = ( perStar[ r ] || 0 ) + 1;
		} );

		if ( avgEl )   avgEl.textContent   = avg;
		if ( totalEl ) totalEl.textContent = total;
		if ( listTotal ) listTotal.textContent = total;
		if ( starsEl ) starsEl.textContent = STARS_FULL.repeat( Math.round( parseFloat( avg ) ) ) + STARS_EMPTY.repeat( 5 - Math.round( parseFloat( avg ) ) );

		// Breakdown rows — 5 → 1, each clickable to filter.
		if ( breakdown ) {
			breakdown.innerHTML = '';
			[ 5, 4, 3, 2, 1 ].forEach( function ( star ) {
				var count   = perStar[ star ] || 0;
				var percent = total ? Math.round( ( count / total ) * 100 ) : 0;
				var row     = document.createElement( 'li' );
				row.className = 'reviews-breakdown__row';
				row.dataset.star = String( star );
				row.innerHTML =
					'<button type="button" class="reviews-breakdown__btn" data-filter-star="' + star + '">' +
						'<span class="reviews-breakdown__stars">' + STARS_FULL.repeat( star ) + STARS_EMPTY.repeat( 5 - star ) + '</span>' +
						'<span class="reviews-breakdown__bar"><span style="width:' + percent + '%"></span></span>' +
						'<span class="reviews-breakdown__count">' + count + '</span>' +
					'</button>';
				breakdown.appendChild( row );
			} );
		}

		function render() {
			// Apply filter.
			var filtered = entries.slice();
			if ( state.filterStar ) {
				filtered = filtered.filter( function ( e ) {
					return parseInt( e.rating, 10 ) === state.filterStar;
				} );
			}
			// Apply sort.
			filtered.sort( function ( a, b ) {
				switch ( state.sort ) {
					case 'oldest':
						return String( a.date || '' ).localeCompare( String( b.date || '' ) );
					case 'highest':
						return ( parseInt( b.rating, 10 ) || 0 ) - ( parseInt( a.rating, 10 ) || 0 );
					case 'lowest':
						return ( parseInt( a.rating, 10 ) || 0 ) - ( parseInt( b.rating, 10 ) || 0 );
					default:
						return String( b.date || '' ).localeCompare( String( a.date || '' ) );
				}
			} );

			// Repaint the list.
			listEl.innerHTML = '';
			filtered.forEach( function ( entry ) {
				listEl.appendChild( renderCard( entry ) );
			} );
			if ( listCount ) listCount.textContent = filtered.length;
			if ( noMatch ) noMatch.hidden = filtered.length > 0;

			// Highlight active filter row.
			document.querySelectorAll( '.reviews-breakdown__row' ).forEach( function ( row ) {
				row.classList.toggle( 'is-active', parseInt( row.dataset.star, 10 ) === state.filterStar );
			} );
		}

		// Wire up filter clicks (one delegated listener on the breakdown).
		if ( breakdown ) {
			breakdown.addEventListener( 'click', function ( event ) {
				var btn = event.target.closest( '[data-filter-star]' );
				if ( ! btn ) return;
				var star = parseInt( btn.dataset.filterStar, 10 );
				// Re-click same star → clear filter (toggle behaviour).
				state.filterStar = ( state.filterStar === star ) ? 0 : star;
				render();
			} );
		}

		if ( sortSelect ) {
			sortSelect.addEventListener( 'change', function () {
				state.sort = sortSelect.value;
				render();
			} );
		}

		if ( clearBtn ) {
			clearBtn.addEventListener( 'click', function () {
				state.filterStar = 0;
				render();
			} );
		}

		render();
	}

	function sourceBadge( source ) {
		switch ( source ) {
			case 'wp-org':    return 'WordPress.org';
			case 'wp-admin':  return 'Verified user';
			case 'g2':        return 'G2';
			case 'capterra':  return 'Capterra';
			case 'site':      return 'Submitted via site';
			default:          return '';
		}
	}

	function esc( str ) {
		var div = document.createElement( 'div' );
		div.textContent = String( str );
		return div.innerHTML;
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
