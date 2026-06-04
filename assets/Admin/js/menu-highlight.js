/**
 * Move the admin-menu highlight to "Order Updates" on the settings tab.
 *
 * The settings page physically lives under WooCommerce > Settings, so WordPress
 * highlights the WooCommerce menu. This script is enqueued only on that tab, so
 * it can shift the "current" highlight to our own menu unconditionally. WordPress
 * puts the highlight classes on BOTH the top-level <li> and its <a class="menu-top">
 * (the colour + the arrow indicator are driven by the anchor), so we toggle both.
 * The PHP parent_file / submenu_file filters do the same server-side (no flash);
 * this is the client-side guarantee.
 *
 * @package OrderUpdatesForWoo
 */

( function () {
	'use strict';

	// Apply or clear the "current top-level menu" state on a menu <li> and its
	// top-level anchor together, so both the background colour and the arrow
	// indicator move with it.
	function setTopLevelCurrent( li, isCurrent ) {
		if ( ! li ) {
			return;
		}

		var add  = isCurrent ? [ 'wp-has-current-submenu', 'wp-menu-open' ] : [ 'wp-not-current-submenu' ];
		var drop = isCurrent ? [ 'wp-not-current-submenu' ] : [ 'wp-has-current-submenu', 'wp-menu-open', 'current' ];
		var els  = [ li, li.querySelector( 'a.menu-top' ) ];

		els.forEach(
			function ( el ) {
				if ( ! el ) {
						return;
				}

				drop.forEach(
					function ( cls ) {
						el.classList.remove( cls );
					} 
				);
				add.forEach(
					function ( cls ) {
						el.classList.add( cls );
					} 
				);
			} 
		);
	}

	// Clear the "current" sub-item under a menu (the one WordPress highlighted).
	function clearCurrentSubItem( li ) {
		if ( ! li ) {
			return;
		}

		var current = li.querySelector( '.wp-submenu li.current' );

		if ( current ) {
			current.classList.remove( 'current' );

			var link = current.querySelector( 'a' );

			if ( link ) {
				link.removeAttribute( 'aria-current' );
			}
		}
	}

	function moveHighlight() {
		var ours = document.getElementById( 'toplevel_page_order-updates-for-woo' );

		if ( ! ours ) {
			return;
		}

		var woo = document.getElementById( 'toplevel_page_woocommerce' );

		setTopLevelCurrent( woo, false );
		clearCurrentSubItem( woo );
		setTopLevelCurrent( ours, true );

		// Mark our Settings sub-item current.
		var settings = ours.querySelector( 'a[href*="tab=order_updates_for_woo"]' );

		if ( settings ) {
			var item = settings.closest( 'li' );

			if ( item ) {
				item.classList.add( 'current' );
			}

			settings.setAttribute( 'aria-current', 'page' );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', moveHighlight );
	} else {
		moveHighlight();
	}
}() );
