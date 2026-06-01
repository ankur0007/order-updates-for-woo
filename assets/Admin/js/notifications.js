/**
 * Notifications inbox behaviour.
 *
 * Progressive enhancement over plain links/forms:
 *  - Row actions (read/unread, favorite, archive, delete) fire over AJAX so
 *    the page never reloads. If the request fails we fall back to the link.
 *  - Select-all + the bulk bar (bulk actions stay a normal form POST).
 *
 * Config arrives in `awtsNotif` (ajaxUrl, action, nonce, status, tips).
 */
( function ( $ ) {
	'use strict';

	var cfg   = window.awtsNotif || {};
	var $form = $( '.awts-inbox__list-form' );
	if ( ! $form.length ) {
		return;
	}

	// ----- Select-all + bulk bar -----
	var $selectAll = $form.find( '#awts-inbox-select-all' );
	var $bulkbar   = $form.find( '.awts-inbox__bulkbar' );

	function refreshBulkbar() {
		$bulkbar.toggleClass( 'is-active', $form.find( '.awts-inbox__check:checked' ).length > 0 );
	}

	$selectAll.on( 'change', function () {
		$form.find( '.awts-inbox__check' ).prop( 'checked', $selectAll.prop( 'checked' ) );
		refreshBulkbar();
	} );

	$form.on( 'change', '.awts-inbox__check', refreshBulkbar );
	refreshBulkbar();

	// ----- Instant row actions over AJAX -----
	if ( ! cfg.ajaxUrl ) {
		return;
	}

	var tips = cfg.tips || {};

	function updateCounts( counts ) {
		if ( ! counts ) {
			return;
		}
		var order = [ 'all', 'unread', 'favorite', 'archived' ];
		$( '.awts-inbox__tab' ).each( function ( i ) {
			if ( order[ i ] in counts ) {
				$( this ).find( '.awts-inbox__tab-count' ).text( counts[ order[ i ] ] );
			}
		} );
	}

	function removeRow( $row ) {
		$row.css( 'opacity', 0 );
		window.setTimeout( function () {
			$row.remove();
		}, 150 );
	}

	function setIcon( $link, icon ) {
		$link.find( '.dashicons' ).attr( 'class', 'dashicons ' + icon );
	}

	function setTip( $link, tip ) {
		$link.attr( 'data-awts-tip', tip ).attr( 'aria-label', tip );
	}

	// Apply the local DOM change for an action; remove the row if it no
	// longer belongs to the tab being viewed.
	function applyResult( $link, $row, action ) {
		var status = cfg.status || '';

		switch ( action ) {
			case 'delete':
			case 'archive':   // leaves any non-archived tab
			case 'unarchive': // only shown on the archived tab, so it leaves
				removeRow( $row );
				return;

			case 'mark_read':
				if ( 'unread' === status ) {
					removeRow( $row );
					return;
				}
				$row.removeClass( 'is-unread' ).addClass( 'is-read' );
				$link.attr( 'data-action', 'mark_unread' );
				setIcon( $link, 'dashicons-marker' );
				setTip( $link, tips.markUnread || '' );
				return;

			case 'mark_unread':
				$row.removeClass( 'is-read' ).addClass( 'is-unread' );
				$link.attr( 'data-action', 'mark_read' );
				setIcon( $link, 'dashicons-yes-alt' );
				setTip( $link, tips.markRead || '' );
				return;

			case 'favorite':
				$row.addClass( 'is-favorited' );
				$link.attr( 'data-action', 'unfavorite' );
				setIcon( $link, 'dashicons-star-filled' );
				setTip( $link, tips.unfavorite || '' );
				return;

			case 'unfavorite':
				if ( 'favorite' === status ) {
					removeRow( $row );
					return;
				}
				$row.removeClass( 'is-favorited' );
				$link.attr( 'data-action', 'favorite' );
				setIcon( $link, 'dashicons-star-empty' );
				setTip( $link, tips.favorite || '' );
				return;
		}
	}

	$form.on( 'click', '.awts-inbox__action[data-action]', function ( event ) {
		var $link  = $( this );
		var action = $link.attr( 'data-action' );
		var key    = $link.attr( 'data-key' );
		if ( ! action || ! key ) {
			return; // No data — let the plain link handle it.
		}

		event.preventDefault();
		var $row = $link.closest( '.awts-inbox__row' );

		$.post( cfg.ajaxUrl, {
			action: cfg.action,
			notif_action: action,
			notif_key: key,
			_ajax_nonce: cfg.nonce
		} ).done( function ( res ) {
			if ( res && res.success ) {
				applyResult( $link, $row, action );
				updateCounts( res.data && res.data.counts );
			} else {
				window.location = $link.attr( 'href' ); // Fallback.
			}
		} ).fail( function () {
			window.location = $link.attr( 'href' ); // Fallback.
		} );
	} );
}( jQuery ) );
