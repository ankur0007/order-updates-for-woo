/**
 * Wraps every code block in a container with a header bar; the header
 * holds a "Copy" button. Pure DOM, no dependencies.
 */
( function () {
	'use strict';

	function copyToClipboard( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.style.position = 'fixed';
		ta.style.left = '-9999px';
		document.body.appendChild( ta );
		ta.select();
		try { document.execCommand( 'copy' ); } catch ( e ) { /* silent */ }
		document.body.removeChild( ta );
		return Promise.resolve();
	}

	function attach( pre ) {
		if ( pre.dataset.copyAttached ) {
			return;
		}
		var code = pre.querySelector( 'code' );
		if ( ! code ) {
			return;
		}
		pre.dataset.copyAttached = '1';

		// Resolve the language for the header label.
		var lang = '';
		if ( code.className ) {
			var m = code.className.match( /language-([a-z0-9-]+)/i );
			if ( m ) {
				lang = m[ 1 ];
			}
		}

		// Build wrapper.
		var wrap = document.createElement( 'div' );
		wrap.className = 'docs-code-block';

		var header = document.createElement( 'div' );
		header.className = 'docs-code-block__header';

		if ( lang ) {
			var langLabel = document.createElement( 'span' );
			langLabel.className = 'docs-code-block__lang';
			langLabel.textContent = lang;
			header.appendChild( langLabel );
		}

		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'docs-copy-btn';
		btn.setAttribute( 'aria-label', 'Copy code to clipboard' );
		btn.innerHTML =
			'<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			+ '<rect x="9" y="9" width="13" height="13" rx="2"></rect>'
			+ '<path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"></path>'
			+ '</svg><span>Copy</span>';

		btn.addEventListener( 'click', function () {
			var label = btn.querySelector( 'span' );
			copyToClipboard( code.textContent ).then( function () {
				btn.classList.add( 'is-copied' );
				if ( label ) { label.textContent = 'Copied'; }
				setTimeout( function () {
					btn.classList.remove( 'is-copied' );
					if ( label ) { label.textContent = 'Copy'; }
				}, 1800 );
			} );
		} );

		header.appendChild( btn );

		// Inject the wrap into the DOM in place of <pre>, then move <pre> inside.
		pre.parentNode.insertBefore( wrap, pre );
		wrap.appendChild( header );
		wrap.appendChild( pre );
	}

	function init() {
		var pres = document.querySelectorAll( '.docs-page pre, .docs-landing__sections pre' );
		Array.prototype.forEach.call( pres, attach );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
