( function ( $ ) {
	'use strict';

	const config = window.awtsAdminBarData || {};
	const key    = config.heartbeatKey || '';
	const nodeId = config.nodeId || '';

	if ( ! key || ! nodeId ) return;

	$( document ).on( 'heartbeat-send', function ( _e, data ) {
		data[ key ] = 1;
	} );

	$( document ).on( 'heartbeat-tick', function ( _e, data ) {
		if ( ! data[ key ] ) return;
		const count = parseInt( data[ key ].count || 0, 10 );
		const items = Array.isArray( data[ key ].items ) ? data[ key ].items : null;
		updateBadge( count, items );
	} );

	// Click: dismiss server-side, remove from DOM, then navigate or focus.
	$( document ).on( 'click', '#' + nodeId + ' .awts-ab-row-item > .ab-item', function ( e ) {
		e.preventDefault();

		const href     = $( this ).attr( 'href' ) || '';
		const $li      = $( this ).closest( 'li' );
		const notifKey = getNotifKey( $li );
		const updateId = getRowId( $li, 'update' );
		const noteId   = getRowId( $li, 'note' );
		// Tab the note lives in (Internal vs Customer), encoded in the deep-link
		// hash. Lets the in-page focus switch tabs, not just scroll.
		const tab      = ( href.match( /#awts-update-\d+-(internal|customer)-note-\d+/ ) || [] )[ 1 ] || '';
		const isDeletedRow = $li.hasClass( 'awts-ab-deleted-row' );

		removeItem( $li );
		dismiss( notifKey );

		if ( ! href ) return;

		// Deleted-update rows always force a navigation/reload — the update
		// card is still in the DOM (the user hasn't refreshed since the
		// delete) so focusInPage would scroll to a stale card and look
		// like nothing happened. Skip the in-page focus path for these.
		if ( isDeletedRow ) {
			if ( href === window.location.href ) {
				window.location.reload();
			} else {
				window.location.href = href;
			}
			return;
		}

		// In-page focus when the target is already on this page: avoid a
		// reload, scroll to the note (or the update card if no note id),
		// and briefly highlight it. Falls through to a reload/navigate when
		// either id is missing from the DOM — that's the signal the user
		// is on a different order or hasn't loaded the freshest data yet.
		if ( focusInPage( updateId, noteId, tab ) ) {
			return;
		}

		// Same URL → browser would no-op the assignment. Force a reload.
		if ( href === window.location.href ) {
			window.location.reload();
		} else {
			window.location.href = href;
		}
	} );

	function focusInPage( updateId, noteId, tab ) {
		if ( ! updateId ) return false;

		const updateEl = document.querySelector( '[data-awts-update-id="' + updateId + '"]' );
		if ( ! updateEl ) return false;

		// Switch to the note's tab (Internal vs Customer) first, so the message
		// is actually on screen — otherwise we'd scroll to a note sitting in a
		// hidden tab panel and nothing would appear to happen.
		if ( tab ) {
			const tabBtn = updateEl.querySelector( '.awts_card_tab[data-awts-tab="' + tab + '"]' );
			if ( tabBtn ) {
				tabBtn.click();
			}
		}

		// Note id present: bail out unless the note is also rendered
		// (admin may not have expanded the customer-notes tab yet, or
		// the note belongs to a not-yet-loaded page of the thread).
		let target = updateEl;
		if ( noteId ) {
			const noteEl = updateEl.querySelector( '[data-awts-note-id="' + noteId + '"]' );
			if ( ! noteEl ) return false;
			target = noteEl;
		}

		target.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		highlightBriefly( target );
		return true;
	}

	function highlightBriefly( el ) {
		el.classList.add( 'awts-ab-focus-flash' );
		window.setTimeout( function () {
			el.classList.remove( 'awts-ab-focus-flash' );
		}, 1600 );
	}

	// "Clear all" footer row — dismiss every active notification for this
	// user in one shot. preventDefault stops the `href="#"` from scrolling
	// to the top of the page; stopPropagation keeps the outer row-item
	// click handler from also firing (the clear-all row is not a row item,
	// but defence in depth doesn't hurt here).
	$( document ).on( 'click', '#' + nodeId + ' .awts-ab-clear-all > .ab-item', function ( e ) {
		e.preventDefault();
		e.stopPropagation();

		const $node = $( '#' + nodeId );
		$node.find( '.awts-ab-row-item' ).remove();
		$node.find( '.awts-ab-section-header' ).remove();
		$node.find( '.awts-ab-clear-all' ).remove();

		const $count = $node.find( '.awts-ab-count' );
		$node.addClass( 'awts-no-notifications' ).removeClass( 'awts-has-notifications' );
		$count.text( '' ).attr( 'aria-hidden', 'true' );

		dismissAll();
	} );

	// Mark-as-read in meta box: dismiss all notifications belonging to that update.
	$( document ).on( 'awts:notes-read', function ( _e, data ) {
		const updateId = parseInt( ( data || {} ).updateId || 0, 10 );
		if ( ! updateId ) return;

		$( '#' + nodeId + ' .awts-ab-update-' + updateId ).each( function () {
			removeItem( $( this ) );
		} );

		dismissForUpdate( updateId );
	} );

	// -------------------------------------------------------------------------

	function updateBadge( count, items ) {
		const $node  = $( '#' + nodeId );
		const $count = $node.find( '.awts-ab-count' );
		if ( ! $node.length || ! $count.length ) return;

		const prevCount = parseInt( $count.text() || '0', 10 );

		if ( count <= 0 ) {
			$node.addClass( 'awts-no-notifications' ).removeClass( 'awts-has-notifications' );
			$count.text( '' ).attr( 'aria-hidden', 'true' );
		} else {
			$node.addClass( 'awts-has-notifications' ).removeClass( 'awts-no-notifications' );
			$count.text( count ).attr( 'aria-hidden', 'false' );
		}

		// Always rebuild when the server sends items, even if the count is
		// unchanged. Without this, a same-count delta (e.g. one dismissed +
		// one new) leaves the submenu showing stale rows that no longer match
		// the server's view, and the user sees ghost notifications.
		if ( items !== null ) {
			rebuildSubmenu( $node, items );
		}
	}

	function rebuildSubmenu( $node, items ) {
		const $submenu = $node.find( '.ab-submenu' );

		if ( ! $submenu.length ) return;

		$submenu.empty();

		items.forEach( function ( item ) {
			if ( item.type === 'header' ) {
				$submenu.append(
					'<li class="awts-ab-section-header">'
					+ '<a class="ab-item" href="#" tabindex="-1">' + esc( item.label || '' ) + '</a>'
					+ '</li>'
				);
			} else if ( item.type === 'view-all' ) {
				$submenu.append(
					'<li class="awts-ab-view-all">'
					+ '<a class="ab-item" href="' + esc( item.url || '' ) + '">' + esc( config.viewAllLabel || '' ) + '</a>'
					+ '</li>'
				);
			} else if ( item.type === 'show-all' ) {
				$submenu.append(
					'<li class="awts-ab-show-all">'
					+ '<a class="ab-item" href="' + esc( item.url || '' ) + '">' + esc( config.showAllLabel || '' ) + '</a>'
					+ '</li>'
				);
			} else if ( item.type === 'clear-all' ) {
				$submenu.append(
					'<li class="awts-ab-clear-all">'
					+ '<a class="ab-item" href="#">' + esc( config.clearAllLabel || '' ) + '</a>'
					+ '</li>'
				);
			} else {
				const updateId = parseInt( item.update_id || 0, 10 );
				const noteId   = parseInt( item.note_id || 0, 10 );
				const timeHtml = item.time_ago ? ' &middot; ' + esc( item.time_ago ) : '';
				const noteCls  = noteId ? ' awts-ab-note-' + noteId : '';
				const noteAttr = noteId ? ' data-awts-note-id="' + noteId + '"' : '';
				$submenu.append(
					'<li class="awts-ab-row-item awts-ab-update-' + updateId + noteCls + '"'
					+ ' data-awts-notif-key="' + esc( item.notif_key || '' ) + '"'
					+ ' data-awts-update-id="' + updateId + '"'
					+ noteAttr + '>'
					+ '<a class="ab-item" href="' + esc( item.url || '' ) + '">'
					+ '<span class="awts-ab-row">'
					+ '<span class="awts-ab-row-title">' + esc( item.title || '' ) + '</span>'
					+ '<span class="awts-ab-row-meta">' + esc( item.meta || '' ) + timeHtml + '</span>'
					+ '</span></a></li>'
				);
			}
		} );
	}

	function getNotifKey( $li ) {
		// JS-rebuilt items carry the key as a data attribute.
		const fromData = ( $li.data( 'awts-notif-key' ) || '' ).toString();
		if ( fromData ) return fromData;

		// PHP-rendered items carry the key in a CSS class: awts-ab-notif-{key}.
		// sanitize_html_class preserves underscores, so "reply_7" → "awts-ab-notif-reply_7".
		const match = ( $li.attr( 'class' ) || '' ).match( /\bawts-ab-notif-([\w-]+)\b/ );
		return match ? match[ 1 ] : '';
	}

	// kind = 'update' or 'note'. JS-rebuilt items carry data attrs; PHP-rendered
	// items carry the id in a `awts-ab-{kind}-{N}` class. Returns 0 if absent.
	function getRowId( $li, kind ) {
		const fromData = parseInt( $li.attr( 'data-awts-' + kind + '-id' ) || '0', 10 );
		if ( fromData ) return fromData;

		const re = new RegExp( '\\bawts-ab-' + kind + '-(\\d+)\\b' );
		const match = ( $li.attr( 'class' ) || '' ).match( re );
		return match ? parseInt( match[ 1 ], 10 ) : 0;
	}

	function removeItem( $li ) {
		const $node = $( '#' + nodeId );
		$li.remove();

		$node.find( '.awts-ab-section-header' ).each( function () {
			if ( ! $( this ).nextAll( '.awts-ab-row-item' ).length ) {
				$( this ).remove();
			}
		} );

		const remaining = $node.find( '.awts-ab-row-item' ).length;
		const $count    = $node.find( '.awts-ab-count' );

		if ( remaining <= 0 ) {
			$node.addClass( 'awts-no-notifications' ).removeClass( 'awts-has-notifications' );
			$count.text( '' ).attr( 'aria-hidden', 'true' );
		} else {
			$count.text( remaining ).attr( 'aria-hidden', 'false' );
		}
	}

	function dismiss( notifKey ) {
		if ( ! notifKey || ! config.ajaxUrl || ! config.dismissNonce ) return;

		const data = new FormData();
		data.append( 'action', 'awts_dismiss_notification' );
		data.append( 'notif_key', notifKey );
		data.append( 'nonce', config.dismissNonce );

		// sendBeacon can return false when the browser refuses (queue full,
		// CSP blocked, etc). Fall through to $.post in that case so the
		// notification actually gets dismissed server-side — otherwise the
		// next heartbeat re-paints it and the user thinks the click did nothing.
		const queued = navigator.sendBeacon
			? navigator.sendBeacon( config.ajaxUrl, data )
			: false;

		if ( ! queued ) {
			$.post( config.ajaxUrl, {
				action:    'awts_dismiss_notification',
				notif_key: notifKey,
				nonce:     config.dismissNonce,
			} );
		}
	}

	function dismissAll() {
		const action = config.clearAllAction || '';
		if ( ! action || ! config.ajaxUrl || ! config.dismissNonce ) return;

		const data = new FormData();
		data.append( 'action', action );
		data.append( 'nonce', config.dismissNonce );

		const queued = navigator.sendBeacon
			? navigator.sendBeacon( config.ajaxUrl, data )
			: false;

		if ( ! queued ) {
			$.post( config.ajaxUrl, {
				action: action,
				nonce:  config.dismissNonce,
			} );
		}
	}

	function dismissForUpdate( updateId ) {
		if ( ! updateId || ! config.ajaxUrl || ! config.dismissNonce ) return;

		const data = new FormData();
		data.append( 'action', 'awts_dismiss_update_notifications' );
		data.append( 'update_id', updateId );
		data.append( 'nonce', config.dismissNonce );

		const queued = navigator.sendBeacon
			? navigator.sendBeacon( config.ajaxUrl, data )
			: false;

		if ( ! queued ) {
			$.post( config.ajaxUrl, {
				action:    'awts_dismiss_update_notifications',
				update_id: updateId,
				nonce:     config.dismissNonce,
			} );
		}
	}

	function esc( str ) {
		return $( '<span>' ).text( str ).html();
	}

} )( jQuery );
