/**
 * Click-to-zoom for any screenshot inside .docs-row__media.
 * Plain DOM, no dependencies. Loaded with `defer` from every page.
 */
( function () {
	'use strict';

	var lightbox = null;

	function buildLightbox() {
		if ( lightbox ) {
			return lightbox;
		}

		lightbox = document.createElement( 'div' );
		lightbox.className = 'docs-lightbox';
		lightbox.setAttribute( 'role', 'dialog' );
		lightbox.setAttribute( 'aria-modal', 'true' );
		lightbox.innerHTML = ''
			+ '<button type="button" class="docs-lightbox__close" aria-label="Close (Esc)">&times;</button>'
			+ '<div class="docs-lightbox__inner">'
			+   '<img alt="" />'
			+   '<p class="docs-lightbox__caption"></p>'
			+ '</div>';

		document.body.appendChild( lightbox );
		return lightbox;
	}

	function open( src, alt, caption ) {
		var lb = buildLightbox();
		var img = lb.querySelector( 'img' );
		var cap = lb.querySelector( '.docs-lightbox__caption' );

		img.src = src;
		img.alt = alt || '';

		if ( caption ) {
			cap.textContent = caption;
			cap.style.display = '';
		} else {
			cap.textContent = '';
			cap.style.display = 'none';
		}

		lb.classList.add( 'is-visible' );
		document.body.style.overflow = 'hidden';
	}

	function close() {
		if ( ! lightbox ) {
			return;
		}
		lightbox.classList.remove( 'is-visible' );
		document.body.style.overflow = '';
	}

	function onClick( event ) {
		// Image click — open. Works on docs pages (.docs-row__media) and
		// the landing page (any image inside a .landing__zoom wrapper).
		var img = event.target.closest( '.docs-row__media img, .landing__zoom img' );
		if ( img ) {
			event.preventDefault();
			var mediaEl = img.closest( '.docs-row__media, .landing__zoom' );
			var captionEl = mediaEl ? mediaEl.querySelector( '.docs-row__caption, .landing__zoom__caption' ) : null;
			open( img.src, img.alt, captionEl ? captionEl.textContent : '' );
			return;
		}

		// Click anywhere on the overlay (or the close button) — close.
		if ( lightbox && lightbox.classList.contains( 'is-visible' ) ) {
			if ( event.target.classList.contains( 'docs-lightbox' )
				|| event.target.classList.contains( 'docs-lightbox__close' )
				|| event.target.classList.contains( 'docs-lightbox__inner' )
			) {
				close();
			}
		}
	}

	document.addEventListener( 'click', onClick );

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key && lightbox && lightbox.classList.contains( 'is-visible' ) ) {
			close();
		}
	} );
} )();
