/**
 * Order lock banner — heartbeat listener.
 *
 * The banner HTML is rendered server-side by OrderLockBanner.php on every
 * page load. This script only needs to catch lock-state transitions that
 * happen between page loads (someone else grabs the lock, or the holder
 * releases it and the lock comes back to us) and reload so PHP can
 * re-render with the new state.
 *
 * Care must be taken NOT to reload on every heartbeat — WC's heartbeat
 * reports `error` every tick as long as the lock is still held by the
 * same person. Reloading on every error would loop forever. So we
 * compare the heartbeat's `user_name` against the holder PHP rendered
 * the banner for and only reload when the identity changes (or the
 * lock returns to us).
 */
( function ( $ ) {
	'use strict';

	if ( ! $ ) {
		return;
	}

	var reloading = false;

	$( document ).on( 'heartbeat-tick.awts-order-lock', function ( event, data ) {
		if ( reloading ) {
			return;
		}

		var payload = data && data['wc-refresh-order-lock'];
		if ( ! payload ) {
			return;
		}

		var renderedHolder = ( window.awtsOrderLockData && window.awtsOrderLockData.currentHolderName ) || '';
		var shouldReload   = false;

		if ( payload.error ) {
			// Someone else holds the lock. If it's the same person PHP
			// rendered for, the banner is already correct — nothing to do.
			// If a different person now holds it, reload so the new name
			// and avatar paint.
			if ( payload.error.user_name && payload.error.user_name !== renderedHolder ) {
				shouldReload = true;
			}
		} else if ( payload.lock ) {
			// No error means the lock is ours now. If a banner was on
			// screen, it must come down — reload so PHP renders the
			// normal (banner-free) edit page.
			if ( renderedHolder ) {
				shouldReload = true;
			}
		}

		if ( shouldReload ) {
			reloading = true;
			window.location.reload();
		}
	} );
} )( window.jQuery );
