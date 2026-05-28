jQuery(function($) {
	'use strict';

	$( document ).on( 'click', '#awts_welcome_newsletter_change', function( event ) {
		event.preventDefault();

		var $link = $( this );
		$link.css( 'pointer-events', 'none' );

		$.post( ajaxurl, {
			action: 'order_updates_for_woo_newsletter_reset',
			_nonce: awtsNewsletter.nonce
		}, function( response ) {
			if ( response.success ) {
				window.location.reload();
			} else {
				$link.css( 'pointer-events', '' );
			}
		} ).fail( function() {
			$link.css( 'pointer-events', '' );
		} );
	} );

	$( document ).on( 'click', '#awts_newsletter_subscribe, #awts_welcome_newsletter_subscribe', function() {
		var $btn = $( this ),
			$input = $btn.closest( '.awts_newsletter_form, .awts_welcome_newsletter_form' ).find( 'input[type="email"]' ),
			$feedback = $btn.closest( '.awts_newsletter_form, .awts_welcome_newsletter_form' ).siblings( '.awts_newsletter_feedback, .awts_welcome_feedback' ),
			email = $input.val().trim();

		if ( ! email || ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email ) ) {
			$feedback.text( awtsNewsletter.invalidEmail ).css( 'color', '#dc2626' ).show();
			return;
		}

		$btn.prop( 'disabled', true ).text( awtsNewsletter.subscribing );
		$feedback.hide();

		$.post( ajaxurl, {
			action: 'order_updates_for_woo_newsletter_subscribe',
			email: email,
			_nonce: awtsNewsletter.nonce
		}, function( response ) {
			if ( response.success ) {
				$input.hide();
				$btn.hide();
				$feedback.text( response.data.message ).css( 'color', '#16a34a' ).show();
			} else {
				var msg = ( response.data && response.data.message ) ? response.data.message : awtsNewsletter.failed;
				$feedback.text( msg ).css( 'color', '#dc2626' ).show();
				$btn.prop( 'disabled', false ).text( awtsNewsletter.subscribe );
			}
		}).fail( function() {
			$feedback.text( awtsNewsletter.failed ).css( 'color', '#dc2626' ).show();
			$btn.prop( 'disabled', false ).text( awtsNewsletter.subscribe );
		});
	});
});
