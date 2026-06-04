/**
 * Move the admin-menu highlight to "Order Updates" on the settings tab.
 *
 * The settings page physically lives under WooCommerce > Settings, so WordPress
 * highlights the WooCommerce menu. This script is enqueued only on that tab, so
 * it can shift the "current" highlight to our own menu unconditionally: it drops
 * the highlight from the WooCommerce item and marks our menu + Settings sub-item
 * current. The PHP parent_file / submenu_file filters do the same server-side
 * (no flash); this is the client-side guarantee.
 *
 * @package OrderUpdatesForWoo
 */

( function () {
	'use strict';

	function moveHighlight() {
		var ours = document.getElementById( 'toplevel_page_order-updates-for-woo' );

		if ( ! ours ) {
			return;
		}

		// Drop the highlight from the WooCommerce menu and its current sub-item.
		var woo = document.getElementById( 'toplevel_page_woocommerce' );

		if ( woo ) {
			woo.classList.remove( 'wp-has-current-submenu', 'wp-menu-open', 'current' );
			woo.classList.add( 'wp-not-current-submenu' );

			var wooCurrent = woo.querySelector( '.current' );

			if ( wooCurrent ) {
				wooCurrent.classList.remove( 'current' );

				var wooLink = wooCurrent.querySelector( 'a' );

				if ( wooLink ) {
					wooLink.removeAttribute( 'aria-current' );
				}
			}
		}

		// Highlight our menu and open its submenu.
		ours.classList.remove( 'wp-not-current-submenu' );
		ours.classList.add( 'wp-has-current-submenu', 'wp-menu-open' );

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
