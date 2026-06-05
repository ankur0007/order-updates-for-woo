/**
 * "Enjoying the plugin?" review notice (orders list + order edit screens).
 *
 * Click a star to expand the form, then submit a rating to Web3Forms. On
 * success the page redirects to the "already rated" URL so the notice stops
 * coming back. That URL comes from `awtsReviewNotice` (localised on this
 * script).
 */
( function () {
	'use strict';

	var notice = document.querySelector( '[data-awts-review-notice]' );
	if ( ! notice ) {
		return;
	}

	var stars   = notice.querySelectorAll( '[data-awts-star]' );
	var form    = notice.querySelector( '[data-awts-review-form]' );
	var input   = notice.querySelector( '[data-awts-rating-input]' );
	var status  = notice.querySelector( '[data-awts-status]' );
	var markUrl = ( window.awtsReviewNotice && window.awtsReviewNotice.markUrl ) || '';

	function paint( picked ) {
		stars.forEach( function ( star, i ) {
			star.style.color = ( i < picked ) ? '#f59e0b' : '#cbd5e1';
		} );
	}

	stars.forEach( function ( star, i ) {
		var rank = i + 1;
		star.addEventListener( 'mouseenter', function () { paint( rank ); } );
		star.addEventListener( 'mouseleave', function () { paint( parseInt( input.value || '0', 10 ) ); } );
		star.addEventListener( 'click', function () {
			input.value = rank;
			paint( rank );
			form.style.display = 'block';
		} );
	} );

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		if ( ! input.value ) {
			return;
		}
		status.style.display = 'inline';
		status.textContent   = 'Sending…';
		status.style.color   = '#475569';

		fetch( 'https://api.web3forms.com/submit', {
			method: 'POST',
			body:   new FormData( form ),
		} )
			.then( function ( r ) { return r.json().catch( function () { return {}; } ); } )
			.then( function ( data ) {
				if ( data && data.success !== false ) {
					status.textContent = '✓ Thanks — submitted!';
					status.style.color = '#059669';
					setTimeout( function () { window.location.href = markUrl; }, 1200 );
				} else {
					status.textContent = '⚠ ' + ( ( data && data.message ) || 'Could not send. Try again.' );
					status.style.color = '#b91c1c';
				}
			} )
			.catch( function () {
				status.textContent = '⚠ Network error.';
				status.style.color = '#b91c1c';
			} );
	} );
} )();
