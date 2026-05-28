/**
 * Form handlers for the landing page — contact form + email-gated download.
 *
 * Both forms POST to Web3Forms (https://api.web3forms.com/submit) as
 * `multipart/form-data`. The endpoint handles spam protection and relays
 * the submission to the configured support inbox; the autoresponder
 * (configured in the Web3Forms dashboard) sends the download link to the
 * user when the "Download requested" subject comes in.
 *
 * Replaces the alert()-based success path with inline status UI so the
 * page doesn't context-switch on submit.
 */
( function () {
	'use strict';

	var ENDPOINT = 'https://api.web3forms.com/submit';

	function init( form ) {
		var submitBtn = form.querySelector( 'button[type="submit"]' );
		var statusEl  = form.querySelector( '[data-status]' );
		var successEl = document.getElementById( form.dataset.successId || '' );

		if ( ! submitBtn ) {
			return;
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			submit( form, submitBtn, statusEl, successEl );
		} );
	}

	async function submit( form, submitBtn, statusEl, successEl ) {
		var originalText = submitBtn.textContent;

		setStatus( statusEl, '', null );
		submitBtn.textContent = 'Sending…';
		submitBtn.disabled    = true;

		// If the form points at a Cloudflare Worker (Mailchimp proxy),
		// POST JSON instead of multipart form-data. Same success/error UI.
		var workerUrl = form.dataset.mailchimpWorker;

		try {
			var response;
			if ( workerUrl ) {
				var emailInput = form.querySelector( 'input[type="email"]' );
				response = await fetch( workerUrl, {
					method:  'POST',
					headers: { 'Content-Type': 'application/json' },
					body:    JSON.stringify( {
						email: emailInput ? emailInput.value : '',
						site:  window.location.origin,
					} ),
				} );
			} else {
				response = await fetch( ENDPOINT, {
					method: 'POST',
					body:   new FormData( form ),
				} );
			}

			var data = {};
			try {
				data = await response.json();
			} catch ( parseErr ) {
				// Non-JSON response → fall through to the error branch below.
			}

			if ( response.ok && data && data.success !== false ) {
				// Swap the form for the success block so the page reads as
				// "done" without an alert popping over the layout.
				if ( successEl ) {
					form.hidden    = true;
					successEl.hidden = false;
					successEl.scrollIntoView( { behavior: 'smooth', block: 'center' } );
				} else {
					setStatus( statusEl, 'Thanks — your message is on its way.', 'success' );
					form.reset();
				}
				return;
			}

			var message = 'Something went wrong. Please try again.';
			if ( data ) {
				if ( data.mailchimp && data.mailchimp.detail ) {
					message = data.mailchimp.detail;
				} else if ( data.message ) {
					message = data.message;
				} else if ( data.error === 'invalid_email' ) {
					message = 'Please enter a valid email address.';
				}
			}
			setStatus( statusEl, message, 'error' );
		} catch ( networkErr ) {
			setStatus( statusEl, 'Network error — please try again in a minute.', 'error' );
		} finally {
			submitBtn.textContent = originalText;
			submitBtn.disabled    = false;
		}
	}

	function setStatus( statusEl, message, kind ) {
		if ( ! statusEl ) return;

		statusEl.textContent = message;
		statusEl.classList.remove( 'is-success', 'is-error' );

		if ( kind ) {
			statusEl.classList.add( 'is-' + kind );
			statusEl.hidden = false;
		} else {
			statusEl.hidden = true;
		}
	}

	function boot() {
		var forms = document.querySelectorAll( '.awts-inline-form' );
		Array.prototype.forEach.call( forms, init );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
