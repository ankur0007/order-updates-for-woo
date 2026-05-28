/**
 * Assignee rotation field — drag-and-drop reordering for the settings page.
 *
 * Wires jQuery UI Sortable onto the list rendered by AssigneeRotationField.
 * The DOM order is the saved order — when the form submits, the checkbox
 * inputs serialize in their current visual order, and the field's PHP
 * sanitize handler stores them as-is.
 */
jQuery( function ( $ ) {
	'use strict';

	$( '[data-awts-rotation-list]' ).each( function () {
		$( this ).sortable( {
			items: '> li',
			handle: '.awts_assignee_rotation__handle',
			axis: 'y',
			placeholder: 'awts_assignee_rotation__placeholder',
			tolerance: 'pointer',
		} );
	} );
} );
