/**
 * Augments any text input rendered with `class="awts-media-picker"` on the
 * Order Updates settings screen with a "Choose image" button that opens the
 * WP Media Library and writes the chosen attachment URL back into the input.
 *
 * Plain URL paste still works — the button is purely additive.
 */
( function ( $ ) {
	$( function () {
		if ( typeof wp === 'undefined' || ! wp.media ) {
			return;
		}

		$( 'input.awts-media-picker' ).each( function () {
			var $input = $( this );

			if ( $input.next( '.awts-media-picker__btn' ).length ) {
				return;
			}

			var $btn = $(
				'<button type="button" class="button awts-media-picker__btn" style="margin-left:8px;">' +
					'Choose image' +
				'</button>'
			);

			$input.after( $btn );

			$btn.on( 'click', function ( event ) {
				event.preventDefault();

				var frame = wp.media( {
					title:    'Choose background image',
					button:   { text: 'Use this image' },
					library:  { type: 'image' },
					multiple: false,
				} );

				frame.on( 'select', function () {
					var attachment = frame.state().get( 'selection' ).first().toJSON();
					if ( attachment && attachment.url ) {
						$input.val( attachment.url ).trigger( 'change' );
					}
				} );

				frame.open();
			} );
		} );
	} );
}( jQuery ) );
