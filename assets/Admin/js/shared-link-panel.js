/**
 * No-login chat-link panel (order edit screen).
 *
 * Copy the link, change its expiry, or regenerate it (optionally emailing the
 * fresh link to the customer). Talks to the SharedLinkEndpoint over REST.
 *
 * Endpoints come from the panel's data attributes; the REST nonce + strings
 * come from `awtsData` (localised on the admin meta-box script, which this
 * file depends on). Nonce is read fresh per request because the panel can
 * render before that footer script defines `awtsData`.
 */
( function () {
	'use strict';

	var panel = document.querySelector( '.awts_panel__customer_link' );
	if ( ! panel ) {
		return;
	}

	var copyBtn   = panel.querySelector( '[data-awts-copy-link]' );
	var display   = panel.querySelector( '[data-awts-link-display]' );
	var daysInput = panel.querySelector( '[data-awts-link-days]' );
	var regenBtn  = panel.querySelector( '[data-awts-link-regenerate]' );
	var status    = panel.querySelector( '[data-awts-link-status]' );

	var expiryEndpoint = panel.getAttribute( 'data-awts-link-expiry-endpoint' ) || '';
	var regenEndpoint  = panel.getAttribute( 'data-awts-link-regenerate-endpoint' ) || '';

	var strings = ( window.awtsData && window.awtsData.strings ) || {};
	function t( key, fallback ) {
		return strings[ key ] || fallback;
	}

	function restNonce() {
		return ( window.awtsData && window.awtsData.nonce ) || '';
	}

	var statusTimer;
	function setStatus( text, type ) {
		if ( ! status ) {
			return;
		}
		clearTimeout( statusTimer );
		status.textContent = text || '';
		status.classList.remove( 'is-success', 'is-error' );
		if ( 'success' === type ) {
			status.classList.add( 'is-success' );
		} else if ( 'error' === type ) {
			status.classList.add( 'is-error' );
		}
		// Success notices fade away on their own; errors stay put.
		if ( text && 'success' === type ) {
			statusTimer = setTimeout( function () {
				setStatus( '' );
			}, 4000 );
		}
	}

	function applyState( payload ) {
		if ( ! payload ) {
			return;
		}
		if ( payload.url && display ) {
			display.textContent = payload.url;
		}
		if ( payload.url && copyBtn ) {
			copyBtn.setAttribute( 'data-awts-copy-link', payload.url );
		}
		if ( daysInput && typeof payload.daysLeft !== 'undefined' ) {
			daysInput.value = String( payload.daysLeft );
		}
	}

	function post( url, body, onDone ) {
		if ( ! url ) {
			return;
		}
		fetch( url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': restNonce()
			},
			credentials: 'same-origin',
			body: JSON.stringify( body || {} )
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				return { ok: res.ok, data: data };
			} );
		} ).then( function ( result ) {
			if ( result.ok ) {
				applyState( result.data );
				onDone( null, result.data );
			} else {
				var message = ( result.data && result.data.message ) || t( 'sharedLinkSaveError', 'Could not save. Please try again.' );
				onDone( message, null );
			}
		} ).catch( function () {
			onDone( t( 'sharedLinkNetworkError', 'Network error. Please try again.' ), null );
		} );
	}

	if ( copyBtn ) {
		var defaultLabel = copyBtn.textContent;
		var copiedLabel  = t( 'sharedLinkCopied', 'Copied!' );
		copyBtn.addEventListener( 'click', function () {
			var url = copyBtn.getAttribute( 'data-awts-copy-link' ) || '';
			var done = function () {
				copyBtn.textContent = copiedLabel;
				setTimeout( function () {
					copyBtn.textContent = defaultLabel;
				}, 1500 );
			};
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( url ).then( done, function () {
					window.prompt( '', url );
				} );
				return;
			}
			var ta = document.createElement( 'textarea' );
			ta.value = url;
			ta.setAttribute( 'readonly', '' );
			ta.style.position = 'absolute';
			ta.style.left = '-9999px';
			document.body.appendChild( ta );
			ta.select();
			try {
				document.execCommand( 'copy' );
				done();
			} catch ( e ) {}
			document.body.removeChild( ta );
		} );
	}

	if ( daysInput ) {
		var lastSent = parseInt( daysInput.value, 10 );
		daysInput.addEventListener( 'change', function () {
			var days = parseInt( daysInput.value, 10 );
			if ( isNaN( days ) || days < 1 || days > 365 ) {
				daysInput.value = String( lastSent || 30 );
				return;
			}
			if ( days === lastSent ) {
				return;
			}
			setStatus( t( 'sharedLinkSaving', 'Saving…' ) );
			post( expiryEndpoint, { days: days }, function ( err ) {
				if ( err ) {
					setStatus( err, 'error' );
					return;
				}
				lastSent = days;
				setStatus( t( 'sharedLinkSaved', 'Saved.' ), 'success' );
			} );
		} );
	}

	var confirmBox = panel.querySelector( '[data-awts-link-confirm]' );
	var notifyChk  = panel.querySelector( '[data-awts-link-notify]' );
	var confirmGo  = panel.querySelector( '[data-awts-link-confirm-go]' );
	var cancelBtn  = panel.querySelector( '[data-awts-link-confirm-cancel]' );

	if ( regenBtn && confirmBox ) {
		regenBtn.addEventListener( 'click', function () {
			if ( notifyChk ) {
				notifyChk.checked = false;
			}
			confirmBox.hidden = false;
		} );
	}

	if ( cancelBtn && confirmBox ) {
		cancelBtn.addEventListener( 'click', function () {
			confirmBox.hidden = true;
		} );
	}

	if ( confirmGo && confirmBox ) {
		confirmGo.addEventListener( 'click', function () {
			var days   = parseInt( ( daysInput && daysInput.value ) || '0', 10 );
			var notify = !! ( notifyChk && notifyChk.checked );
			confirmBox.hidden = true;
			setStatus( t( 'sharedLinkRegenerating', 'Regenerating…' ) );
			post( regenEndpoint, { days: days, notify_customer: notify }, function ( err, data ) {
				if ( err ) {
					setStatus( err, 'error' );
					return;
				}
				var msg = ( data && data.emailQueued )
					? t( 'sharedLinkRegeneratedEmailed', 'New link generated and emailed to the customer.' )
					: t( 'sharedLinkRegenerated', 'New link generated. The old one no longer works.' );
				setStatus( msg, 'success' );
			} );
		} );
	}
}() );
