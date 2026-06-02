/**
 * Assignments page filters — submit on search-clear and on select change.
 * Progressive enhancement: the form still works via its Filter button without
 * this. Clearing the search (native ×) reloads the unfiltered list instead of
 * leaving ?s= stuck in the URL.
 */
( function ( $ ) {
	'use strict';

	var form = document.querySelector( '.awts-asg__filters' );
	if ( ! form ) {
		return;
	}

	$( form ).find( 'input[type="search"]' ).on( 'search', function () {
		form.submit();
	} );

	$( form ).find( 'select' ).on( 'change', function () {
		form.submit();
	} );
}( jQuery ) );
