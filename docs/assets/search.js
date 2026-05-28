/**
 * Doc-site search. Loads the pre-built index, injects a search input into the
 * sidebar, filters as the user types, navigates on Enter / click.
 *
 * Pure DOM. No dependencies. Loaded with defer.
 */
( function () {
	'use strict';

	var index = [];
	var ready = false;
	var input;
	var dropdown;
	var sidebar;
	var rootPath; // ../../  prefix to resolve URLs from this page back to docs root

	function resolveRootPath() {
		// Read the prefix the page itself uses for its stylesheet — that's
		// already correct for the current page's depth from the docs root.
		var stylesheet = document.querySelector( 'link[rel="stylesheet"][href*="assets/styles.css"]' );
		if ( stylesheet ) {
			var href = stylesheet.getAttribute( 'href' ) || '';
			rootPath = href.replace( /assets\/styles\.css(\?.*)?$/, '' );
		} else {
			rootPath = '';
		}
	}

	function loadIndex() {
		resolveRootPath();
		// search-index.js sets window.DOCS_SEARCH_INDEX. No fetch — works on
		// file:// as well as any web host.
		if ( window.DOCS_SEARCH_INDEX && Array.isArray( window.DOCS_SEARCH_INDEX ) ) {
			index = window.DOCS_SEARCH_INDEX;
			ready = true;
			if ( input && input.value ) {
				handleInput();
			}
		}
	}

	function escapeHtml( s ) {
		return String( s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

	function highlight( text, query ) {
		if ( ! query ) { return escapeHtml( text ); }
		var safe = escapeHtml( text );
		var tokens = query.toLowerCase().split( /\s+/ ).filter( Boolean );
		// Escape each token separately and join with | for an OR regex.
		var pattern = tokens
			.map( function ( t ) { return t.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ); } )
			.filter( Boolean )
			.join( '|' );
		if ( ! pattern ) { return safe; }
		var re = new RegExp( '(' + pattern + ')', 'ig' );
		return safe.replace( re, '<mark>$1</mark>' );
	}

	function matchResults( query ) {
		if ( ! query ) { return []; }

		// Tokenise on whitespace. Each token must appear somewhere in the
		// candidate text, in any order. "email override" finds headings like
		// "Override the email templates".
		var tokens = query.toLowerCase().split( /\s+/ ).filter( Boolean );
		if ( ! tokens.length ) { return []; }

		function matchesAllTokens( text ) {
			if ( ! text ) { return false; }
			var lower = text.toLowerCase();
			for ( var i = 0; i < tokens.length; i++ ) {
				if ( lower.indexOf( tokens[ i ] ) === -1 ) {
					return false;
				}
			}
			return true;
		}

		var results = [];

		for ( var i = 0; i < index.length; i++ ) {
			var page = index[ i ];
			var titleHit = matchesAllTokens( page.title );
			var sectionHit = matchesAllTokens( page.section );
			var headingHits = [];

			if ( page.headings ) {
				for ( var j = 0; j < page.headings.length; j++ ) {
					var h = page.headings[ j ];
					if ( matchesAllTokens( h.text ) ) {
						headingHits.push( h.text );
					}
				}
			}

			// Content match — full body text of the page.
			var contentHit = page.content && matchesAllTokens( page.content );

			// Combined match across title + section + every heading. Catches
			// queries that span multiple fields ("rest api" → title + section).
			var combinedHit = false;
			if ( ! titleHit && ! sectionHit && ! headingHits.length && ! contentHit ) {
				var combined = page.title + ' ' + ( page.section || '' );
				if ( page.headings ) {
					for ( var k = 0; k < page.headings.length; k++ ) {
						combined += ' ' + page.headings[ k ].text;
					}
				}
				if ( matchesAllTokens( combined ) ) {
					combinedHit = true;
				}
			}

			if ( titleHit || sectionHit || headingHits.length || contentHit || combinedHit ) {
				// If we only matched via content, look for the closest heading
				// containing the first token — surfaces a useful subtitle.
				if ( contentHit && ! headingHits.length && page.headings ) {
					var firstToken = tokens[ 0 ];
					for ( var n = 0; n < page.headings.length; n++ ) {
						if ( page.headings[ n ].text.toLowerCase().indexOf( firstToken ) !== -1 ) {
							headingHits.push( page.headings[ n ].text );
							break;
						}
					}
				}

				results.push( {
					page: page,
					titleHit: titleHit,
					headingHits: headingHits,
					contentHit: contentHit,
				} );
			}
		}

		// Title hits first, then heading hits, then combined.
		results.sort( function ( a, b ) {
			if ( a.titleHit && ! b.titleHit ) { return -1; }
			if ( ! a.titleHit && b.titleHit ) { return 1; }
			if ( a.headingHits.length && ! b.headingHits.length ) { return -1; }
			if ( ! a.headingHits.length && b.headingHits.length ) { return 1; }
			return 0;
		} );

		return results.slice( 0, 20 );
	}

	function render( results, query ) {
		if ( ! results.length ) {
			dropdown.innerHTML = '<div class="docs-search__empty">No matches.</div>';
			dropdown.hidden = false;
			return;
		}

		var html = '<ul class="docs-search__list">';
		for ( var i = 0; i < results.length; i++ ) {
			var r = results[ i ];
			var page = r.page;
			var url = rootPath + page.url;
			html += '<li class="docs-search__item">';
			html += '<a href="' + escapeHtml( url ) + '">';
			html += '<span class="docs-search__title">' + highlight( page.title, query ) + '</span>';
			if ( page.section ) {
				html += ' <span class="docs-search__section">' + escapeHtml( page.section ) + '</span>';
			}
			if ( r.headingHits.length ) {
				html += '<div class="docs-search__heading">' + highlight( r.headingHits[ 0 ], query ) + '</div>';
			}
			html += '</a></li>';
		}
		html += '</ul>';
		dropdown.innerHTML = html;
		dropdown.hidden = false;
	}

	function handleInput() {
		var query = input.value.trim();
		if ( ! query ) {
			dropdown.hidden = true;
			return;
		}
		if ( ! ready ) {
			dropdown.innerHTML = '<div class="docs-search__empty">Loading…</div>';
			dropdown.hidden = false;
			return;
		}
		var results = matchResults( query );
		render( results, query );
	}

	function inject() {
		sidebar = document.querySelector( '.docs-sidebar' );
		var brand = document.querySelector( '.docs-sidebar__brand' );
		if ( ! sidebar || ! brand ) {
			return;
		}

		var wrap = document.createElement( 'div' );
		wrap.className = 'docs-search';
		wrap.innerHTML =
			'<label class="docs-search__label" for="docs-search-input">' +
			'<svg class="docs-search__icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
			'<circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>' +
			'<input id="docs-search-input" type="search" placeholder="Search docs" autocomplete="off" spellcheck="false">' +
			'</label>' +
			'<div class="docs-search__dropdown" hidden></div>';

		brand.insertAdjacentElement( 'afterend', wrap );

		input = wrap.querySelector( 'input' );
		dropdown = wrap.querySelector( '.docs-search__dropdown' );

		input.addEventListener( 'input', handleInput );
		input.addEventListener( 'focus', handleInput );

		input.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				input.value = '';
				dropdown.hidden = true;
				input.blur();
			}
			if ( 'Enter' === event.key ) {
				var firstLink = dropdown.querySelector( 'a' );
				if ( firstLink ) {
					event.preventDefault();
					window.location.href = firstLink.href;
				}
			}
		} );

		// Click outside closes.
		document.addEventListener( 'click', function ( event ) {
			if ( ! wrap.contains( event.target ) ) {
				dropdown.hidden = true;
			}
		} );

		// Keyboard shortcut: "/" focuses search (unless an input is already focused).
		document.addEventListener( 'keydown', function ( event ) {
			if ( '/' !== event.key ) { return; }
			var active = document.activeElement;
			if ( active && ( active.tagName === 'INPUT' || active.tagName === 'TEXTAREA' || active.isContentEditable ) ) {
				return;
			}
			event.preventDefault();
			input.focus();
			input.select();
		} );
	}

	function init() {
		inject();
		loadIndex();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
