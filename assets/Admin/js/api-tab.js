/**
 * API Endpoints settings tab — copy-curl-to-clipboard.
 *
 * Each endpoint card has a `[data-awts-copy-target]` button that copies
 * the matching `<pre data-awts-curl-id>` content. Falls back to a manual
 * select-and-copy hint if the Clipboard API is unavailable (very old
 * browsers or insecure contexts).
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var trigger = event.target.closest( '[data-awts-copy-target]' );

		if ( ! trigger ) {
			return;
		}

		event.preventDefault();

		var targetId = trigger.getAttribute( 'data-awts-copy-target' );
		var pre      = targetId ? document.getElementById( targetId ) : null;

		if ( ! pre ) {
			return;
		}

		var text = pre.innerText || pre.textContent || '';

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then(
				function () { flash( trigger ); },
				function () { selectFallback( pre ); }
			);
			return;
		}

		selectFallback( pre );
	} );

	function flash( trigger ) {
		var original = trigger.textContent;
		trigger.textContent = trigger.getAttribute( 'data-awts-copied-label' ) || 'Copied';
		window.setTimeout( function () { trigger.textContent = original; }, 1400 );
	}

	function selectFallback( pre ) {
		var range = document.createRange();
		range.selectNodeContents( pre );
		var selection = window.getSelection();
		selection.removeAllRanges();
		selection.addRange( range );
	}
} )();
