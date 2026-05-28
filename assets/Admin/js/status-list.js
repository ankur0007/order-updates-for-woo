/**
 * Status-list settings field — admin-managed list of update statuses.
 *
 * Responsibilities: drag-to-reorder via jQuery UI Sortable, add-row button
 * that clones a hidden <template>, remove-row button. Persistence is all
 * standard form submit; this script only manages the in-form state.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		$( '[data-awts-status-list]' ).each( function () {
			const $list = $( this );
			const $template = $list.next( 'button' ).next( 'template[data-awts-status-template]' );

			$list.sortable( {
				handle: '.awts_status_list__handle',
				placeholder: 'awts_status_list__item awts_status_list__item--placeholder',
				forcePlaceholderSize: true,
				tolerance: 'pointer',
			} );

			// Add — clone the template's first <li> and append.
			$list.parent().on( 'click', '[data-awts-status-add]', function ( event ) {
				event.preventDefault();
				if ( ! $template.length ) return;

				// <template> contents live in $template[0].content (DocumentFragment).
				const fragment = $template[ 0 ].content.cloneNode( true );
				$list.append( fragment );
				$list.find( '[data-awts-status-row]:last .awts_status_list__label' ).trigger( 'focus' );
			} );

			// Remove — drop the row. Keep at least one row so the form
			// dropdown is never rendered empty; if the admin really wants
			// zero statuses, the sanitizer reseeds the defaults.
			$list.on( 'click', '[data-awts-status-remove]', function ( event ) {
				event.preventDefault();
				const $row = $( this ).closest( '[data-awts-status-row]' );
				if ( $list.find( '[data-awts-status-row]' ).length > 1 ) {
					$row.remove();
				} else {
					$row.find( '.awts_status_list__label' ).val( '' ).trigger( 'focus' );
				}
			} );
		} );
	} );
} )( jQuery );
