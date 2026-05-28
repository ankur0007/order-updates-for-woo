/**
 * Pull releases + open bug issues from the GitHub API and render them.
 *
 * No auth needed for public read endpoints — GitHub's unauthenticated
 * rate limit (60/hour/IP) is plenty for a marketing page. If either
 * endpoint fails we show the matching error block, which keeps a link
 * to the GitHub page so visitors aren't left stranded.
 */
( function () {
	'use strict';

	var REPO = 'ankur0007/order-updates-for-woo';

	function boot() {
		loadReleases();
		loadIssues();
	}

	function loadReleases() {
		var list   = document.querySelector( '[data-releases-list]' );
		var empty  = document.querySelector( '[data-releases-empty]' );
		var error  = document.querySelector( '[data-releases-error]' );
		if ( ! list ) return;

		fetch( 'https://api.github.com/repos/' + REPO + '/releases?per_page=30' )
			.then( function ( r ) {
				if ( ! r.ok ) throw new Error( 'releases fetch failed' );
				return r.json();
			} )
			.then( function ( releases ) {
				list.innerHTML = '';

				// Filter out drafts; show pre-releases with a badge.
				releases = ( releases || [] ).filter( function ( r ) {
					return ! r.draft;
				} );

				if ( ! releases.length ) {
					list.hidden = true;
					if ( empty ) empty.hidden = false;
					return;
				}

				releases.forEach( function ( release ) {
					list.appendChild( renderRelease( release ) );
				} );
			} )
			.catch( function () {
				list.hidden = true;
				if ( error ) error.hidden = false;
			} );
	}

	function loadIssues() {
		var list  = document.querySelector( '[data-issues-list]' );
		var empty = document.querySelector( '[data-issues-empty]' );
		var error = document.querySelector( '[data-issues-error]' );
		if ( ! list ) return;

		// `?labels=bug` filters to issues tagged "bug" so we don't list
		// feature requests + questions in this section.
		fetch( 'https://api.github.com/repos/' + REPO + '/issues?state=open&labels=bug&per_page=50' )
			.then( function ( r ) {
				if ( ! r.ok ) throw new Error( 'issues fetch failed' );
				return r.json();
			} )
			.then( function ( issues ) {
				list.innerHTML = '';

				// GitHub's issues endpoint also returns pull-requests — strip them.
				issues = ( issues || [] ).filter( function ( i ) {
					return ! i.pull_request;
				} );

				if ( ! issues.length ) {
					list.hidden = true;
					if ( empty ) empty.hidden = false;
					return;
				}

				issues.forEach( function ( issue ) {
					list.appendChild( renderIssue( issue ) );
				} );
			} )
			.catch( function () {
				list.hidden = true;
				if ( error ) error.hidden = false;
			} );
	}

	function renderRelease( release ) {
		var article = document.createElement( 'article' );
		article.className = 'changelog__release';

		var date    = release.published_at ? formatDate( release.published_at ) : '';
		var badge   = release.prerelease ? '<span class="changelog__release__badge changelog__release__badge--pre">Pre-release</span>' : '';
		var version = esc( release.tag_name || release.name || 'Untitled' );
		var title   = esc( release.name || release.tag_name || '' );
		var body    = markdownToHtml( release.body || '' );

		article.innerHTML =
			'<header class="changelog__release__head">' +
				'<div class="changelog__release__version">' +
					'<a href="' + esc( release.html_url ) + '" target="_blank" rel="noopener">' + version + '</a>' +
					badge +
				'</div>' +
				( date ? '<time class="changelog__release__date">' + esc( date ) + '</time>' : '' ) +
			'</header>' +
			( title && title !== version ? '<h3 class="changelog__release__title">' + title + '</h3>' : '' ) +
			'<div class="changelog__release__body">' + body + '</div>';

		return article;
	}

	function renderIssue( issue ) {
		var row = document.createElement( 'li' );
		row.className = 'changelog__issue';

		var labels = ( issue.labels || [] )
			.filter( function ( l ) { return l.name !== 'bug'; } )
			.map( function ( l ) {
				return '<span class="changelog__issue__label">' + esc( l.name ) + '</span>';
			} )
			.join( '' );

		var comments = parseInt( issue.comments, 10 ) || 0;

		row.innerHTML =
			'<a class="changelog__issue__link" href="' + esc( issue.html_url ) + '" target="_blank" rel="noopener">' +
				'<span class="changelog__issue__number">#' + issue.number + '</span>' +
				'<span class="changelog__issue__title">' + esc( issue.title ) + '</span>' +
				'<span class="changelog__issue__labels">' + labels + '</span>' +
				'<span class="changelog__issue__comments" title="Comments">' +
					'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>' +
					comments +
				'</span>' +
			'</a>';

		return row;
	}

	/**
	 * Very small Markdown-ish renderer for release notes. Handles bullets,
	 * code spans, bold, italics, autolinks. Not a full parser — release
	 * notes that need rich formatting should link to GitHub for the full read.
	 */
	function markdownToHtml( md ) {
		if ( ! md ) return '<p class="changelog__release__empty">No release notes for this version.</p>';

		var lines = md.split( /\r?\n/ );
		var html  = '';
		var inList = false;

		function close() {
			if ( inList ) { html += '</ul>'; inList = false; }
		}

		lines.forEach( function ( line ) {
			line = line.replace( /\s+$/, '' );

			// Markdown heading → small strong line.
			if ( /^#{1,6}\s+/.test( line ) ) {
				close();
				html += '<p class="changelog__release__h">' + inline( line.replace( /^#{1,6}\s+/, '' ) ) + '</p>';
				return;
			}

			// Bullet list item.
			if ( /^\s*[-*+]\s+/.test( line ) ) {
				if ( ! inList ) { html += '<ul>'; inList = true; }
				html += '<li>' + inline( line.replace( /^\s*[-*+]\s+/, '' ) ) + '</li>';
				return;
			}

			// Blank line → paragraph break.
			if ( ! line.trim() ) {
				close();
				return;
			}

			close();
			html += '<p>' + inline( line ) + '</p>';
		} );
		close();
		return html;
	}

	/** Inline markdown: code, bold, italics, URLs. */
	function inline( str ) {
		str = esc( str );
		str = str.replace( /`([^`]+)`/g, '<code>$1</code>' );
		str = str.replace( /\*\*([^*]+)\*\*/g, '<strong>$1</strong>' );
		str = str.replace( /(^|[^*])\*([^*]+)\*/g, '$1<em>$2</em>' );
		str = str.replace( /(https?:\/\/[^\s)<]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>' );
		return str;
	}

	function formatDate( iso ) {
		try {
			var d = new Date( iso );
			return d.toLocaleDateString( undefined, { year: 'numeric', month: 'short', day: 'numeric' } );
		} catch ( e ) {
			return iso.substring( 0, 10 );
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
