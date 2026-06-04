jQuery( function( $ ) {
	'use strict';

	/**
	 * Custom events fired on `document` for developer hooks.
	 *
	 * awts_form_open     — popover opened  { updateId, mode }
	 * awts_form_close    — popover closed
	 * awts_update_saved  — new update created { updateId, response }
	 * awts_update_edited — existing update saved { updateId, response }
	 * awts_update_solved   — update marked as solved { updateId, response }
	 * awts_update_reopened — update re-opened        { updateId, response }
	 * awts_updates_loaded  — load-more completed     { orderId, response }
	 *
	 * Example:
	 *   $( document ).on( 'awts_update_saved', function( e, data ) {
	 *       console.log( 'New update created:', data.updateId );
	 *   } );
	 */

	const updateMetaBox = {

		// -------------------------------------------------------------------------
		// Helpers
		// -------------------------------------------------------------------------

		getString( key ) {
			return ( awtsData.strings && awtsData.strings[ key ] ) ? awtsData.strings[ key ] : '';
		},

		sprintf( template, replacements ) {
			let result = template;
			let i = 0;

			replacements.forEach( ( replacement, index ) => {
				result = result.replace( '%' + ( index + 1 ) + '$s', replacement );
			} );

			return result.replace( /%s/g, () => String( replacements[ i++ ] ?? '' ) );
		},

		/**
		 * Central AJAX wrapper — attaches the WP REST nonce and returns a native Promise.
		 * The Promise also exposes an `.abort()` method to cancel in-flight requests.
		 *
		 * @param {Object} config  Standard $.ajax config (url, method, data, etc.)
		 * @returns {Promise & { abort: Function }}
		 */
		request( config ) {
			let jqXhr;

			const promise = new Promise( ( resolve, reject ) => {
				jqXhr = $.ajax( Object.assign( {}, config, {
					headers: Object.assign( { 'X-WP-Nonce': awtsData.nonce }, config.headers || {} ),
				} ) )
					.done( resolve )
					.fail( ( xhr, status ) => reject( { xhr, status } ) );
			} );

			promise.abort = () => jqXhr && jqXhr.abort();

			return promise;
		},

		/**
		 * Disable $element while a request is in flight; re-enable in both
		 * the success and failure paths via .finally(). Returns the original
		 * request promise so callers can chain .then / .catch as usual.
		 * Use ONLY when the re-enable is unconditional — sites that change
		 * text labels, swap aria attrs, or rebuild the element should stay
		 * inline.
		 */
		withDisabled( $element, requestPromise ) {
			$element.prop( 'disabled', true );
			return requestPromise.finally( () => $element.prop( 'disabled', false ) );
		},

		getRequestErrorMessage( xhr, fallbackKey ) {
			const response = xhr && xhr.responseJSON ? xhr.responseJSON : null;
			const code = response && response.code ? String( response.code ) : '';
			const message = response && response.message ? String( response.message ) : '';

			// Session-expired wording is only valid when the failure is a
			// real nonce/cookie problem. Blanket-mapping every 403 to
			// "session expired" lied to staff every time the server denied
			// something for a real reason (edit window closed, lost cap,
			// etc.) — the actual server message is more useful.
			if (
				'order_updates_for_woo_invalid_nonce' === code ||
				/cookie check failed/i.test( message )
			) {
				return this.getString( 'sessionExpiredRefresh' ) || this.getString( 'invalidNonce' ) || this.getString( fallbackKey );
			}

			return message || this.getString( fallbackKey );
		},

		trigger( event, data ) {
			$( document ).trigger( event, [ data || {} ] );
		},

		emoticonMap: [
			// Text emoticons
			[ /:-?\)/g,  '\u{1F642}' ],
			[ /:-?\(/g,  '\u{1F641}' ],
			[ /:-?D/g,   '\u{1F600}' ],
			[ /;-?\)/g,  '\u{1F609}' ],
			[ /:-?P/gi,  '\u{1F61B}' ],
			[ /:-?\//g,  '\u{1F615}' ],
			[ /:-?\|/g,  '\u{1F610}' ],
			[ /:-?O/gi,  '\u{1F62E}' ],
			[ /:'?\(/g,  '\u{1F622}' ],
			[ /:-?\*/g,  '\u{1F618}' ],
			[ /B-?\)/g,  '\u{1F60E}' ],
			[ /<3/g,     '\u{2764}\u{FE0F}' ],
			[ /\^\^/g,   '\u{1F60A}' ],
		],

		shortcodeMap: {
			// Smileys
			smile: '\u{1F642}', grinning: '\u{1F600}', joy: '\u{1F602}', rofl: '\u{1F923}',
			sweat_smile: '\u{1F605}', laughing: '\u{1F606}', wink: '\u{1F609}', blush: '\u{1F60A}',
			heart_eyes: '\u{1F60D}', kissing_heart: '\u{1F618}', thinking: '\u{1F914}',
			raised_eyebrow: '\u{1F928}', neutral_face: '\u{1F610}', expressionless: '\u{1F611}',
			unamused: '\u{1F612}', rolling_eyes: '\u{1F644}', grimacing: '\u{1F62C}',
			relieved: '\u{1F60C}', pensive: '\u{1F614}', sleepy: '\u{1F62A}',
			drooling_face: '\u{1F924}', sleeping: '\u{1F634}', mask: '\u{1F637}',
			sunglasses: '\u{1F60E}', confused: '\u{1F615}', worried: '\u{1F61F}',
			hushed: '\u{1F62F}', astonished: '\u{1F632}', flushed: '\u{1F633}',
			disappointed: '\u{1F61E}', cry: '\u{1F622}', sob: '\u{1F62D}',
			scream: '\u{1F631}', angry: '\u{1F620}', rage: '\u{1F621}',
			innocent: '\u{1F607}', smirk: '\u{1F60F}', yum: '\u{1F60B}',
			stuck_out_tongue: '\u{1F61B}', stuck_out_tongue_winking_eye: '\u{1F61C}',
			zany_face: '\u{1F92A}', shushing_face: '\u{1F92B}', money_mouth_face: '\u{1F911}',
			hugs: '\u{1F917}', nerd_face: '\u{1F913}', skull: '\u{1F480}',
			clown_face: '\u{1F921}', exploding_head: '\u{1F92F}', partying_face: '\u{1F973}',
			star_struck: '\u{1F929}', hot_face: '\u{1F975}', cold_face: '\u{1F976}',
			pleading_face: '\u{1F97A}', face_with_monocle: '\u{1F9D0}',
			// Hands
			thumbsup: '\u{1F44D}', thumbsdown: '\u{1F44E}', '+1': '\u{1F44D}', '-1': '\u{1F44E}',
			ok_hand: '\u{1F44C}', wave: '\u{1F44B}', clap: '\u{1F44F}',
			raised_hands: '\u{1F64C}', pray: '\u{1F64F}', handshake: '\u{1F91D}',
			point_up: '\u{261D}\u{FE0F}', point_down: '\u{1F447}',
			point_left: '\u{1F448}', point_right: '\u{1F449}',
			muscle: '\u{1F4AA}', crossed_fingers: '\u{1F91E}',
			v: '\u{270C}\u{FE0F}', metal: '\u{1F918}', call_me_hand: '\u{1F919}',
			writing_hand: '\u{270D}\u{FE0F}', palms_up: '\u{1F932}',
			// Hearts
			heart: '\u{2764}\u{FE0F}', orange_heart: '\u{1F9E1}', yellow_heart: '\u{1F49B}',
			green_heart: '\u{1F49A}', blue_heart: '\u{1F499}', purple_heart: '\u{1F49C}',
			black_heart: '\u{1F5A4}', broken_heart: '\u{1F494}', sparkling_heart: '\u{1F496}',
			heartbeat: '\u{1F493}', two_hearts: '\u{1F495}',
			// Common objects
			fire: '\u{1F525}', star: '\u{2B50}', sparkles: '\u{2728}', zap: '\u{26A1}',
			boom: '\u{1F4A5}', tada: '\u{1F389}', confetti_ball: '\u{1F38A}',
			trophy: '\u{1F3C6}', medal: '\u{1F3C5}', crown: '\u{1F451}', gem: '\u{1F48E}',
			bell: '\u{1F514}', no_bell: '\u{1F515}', mega: '\u{1F4E3}',
			bulb: '\u{1F4A1}', money_bag: '\u{1F4B0}', dollar: '\u{1F4B5}',
			email: '\u{1F4E7}', inbox_tray: '\u{1F4E5}', outbox_tray: '\u{1F4E4}',
			package: '\u{1F4E6}', link: '\u{1F517}', wrench: '\u{1F527}',
			hammer: '\u{1F528}', gear: '\u{2699}\u{FE0F}', lock: '\u{1F512}', unlock: '\u{1F513}',
			key: '\u{1F511}', pushpin: '\u{1F4CC}', paperclip: '\u{1F4CE}',
			scissors: '\u{2702}\u{FE0F}', pencil: '\u{270F}\u{FE0F}', pencil2: '\u{270F}\u{FE0F}',
			pen: '\u{1F58A}\u{FE0F}', clipboard: '\u{1F4CB}', memo: '\u{1F4DD}',
			calendar: '\u{1F4C5}', chart_with_upwards_trend: '\u{1F4C8}',
			chart_with_downwards_trend: '\u{1F4C9}', bar_chart: '\u{1F4CA}',
			// Status / signals
			check: '\u{2705}', white_check_mark: '\u{2705}',
			heavy_check_mark: '\u{2714}\u{FE0F}',
			x: '\u{274C}', cross_mark: '\u{274C}',
			warning: '\u{26A0}\u{FE0F}', no_entry: '\u{26D4}',
			stop_sign: '\u{1F6D1}', construction: '\u{1F6A7}',
			red_circle: '\u{1F534}', green_circle: '\u{1F7E2}', yellow_circle: '\u{1F7E1}',
			blue_circle: '\u{1F535}', white_circle: '\u{26AA}', black_circle: '\u{26AB}',
			question: '\u{2753}', exclamation: '\u{2757}', bangbang: '\u{203C}\u{FE0F}',
			interrobang: '\u{2049}\u{FE0F}', '100': '\u{1F4AF}',
			// Arrows
			arrow_up: '\u{2B06}\u{FE0F}', arrow_down: '\u{2B07}\u{FE0F}',
			arrow_left: '\u{2B05}\u{FE0F}', arrow_right: '\u{27A1}\u{FE0F}',
			arrow_right_hook: '\u{21AA}\u{FE0F}',
			// Nature / weather
			sun: '\u{2600}\u{FE0F}', cloud: '\u{2601}\u{FE0F}', umbrella: '\u{2602}\u{FE0F}',
			rainbow: '\u{1F308}', snowflake: '\u{2744}\u{FE0F}',
			// Transport / tech
			rocket: '\u{1F680}', airplane: '\u{2708}\u{FE0F}', car: '\u{1F697}',
			truck: '\u{1F69A}', ship: '\u{1F6A2}',
			computer: '\u{1F4BB}', phone: '\u{1F4F1}', desktop_computer: '\u{1F5A5}\u{FE0F}',
			printer: '\u{1F5A8}\u{FE0F}',
			// Food / drink
			coffee: '\u{2615}', pizza: '\u{1F355}', hamburger: '\u{1F354}',
			beer: '\u{1F37A}', wine_glass: '\u{1F377}', cake: '\u{1F370}',
			// People
			eyes: '\u{1F440}', eye: '\u{1F441}\u{FE0F}',
			brain: '\u{1F9E0}', speaking_head: '\u{1F5E3}\u{FE0F}',
			busts_in_silhouette: '\u{1F465}',
			// Celebration / fun
			party_popper: '\u{1F389}', balloon: '\u{1F388}',
			gift: '\u{1F381}', ribbon: '\u{1F380}',
			// Animals
			bug: '\u{1F41B}', bee: '\u{1F41D}', lady_beetle: '\u{1F41E}',
			turtle: '\u{1F422}', snake: '\u{1F40D}', unicorn: '\u{1F984}',
			// Misc
			hourglass: '\u{231B}', timer_clock: '\u{23F2}\u{FE0F}', alarm_clock: '\u{23F0}',
			stopwatch: '\u{23F1}\u{FE0F}', clock: '\u{1F570}\u{FE0F}',
			flag: '\u{1F3F4}', checkered_flag: '\u{1F3C1}',
			triangular_flag: '\u{1F6A9}',
			recycle: '\u{267B}\u{FE0F}', sos: '\u{1F198}', new: '\u{1F195}',
			free: '\u{1F193}', up: '\u{1F199}', cool: '\u{1F192}',
			ok: '\u{1F197}', info: '\u{2139}\u{FE0F}',
		},

		convertEmoticons( text ) {
			// Split on URLs first so emoticon patterns don't fire inside them.
			// Otherwise `https://example.com` gets its `:/` turned into 😕.
			const urlPattern = /\bhttps?:\/\/\S+/g;
			const parts = [];
			let lastIndex = 0;
			let match;
			while ( ( match = urlPattern.exec( text ) ) !== null ) {
				if ( match.index > lastIndex ) {
					parts.push( { text: text.slice( lastIndex, match.index ), isUrl: false } );
				}
				parts.push( { text: match[ 0 ], isUrl: true } );
				lastIndex = match.index + match[ 0 ].length;
			}
			if ( lastIndex < text.length ) {
				parts.push( { text: text.slice( lastIndex ), isUrl: false } );
			}

			return parts.map( ( part ) => {
				if ( part.isUrl ) {
					return part.text;
				}
				let chunk = part.text;
				this.emoticonMap.forEach( ( [ pattern, emoji ] ) => {
					chunk = chunk.replace( pattern, emoji );
				} );
				chunk = chunk.replace( /:([a-z0-9_+-]+):/g, ( m, code ) => {
					return this.shortcodeMap[ code ] || m;
				} );
				return chunk;
			} ).join( '' );
		},

		emojiPickerEmojis: [
			{ label: 'Smileys', items: [ '\u{1F642}', '\u{1F600}', '\u{1F602}', '\u{1F605}', '\u{1F609}', '\u{1F60A}', '\u{1F60D}', '\u{1F618}', '\u{1F60E}', '\u{1F914}', '\u{1F644}', '\u{1F60F}', '\u{1F612}', '\u{1F615}', '\u{1F641}', '\u{1F622}', '\u{1F62D}', '\u{1F621}', '\u{1F631}', '\u{1F607}', '\u{1F973}', '\u{1F929}', '\u{1F92F}', '\u{1F913}' ] },
			{ label: 'Hands', items: [ '\u{1F44D}', '\u{1F44E}', '\u{1F44C}', '\u{1F44B}', '\u{1F44F}', '\u{1F64C}', '\u{1F64F}', '\u{1F91D}', '\u{1F4AA}', '\u{1F91E}', '\u{270C}\u{FE0F}', '\u{1F918}', '\u{261D}\u{FE0F}', '\u{1F449}' ] },
			{ label: 'Symbols', items: [ '\u{2764}\u{FE0F}', '\u{1F494}', '\u{1F525}', '\u{2B50}', '\u{2728}', '\u{26A1}', '\u{1F4A5}', '\u{1F389}', '\u{1F3C6}', '\u{1F451}', '\u{1F4A1}', '\u{1F4AF}', '\u{2705}', '\u{274C}', '\u{26A0}\u{FE0F}', '\u{1F6D1}', '\u{1F6A7}', '\u{2753}', '\u{2757}', '\u{1F534}', '\u{1F7E2}', '\u{1F7E1}' ] },
			{ label: 'Objects', items: [ '\u{1F680}', '\u{1F4E6}', '\u{1F4DD}', '\u{1F4CB}', '\u{1F4CC}', '\u{1F517}', '\u{1F527}', '\u{2699}\u{FE0F}', '\u{1F512}', '\u{1F511}', '\u{1F4E7}', '\u{1F514}', '\u{1F4B0}', '\u{1F4BB}', '\u{1F4F1}', '\u{2615}', '\u{23F0}', '\u{1F4C5}', '\u{1F4C8}', '\u{1F41B}', '\u{1F3C1}', '\u{267B}\u{FE0F}' ] },
		],

		toggleEmojiPicker( event ) {
			event.preventDefault();
			event.stopPropagation();

			const $btn   = $( event.currentTarget );
			const $wrap  = $btn.closest( '.awts_notes_input_wrap, .awts_customer_notes_input_wrap' );
			let $picker  = $wrap.find( '.awts_emoji_picker' );

			if ( $picker.length ) {
				$picker.remove();
				return;
			}

			$( '.awts_emoji_picker' ).remove();

			let html = '<div class="awts_emoji_picker">';
			this.emojiPickerEmojis.forEach( group => {
				html += '<div class="awts_emoji_group_label">' + group.label + '</div>';
				html += '<div class="awts_emoji_grid">';
				group.items.forEach( emoji => {
					html += '<button type="button" class="awts_emoji_item">' + emoji + '</button>';
				} );
				html += '</div>';
			} );
			html += '</div>';

			$btn.after( html );
		},

		insertEmoji( event ) {
			event.preventDefault();
			event.stopPropagation();

			const $btn    = $( event.currentTarget );
			const emoji   = $btn.text();
			const $wrap   = $btn.closest( '.awts_notes_input_wrap, .awts_customer_notes_input_wrap' );
			const $input  = $wrap.find( 'textarea' );

			if ( $input.length ) {
				const el    = $input.get( 0 );
				const start = el.selectionStart;
				const end   = el.selectionEnd;
				const val   = $input.val();
				$input.val( val.substring( 0, start ) + emoji + val.substring( end ) );
				el.selectionStart = el.selectionEnd = start + emoji.length;
				$input.trigger( 'focus' );
			}

			$btn.closest( '.awts_emoji_picker' ).remove();
		},

		// -------------------------------------------------------------------------
		// Init
		// -------------------------------------------------------------------------

		init( element ) {
			this.$panel              = $( element );
			this.$updateList         = this.$panel.find( '.awts_update_list' );
			this.$popover            = $( '.awts_popover' );
			this.$form               = this.$popover.find( '.awts_form' );
			this.$formNotice         = this.$popover.find( '.awts_form_notice' );
			this.$heading            = this.$popover.find( '.awts_form_heading' );
			this.$formModeHint       = this.$popover.find( '.awts_form_mode_hint' );
			this.$saveButton         = this.$popover.find( '.awts_save_form' );
			this.$name               = this.$popover.find( '#awts_update_name' );
			this.$orderId            = this.$popover.find( '#awts_order_id' );
			this.$updateId           = this.$popover.find( '#awts_update_id' );
			this.$assignee           = this.$popover.find( '#awts_update_assignee' );
			this.$assigneeId         = this.$popover.find( '#awts_update_assignee_id' );
			this.$status             = this.$popover.find( '#awts_update_status' );
			this.$internalNote       = this.$popover.find( '#awts_update_internal_note' );
			this.$customerNote       = this.$popover.find( '#awts_update_customer_note' );
			this.$addOnlyFields      = this.$popover.find( '.awts_add_only_field' );
			this.$customerNoteField  = this.$popover.find( '.awts_customer_note_field' );
			this.$noteFields         = this.$popover.find( '[data-awts-character-limit]' );
			this.$metaBlock          = this.$popover.find( '.awts_meta_block' );
			this.$metaCreatedValue   = this.$popover.find( '.awts_meta_created_value' );
			this.$metaNotifiedValue  = this.$popover.find( '.awts_meta_notified_value' );
			this.activeTrigger       = null;
			this.pointerDownEvent    = window.PointerEvent ? 'pointerdown' : 'mousedown';
			this.noticeTimer         = null;
			this.mentionTeam         = Array.isArray( awtsData.team ) ? awtsData.team : [];
			this.mentionCurrentUser  = parseInt( awtsData.currentUserId, 10 ) || 0;
			this.mentionDropdown     = null;
			this.mentionContext      = null;
			this.mentionActiveIndex  = 0;
			// Tracks the highest rendered note id per update for heartbeat delta.
			// Pre-seeded with 0 for every visible card so the heartbeat fires
			// immediately, even before a customer notes tab has been opened.
			this.noteHighWater         = {};
			this.internalNoteHighWater = {};
			this.$panel.find( '[data-awts-update-id]' ).each( ( _, el ) => {
				const id = parseInt( $( el ).data( 'awts-update-id' ), 10 );
				if ( id ) {
					this.noteHighWater[ id ]         = 0;
					this.internalNoteHighWater[ id ] = 0;
				}
			} );

			this.bindEvents();
			this.bindHeartbeat();
			this.bindEnterToSend();
			this.restoreLastTab();
			this.enhanceTooltips();
		},

		// Turn short button/link titles into custom hover tooltips (the dark
		// pill from the notifications page) so the meta box matches. Long
		// explanatory titles stay as the browser's native tooltip. A
		// MutationObserver re-runs on dynamically-rendered cards/notes.
		enhanceTooltips() {
			const panel = this.$panel.get( 0 );
			if ( ! panel ) {
				return;
			}

			const convert = root => {
				if ( ! root || ! root.querySelectorAll ) {
					return;
				}
				const els = root.querySelectorAll( 'button[title], a[title]' );
				Array.prototype.forEach.call( els, el => {
					const tip = el.getAttribute( 'title' );
					if ( tip && tip.length <= 28 && ! el.hasAttribute( 'data-awts-tip' ) ) {
						el.setAttribute( 'data-awts-tip', tip );
						el.removeAttribute( 'title' );
					}
				} );
			};

			convert( panel );

			if ( typeof MutationObserver === 'function' ) {
				new MutationObserver( mutations => {
					mutations.forEach( m => {
						Array.prototype.forEach.call( m.addedNodes, node => {
							if ( node.nodeType === 1 ) {
								convert( node );
							}
						} );
					} );
				} ).observe( panel, { childList: true, subtree: true } );
			}
		},

		// Persist the last-opened card tab in localStorage so a refresh
		// lands on Customer Notes or Tracking Log if that's where the
		// staff member was working — instead of always snapping back to
		// Internal Notes. Scoped per-update (not per-order) so each card
		// on a multi-update order remembers its own tab independently.
		lastTabStorageKey( updateId ) {
			const id = parseInt( updateId, 10 ) || 0;
			return id ? 'awts_last_card_tab_' + id : '';
		},

		restoreLastTab() {
			this.$panel.find( '.awts_card[data-awts-update-id]' ).each( ( _, cardEl ) => {
				const $card    = $( cardEl );
				const updateId = parseInt( $card.attr( 'data-awts-update-id' ), 10 ) || 0;
				const key      = this.lastTabStorageKey( updateId );

				if ( ! key ) return;

				const saved = localStorage.getItem( key );

				if ( saved && 'internal' !== saved ) {
					$card.find( '.awts_card_tab[data-awts-tab="' + saved + '"]' ).each( ( __, tabEl ) => {
						this.selectTab( $( tabEl ), false );
					} );
				}
			} );
		},

		// Single per-browser preference for "Enter = Send" across every
		// chat composer on the page. Default ON (Slack / Discord pattern).
		// Saved to localStorage so the choice survives reload + sticks
		// across sessions on the same browser.
		bindEnterToSend() {
			const KEY = 'awts_enter_to_send';
			const enabled = () => '0' !== localStorage.getItem( KEY );
			const setEnabled = ( on ) => {
				localStorage.setItem( KEY, on ? '1' : '0' );
				this.$panel.find( '[data-awts-enter-to-send]' ).prop( 'checked', on );
			};

			// Sync each checkbox to the stored value on first paint, then
			// listen for changes to keep them all in lock-step.
			this.$panel.find( '[data-awts-enter-to-send]' ).prop( 'checked', enabled() );
			this.$panel.on( 'change', '[data-awts-enter-to-send]', e => setEnabled( e.currentTarget.checked ) );

			// Internal-notes textarea → click .awts_notes_submit;
			// customer-notes textarea → .awts_customer_notes_submit.
			this.$panel.on( 'keydown', '.awts_notes_input, .awts_customer_notes_input', e => {
				if ( 'Enter' !== e.key || e.shiftKey ) return;
				// When the @mention dropdown is open, Enter belongs to the
				// mention picker (it confirms the highlighted suggestion).
				// Without this gate, the Enter-to-Send handler — registered
				// first — would submit the note before the user could pick
				// the person they're tagging.
				if ( this.mentionDropdown ) return;

				// Read live state from THIS composer's checkbox instead of
				// localStorage. Dynamically-added update cards render their
				// "Enter = Send" checkbox unchecked by default (template) and
				// the init-time sync only touches checkboxes that existed at
				// boot — so localStorage could say "enabled" while the user
				// sees an unchecked box. Trust what they see.
				const $wrap = $( e.currentTarget ).closest( '.awts_notes_input_wrap, .awts_customer_notes_input_wrap' );
				const $pref = $wrap.find( '[data-awts-enter-to-send]' );
				if ( $pref.length && ! $pref.prop( 'checked' ) ) return;

				e.preventDefault();
				const $submit = $wrap.find( '.awts_notes_submit, .awts_customer_notes_submit' ).first();
				if ( $submit.length ) {
					$submit.trigger( 'click' );
				}
			} );
		},

getFieldValue( $field ) {
			return $field.length ? String( $field.val() || '' ) : '';
		},

		escapeAttribute( value ) {
			return String( value || '' )
				.replace( /&/g, '&amp;' )
				.replace( /"/g, '&quot;' )
				.replace( /</g, '&lt;' )
				.replace( />/g, '&gt;' );
		},

		// -------------------------------------------------------------------------
		// Events
		// -------------------------------------------------------------------------

		bindEvents() {
			this.$panel.on( 'click', '.awts_onboarding_dismiss',   e => this.dismissOnboarding( e ) );
			this.$panel.on( 'click', '.awts_toggle_form',         e => this.toggleForm( e ) );
			this.$panel.on( 'click', '.awts_refresh_updates',     e => this.refreshUpdates( e ) );
			this.$panel.on( 'click', '.awts_load_more_updates',   e => this.loadMoreUpdates( e ) );
			this.$panel.on( 'click', '.awts_mark_solved',         e => this.markSolved( e ) );
			this.$panel.on( 'click', '.awts_reopen_update',       e => this.reopenUpdate( e ) );
			this.$panel.on( 'click', '.awts_edit_status',          e => this.toggleStatusPicker( e ) );
			this.$panel.on( 'change', '[data-awts-status-picker]', e => this.changeStatus( e ) );
			this.$panel.on( 'click', '.awts_view_history',        e => this.viewHistory( e ) );
			this.$panel.on( 'click', '.awts_card_tab',            e => this.activateTab( e ) );
			this.$panel.on( 'keydown', '.awts_card_tab',          e => this.handleTabKeydown( e ) );
			this.$panel.on( 'click', '.awts_view_notes',               e => this.viewNotes( e ) );
			this.$panel.on( 'click', '.awts_notes_submit',             e => this.addNote( e ) );
			this.$panel.on( 'keydown', '.awts_notes_input',          e => this.handleNotesUpArrow( e ) );
			this.$panel.on( 'keydown', '.awts_customer_notes_input', e => this.handleCustomerNotesUpArrow( e ) );
			this.$panel.on( 'click', '.awts_edit_note',                e => this.editNote( e ) );
			this.$panel.on( 'click', '.awts_delete_note',              e => this.deleteNote( e ) );
			this.$panel.on( 'click', '.awts_mark_notes_read',          e => this.markNotesAsRead( e ) );
			this.$panel.on( 'click', '.awts_mark_note_read',           e => this.markSingleNoteAsRead( e ) );
			this.$panel.on( 'click', '.awts_mark_customer_notes_read', e => this.markCustomerNotesAsRead( e ) );
			this.$panel.on( 'click', '.awts_view_customer_notes',      e => this.viewCustomerNotes( e ) );
			this.$panel.on( 'click', '.awts_customer_notes_submit',    e => this.addCustomerNote( e ) );
			this.$panel.on( 'click', '.awts_edit_customer_note',       e => this.editCustomerNote( e ) );
			this.$panel.on( 'click', '.awts_notify_customer_note',        e => this.notifyCustomerNote( e ) );
			this.$panel.on( 'click', '.awts_customer_email_pref__toggle', e => this.toggleCustomerEmailPref( e ) );
			this.$panel.on( 'change', '[data-awts-staff-email-pref]',    e => this.saveStaffEmailPref( e ) );
			this.$panel.on( 'click', '.awts_view_customer_note_history', e => this.viewCustomerNoteHistory( e ) );
			this.$panel.on( 'click', '.awts_show_more',            e => this.toggleShowMore( e ) );
			this.$panel.on( 'click', '.awts_load_previous_notes', e => this.loadPreviousNotes( e ) );
			this.$panel.on( 'click', '.awts_jump_latest',         e => this.onJumpToLatest( e ) );
			this.$panel.on( 'click', '.awts_emoji_trigger',        e => this.toggleEmojiPicker( e ) );
			this.$panel.on( 'click', '.awts_emoji_item',          e => this.insertEmoji( e ) );
			this.$panel.on( 'click', '.awts_attach_trigger',      e => this.handleAttachClick( e ) );
			this.$panel.on( 'change', '.awts_attach_input',       e => this.handleAttachChange( e ) );
			this.$panel.on( 'dragenter dragover', '.awts_notes_input_wrap, .awts_customer_notes_input_wrap', e => this.handleDragOver( e ) );
			this.$panel.on( 'dragleave dragend', '.awts_notes_input_wrap, .awts_customer_notes_input_wrap', e => this.handleDragLeave( e ) );
			this.$panel.on( 'drop', '.awts_notes_input_wrap, .awts_customer_notes_input_wrap', e => this.handleDrop( e ) );
			this.$panel.on( 'click', '.awts_pending_attachment_remove', e => this.removePendingAttachment( e ) );
			this.$panel.on( 'click', '.awts_attachment_remove',   e => this.deleteAttachment( e ) );
			this.$panel.on( 'click', '.awts_delete_update',       e => this.deleteUpdate( e ) );
			this.$panel.on( 'click', '.awts_edit_title',          e => this.startInlineEditTitle( e ) );
			this.$panel.on( 'click', '.awts_edit_assignee',       e => this.startInlineEditAssignee( e ) );
			this.$panel.on( 'input', '.awts_inline_assignee_input', e => this.searchInlineAssignee( e ) );
			this.$panel.on( this.pointerDownEvent, '.awts_inline_assignee_wrap .awts_suggestion_item', e => this.selectInlineAssignee( e ) );
			this.$panel.on( 'click', '.awts_card_collapse_toggle',e => this.toggleCollapse( e ) );
			this.$panel.on( 'click', '.awts_card_menu_btn',       e => this.toggleCardMenu( e ) );
			this.$popover.on( 'click', '.awts_cancel_form', e => this.cancelForm( e ) );
			this.$popover.on( 'click', '.awts_save_form',   e => this.saveForm( e ) );
			this.$popover.on( this.pointerDownEvent, '.awts_suggestion_item', e => this.handleAssigneeSelection( e ) );
			this.$popover.on( 'click', '.awts_chip_remove', e => this.handleAssigneeClear( e ) );
			this.$assignee.on( 'input', () => this.searchAssignees() );
			this.$noteFields.on( 'input blur', e => this.handleNoteChange( e ) );
			this.$panel.on( 'input', '.awts_notes_input', e => this.handleMentionInput( e ) );
			this.$panel.on( 'keydown', '.awts_notes_input', e => this.handleMentionKeydown( e ) );
			this.$panel.on( 'blur', '.awts_notes_input', () => this.scheduleCloseMentionDropdown() );
			this.$internalNote.on( 'input', e => this.handleMentionInput( e ) );
			this.$internalNote.on( 'keydown', e => this.handleMentionKeydown( e ) );
			this.$internalNote.on( 'blur', () => this.scheduleCloseMentionDropdown() );
			$( document ).on( 'keydown', e => this.onKeydown( e ) );
			$( document ).on( 'click', e => this.onDocumentClick( e ) );
			$( document ).on( 'click', '.awts_history_modal_close, .awts_history_modal_overlay', e => this.closeHistoryModal( e ) );
			this.refreshNoteCounters();
			this.toggleCustomerNoteField();
			this.autoLoadNotes();
			this.autoLoadCustomerNotes();
			this.handleDeepLinkScroll();
			$( window ).on( 'hashchange', () => this.handleDeepLinkScroll() );
		},

		// -------------------------------------------------------------------------
		// WP Heartbeat — admin customer-thread sync
		// -------------------------------------------------------------------------

		bindHeartbeat() {
			// Piggyback on the existing WP Heartbeat rather than a separate interval.
			// All open update threads are aggregated into one request, so N cards = 1 HTTP hit.

			$( document ).on( 'heartbeat-send', ( e, data ) => {
				const since         = Object.assign( {}, this.noteHighWater );
				const sinceInternal = Object.assign( {}, this.internalNoteHighWater );
				if ( Object.keys( since ).length === 0 && Object.keys( sinceInternal ).length === 0 ) return;
				data[ awtsData.heartbeatKey ] = { since, since_internal: sinceInternal };
			} );

			$( document ).on( 'heartbeat-tick', ( e, data ) => {
				if ( ! data[ awtsData.heartbeatKey ] ) return;
				const payload = data[ awtsData.heartbeatKey ];

				const byUpdate = payload.notes_by_update || {};
				Object.keys( byUpdate ).forEach( updateId => {
					const notes = byUpdate[ updateId ];
					if ( ! Array.isArray( notes ) || ! notes.length ) return;
					this.appendHeartbeatNotes( parseInt( updateId, 10 ), notes );
				} );

				const byUpdateInternal = payload.internal_notes_by_update || {};
				Object.keys( byUpdateInternal ).forEach( updateId => {
					const notes = byUpdateInternal[ updateId ];
					if ( ! Array.isArray( notes ) || ! notes.length ) return;
					this.appendHeartbeatInternalNotes( parseInt( updateId, 10 ), notes );
				} );

				// State sync: a teammate changed an update's status / title /
				// assignee or solved/reopened/deleted it on another screen.
				const stateByUpdate = payload.state_by_update || {};
				Object.keys( stateByUpdate ).forEach( updateId => {
					this.syncCardState( parseInt( updateId, 10 ), String( stateByUpdate[ updateId ] || '' ) );
				} );
			} );
		},

		// Refresh one card in place when the server reports a newer last-changed
		// time than what it was rendered with — so a teammate's status / title /
		// assignee / solve / reopen shows up live, without a reload. This only
		// ever refreshes a card; it never removes one. An empty stamp means
		// "nothing to compare", not "deleted", so we leave the card alone.
		syncCardState( updateId, serverStamp ) {
			if ( ! updateId || ! serverStamp ) return;

			const $card = this.$updateList.children( `.awts_card[data-awts-update-id="${ updateId }"]` );
			if ( ! $card.length ) return;

			if ( String( $card.attr( 'data-awts-last-updated' ) || '' ) === String( serverStamp ) ) return;

			// Guard against overlapping refreshes while the GET is in flight.
			if ( $card.data( 'awts-state-refreshing' ) ) return;
			$card.data( 'awts-state-refreshing', true );

			this.request( { url: awtsData.updateEndpointBase + updateId, method: 'GET' } )
				.then( response => {
					if ( response && response.cardHtml ) {
						this.updateSavedCard( { ...response, isEdit: true }, updateId );
					}
				} )
				.catch( () => {} )
				// On failure the old node survives — clear the guard so a later
				// heartbeat can retry instead of being stuck "refreshing".
				.then( () => $card.data( 'awts-state-refreshing', false ) );
		},

		appendHeartbeatNotes( updateId, notes ) {
			const $thread = this.$panel.find( `.awts_customer_notes_thread[data-awts-update-id="${ updateId }"]` );
			if ( ! $thread.length ) return;

			// Windowed (deep-link) view — its bottom isn't the live newest, so
			// don't append polled messages there. They load on "Jump to latest".
			if ( $thread.data( 'awts-windowed' ) ) return;

			// A customer can only post on an OPEN update. If a new customer note
			// arrives while this card still shows the resolved state (the Re-open
			// button is present), the card is stale — the customer reopened it
			// from their side. Re-render from the server so the composer and
			// buttons match live, instead of dropping a reply under a "resolved"
			// card and leaving the assignee on a Re-open button until they refresh.
			const $card = $thread.closest( '.awts_card' );
			const hasNewCustomerNote = notes.some( note =>
				!! note.from_customer
				&& 0 === $thread.find( `[data-awts-note-id="${ parseInt( note.id, 10 ) }"]` ).length
			);
			if ( hasNewCustomerNote && $card.find( '.awts_reopen_update' ).length ) {
				this.request( { url: awtsData.updateEndpointBase + updateId, method: 'GET' } )
					.then( response => {
						if ( response && response.cardHtml ) {
							this.updateSavedCard( { ...response, isEdit: true }, updateId );
						}
					} )
					.catch( () => {} );
				return;
			}

			const lastReadId = this.getLastReadCustomerNoteId( updateId );
			let newCount     = parseInt( $thread.closest( '.awts_card' ).find( `[data-awts-tab-badge="customer"]` ).attr( 'data-awts-count' ) || '0', 10 );

			let appended = false;

			notes.forEach( note => {
				const noteId      = parseInt( note.id, 10 );
				const isFromCustomer = !! note.from_customer;
				const isUnread    = isFromCustomer && noteId > lastReadId;

				// Avoid duplicate if the note was already added locally (e.g. staff
				// just sent it and the heartbeat fires before they navigate away).
				if ( $thread.find( `[data-awts-note-id="${ noteId }"]` ).length ) return;

				$thread.find( '.awts_customer_notes_empty' ).remove();
				$thread.append( this.buildCustomerNoteHtml( note, isUnread ) );
				appended = true;

				if ( isUnread ) newCount++;
				if ( noteId > ( this.noteHighWater[ updateId ] || 0 ) ) {
					this.noteHighWater[ updateId ] = noteId;
				}
			} );

			// Slide the thread to the latest note so polled-in messages don't
			// land below the fold and look like nothing happened.
			if ( appended ) {
				this.scrollThreadToBottom( $thread );
			this.pruneShowMoreButtons( $thread );
				// Latest-only rule: any newer message locks every previous one.
				// Strip edit affordances from older bubbles so the UI matches
				// the server-side gate — the endpoint would 403 anyway, but
				// showing a button that errors on click is bad UX.
				this.sweepStaleNoteActions( $thread, '.awts_customer_note_item', [ '.awts_edit_customer_note' ] );
			}

			this.updateCustomerNotesBadge( $thread, newCount );
		},

		/**
		 * Remove edit / delete affordances from every bubble in $thread that
		 * isn't the highest-id note. Used after heartbeat / poll appends so
		 * the on-screen state matches the latest-only policy: only the most
		 * recent note keeps its buttons; everything older is part of the
		 * locked history.
		 */
		sweepStaleNoteActions( $thread, bubbleSelector, buttonSelectors ) {
			const $bubbles = $thread.find( bubbleSelector + '[data-awts-note-id]' );
			if ( $bubbles.length < 2 ) return;

			let maxId = 0;
			$bubbles.each( ( _, el ) => {
				const id = parseInt( $( el ).attr( 'data-awts-note-id' ), 10 ) || 0;
				if ( id > maxId ) maxId = id;
			} );

			$bubbles.each( ( _, el ) => {
				const $bubble = $( el );
				const id      = parseInt( $bubble.attr( 'data-awts-note-id' ), 10 ) || 0;
				if ( id === maxId ) return;
				$bubble.find( buttonSelectors.join( ', ' ) ).remove();
			} );
		},

		appendHeartbeatInternalNotes( updateId, notes ) {
			const $thread = this.$panel.find( `.awts_notes_thread[data-awts-update-id="${ updateId }"]` );
			if ( ! $thread.length ) return;

			// Always advance the high-water mark so we don't re-receive these notes.
			notes.forEach( note => {
				const noteId = parseInt( note.id, 10 );
				if ( noteId > ( this.internalNoteHighWater[ updateId ] || 0 ) ) {
					this.internalNoteHighWater[ updateId ] = noteId;
				}
			} );

			// Only append to the DOM once the tab has been fully loaded, and
			// never into a windowed (deep-link) view — its bottom isn't the
			// live newest. Those notes load on "Jump to latest".
			if ( ! $thread.data( 'awts-loaded' ) || $thread.data( 'awts-windowed' ) ) return;

			let newCount = parseInt( $thread.closest( '.awts_card' ).find( '[data-awts-tab-badge="internal"]' ).attr( 'data-awts-count' ) || '0', 10 );

			let appended = false;

			notes.forEach( note => {
				const noteId = parseInt( note.id, 10 );
				if ( $thread.find( `[data-awts-note-id="${ noteId }"]` ).length ) return;
				$thread.find( '.awts_notes_empty' ).remove();
				$thread.append( this.buildNoteHtml( note, true ) );
				appended = true;
				newCount++;
			} );

			if ( newCount > 0 ) {
				const $wrap = $thread.closest( '.awts_notes_wrap' );
				if ( ! $wrap.find( '.awts_mark_notes_read' ).length ) {
					$wrap.prepend( $( '<button type="button" class="awts_mark_notes_read">' + this.getString( 'markAsReadLabel' ) + '</button>' ) );
				}
			}

			if ( appended ) {
				this.scrollThreadToBottom( $thread );
			this.pruneShowMoreButtons( $thread );
				this.sweepStaleNoteActions( $thread, '.awts_notes_item', [ '.awts_edit_note', '.awts_delete_note' ] );
			}

			this.updateNotesBadge( $thread, newCount );
		},

		handleDeepLinkScroll() {
			// Two accepted shapes:
			//   #awts-update-X
			//   #awts-update-X-note-N
			//   #awts-update-X-internal-note-N
			//   #awts-update-X-customer-note-N
			// The tab segment (when present) tells the card which note tab
			// to switch to before scrolling — clicking an admin-bar row for
			// a customer reply lands the user on Customer Notes, not on
			// whichever tab happened to be open from last visit.
			const match = ( window.location.hash || '' ).match( /^#awts-update-(\d+)(?:-(internal|customer))?(?:-note-(\d+))?$/ );
			if ( ! match ) {
				return;
			}
			const updateId = parseInt( match[ 1 ], 10 );
			const tabKey   = match[ 2 ] || '';
			const noteId   = parseInt( match[ 3 ] || '0', 10 );
			if ( ! updateId ) {
				return;
			}
			const $card = this.$panel.find( '.awts_card[data-awts-update-id="' + updateId + '"]' );

			if ( ! $card.length ) {
				// Card not on this page. Reload once to surface a fresh update
				// added since the page loaded, but bail without reload on the
				// second pass to avoid the "hash points to a deleted update"
				// refresh loop. sessionStorage flag is per-tab + per-update.
				const reloadKey = 'awts_dl_reloaded_' + updateId;
				try {
					if ( window.sessionStorage && ! window.sessionStorage.getItem( reloadKey ) ) {
						window.sessionStorage.setItem( reloadKey, '1' );
						window.location.reload();
						return;
					}
				} catch ( e ) {
					// sessionStorage blocked — skip the reload fallback rather
					// than risk an infinite loop.
				}

				// Reloaded already and still missing — the update was deleted.
				// Centre the message: there's no card to scroll to, so a panel
				// toast would sit off-screen.
				this.showCenterNotice( this.getString( 'deepLinkMissing' ) );
				return;
			}

			try {
				if ( window.sessionStorage ) {
					window.sessionStorage.removeItem( 'awts_dl_reloaded_' + updateId );
				}
			} catch ( e ) {}

			// A deep link focuses one update — collapse the rest so the linked
			// card stands alone instead of landing in a wall of open cards.
			this.$panel.find( '.awts_card[data-awts-update-id]' ).not( $card ).addClass( 'awts_card--collapsed' );
			$card.removeClass( 'awts_card--collapsed' );

			// Switch to the right note tab if the hash carried one. selectTab
			// fires the same code path a manual click does, so the note thread
			// lazy-loads + the badge clears + localStorage persists — same as
			// the user opening the tab themselves.
			if ( tabKey ) {
				const $tab = $card.find( '.awts_card_tab[data-awts-tab="' + tabKey + '"]' );
				if ( $tab.length ) {
					this.selectTab( $tab, true );
				}
			}

			// Give the tab's thread a moment to lazy-load, then reveal the note
			// — paging through "Load previous" if it sits in an older batch.
			window.setTimeout( () => this.revealDeepLinkNote( $card, tabKey, noteId, 15 ), 150 );
		},

		/**
		 * Scroll to a deep-linked note. If it isn't in the latest batch, jump
		 * straight to a window around it in one request (rather than paging the
		 * whole thread). Bounded by attemptsLeft, which only covers waiting for
		 * the tab's initial load — not repeated fetches.
		 */
		revealDeepLinkNote( $card, tabKey, noteId, attemptsLeft ) {
			if ( ! noteId ) {
				this.focusDeepLink( $card );
				return;
			}

			const $note = $card.find( '[data-awts-note-id="' + noteId + '"]' );
			if ( $note.length ) {
				this.focusDeepLink( $note );
				return;
			}

			const threadSel = 'customer' === tabKey ? '.awts_customer_notes_thread' : '.awts_notes_thread';
			const $thread   = $card.find( threadSel ).first();

			// Wait for the tab's thread to finish its initial (latest) load.
			if ( ( ! $thread.length || ! $thread.data( 'awts-loaded' ) ) && attemptsLeft > 0 ) {
				window.setTimeout( () => this.revealDeepLinkNote( $card, tabKey, noteId, attemptsLeft - 1 ), 120 );
				return;
			}

			// Loaded but the note isn't in the latest batch — load a window
			// centred on it (one request), then scroll to it.
			if ( $thread.length && $thread.data( 'awts-loaded' ) ) {
				const updateId = parseInt( $card.attr( 'data-awts-update-id' ), 10 ) || 0;
				const kind     = 'customer' === tabKey ? 'customer' : 'internal';
				this.loadNoteWindow( $thread, updateId, kind, noteId ).then( () => {
					const $found = $card.find( '[data-awts-note-id="' + noteId + '"]' );
					this.focusDeepLink( $found.length ? $found : $card );
				} );
				return;
			}

			this.focusDeepLink( $card );
		},

		/**
		 * Fetch a window of notes centred on noteId (older + note + newer) in
		 * one request and render it. If newer notes exist below the window the
		 * thread enters "windowed" mode and shows a "Jump to latest" bubble.
		 * Returns a Promise that resolves once the window is rendered.
		 */
		loadNoteWindow( $thread, updateId, kind, noteId ) {
			if ( ! updateId || ! noteId ) {
				return Promise.resolve();
			}

			const route = 'customer' === kind
				? awtsData.customerNotesEndpointBase + updateId + '/customer-notes'
				: awtsData.notesEndpointBase + updateId + '/notes';

			return this.request( { url: route + '?around_id=' + noteId, method: 'GET' } )
				.then( response => {
					const notes = ( response && response.notes ) ? response.notes : [];

					// Target note is gone (deleted). Don't wipe the thread to an
					// empty window — restore the live latest batch and say so.
					if ( ! notes.length ) {
						if ( 'customer' === kind ) {
							this.loadCustomerNotesForThread( $thread, updateId );
						} else {
							this.loadNotesForThread( $thread, updateId );
						}
						this.showCenterNotice( this.getString( 'deepLinkMissing' ) );
						return;
					}

					if ( 'customer' === kind ) {
						this.renderCustomerNotes( response, $thread );
					} else {
						this.renderNotes( response, $thread );
					}

					// renderX clears windowed state and scrolls to the bottom.
					// When notes exist below the window, flag windowed mode and
					// offer "Jump to latest" instead of pretending we're live.
					if ( response && response.has_newer ) {
						$thread.data( 'awts-windowed', true );
						this.addJumpToLatest( $thread, updateId, kind );
					}
				} )
				.catch( () => {} );
		},

		// Floating "Jump to latest" bubble — sits sticky at the bottom of the
		// thread's scroll area while windowed (its bottom isn't the live newest).
		addJumpToLatest( $thread, updateId, kind ) {
			if ( ! $thread.length || $thread.find( '.awts_jump_latest' ).length ) {
				return;
			}

			const $btn = $(
				'<button type="button" class="awts_jump_latest" data-awts-kind="' + kind + '" data-awts-update-id="' + updateId + '">'
				+ '<span class="dashicons dashicons-arrow-down-alt"></span>'
				+ '<span class="awts_jump_latest_label">' + this.getString( 'jumpToLatest' ) + '</span>'
				+ '</button>'
			);
			$thread.append( $btn );
		},

		removeJumpToLatest( $thread ) {
			$thread.find( '.awts_jump_latest' ).remove();
		},

		// Leave windowed mode and reload the live latest batch (which scrolls
		// to the bottom and restores real-time append).
		jumpToLatest( $thread, updateId, kind ) {
			$thread.data( 'awts-windowed', false );
			this.removeJumpToLatest( $thread );

			if ( 'customer' === kind ) {
				this.loadCustomerNotesForThread( $thread, updateId );
			} else {
				this.loadNotesForThread( $thread, updateId );
			}
		},

		onJumpToLatest( event ) {
			event.preventDefault();
			const $btn     = $( event.currentTarget );
			const $thread  = $btn.closest( '.awts_notes_thread, .awts_customer_notes_thread' );
			const kind     = 'customer' === $btn.attr( 'data-awts-kind' ) ? 'customer' : 'internal';
			const updateId = parseInt( $btn.attr( 'data-awts-update-id' ), 10 ) || parseInt( $thread.data( 'awts-update-id' ), 10 ) || 0;
			this.jumpToLatest( $thread, updateId, kind );
		},

		focusDeepLink( $focus ) {
			const el = $focus.get( 0 );
			if ( ! el ) {
				return;
			}

			// Cancel the render's in-flight scroll-to-bottom so it can't drag
			// the thread past the target and leave it above the fold.
			const $thread = $focus.closest( '.awts_notes_thread, .awts_customer_notes_thread' );
			$thread.stop( true );
			$focus.addClass( 'awts_card--highlight' );

			// Defer until the scroll-to-bottom is cancelled and any clamp/"Show
			// more" prune has reflowed.
			window.setTimeout( () => {
				// Centre the note within its (small) scroll container instantly,
				// so the page then eases over a short distance in one smooth
				// motion — scrolling two containers at once felt abrupt.
				const thread = $thread.get( 0 );
				if ( thread ) {
					const threadRect = thread.getBoundingClientRect();
					const elRect     = el.getBoundingClientRect();
					thread.scrollTop += ( elRect.top - threadRect.top ) - ( thread.clientHeight - elRect.height ) / 2;
				}

				if ( typeof el.scrollIntoView === 'function' ) {
					el.scrollIntoView( { behavior: 'smooth', block: 'center' } );
				}
			}, 150 );
			window.setTimeout( () => $focus.removeClass( 'awts_card--highlight' ), 2650 );
		},

		// -------------------------------------------------------------------------
		// Form open / close
		// -------------------------------------------------------------------------

		dismissOnboarding( event ) {
			event.preventDefault();
			const $banner = this.$panel.find( '.awts_onboarding' );
			const nonce = $banner.data( 'awts-nonce' );

			$banner.slideUp( 300, function() { $( this ).remove(); } );

			$.post( ajaxurl, {
				action: 'order_updates_for_woo_dismiss_onboarding',
				_nonce: nonce,
			} );
		},

		toggleForm( event ) {
			event.preventDefault();

			const mode = $( event.currentTarget ).data( 'awts-mode' );
			this.activeTrigger = $( event.currentTarget );

			if ( mode === 'add' ) {
				this.prepareAddMode();
				this.showPopover();
				return;
			}

			this.fetchUpdateForEdit();
		},

		cancelForm( event ) {
			event.preventDefault();
			this.resetForm();
			this.hideFormNotice();
			this.hidePopover();
		},

		showPopover( updateId, mode ) {
			this.$popover.prop( 'hidden', false );
			this.positionPopover();
			this.$name.trigger( 'focus' );
			this.trigger( 'awts_form_open', { updateId: updateId || 0, mode: mode || 'add' } );
		},

		hidePopover() {
			this.$popover.prop( 'hidden', true );
			this.activeTrigger = null;
			this.trigger( 'awts_form_close' );
		},

		positionPopover() {
			if ( ! this.activeTrigger || ! this.activeTrigger.length ) {
				return;
			}

			const triggerRect = this.activeTrigger.get( 0 ).getBoundingClientRect();
			const margin      = 12;

			this.$popover.css( { left: '', top: '', right: '', visibility: 'hidden' } );

			const popoverWidth  = this.$popover.outerWidth();
			const popoverHeight = this.$popover.outerHeight();

			let left = triggerRect.left - popoverWidth - margin;
			let top  = triggerRect.top;

			if ( left < margin ) {
				left = triggerRect.right + margin;
			}

			if ( left + popoverWidth > window.innerWidth - margin ) {
				left = Math.max( margin, window.innerWidth - popoverWidth - margin );
			}

			if ( top + popoverHeight > window.innerHeight - margin ) {
				top = Math.max( margin, window.innerHeight - popoverHeight - margin );
			}

			this.$popover.css( { left: left + 'px', top: top + 'px', visibility: 'visible' } );
		},

		repositionPopover() {
			if ( ! this.$popover.prop( 'hidden' ) ) {
				window.requestAnimationFrame( () => this.positionPopover() );
			}
		},

		onKeydown( event ) {
			if ( event.key === 'Escape' ) {
				this.closeAllCardMenus();

				if ( ! this.$popover.prop( 'hidden' ) ) {
					this.resetForm();
					this.hidePopover();
				}
			}
		},

		onDocumentClick( event ) {
			if ( ! $( event.target ).closest( '.awts_card_menu_wrap' ).length ) {
				this.closeAllCardMenus();
			}
			if ( ! $( event.target ).closest( '.awts_emoji_picker, .awts_emoji_trigger' ).length ) {
				$( '.awts_emoji_picker' ).remove();
			}
		},

		toggleCardMenu( event ) {
			event.stopPropagation();

			const $btn      = $( event.currentTarget );
			const $dropdown = $btn.siblings( '.awts_card_dropdown' );
			const isHidden  = $dropdown.prop( 'hidden' );

			this.closeAllCardMenus();

			if ( isHidden ) {
				$dropdown.prop( 'hidden', false );
				$btn.attr( 'aria-expanded', 'true' );
			}
		},

		closeAllCardMenus() {
			this.$panel.find( '.awts_card_dropdown' ).prop( 'hidden', true );
			this.$panel.find( '.awts_card_menu_btn' ).attr( 'aria-expanded', 'false' );
		},

		// -------------------------------------------------------------------------
		// Save / edit
		// -------------------------------------------------------------------------

		saveForm( event ) {
			event.preventDefault();

			if ( ! this.validateFields() ) {
				return;
			}

			this.hideFormNotice();

			this.request( {
				url:    awtsData.saveEndpoint,
				method: 'POST',
				data:   this.getSavePayload(),
			} )
				.then( response => this.handleSaveSuccess( response ) )
				.catch( ( { xhr } ) => this.handleSaveFailure( xhr ) );
		},

		getSavePayload() {
			return {
				order_id:        this.$orderId.val(),
				update_id:       this.$updateId.val(),
				title:           this.$name.val().trim(),
				assignee_id:     this.$assigneeId.val(),
				status:          this.$status.val(),
				internal_note:   this.getFieldValue( this.$internalNote ).trim(),
				customer_note:   this.getFieldValue( this.$customerNote ).trim(),
				mentioned_user_ids: this.extractMentionedUserIdsFromText( this.getFieldValue( this.$internalNote ) ),
			};
		},

		handleSaveSuccess( response ) {
			const updateId = response && response.updateId ? parseInt( response.updateId, 10 ) : 0;
			const wasEdit  = !! this.$updateId.val();

			this.updateSavedCard( response, updateId );

			this.resetForm();
			this.hideFormNotice();
			this.hidePopover();

			const message = response && response.message ? response.message : awtsData.successMessage;
			this.showToast( message, 'success' );

			this.trigger( wasEdit ? 'awts_update_edited' : 'awts_update_saved', { updateId, response } );
		},

		handleSaveFailure( xhr ) {
			const message = this.getRequestErrorMessage( xhr, 'saveFailed' );

			this.showFormNotice( message, 'error' );
		},

		fetchUpdateForEdit() {
			const updateId = this.activeTrigger
				? parseInt( this.activeTrigger.attr( 'data-awts-update-id' ), 10 )
				: 0;

			if ( ! updateId ) {
				return;
			}

			this.request( {
				url:    awtsData.updateEndpointBase + updateId,
				method: 'GET',
			} )
				.then( response => {
					this.prepareEditMode( response && response.update ? response.update : null );
					this.showPopover( updateId, 'edit' );
				} )
				.catch( ( { xhr } ) => this.handleSaveFailure( xhr ) );
		},

		// -------------------------------------------------------------------------
		// Mark as solved
		// -------------------------------------------------------------------------

		toggleStatusPicker( event ) {
			event.preventDefault();
			// Mirror the assignee pattern — clicking the pill button hides
			// the static label and reveals the inline <select>. The select
			// already carries the current value via `selected`, so opening
			// then closing without changing is a no-op.
			const $btn  = $( event.currentTarget );
			const $item = $btn.closest( '.awts_status_item' );
			$btn.attr( 'hidden', true );
			const $wrap = $item.find( '.awts_inline_status_wrap' );
			$wrap.removeAttr( 'hidden' );
			$wrap.find( 'select' ).trigger( 'focus' );
		},

		changeStatus( event ) {
			const $select  = $( event.currentTarget );
			const $wrap    = $select.closest( '[data-awts-update-id]' );
			const updateId = parseInt( $wrap.data( 'awts-update-id' ), 10 );
			const status   = String( $select.val() || '' );

			if ( ! updateId || '' === status ) return;

			const previousValue = $select.data( 'awts-previous-value' ) || $select.find( 'option:first' ).val();

			this.withDisabled( $select, this.request( {
				url:    awtsData.updateEndpointBase + updateId + '/status',
				method: 'POST',
				data:   { status },
			} ) )
				.then( response => {
					$select.data( 'awts-previous-value', status );
					// The endpoint returns a fresh cardHtml — reuse the existing
					// updateSavedCard plumbing so the color stripe, pill, and
					// every dependent affordance refresh in one swap.
					this.updateSavedCard( { ...response, isEdit: true }, updateId );
					this.trigger( 'awts_update_status_changed', { updateId, status, response } );
				} )
				.catch( ( { xhr } = {} ) => {
					// Roll back to the previously-saved value so the dropdown
					// doesn't keep showing a status the server rejected.
					$select.val( previousValue );
					window.alert( this.getRequestErrorMessage( xhr, 'saveFailed' ) );
				} );
		},

		markSolved( event ) {
			event.preventDefault();

			const $button  = $( event.currentTarget );
			const updateId = parseInt( $button.closest( '[data-awts-update-id]' ).data( 'awts-update-id' ), 10 );

			if ( ! updateId || $button.prop( 'disabled' ) ) {
				return;
			}

			const $card = $button.closest( '.awts_card' );
			const isCustomerVisible = '1' === String( $card.data( 'awts-customer-visible' ) );

			this.showInlineActionConfirm( $button, {
				prompt: this.getString( 'solveInlinePrompt' ) || 'Mark this update as solved?',
				cancel: this.getString( 'deleteInlineCancel' ) || 'Cancel',
				silent: isCustomerVisible
					? ( this.getString( 'solveInlineSilent' ) || 'Silently mark solved' )
					: ( this.getString( 'solveInlineBare' ) || 'Mark solved' ),
				notify: this.getString( 'solveInlineNotify' ) || 'Notify customer & mark solved',
				showNotify: isCustomerVisible,
				onChoice: ( choice, { restore } ) => {
					this.request( {
						url:    awtsData.solveEndpointBase + updateId + '/solve',
						method: 'POST',
						data:   { notify_customer: 'notify' === choice ? '1' : '0' },
					} )
						.then( response => {
							this.updateSavedCard( { ...response, isEdit: true }, updateId );
							this.trigger( 'awts_update_solved', { updateId, response } );
						} )
						.catch( ( { xhr } = {} ) => {
							restore();
							if ( xhr && xhr.status === 409 ) {
								window.alert( this.getString( 'alreadySolved' ) );
								this.request( { url: awtsData.updateEndpointBase + updateId, method: 'GET' } )
									.then( response => {
										if ( response && response.update ) {
											this.updateSavedCard( { ...response, isEdit: true }, updateId );
										}
									} )
									.catch( () => {} );
							}
						} );
				},
			} );
		},

		reopenUpdate( event ) {
			event.preventDefault();

			const $button  = $( event.currentTarget );
			const updateId = parseInt( $button.closest( '[data-awts-update-id]' ).data( 'awts-update-id' ), 10 );

			if ( ! updateId || $button.prop( 'disabled' ) ) {
				return;
			}

			if ( ! window.confirm( this.getString( 'reopenConfirm' ) ) ) {
				return;
			}

			this.withDisabled( $button, this.request( {
				url:    awtsData.reopenEndpointBase + updateId + '/reopen',
				method: 'POST',
			} ) )
				.then( response => {
					this.updateSavedCard( { ...response, isEdit: true }, updateId );
					this.trigger( 'awts_update_reopened', { updateId, response } );
				} )
				.catch( ( { xhr } = {} ) => {
					if ( xhr && xhr.status === 409 ) {
						window.alert( this.getString( 'alreadyOpen' ) );
						this.request( { url: awtsData.updateEndpointBase + updateId, method: 'GET' } )
							.then( response => {
								if ( response && response.update ) {
									this.updateSavedCard( { ...response, isEdit: true }, updateId );
								}
							} )
							.catch( () => {} );
					}
				} );
		},

		// -------------------------------------------------------------------------
		// Delete
		// -------------------------------------------------------------------------

		/**
		 * Render an inline 3-choice confirm row inside the card's
		 * .awts_card_actions container. Swaps the existing children out for
		 * a Cancel / Silent / Notify row; calls onChoice('silent'|'notify')
		 * when the admin picks one. Used by Delete and Mark-as-solved.
		 */
		showInlineActionConfirm( $trigger, { prompt, cancel, silent, notify, showNotify, onChoice } ) {
			const $actions = $trigger.closest( '.awts_card_actions' );

			if ( $actions.find( '.awts_inline_confirm' ).length ) {
				return;
			}

			const $originalChildren = $actions.children().not( '.awts_inline_confirm' );
			$originalChildren.hide();

			let confirmHtml = ''
				+ '<div class="awts_inline_confirm">'
				+ '<span class="awts_inline_confirm__prompt"></span>'
				+ '<button type="button" class="awts_text_action awts_inline_confirm_cancel"></button>'
				+ '<button type="button" class="awts_text_action awts_inline_confirm_silent"></button>';

			if ( false !== showNotify ) {
				confirmHtml += '<button type="button" class="awts_text_action awts_text_action_primary awts_inline_confirm_notify"></button>';
			}

			confirmHtml += '</div>';

			const $confirm = $( confirmHtml );
			$confirm.find( '.awts_inline_confirm__prompt' ).text( prompt );
			$confirm.find( '.awts_inline_confirm_cancel' ).text( cancel );
			$confirm.find( '.awts_inline_confirm_silent' ).text( silent );
			$confirm.find( '.awts_inline_confirm_notify' ).text( notify );

			$actions.append( $confirm );

			const restore = () => {
				$confirm.remove();
				$originalChildren.show();
			};

			const pick = ( choice ) => {
				$confirm.find( 'button' ).prop( 'disabled', true );
				onChoice( choice, { restore, $confirm } );
			};

			$confirm.on( 'click', '.awts_inline_confirm_cancel', e => { e.preventDefault(); restore(); } );
			$confirm.on( 'click', '.awts_inline_confirm_silent', e => { e.preventDefault(); pick( 'silent' ); } );
			$confirm.on( 'click', '.awts_inline_confirm_notify', e => { e.preventDefault(); pick( 'notify' ); } );
		},

		deleteUpdate( event ) {
			event.preventDefault();

			const $button  = $( event.currentTarget );
			const updateId = parseInt( $button.data( 'awts-update-id' ), 10 );

			if ( ! updateId || $button.prop( 'disabled' ) ) {
				return;
			}

			const $card = $button.closest( '.awts_card' );
			const isCustomerVisible = '1' === String( $card.data( 'awts-customer-visible' ) );

			this.showInlineActionConfirm( $button, {
				prompt: this.getString( 'deleteInlinePrompt' ) || 'Delete this update?',
				cancel: this.getString( 'deleteInlineCancel' ) || 'Cancel',
				silent: isCustomerVisible
					? ( this.getString( 'deleteInlineSilent' ) || 'Silently delete' )
					: ( this.getString( 'deleteInlineBare' ) || 'Delete' ),
				notify: this.getString( 'deleteInlineNotify' ) || 'Notify customer & delete',
				showNotify: isCustomerVisible,
				onChoice: ( choice, { restore } ) => {
					this.request( {
						url:    awtsData.deleteEndpointBase + updateId,
						method: 'DELETE',
						data:   { notify_customer: 'notify' === choice ? '1' : '0' },
					} )
						.then( () => {
							this.$updateList.find( '[data-awts-update-id="' + updateId + '"]' ).remove();
						} )
						.catch( ( { xhr } ) => {
							restore();
							const message = this.getRequestErrorMessage( xhr, 'saveFailed' );
							window.alert( message );
						} );
				},
			} );
		},

		// -------------------------------------------------------------------------
		// Collapse toggle
		// -------------------------------------------------------------------------

		toggleCollapse( event ) {
			event.preventDefault();
			event.stopPropagation();
			const $card = $( event.currentTarget ).closest( '.awts_card' );
			$card.toggleClass( 'awts_card--collapsed' );

			// On expand, kick the same note-thread bootstrap the legacy
			// whole-row handler used — opens the wraps and loads either
			// thread if it hasn't been fetched yet.
			if ( ! $card.hasClass( 'awts_card--collapsed' ) ) {
				const $notesWrap          = $card.find( '.awts_notes_wrap' );
				const $notesThread        = $notesWrap.find( '.awts_notes_thread' );
				const $customerNotesWrap  = $card.find( '.awts_customer_notes_wrap' );
				const $customerThread     = $customerNotesWrap.find( '.awts_customer_notes_thread' );
				const updateId            = parseInt( $notesThread.data( 'awts-update-id' ), 10 );

				$notesWrap.addClass( 'awts_open' );
				$customerNotesWrap.addClass( 'awts_open' );

				if ( updateId ) {
					this.loadNotesForThread( $notesThread, updateId );
					if ( $customerThread.length ) {
						this.loadCustomerNotesForThread( $customerThread, updateId );
					}
				}
			}
		},

		toggleCollapseOnHeader( event ) {
			if ( $( event.target ).closest( '.awts_card_actions, button, a, input, select, textarea' ).length ) {
				return;
			}
			const $card = $( event.currentTarget ).closest( '.awts_card' );
			$card.toggleClass( 'awts_card--collapsed' );

			if ( ! $card.hasClass( 'awts_card--collapsed' ) ) {
				const $notesWrap          = $card.find( '.awts_notes_wrap' );
				const $notesThread        = $notesWrap.find( '.awts_notes_thread' );
				const $customerNotesWrap  = $card.find( '.awts_customer_notes_wrap' );
				const $customerThread     = $customerNotesWrap.find( '.awts_customer_notes_thread' );
				const updateId            = parseInt( $notesThread.data( 'awts-update-id' ), 10 );

				$notesWrap.addClass( 'awts_open' );
				$customerNotesWrap.addClass( 'awts_open' );

				if ( updateId ) {
					this.loadNotesForThread( $notesThread, updateId );
					if ( $customerThread.length ) {
						this.loadCustomerNotesForThread( $customerThread, updateId );
					}
				}
			}
		},

		// -------------------------------------------------------------------------
		// Load more
		// -------------------------------------------------------------------------

		loadMoreUpdates( event ) {
			event.preventDefault();

			const $button  = $( event.currentTarget );
			const orderId  = parseInt( $button.data( 'awts-order-id' ), 10 );
			const offset   = parseInt( $button.data( 'awts-offset' ), 10 ) || 0;

			if ( ! orderId || $button.prop( 'disabled' ) ) {
				return;
			}

			$button.prop( 'disabled', true ).text( this.getString( 'loadingMore' ) );

			this.request( {
				url:    awtsData.listEndpoint,
				method: 'GET',
				data:   { order_id: orderId, offset },
			} )
				.then( response => this.handleLoadMoreSuccess( response, $button, orderId ) )
				.catch( () => $button.prop( 'disabled', false ).text( this.getString( 'loadMore' ) ) );
		},

		handleLoadMoreSuccess( response, $button, orderId ) {
			if ( response && response.html ) {
				this.$updateList.append( response.html );
			}

			if ( response && response.hasMore ) {
				$button
					.prop( 'disabled', false )
					.text( this.getString( 'loadMore' ) )
					.data( 'awts-offset', response.nextOffset );
			} else {
				$button.closest( '.awts_button_row' ).remove();
			}

			this.trigger( 'awts_updates_loaded', { orderId, response } );
		},

		// -------------------------------------------------------------------------
		// Refresh
		// -------------------------------------------------------------------------

		refreshUpdates( event ) {
			event.preventDefault();

			const $button = $( event.currentTarget );
			const orderId = parseInt( this.$panel.find( '#awts_order_id' ).val() || $button.data( 'awts-order-id' ), 10 );

			if ( ! orderId || $button.hasClass( 'awts_spinning' ) ) {
				return;
			}

			$button.addClass( 'awts_spinning' );

			this.request( {
				url:    awtsData.listEndpoint,
				method: 'GET',
				data:   { order_id: orderId, offset: 0 },
			} )
				.then( response => {
					if ( response && response.html ) {
						this.$updateList.html( response.html );
						this.autoLoadNotes();
						this.autoLoadCustomerNotes();
					}
					$button.removeClass( 'awts_spinning' );
				} )
				.catch( () => {
					$button.removeClass( 'awts_spinning' );
				} );
		},

		// -------------------------------------------------------------------------
		// Card update
		// -------------------------------------------------------------------------

		updateSavedCard( response, updateId ) {
			if ( ! response || ! response.cardHtml || ! updateId ) {
				return;
			}

			// Mark the note we just created as already read so it won't show as New to ourselves.
			if ( response.noteId ) {
				this.setLastReadNoteId( updateId, parseInt( response.noteId, 10 ) );
			}

			const $card         = $( response.cardHtml );
			const $existingCard = this.$updateList.children( '.awts_card[data-awts-update-id="' + updateId + '"]' );

			// Re-rendered cards default the "Enter = Send" box to checked (template);
			// sync it to the saved per-browser preference so a user who turned it
			// off doesn't get it back on every re-render.
			$card.find( '[data-awts-enter-to-send]' ).prop( 'checked', '0' !== localStorage.getItem( 'awts_enter_to_send' ) );

			this.$updateList.find( '.awts_update_card_empty' ).remove();

			if ( response.isEdit && $existingCard.length ) {
				$existingCard.replaceWith( $card );
				this.loadNotesForCard( $card );
				this.loadCustomerNotesForCard( $card );
				return;
			}

			this.$updateList.prepend( $card );
			this.loadNotesForCard( $card );
			this.loadCustomerNotesForCard( $card );

			const $newCard = $( '#awts-update-' + updateId );
			if ( $newCard.length ) {
				$newCard[ 0 ].scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}
		},

		// -------------------------------------------------------------------------
		// Assignee search
		// -------------------------------------------------------------------------

		searchAssignees() {
			clearTimeout( this.assigneeTimer );
			this.$assigneeId.val( '' );

			const query = this.$assignee.val().trim();

			if ( query.length < 3 ) {
				this.clearAssigneeSuggestions();
				return;
			}

			this.assigneeTimer = setTimeout( () => this.fetchAssignees( query ), 400 );
		},

		fetchAssignees( query ) {
			if ( this.assigneeRequest ) {
				this.assigneeRequest.abort();
			}

			this.$assignee.addClass( 'awts_loading' );

			this.assigneeRequest = this.request( {
				url:    awtsData.searchEndpoint,
				method: 'GET',
				data:   { query },
			} )
				.then( users => {
					users.length ? this.renderAssigneeSuggestions( users ) : this.clearAssigneeSuggestions();
				} )
				.catch( ( { status } ) => {
					if ( status !== 'abort' ) {
						this.clearAssigneeSuggestions();
					}
				} )
				.finally( () => {
					this.$assignee.removeClass( 'awts_loading' );
					this.assigneeRequest = null;
				} );
		},

		renderAssigneeSuggestions( users ) {
			this.clearAssigneeSuggestions();

			const $list = $( '<ul class="awts_suggestions"></ul>' );

			users.forEach( user => {
				const $avatar = user.avatar
					? $( '<img class="awts_suggestion_avatar">' ).attr( 'src', user.avatar )
					: $( '<span class="awts_suggestion_avatar awts_suggestion_avatar_fallback"></span>' ).text( user.name.charAt( 0 ) );

				const $item = $( '<li class="awts_suggestion_item"></li>' ).append(
					$avatar,
					$( '<span class="awts_suggestion_info"></span>' ).append(
						$( '<span class="awts_suggestion_name"></span>' ).text( user.name ),
						$( '<span class="awts_suggestion_email"></span>' ).text( user.email )
					)
				);

				$item.data( 'awts-user', user );
				$list.append( $item );
			} );

			this.$assignee.after( $list );
		},

		clearAssigneeSuggestions() {
			this.$assignee.siblings( '.awts_suggestions' ).remove();
		},

		handleAssigneeSelection( event ) {
			event.preventDefault();
			event.stopPropagation();

			if ( event.type === 'pointerdown' && event.pointerType === 'mouse' && event.button !== 0 ) {
				return;
			}

			const user = $( event.currentTarget ).data( 'awts-user' );

			if ( user ) {
				this.selectAssignee( user );
			}
		},

		handleAssigneeClear( event ) {
			event.preventDefault();
			this.clearAssignee();
		},

		selectAssignee( user ) {
			this.$assigneeId.val( user.id );
			this.$assignee.val( user.name ).hide();
			this.clearAssigneeSuggestions();

			const $chip = $( '<span class="awts_assignee_chip"></span>' ).append(
				$( '<span class="awts_chip_name"></span>' ).text( user.name ),
				$( '<button class="awts_chip_remove" type="button">&#x2715;</button>' )
			);

			this.$assignee.before( $chip );
		},

		clearAssignee() {
			this.$assignee.siblings( '.awts_assignee_chip' ).remove();
			this.$assignee.val( '' ).show().trigger( 'focus' );
			this.$assigneeId.val( '' );
		},

		// -------------------------------------------------------------------------
		// Inline title editing
		// -------------------------------------------------------------------------

		startInlineEditTitle( event ) {
			event.preventDefault();
			event.stopPropagation();

			const $btn   = $( event.currentTarget );
			const $card  = $btn.closest( '.awts_card' );
			const $title = $card.find( '.awts_title' );
			const $text  = $title.find( '.awts_title_text' );
			const currentTitle = $text.text().trim();

			$title.find( '.awts_edit_title' ).hide();
			$text.hide();

			const $input = $( '<input type="text" class="awts_inline_title_input" maxlength="191">' )
				.val( currentTitle )
				.insertAfter( $text );

			$input.trigger( 'focus' ).select();

			const finishEdit = () => {
				const newTitle = $input.val().trim();
				$input.remove();
				$text.show();
				$title.find( '.awts_edit_title' ).show();

				if ( ! newTitle || newTitle === currentTitle ) {
					return;
				}

				$text.text( newTitle );
				this.saveInlineField( $card, { title: newTitle } );
			};

			$input.on( 'blur', finishEdit );
			$input.on( 'keydown', e => {
				if ( e.key === 'Enter' ) {
					e.preventDefault();
					$input.off( 'blur' );
					finishEdit();
				}
				if ( e.key === 'Escape' ) {
					$input.off( 'blur' );
					$input.remove();
					$text.show();
					$title.find( '.awts_edit_title' ).show();
				}
			} );
		},

		// -------------------------------------------------------------------------
		// Inline assignee editing
		// -------------------------------------------------------------------------

		startInlineEditAssignee( event ) {
			event.preventDefault();
			event.stopPropagation();

			const $btn   = $( event.currentTarget );
			const $item  = $btn.closest( '.awts_assignee_item' );
			const $wrap  = $item.find( '.awts_inline_assignee_wrap' );
			const $input = $wrap.find( '.awts_inline_assignee_input' );

			$btn.hide();
			$wrap.removeAttr( 'hidden' );
			$input.val( '' ).trigger( 'focus' );

			const closeAssigneeEdit = () => {
				$wrap.attr( 'hidden', 'hidden' );
				$wrap.find( '.awts_suggestions' ).remove();
				$btn.show();
			};

			$input.off( 'blur.assignee' ).on( 'blur.assignee', () => {
				setTimeout( closeAssigneeEdit, 200 );
			} );

			$input.off( 'keydown.assignee' ).on( 'keydown.assignee', e => {
				if ( e.key === 'Escape' ) {
					$input.off( 'blur.assignee' );
					closeAssigneeEdit();
					return;
				}

				if ( e.key === 'Enter' ) {
					// Prevent the order edit form from submitting (the assignee
					// input sits inside the WC order form, so a bare Enter
					// would refresh the whole page and lose the picker state).
					// If a suggestion is open, pick the first one — that's
					// what the user was reaching for. The click handler is
					// bound to `pointerDownEvent`, so dispatch that exact event.
					e.preventDefault();
					const $first = $wrap.find( '.awts_suggestion_item' ).first();
					if ( $first.length ) {
						$first.trigger( this.pointerDownEvent || 'mousedown' );
					}
				}
			} );
		},

		searchInlineAssignee( event ) {
			const $input = $( event.currentTarget );
			const $wrap  = $input.closest( '.awts_inline_assignee_wrap' );
			const query  = $input.val().trim();

			clearTimeout( this.inlineAssigneeTimer );
			$wrap.find( '.awts_suggestions' ).remove();

			if ( query.length < 3 ) {
				return;
			}

			this.inlineAssigneeTimer = setTimeout( () => {
				this.request( {
					url:    awtsData.searchEndpoint,
					method: 'GET',
					data:   { query: query },
				} )
					.then( response => {
						$wrap.find( '.awts_suggestions' ).remove();

						const users = Array.isArray( response ) ? response : [];

						if ( ! users.length ) {
							return;
						}

						const $list = $( '<ul class="awts_suggestions"></ul>' );

						users.forEach( user => {
							const $li = $( '<li class="awts_suggestion_item"></li>' )
								.attr( 'data-awts-user-id', user.id )
								.attr( 'data-awts-user-name', user.name );

							if ( user.avatar ) {
								// Build via .attr() so the URL is set through the DOM
								// API and can't break out of the src attribute, matching
								// the renderAssigneeSuggestions path above.
								$li.append( $( '<img class="awts_suggestion_avatar" alt="">' ).attr( 'src', user.avatar ) );
							}

							$li.append(
								$( '<span class="awts_suggestion_name"></span>' ).text( user.name ),
								$( '<span class="awts_suggestion_email"></span>' ).text( user.email )
							);

							$list.append( $li );
						} );

						$wrap.append( $list );
					} );
			}, 300 );
		},

		selectInlineAssignee( event ) {
			event.preventDefault();
			event.stopPropagation();

			const $li    = $( event.currentTarget );
			const $item  = $li.closest( '.awts_assignee_item' );
			const $card  = $li.closest( '.awts_card' );
			const $wrap  = $item.find( '.awts_inline_assignee_wrap' );
			const $input = $wrap.find( '.awts_inline_assignee_input' );
			const $idField = $wrap.find( '.awts_inline_assignee_id' );
			const $btn   = $item.find( '.awts_edit_assignee' );
			const $name  = $item.find( '.awts_assignee_name' );

			const userId   = $li.data( 'awts-user-id' );
			const userName = $li.data( 'awts-user-name' );
			$name.text( userName );
			$idField.val( userId );
			$item.attr( 'data-awts-assignee-id', userId );

			$input.off( 'blur.assignee' );
			$wrap.attr( 'hidden', 'hidden' );
			$wrap.find( '.awts_suggestions' ).remove();
			$btn.show();

			const savePromise = this.saveInlineField( $card, { assignee_id: userId } );

			// Re-render from the server card on every assignee change. The avatar
			// is a server-composited disc (initials + colour + Gravatar overlay),
			// so it can't be patched client-side — only a re-render shows the new
			// assignee's avatar instead of leaving the previous one in place.
			if ( savePromise ) {
				const updateId = parseInt( $card.data( 'awts-update-id' ), 10 );
				savePromise.then( response => {
					if ( response && response.cardHtml ) {
						this.updateSavedCard( { ...response, isEdit: true }, updateId );
					}
				} ).catch( () => {} );
			}
		},

		// -------------------------------------------------------------------------
		// Inline save helper
		// -------------------------------------------------------------------------

		saveInlineField( $card, fields ) {
			const updateId = parseInt( $card.data( 'awts-update-id' ), 10 );
			const orderId  = this.$orderId.val();

			if ( ! updateId || ! orderId ) {
				return;
			}

			const data = Object.assign( {
				order_id:  orderId,
				update_id: updateId,
			}, fields );

			if ( ! data.title ) {
				const $titleText = $card.find( '.awts_title_text' );
				data.title = $titleText.text().trim();
			}

			if ( ! data.hasOwnProperty( 'assignee_id' ) ) {
				const $assigneeItem = $card.find( '.awts_assignee_item' );
				data.assignee_id = $assigneeItem.length ? ( $assigneeItem.data( 'awts-assignee-id' ) || 0 ) : 0;
			}

			if ( ! data.hasOwnProperty( 'color' ) ) {
				const $border = $card.find( '.awts_card_left_border' );
				data.color = $border.css( 'background-color' ) || '#d97706';
				const rgb = data.color.match( /rgb\((\d+),\s*(\d+),\s*(\d+)\)/ );
				if ( rgb ) {
					data.color = '#' + [ rgb[1], rgb[2], rgb[3] ].map( x => parseInt(x).toString(16).padStart(2, '0') ).join('');
				}
			}

			return this.request( {
				url:    awtsData.saveEndpoint,
				method: 'POST',
				data:   data,
			} )
				.catch( ( { xhr } ) => {
					const message = this.getRequestErrorMessage( xhr, 'saveFailed' );
					window.alert( message );
				} );
		},

		// -------------------------------------------------------------------------
		// Form modes
		// -------------------------------------------------------------------------

		prepareAddMode() {
			this.clearErrors();
			this.hideFormNotice();
			this.$heading.text( this.getString( 'addHeading' ) );
			this.$formModeHint.attr( 'hidden', 'hidden' );
			this.$saveButton.text( this.getString( 'addAction' ) );
			this.$updateId.val( '' );
			this.$name.val( '' );
			this.clearAssignee();
			// Reset status dropdown to its first option (admin's default
			// ordering in settings places the seed default at the top).
			this.$status.prop( 'selectedIndex', 0 );
			this.$internalNote.val( '' );
			this.$customerNote.val( '' );
			this.$addOnlyFields.removeAttr( 'hidden' );
			this.hideMetaBlock();
			this.setMetaBlockValues( '', '' );
			this.refreshNoteCounters();
			this.toggleCustomerNoteField();
		},

		prepareEditMode( update ) {
			if ( ! update ) {
				this.prepareAddMode();
				return;
			}

			this.clearErrors();
			this.hideFormNotice();
			this.$heading.text( this.getString( 'editHeading' ) );
			this.$formModeHint.removeAttr( 'hidden' );
			this.$saveButton.text( this.getString( 'editAction' ) );
			this.$updateId.val( update.id || '' );
			this.$name.val( update.title || '' );
			// Restore the stored status key — if it doesn't match an option
			// (admin removed the status after the row was saved), fall back
			// to the first option so the dropdown is never blank.
			const storedStatus = String( update.status || '' );
			this.$status.val( storedStatus );
			if ( ! this.$status.val() ) {
				this.$status.prop( 'selectedIndex', 0 );
			}
			this.$internalNote.val( '' );
			this.$customerNote.val( '' );
			this.$addOnlyFields.attr( 'hidden', 'hidden' );

			if ( update.assignee_user_id && update.assignee_name ) {
				this.selectAssignee( { id: update.assignee_user_id, name: update.assignee_name } );
			} else {
				this.clearAssignee();
			}

			this.setMetaBlockValues( update.created_at || '', update.notified_customer_at || '' );
			this.showMetaBlock();
			this.refreshNoteCounters();
			this.toggleCustomerNoteField();
		},

		resetForm() {
			this.clearErrors();
			this.clearAssignee();
			this.hideFormNotice();
			this.prepareAddMode();
		},

		// -------------------------------------------------------------------------
		// Validation
		// -------------------------------------------------------------------------

		validateFields() {
			this.clearErrors();

			const checks = [
				! this.$name.val().trim() && [ this.$name, 'titleRequired' ],
				this.$internalNote.length && ! this.validatePlainTextField( this.$internalNote, this.getString( 'internalNoteLabel' ) ) && null,
				this.$customerNote.length && ! this.validatePlainTextField( this.$customerNote, this.getString( 'customerNoteLabel' ) ) && null,
			];

			checks.forEach( check => {
				if ( check && check[ 0 ] ) {
					this.showError( check[ 0 ], this.getString( check[ 1 ] ) );
				}
			} );

			return ! this.$form.find( '.awts_input_error' ).length;
		},

		validatePlainTextField( $field, label ) {
			const value          = this.getFieldValue( $field ).trim();
			const characterLimit = this.getCharacterLimit( $field );

			if ( ! value ) {
				this.clearError( $field );
				return true;
			}

			if ( /<[^>]+>/.test( value ) ) {
				this.showError( $field, this.sprintf( this.getString( 'plainTextOnly' ), [ label ] ) );
				return false;
			}

			if ( characterLimit && value.length > characterLimit ) {
				this.showError( $field, this.sprintf( this.getString( 'characterLimit' ), [ label, characterLimit ] ) );
				return false;
			}

			this.clearError( $field );
			return true;
		},


		// -------------------------------------------------------------------------
		// Error display
		// -------------------------------------------------------------------------

		showError( $field, message ) {
			$field.addClass( 'awts_input_error' );
			$field.after( '<span class="awts_error_msg">' + message + '</span>' );
			$field.one( 'input', () => this.clearError( $field ) );
		},

		clearError( $field ) {
			$field.removeClass( 'awts_input_error' );
			$field.next( '.awts_error_msg' ).remove();
		},

		clearErrors() {
			this.$form.find( '.awts_input_error' ).removeClass( 'awts_input_error' );
			this.$form.find( '.awts_error_msg' ).remove();
		},

		// -------------------------------------------------------------------------
		// Notice
		// -------------------------------------------------------------------------

		showFormNotice( message, type ) {
			this.$formNotice
				.removeClass( 'awts_form_notice_success awts_form_notice_error' )
				.addClass( 'success' === type ? 'awts_form_notice_success' : 'awts_form_notice_error' )
				.text( message )
				.removeAttr( 'hidden' );
		},

		hideFormNotice() {
			window.clearTimeout( this.noticeTimer );
			this.$formNotice
				.attr( 'hidden', 'hidden' )
				.removeClass( 'awts_form_notice_success awts_form_notice_error' )
				.text( '' );
		},

		showToast( message, type ) {
			$( '.awts_toast' ).remove();
			const cls = 'awts_toast' + ( type === 'error' ? ' awts_toast_error' : ' awts_toast_success' );
			const $toast = $( '<div class="' + cls + '">' + $( '<span>' ).text( message ).html() + '</div>' );
			this.$panel.prepend( $toast );
			setTimeout( () => $toast.fadeOut( 300, function() { $( this ).remove(); } ), 3000 );
		},

		// Fixed, viewport-centred notice. Used when there's nothing on screen to
		// scroll to (e.g. a deep link to a deleted update) — a panel toast would
		// sit off-screen. Click or wait to dismiss.
		showCenterNotice( message ) {
			$( '.awts_center_notice' ).remove();
			const $notice = $( '<div class="awts_center_notice" role="status"></div>' );
			$( '<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>' ).appendTo( $notice );
			$( '<span>' ).text( message ).appendTo( $notice );
			$( 'body' ).append( $notice );

			const remove = () => $notice.fadeOut( 200, function() { $( this ).remove(); } );
			$notice.on( 'click', remove );
			window.setTimeout( remove, 3500 );
		},

		// -------------------------------------------------------------------------
		// Notes / character counters
		// -------------------------------------------------------------------------

		handleNoteChange( event ) {
			const $field = $( event.currentTarget );
			this.enforceCharacterLimit( $field );
			this.updateNoteCounter( $field );
			this.validatePlainTextField( $field, this.getNoteFieldLabel( $field ) );
		},

		getNoteFieldLabel( $field ) {
			return $field && $field.is( this.$internalNote )
				? this.getString( 'internalNoteLabel' )
				: this.getString( 'customerNoteLabel' );
		},

		enforceCharacterLimit( $field ) {
			const characterLimit = this.getCharacterLimit( $field );
			const value          = this.getFieldValue( $field );

			if ( characterLimit && value.length > characterLimit ) {
				$field.val( value.slice( 0, characterLimit ) );
			}
		},

		updateNoteCounter( $field ) {
			const fieldId        = $field.attr( 'id' );
			const characterLimit = this.getCharacterLimit( $field );
			const $counter       = this.$popover.find( '[data-awts-counter-for="' + fieldId + '"]' );

			if ( ! $counter.length || ! characterLimit ) {
				return;
			}

			const characterCount = this.getFieldValue( $field ).length;
			$counter
				.text( characterCount + '/' + characterLimit )
				.toggleClass( 'awts_field_hint_error', characterCount > characterLimit );
		},

		refreshNoteCounters() {
			this.$noteFields.each( ( _, field ) => this.updateNoteCounter( $( field ) ) );
		},

		getCharacterLimit( $field ) {
			const limit = parseInt( $field.data( 'awts-character-limit' ), 10 );
			return Number.isNaN( limit ) ? 0 : limit;
		},

		// -------------------------------------------------------------------------
		// Meta block
		// -------------------------------------------------------------------------

		showMetaBlock() {
			this.$metaBlock.removeAttr( 'hidden' );
		},

		hideMetaBlock() {
			this.$metaBlock.attr( 'hidden', 'hidden' );
		},

		setMetaBlockValues( createdAt, notifiedAt ) {
			if ( this.$metaCreatedValue.length ) {
				this.$metaCreatedValue.text( this.formatMetaDate( createdAt ) );
			}

			if ( this.$metaNotifiedValue.length ) {
				this.$metaNotifiedValue.text( this.formatMetaDate( notifiedAt, '-' ) );
			}
		},

		formatMetaDate( value, fallback = '' ) {
			if ( ! value ) {
				return fallback;
			}

			const date = new Date( String( value ).replace( ' ', 'T' ) + 'Z' );

			if ( Number.isNaN( date.getTime() ) ) {
				return fallback;
			}

			return date.toLocaleString( undefined, {
				month:  'short',
				day:    'numeric',
				year:   'numeric',
				hour:   'numeric',
				minute: '2-digit',
			} );
		},

		// -------------------------------------------------------------------------
		// Customer note visibility
		// -------------------------------------------------------------------------

		toggleCustomerNoteField() {
			if ( ! this.$customerNoteField.length ) {
				return;
			}

			// Visible in add mode; hidden in edit mode (no customer-note
			// authoring on edits — that happens from the chat thread).
			if ( this.$addOnlyFields.length && this.$addOnlyFields.first().is( '[hidden]' ) ) {
				this.$customerNoteField.attr( 'hidden', 'hidden' );
				this.clearError( this.$customerNote );
				return;
			}

			this.$customerNoteField.removeAttr( 'hidden' );
			this.repositionPopover();
		},

		// -------------------------------------------------------------------------
		// Tabs
		// -------------------------------------------------------------------------

		activateTab( event ) {
			event.preventDefault();
			event.stopPropagation();

			const $tab = $( event.currentTarget );
			this.selectTab( $tab, true );
		},

		selectTab( $tab, focusTab ) {
			const $tablist = $tab.closest( '.awts_card_tabs' );
			const $card    = $tab.closest( '.awts_card' );
			const targetId = $tab.attr( 'aria-controls' );

			if ( ! targetId ) {
				return;
			}

			$tablist.find( '.awts_card_tab' ).each( ( _, el ) => {
				const $other = $( el );
				const isActive = $other.is( $tab );
				$other
					.toggleClass( 'awts_card_tab--active', isActive )
					.attr( 'aria-selected', isActive ? 'true' : 'false' )
					.attr( 'tabindex', isActive ? '0' : '-1' );
			} );

			$card.find( '.awts_card_tab_panel' ).each( ( _, el ) => {
				const $panel = $( el );
				$panel.prop( 'hidden', $panel.attr( 'id' ) !== targetId );
			} );

			// Remember which tab the user picked, scoped to THIS update,
			// so each card on the order remembers its own tab. Only
			// store on user-driven selects (focusTab=true) — programmatic
			// restores would otherwise overwrite themselves on init.
			if ( focusTab ) {
				const tabKey   = $tab.attr( 'data-awts-tab' );
				const updateId = parseInt( $card.attr( 'data-awts-update-id' ), 10 ) || 0;
				const key      = this.lastTabStorageKey( updateId );
				if ( tabKey && key ) {
					localStorage.setItem( key, tabKey );
				}
			}

			if ( focusTab ) {
				$tab.trigger( 'focus' );
			}

			if ( 'history' === $tab.data( 'awts-tab' ) ) {
				const updateId = parseInt( $card.data( 'awts-update-id' ), 10 );
				const $inline  = $card.find( '.awts_history_inline' );

				if ( updateId && $inline.length && ! $inline.data( 'awts-loaded' ) ) {
					this.loadHistoryInline( $inline, updateId );
				}
			}
		},

		handleTabKeydown( event ) {
			const key = event.key;

			if ( 'ArrowLeft' !== key && 'ArrowRight' !== key && 'Home' !== key && 'End' !== key ) {
				return;
			}

			event.preventDefault();

			const $tab     = $( event.currentTarget );
			const $tabs    = $tab.closest( '.awts_card_tabs' ).find( '.awts_card_tab' );
			const count    = $tabs.length;
			const current  = $tabs.index( $tab );
			let nextIndex  = current;

			if ( 'ArrowLeft' === key ) {
				nextIndex = ( current - 1 + count ) % count;
			} else if ( 'ArrowRight' === key ) {
				nextIndex = ( current + 1 ) % count;
			} else if ( 'Home' === key ) {
				nextIndex = 0;
			} else if ( 'End' === key ) {
				nextIndex = count - 1;
			}

			this.selectTab( $tabs.eq( nextIndex ), true );
		},

		loadHistoryInline( $inline, updateId ) {
			$inline.html( '<p class="awts_history_loading">' + this.getString( 'historyLoading' ) + '</p>' );

			this.request( {
				url:    awtsData.historyEndpointBase + updateId + '/history',
				method: 'GET',
			} )
				.then( response => this.renderHistoryInline( response, $inline ) )
				.catch( () => {
					$inline.html( '<p style="color:#dc3232;font-size:12px;">Failed to load history.</p>' );
				} );
		},

		// -------------------------------------------------------------------------
		// History / Actions
		// -------------------------------------------------------------------------

		viewHistory( event ) {
			event.preventDefault();
			event.stopPropagation();

			const $button  = $( event.currentTarget );
			const $card    = $button.closest( '.awts_card' );
			const updateId = parseInt( $button.data( 'awts-update-id' ), 10 );

			if ( ! updateId ) {
				return;
			}

			// Inline history panel inside the card.
			const $wrap   = $card.find( '.awts_history_wrap' );
			const $inline = $wrap.find( '.awts_history_inline' );

			if ( $wrap.length ) {
				if ( $wrap.hasClass( 'awts_open' ) ) {
					$wrap.removeClass( 'awts_open' );
					return;
				}

				$card.removeClass( 'awts_card--collapsed' );
				$wrap.addClass( 'awts_open' );

				if ( $inline.data( 'awts-loaded' ) ) {
					return;
				}

				$inline.html( '<p class="awts_history_loading">' + this.getString( 'historyLoading' ) + '</p>' );

				this.request( {
					url:    awtsData.historyEndpointBase + updateId + '/history',
					method: 'GET',
				} )
					.then( response => this.renderHistoryInline( response, $inline ) )
					.catch( () => {
						$inline.html( '<p style="color:#dc3232;font-size:12px;">Failed to load history.</p>' );
					} );

				return;
			}

			// Fallback: modal for non-modern views.
			this.fetchAndShowHistoryModal( updateId );
		},

		renderHistoryInline( response, $inline ) {
			const events = ( response && response.events ) ? response.events : [];

			if ( ! events.length ) {
				$inline.html( '<p class="awts_history_loading">' + this.getString( 'historyEmpty' ) + '</p>' );
				$inline.data( 'awts-loaded', true );
				return;
			}

			let html = '<div class="awts_history_list_inline">';

			events.forEach( event => {
				const line = this.formatHistoryEvent( event );
				if ( line ) {
					html += '<div class="awts_history_item_inline">' + line + '</div>';
				}
			} );

			html += '</div>';
			$inline.html( html ).data( 'awts-loaded', true );
		},

		fetchAndShowHistoryModal( updateId ) {
			const $modal = $( '.awts_history_modal' );

			if ( ! $modal.length ) {
				$( 'body' ).append( this.createHistoryModal() );
			}

			const $content = $( '.awts_history_modal_content' );
			$content.html( '<p style="padding: 16px; text-align: center;">' + this.getString( 'historyLoading' ) + '</p>' );
			$( '.awts_history_modal_overlay' ).prop( 'hidden', false );

			this.request( {
				url:    awtsData.historyEndpointBase + updateId + '/history',
				method: 'GET',
			} )
				.then( response => this.renderHistoryModal( response ) )
				.catch( () => {
					$content.html( '<p style="padding: 16px; color: #dc3232;">Failed to load history.</p>' );
				} );
		},

		createHistoryModal() {
			return `<div class="awts_history_modal_overlay" hidden>
				<div class="awts_history_modal" role="dialog" aria-labelledby="awts_history_modal_title">
					<div class="awts_history_modal_header">
						<h2 id="awts_history_modal_title">${this.getString( 'historyModalTitle' )}</h2>
						<button type="button" class="awts_history_modal_close" aria-label="Close">&#x2715;</button>
					</div>
					<div class="awts_history_modal_content"></div>
				</div>
			</div>`;
		},

		renderHistoryModal( response ) {
			const events = ( response && response.events ) ? response.events : [];
			const $content = $( '.awts_history_modal_content' );

			if ( ! events.length ) {
				$content.html( '<p style="padding: 16px; color: #646970;">' + this.getString( 'historyEmpty' ) + '</p>' );
				return;
			}

			let html = '<div class="awts_history_list">';

			events.forEach( event => {
				const line = this.formatHistoryEvent( event );
				if ( line ) {
					html += '<div class="awts_history_item">' + line + '</div>';
				}
			} );

			html += '</div>';
			$content.html( html );
		},

		formatHistoryEvent( event ) {
			const timestamp = this.formatMetaDate( event.timestamp );

			switch ( event.type ) {
				case 'created':
					return this.sprintf( this.getString( 'historyCreated' ), [ event.performed_by_name || this.getString( 'unknownUser' ), timestamp ] );

				case 'assigned':
					return this.sprintf( this.getString( 'historyAssigned' ), [ event.assignee_name || this.getString( 'unknownUser' ), event.performed_by_name || this.getString( 'unknownUser' ), timestamp ] );

				case 'unassigned':
					return this.sprintf( this.getString( 'historyUnassigned' ), [ event.assignee_name || this.getString( 'unknownUser' ), event.performed_by_name || this.getString( 'unknownUser' ), timestamp ] );

				case 'notified_assignee':
					return this.sprintf( this.getString( 'historyNotifiedAssignee' ), [ event.assignee_name || this.getString( 'unknownUser' ), timestamp ] );

				case 'solved':
					return this.sprintf( this.getString( 'historySolved' ), [ event.performed_by_name || this.getString( 'unknownUser' ), timestamp ] );

				case 'reopened':
					return this.sprintf( this.getString( 'historyReopened' ), [ event.performed_by_name || this.getString( 'unknownUser' ), timestamp ] );

				case 'notified_customer':
					return this.sprintf( this.getString( 'historyNotifiedCustomer' ), [ timestamp ] );

				case 'status_changed':
				case 'title_changed':
					return this.sprintf(
						this.getString( 'historyStatusChanged' ),
						[ event.message || '', event.performed_by_name || this.getString( 'unknownUser' ), timestamp ]
					);

				case 'rated':
					return this.sprintf(
						this.getString( 'historyRated' ),
						[ event.message || '', timestamp ]
					);

				default:
					return '';
			}
		},

		closeHistoryModal( event ) {
			if ( ! $( event.target ).is( '.awts_history_modal_overlay, .awts_history_modal_close' ) ) {
				return;
			}

			$( '.awts_history_modal_overlay' ).prop( 'hidden', true );
		},

		// -------------------------------------------------------------------------
		// Internal Notes
		// -------------------------------------------------------------------------

		autoLoadNotes() {
			this.$panel.find( '.awts_notes_thread' ).each( ( _, el ) => {
				const $thread  = $( el );
				const updateId = parseInt( $thread.data( 'awts-update-id' ), 10 );

				if ( updateId ) {
					this.loadNotesForThread( $thread, updateId );
				}
			} );
		},

		loadNotesForCard( $card ) {
			const $thread  = $card.find( '.awts_notes_thread' );
			const updateId = parseInt( $thread.data( 'awts-update-id' ), 10 );

			if ( updateId ) {
				this.loadNotesForThread( $thread, updateId );
			}
		},

		loadNotesForThread( $thread, updateId ) {
			if ( $thread.data( 'awts-loaded' ) ) {
				return;
			}

			this.request( {
				url:    awtsData.notesEndpointBase + updateId + '/notes',
				method: 'GET',
			} )
				.then( response => this.renderNotes( response, $thread ) )
				.catch( () => {
					$thread.html( '<p class="awts_notes_error">' + this.getString( 'saveFailed' ) + '</p>' );
				} );
		},

		viewNotes( event ) {
			event.preventDefault();
			event.stopPropagation();

			const $button  = $( event.currentTarget );
			const $card    = $button.closest( '.awts_card' );
			const updateId = parseInt( $button.data( 'awts-update-id' ), 10 );

			if ( ! updateId ) {
				return;
			}

			const $notesWrap          = $card.find( '.awts_notes_wrap' );
			const $notesThread        = $notesWrap.find( '.awts_notes_thread' );
			const $customerNotesWrap  = $card.find( '.awts_customer_notes_wrap' );
			const $customerNotesThread = $customerNotesWrap.find( '.awts_customer_notes_thread' );

			if ( ! $notesWrap.length ) {
				return;
			}

			if ( $notesWrap.hasClass( 'awts_open' ) ) {
				$notesWrap.removeClass( 'awts_open' );
				$customerNotesWrap.removeClass( 'awts_open' );
				return;
			}

			$card.removeClass( 'awts_card--collapsed' );
			$notesWrap.addClass( 'awts_open' );
			this.loadNotesForThread( $notesThread, updateId );

			if ( $customerNotesWrap.length ) {
				$customerNotesWrap.addClass( 'awts_open' );
				this.loadCustomerNotesForThread( $customerNotesThread, updateId );
			}
		},

		// localStorage keys: awts_notes_read_{updateId} for internal notes,
		// awts_customer_notes_read_{updateId} for incoming customer messages —
		// each tracks the highest note id this browser has seen.

		getLastReadNoteId( updateId ) {
			const stored = localStorage.getItem( 'awts_notes_read_' + updateId );
			return stored ? parseInt( stored, 10 ) : 0;
		},

		setLastReadNoteId( updateId, noteId ) {
			const current = this.getLastReadNoteId( updateId );
			if ( noteId > current ) {
				localStorage.setItem( 'awts_notes_read_' + updateId, noteId );
			}
		},

		getLastReadCustomerNoteId( updateId ) {
			const stored = localStorage.getItem( 'awts_customer_notes_read_' + updateId );
			return stored ? parseInt( stored, 10 ) : 0;
		},

		setLastReadCustomerNoteId( updateId, noteId ) {
			const current = this.getLastReadCustomerNoteId( updateId );
			if ( noteId > current ) {
				localStorage.setItem( 'awts_customer_notes_read_' + updateId, noteId );
			}
		},

		renderNotes( response, $thread ) {
			const notes    = ( response && response.notes ) ? response.notes : [];
			const hasMore  = !! ( response && response.has_more );
			const updateId = parseInt( $thread.data( 'awts-update-id' ), 10 );

			// A fresh render is always the live view — clear any windowed state.
			$thread.data( 'awts-windowed', false );
			this.removeJumpToLatest( $thread );

			if ( ! notes.length ) {
				$thread.html( '<p class="awts_notes_empty">' + this.getString( 'notesEmpty' ) + '</p>' );
				$thread.data( 'awts-loaded', true );
				$thread.data( 'awts-thread-kind', 'internal' );
				this.updateNotesBadge( $thread, 0 );
				return;
			}

			let newCount = 0;
			let html     = '';
			const lastId = this.getLastReadNoteId( updateId );

			if ( hasMore ) {
				html += this.buildLoadPreviousButton();
			}

			notes.forEach( note => {
				const isUnread = parseInt( note.id, 10 ) > lastId;
				if ( isUnread ) newCount++;
				html += this.buildNoteHtml( note, isUnread );
			} );

			$thread.html( html )
				.data( 'awts-loaded', true )
				.data( 'awts-thread-kind', 'internal' );

			// Latest messages are at the bottom — open the panel scrolled to
			// the most recent note. Without this the staff member would land
			// on the oldest of the 10-note page and have to scroll down.
			this.scrollThreadToBottom( $thread );
			this.pruneShowMoreButtons( $thread );

			// Advance high-water mark so subsequent heartbeat ticks only fetch newer notes.
			const maxId = notes.reduce( ( max, n ) => Math.max( max, parseInt( n.id, 10 ) || 0 ), 0 );
			if ( updateId && maxId > 0 ) {
				this.internalNoteHighWater[ updateId ] = Math.max( this.internalNoteHighWater[ updateId ] || 0, maxId );
			}

			const $wrap = $thread.closest( '.awts_notes_wrap' );
			$wrap.find( '.awts_mark_notes_read' ).remove();

			if ( newCount > 0 ) {
				$wrap.addClass( 'awts_open' )
				     .prepend( $( '<button type="button" class="awts_mark_notes_read">' + this.getString( 'markAsReadLabel' ) + '</button>' ) );
			}

			this.updateNotesBadge( $thread, newCount );
		},

		// Animated scroll to the bottom of a thread. jQuery `.animate` is
		// already in play so reusing it keeps the bundle small. Used for
		// initial render, heartbeat-arrived notes, and after sending a
		// note. NOT used for the "Load previous" re-anchor — that's an
		// instant `scrollTop` assignment because animating it would
		// visually break the keep-position-steady contract.
		scrollThreadToBottom( $thread, duration = 220 ) {
			const el = $thread && $thread[ 0 ] ? $thread[ 0 ] : null;
			if ( ! el ) return;
			$thread.stop( true ).animate( { scrollTop: el.scrollHeight }, duration );
		},

		// The build* methods render Show-more / Show-less when the note text
		// is over a character threshold. The threshold is naive — a long but
		// narrow-wrapping note can still fit in the 3-line clamp, leaving a
		// button that does nothing visible when clicked. Measure actual
		// overflow after render; drop the button + clamp class when the text
		// fits as-is. Defers one frame so layout is settled before measuring.
		pruneShowMoreButtons( $scope ) {
			if ( ! $scope || ! $scope.length || ! $scope[ 0 ] ) {
				return;
			}
			const root = $scope[ 0 ];
			window.requestAnimationFrame( () => {
				const buttons = root.querySelectorAll( '.awts_show_more' );
				buttons.forEach( ( button ) => {
					const text = button.previousElementSibling;
					if ( ! text || ! text.classList.contains( 'awts_truncated' ) ) {
						return;
					}
					if ( text.scrollHeight <= text.clientHeight + 1 ) {
						text.classList.remove( 'awts_truncated' );
						button.remove();
					}
				} );
			} );
		},

		// Single button used at the top of both internal and customer threads.
		// JS-rendered (not template) so the same handler can target it from
		// either thread without duplicating the click delegate.
		buildLoadPreviousButton() {
			return '<button type="button" class="awts_load_previous_notes">'
				+ this.getString( 'loadPreviousNotes' )
				+ '</button>';
		},

		// Fetch the next 10 older notes and prepend them above the existing
		// thread. Determines internal vs customer thread from the
		// `awts-thread-kind` data attribute set by the renderers, finds the
		// oldest visible note id, and uses it as `before_id`. Newly-arrived
		// rows briefly flash an `awts_notes_item--just_loaded` highlight so
		// the staff member sees what just appeared.
		loadPreviousNotes( event ) {
			event.preventDefault();
			this.fetchOlderNotes( $( event.currentTarget ) );
		},

		// Core of the "Load previous" button — split out so the deep-link
		// reveal can page through older notes too. Returns a Promise that
		// resolves once the fetched notes (if any) are in the DOM.
		fetchOlderNotes( $btn ) {
			const $thread   = $btn.closest( '.awts_notes_thread, .awts_customer_notes_thread' );
			const updateId  = parseInt( $thread.data( 'awts-update-id' ), 10 );
			const kind      = $thread.data( 'awts-thread-kind' ) === 'customer' ? 'customer' : 'internal';
			const itemSel   = 'customer' === kind ? '.awts_customer_note_item' : '.awts_notes_item';
			const oldestId  = parseInt( $thread.find( itemSel ).first().attr( 'data-awts-note-id' ) || '0', 10 );

			if ( ! updateId || ! oldestId || $btn.prop( 'disabled' ) ) {
				return Promise.resolve();
			}

			const route = 'customer' === kind
				? awtsData.customerNotesEndpointBase + updateId + '/customer-notes'
				: awtsData.notesEndpointBase + updateId + '/notes';

			const previousLabel = $btn.text();
			$btn.prop( 'disabled', true ).text( this.getString( 'loadingPreviousNotes' ) );

			return this.request( {
				url:    route + '?before_id=' + oldestId,
				method: 'GET',
			} )
				.then( response => {
					const notes   = ( response && response.notes ) ? response.notes : [];
					const hasMore = !! ( response && response.has_more );

					if ( ! notes.length ) {
						$btn.remove();
						return;
					}

					const buildHtml = 'customer' === kind
						? note => this.buildCustomerNoteHtml( note, false )
						: note => this.buildNoteHtml( note, false );

					// Capture scroll metrics before insert so the existing
					// reading position stays put. Inserting nodes at the top
					// of a scrolled thread would otherwise visually slide
					// everything down — confusing for a returning reader.
					const threadEl       = $thread.get( 0 );
					const prevScrollTop  = threadEl ? threadEl.scrollTop : 0;
					const prevScrollHt   = threadEl ? threadEl.scrollHeight : 0;

					notes.forEach( note => {
						const $node = $( buildHtml( note ) ).addClass( 'awts_notes_item--just_loaded' );
						$btn.after( $node );
					} );

					// Re-anchor scroll: scrollTop + (newHeight - oldHeight)
					// keeps the same DOM node under the same pixel position.
					if ( threadEl ) {
						threadEl.scrollTop = prevScrollTop + ( threadEl.scrollHeight - prevScrollHt );
					}

					if ( hasMore ) {
						$btn.prop( 'disabled', false ).text( previousLabel );
					} else {
						$btn.remove();
					}

					// Drop the highlight after 2s so the row blends back in.
					window.setTimeout( () => {
						$thread.find( '.awts_notes_item--just_loaded' )
							.removeClass( 'awts_notes_item--just_loaded' );
					}, 2000 );
				} )
				.catch( () => {
					$btn.prop( 'disabled', false ).text( previousLabel );
				} );
		},

		updateNotesBadge( $thread, count ) {
			this.setTabBadge( $thread.closest( '.awts_card' ), 'internal', count );
		},

		updateCustomerNotesBadge( $thread, count ) {
			this.setTabBadge( $thread.closest( '.awts_card' ), 'customer', count );
		},

		setTabBadge( $card, name, count ) {
			const $badge = $card.find( '[data-awts-tab-badge="' + name + '"]' );
			const safe   = count > 0 ? count : 0;

			$badge.attr( 'data-awts-count', String( safe ) );
			$badge.text( safe > 0 ? '(' + safe + ')' : '' );

			this.refreshCardTotalBadge( $card );
		},

		// Title badge is the sum of the per-tab badges so it stays useful as a
		// "this update has unread stuff" cue while skimming a long list of cards.
		refreshCardTotalBadge( $card ) {
			let total = 0;

			$card.find( '[data-awts-tab-badge]' ).each( ( _, el ) => {
				total += parseInt( $( el ).attr( 'data-awts-count' ) || '0', 10 );
			} );

			const $title = $card.find( '.awts_title' );

			$title.find( '.awts_notes_count_badge' ).remove();

			if ( total > 0 ) {
				$title.append( '<span class="awts_notes_count_badge">(' + total + ')</span>' );
			}
		},

		// Initials-disc avatar matching the server Avatar helper: a coloured
		// disc with the author's initials, and the Gravatar layered on top with
		// a transparent (d=blank) fallback — so a real photo covers the disc and
		// no photo reveals the letters. Never renders a blank avatar.
		avatarDiscHtml( rawName, extraClass, avatarUrl ) {
			const name  = String( rawName || '' ).trim();
			const parts = name ? name.split( /\s+/ ) : [];
			let initials;
			if ( parts.length >= 2 ) {
				initials = parts[ 0 ].charAt( 0 ) + parts[ parts.length - 1 ].charAt( 0 );
			} else if ( 1 === parts.length ) {
				initials = parts[ 0 ].substr( 0, 2 );
			} else {
				initials = '?';
			}
			let hue = 0;
			for ( let i = 0; i < name.length; i++ ) {
				hue = ( hue * 31 + name.charCodeAt( i ) ) % 360;
			}
			const initialsEsc = $( '<span>' ).text( initials.toUpperCase() ).html();

			// Force d=blank so users without a Gravatar get a transparent image
			// and the initials beneath show through, regardless of the site's
			// default-avatar setting.
			let imgHtml = '';
			const url = String( avatarUrl || '' );
			if ( url ) {
				const blankUrl = /[?&]d=/.test( url )
					? url.replace( /([?&]d=)[^&]*/, '$1blank' )
					: url + ( -1 === url.indexOf( '?' ) ? '?' : '&' ) + 'd=blank';
				imgHtml = '<img class="awts-avatar__img" src="' + this.escapeAttribute( blankUrl ) + '" alt="" loading="lazy" />';
			}

			return '<span class="awts-avatar ' + ( extraClass || '' ) + '" style="width:28px;height:28px;font-size:12px;background:oklch(62% 0.12 ' + hue + ');">'
				+ '<span class="awts-avatar__initials" aria-hidden="true">' + initialsEsc + '</span>'
				+ imgHtml + '</span>';
		},

		buildNoteHtml( note, isNew = false ) {
			const name = note.created_by_name ? $( '<span>' ).text( note.created_by_name ).html() : '';
			const date = note.created_at ? $( '<span>' ).text( note.created_at ).html() : '';
			const text = note.note ? this.highlightMentionsHtml( $( '<span>' ).text( this.convertEmoticons( note.note ) ).html() ) : '';
			const cls = 'awts_notes_item' + ( isNew ? ' awts_notes_item--new' : '' );
			const long = ( note.note || '' ).length > 150;
			const textCls = 'awts_notes_item_text' + ( long ? ' awts_truncated' : '' );
			const attachments = Array.isArray( note.attachments ) ? note.attachments : [];
			const mentions = Array.isArray( note.mentions ) ? note.mentions : [];
			const editedHtml = note.edited_at ? ' &middot; ' + this.getString( 'editedLabel' ) + ' ' + $( '<span>' ).text( note.edited_at ).html() : '';
			const avatarHtml = this.avatarDiscHtml( note.created_by_name, 'awts_notes_item_avatar', note.avatar_url );
			const actions = [];

			if ( note.can_edit ) {
				actions.push( this.buildEditIconButtonHtml( 'awts_edit_note', parseInt( note.id, 10 ) ) );
			}

			if ( note.can_delete ) {
				actions.push( '<button type="button" class="awts_delete_note" data-awts-note-id="' + parseInt( note.id, 10 ) + '">' + this.getString( 'deleteNoteAction' ) + '</button>' );
			}

			return '<div class="' + cls + '" data-awts-note-id="' + parseInt( note.id, 10 ) + '" data-awts-note-text="' + this.escapeAttribute( note.note || '' ) + '">'
				+ avatarHtml
				+ '<div class="awts_notes_item_bubble">'
					+ ( isNew ? '<span class="awts_notes_badge_new">' + this.getString( 'newLabel' ) + '</span>' : '' )
					+ '<p class="awts_notes_item_meta"><strong>' + name + '</strong> &middot; ' + date + editedHtml + '</p>'
					+ '<p class="' + textCls + '">' + text + '</p>'
					+ ( long ? '<button type="button" class="awts_show_more">' + this.getString( 'showMore' ) + '</button>' : '' )
					+ this.buildMentionListHtml( mentions )
					+ this.buildAttachmentListHtml( attachments, true )
					+ ( actions.length ? '<div class="awts_customer_note_actions">' + actions.join( '' ) + '</div>' : '' )
				+ '</div>'
				+ '</div>';
		},

		// Wrap any "@FullName" that resolves to a known team member in a styled
		// span. Operates on already-escaped HTML, so input is safe to scan with a
		// regex; the inserted name is also escaped to keep it that way.
		highlightMentionsHtml( escapedText ) {
			const team = this.mentionTeam || [];
			if ( ! escapedText || ! team.length ) {
				return escapedText;
			}
			const sorted = team.slice().sort( ( a, b ) => ( b.name || '' ).length - ( a.name || '' ).length );
			let html = escapedText;
			for ( const member of sorted ) {
				const name = member.name || '';
				if ( ! name ) {
					continue;
				}
				const escapedName = $( '<span>' ).text( name ).html();
				const reSafe = escapedName.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
				const re = new RegExp( '(^|\\s)@' + reSafe + '(?=\\s|$|[.,!?;:])', 'gi' );
				html = html.replace( re, ( _match, prefix ) => prefix + '<span class="awts_mention_inline">@' + escapedName + '</span>' );
			}
			return html;
		},

		buildMentionListHtml( mentions ) {
			if ( ! Array.isArray( mentions ) || ! mentions.length ) {
				return '';
			}

			const chips = mentions.map( m => {
				const safeName = $( '<span>' ).text( '@' + ( m.name || '#' + m.id ) ).html();
				return '<span class="awts_mention_token">' + safeName + '</span>';
			} ).join( '' );

			return '<p class="awts_notes_item_mentions">' + chips + '</p>';
		},

		markNotesAsRead( event ) {
			event.preventDefault();

			const $btn     = $( event.currentTarget );
			const $wrap    = $btn.closest( '.awts_notes_wrap' );
			const $thread  = $wrap.find( '.awts_notes_thread' );
			const updateId = parseInt( $thread.data( 'awts-update-id' ), 10 );

			if ( ! updateId ) {
				return;
			}

			const maxId = Math.max(
				0,
				...$thread.find( '.awts_notes_item' ).map( ( _, el ) => parseInt( $( el ).data( 'awts-note-id' ), 10 ) ).get()
			);

			this.setLastReadNoteId( updateId, maxId );

			$thread.find( '.awts_notes_item--new' ).removeClass( 'awts_notes_item--new' );
			$thread.find( '.awts_notes_badge_new' ).remove();
			$btn.remove();
			this.updateNotesBadge( $thread, 0 );

			$( document ).trigger( 'awts:notes-read', { updateId } );
		},

		markSingleNoteAsRead( event ) {
			event.preventDefault();

			const $btn     = $( event.currentTarget );
			const $note    = $btn.closest( '.awts_notes_item' );
			const $thread  = $note.closest( '.awts_notes_thread' );
			const noteId   = parseInt( $btn.data( 'awts-note-id' ), 10 );
			const updateId = parseInt( $thread.data( 'awts-update-id' ), 10 );

			if ( ! updateId || ! noteId ) {
				return;
			}

			this.setLastReadNoteId( updateId, noteId );

			// Clear new state on this note and all earlier ones.
			$thread.find( '.awts_notes_item' ).each( ( _, el ) => {
				const $item = $( el );
				if ( parseInt( $item.data( 'awts-note-id' ), 10 ) <= noteId ) {
					$item.removeClass( 'awts_notes_item--new' );
					$item.find( '.awts_notes_badge_new, .awts_mark_note_read' ).remove();
				}
			} );

			this.updateNotesBadge( $thread, $thread.find( '.awts_notes_item--new' ).length );

			// Signal admin bar to dismiss this update.
			$( document ).trigger( 'awts:notes-read', { updateId } );
		},

		markCustomerNotesAsRead( event ) {
			event.preventDefault();

			const $btn     = $( event.currentTarget );
			const $wrap    = $btn.closest( '.awts_customer_notes_wrap' );
			const $thread  = $wrap.find( '.awts_customer_notes_thread' );
			const updateId = parseInt( $thread.data( 'awts-update-id' ), 10 );

			if ( ! updateId ) {
				return;
			}

			// Advance the read marker to the max id across ALL customer notes
			// (incoming + outgoing). Outgoing ids never increase the unread
			// count anyway, but bumping past them avoids re-checking on reload.
			const maxId = Math.max(
				0,
				...$thread.find( '.awts_customer_note_item' ).map( ( _, el ) => parseInt( $( el ).data( 'awts-note-id' ), 10 ) ).get()
			);

			this.setLastReadCustomerNoteId( updateId, maxId );

			$thread.find( '.awts_customer_note_item--new' ).removeClass( 'awts_customer_note_item--new' );
			$thread.find( '.awts_notes_badge_new' ).remove();
			$btn.remove();
			this.updateCustomerNotesBadge( $thread, 0 );

			$( document ).trigger( 'awts:notes-read', { updateId } );
		},

		toggleShowMore( event ) {
			event.preventDefault();
			const $btn = $( event.currentTarget );
			const $text = $btn.prev( '.awts_truncated, .awts_notes_item_text, .awts_customer_note_text' );

			if ( $text.hasClass( 'awts_truncated' ) ) {
				$text.removeClass( 'awts_truncated' );
				$btn.text( this.getString( 'showLess' ) );
			} else {
				$text.addClass( 'awts_truncated' );
				$btn.text( this.getString( 'showMore' ) );
			}
		},


		startNoteComposerEdit( $wrap, $input, $submit, noteId, noteText, submitClass ) {
			if ( ! $wrap.length || ! $input.length || ! $submit.length || ! noteId ) {
				return;
			}

			this.clearPendingFiles( $wrap );

			const defaultLabel = $submit.data( 'awts-default-label' ) || $submit.text();
			$submit.data( 'awts-default-label', defaultLabel );
			$wrap.attr( 'data-awts-editing-note-id', String( noteId ) );
			$input.val( noteText ).trigger( 'focus' );
			$submit.text( this.getString( 'saveNoteAction' ) );

			const $actions = $submit.closest( '.awts_notes_input_actions, .awts_customer_notes_input_actions' );
			$actions.find( '.awts_note_edit_cancel' ).remove();

			const $cancel = $( '<button type="button" class="awts_secondary_button awts_note_edit_cancel"></button>' ).text( this.getString( 'cancelNoteAction' ) );
			$cancel.on( 'click', () => this.resetNoteComposerEdit( $wrap, $submit, submitClass ) );
			$submit.after( $cancel );
		},

		resetNoteComposerEdit( $wrap, $submit, submitClass ) {
			const defaultLabel = $submit.data( 'awts-default-label' );
			const $input = $wrap.find( '.awts_notes_input, .awts_customer_notes_input' ).first();

			$wrap.removeAttr( 'data-awts-editing-note-id' );
			$input.val( '' );
			$submit.prop( 'disabled', false ).text( defaultLabel || this.getString( submitClass === 'awts_customer_notes_submit' ? 'addAction' : 'saveNoteAction' ) );
			$submit.closest( '.awts_notes_input_actions, .awts_customer_notes_input_actions' ).find( '.awts_note_edit_cancel' ).remove();
		},

		addNote( event ) {
			event.preventDefault();

			const $button  = $( event.currentTarget );
			const $wrap    = $button.closest( '.awts_notes_wrap' );
			const $thread  = $wrap.find( '.awts_notes_thread' );
			const $input   = $wrap.find( '.awts_notes_input' );
			const updateId = parseInt( $thread.data( 'awts-update-id' ), 10 );
			const note     = $input.val().trim();
			const pending  = this.getPendingFiles( $wrap );
			const editingNoteId = parseInt( $wrap.attr( 'data-awts-editing-note-id' ) || '0', 10 );

			if ( editingNoteId && ! note ) {
				window.alert( this.getString( 'noteRequired' ) );
				return;
			}

			if ( ! updateId || ( ! note && ! pending.length ) || $button.prop( 'disabled' ) ) {
				return;
			}

			$button.prop( 'disabled', true );

			const mentionedUserIds = this.extractMentionedUserIdsFromText( note );

			if ( editingNoteId ) {
				this.request( {
					url:    awtsData.notesEndpointBase + updateId + '/notes/' + editingNoteId,
					method: 'POST',
					data:   { note, mentioned_user_ids: mentionedUserIds },
				} )
					.then( () => {
						this.resetNoteComposerEdit( $wrap, $button, 'awts_notes_submit' );
						$thread.data( 'awts-loaded', false );
						this.loadNotesForThread( $thread, updateId );
					} )
					.catch( ( { xhr } ) => {
						const message = this.getRequestErrorMessage( xhr, 'saveFailed' );
						window.alert( message );
						$button.prop( 'disabled', false );
					} );
				return;
			}

			this.request( {
				url:    awtsData.notesEndpointBase + updateId + '/notes',
				method: 'POST',
				data:   { note, mentioned_user_ids: mentionedUserIds },
			} )
				.then( response => {
					const noteId = parseInt( response.note.id, 10 );

					return this.uploadPendingFiles( $wrap, updateId, noteId, 'internal' ).then( uploaded => {
						response.note.attachments = uploaded;

						if ( $thread.data( 'awts-windowed' ) ) {
							// Viewing an old window — the new note is the newest,
							// so jump back to the live latest batch (which includes
							// it) rather than appending into the window.
							this.jumpToLatest( $thread, updateId, 'internal' );
						} else {
							if ( ! $thread.data( 'awts-loaded' ) ) {
								$thread.empty();
							} else {
								$thread.find( '.awts_notes_empty' ).remove();
							}
							$thread.append( this.buildNoteHtml( response.note, false ) ).data( 'awts-loaded', true );
							this.scrollThreadToBottom( $thread );
							this.pruneShowMoreButtons( $thread );
						}
						this.setLastReadNoteId( updateId, noteId );
						$input.val( '' );
						this.clearPendingFiles( $wrap );
						$button.prop( 'disabled', false );
					} );
				} )
				.catch( ( { xhr } ) => {
					const message = this.getRequestErrorMessage( xhr, 'saveFailed' );
					window.alert( message );
					$button.prop( 'disabled', false );
				} );
		},

		handleNotesUpArrow( event ) {
			if ( event.key !== 'ArrowUp' ) return;
			// Master gate — when the admin has disabled note editing in
			// Restricted settings, the Up-arrow autofill is dead too. Without
			// this, a tab opened before the toggle flipped would still trigger
			// the edit flow (the bubble's button is in cached DOM) and the
			// server would 403 on save.
			if ( ! awtsData.allowNoteEdit ) return;

			const $input = $( event.currentTarget );
			if ( $input.val().trim() !== '' ) return;

			const $wrap = $input.closest( '.awts_notes_wrap' );
			if ( $wrap.attr( 'data-awts-editing-note-id' ) ) return;

			const $btn = $wrap.find( '.awts_edit_note' ).last();
			if ( ! $btn.length ) return;

			event.preventDefault();
			$btn.trigger( 'click' );
		},

		handleCustomerNotesUpArrow( event ) {
			if ( event.key !== 'ArrowUp' ) return;
			if ( ! awtsData.allowNoteEdit ) return;

			const $input = $( event.currentTarget );
			if ( $input.val().trim() !== '' ) return;

			const $wrap = $input.closest( '.awts_customer_notes_wrap' );
			if ( $wrap.attr( 'data-awts-editing-note-id' ) ) return;

			const $btn = $wrap.find( '.awts_edit_customer_note' ).last();
			if ( ! $btn.length ) return;

			event.preventDefault();
			$btn.trigger( 'click' );
		},

		editNote( event ) {
			event.preventDefault();

			// Stale-DOM guard: a bubble rendered before the master toggle
			// was flipped off may still expose the edit button. The server
			// would 403 anyway — short-circuit here so the user gets a quiet
			// no-op instead of an error toast.
			if ( ! awtsData.allowNoteEdit ) return;

			const $button  = $( event.currentTarget );
			const $item    = $button.closest( '.awts_notes_item' );
			const $thread  = $button.closest( '.awts_notes_thread' );
			const updateId = parseInt( $thread.data( 'awts-update-id' ), 10 );
			const noteId   = parseInt( $item.data( 'awts-note-id' ), 10 );

			if ( ! updateId || ! noteId || $button.prop( 'disabled' ) ) {
				return;
			}

			const current = String( $item.attr( 'data-awts-note-text' ) || '' );
			const $wrap = $thread.closest( '.awts_notes_wrap' );
			const $input = $wrap.find( '.awts_notes_input' );
			const $submit = $wrap.find( '.awts_notes_submit' );

			this.startNoteComposerEdit( $wrap, $input, $submit, noteId, current, 'awts_notes_submit' );
		},

		deleteNote( event ) {
			event.preventDefault();

			if ( ! awtsData.allowNoteDelete ) return;

			if ( ! window.confirm( this.getString( 'deleteNoteConfirm' ) ) ) {
				return;
			}

			const $button  = $( event.currentTarget );
			const $item    = $button.closest( '.awts_notes_item' );
			const $thread  = $button.closest( '.awts_notes_thread' );
			const updateId = parseInt( $thread.data( 'awts-update-id' ), 10 );
			const noteId   = parseInt( $item.data( 'awts-note-id' ), 10 );

			if ( ! updateId || ! noteId || $button.prop( 'disabled' ) ) {
				return;
			}

			this.withDisabled( $button, this.request( {
				url:    awtsData.notesEndpointBase + updateId + '/notes/' + noteId,
				method: 'DELETE',
			} ) )
				.then( () => {
					$thread.data( 'awts-loaded', false );
					this.loadNotesForThread( $thread, updateId );
				} )
				.catch( ( { xhr } ) => {
					window.alert( this.getRequestErrorMessage( xhr, 'saveFailed' ) );
				} );
		},

		// -------------------------------------------------------------------------
		// Customer Notes
		// -------------------------------------------------------------------------

		autoLoadCustomerNotes() {
			this.$panel.find( '.awts_customer_notes_thread' ).each( ( _, el ) => {
				const $thread  = $( el );
				const updateId = parseInt( $thread.data( 'awts-update-id' ), 10 );

				if ( updateId ) {
					this.loadCustomerNotesForThread( $thread, updateId );
				}
			} );
		},

		loadCustomerNotesForCard( $card ) {
			const $thread  = $card.find( '.awts_customer_notes_thread' );
			const updateId = parseInt( $thread.data( 'awts-update-id' ), 10 );

			if ( updateId ) {
				this.loadCustomerNotesForThread( $thread, updateId );
			}
		},

		loadCustomerNotesForThread( $thread, updateId ) {
			if ( $thread.data( 'awts-loaded' ) ) {
				return;
			}

			this.request( {
				url:    awtsData.customerNotesEndpointBase + updateId + '/customer-notes',
				method: 'GET',
			} )
				.then( response => this.renderCustomerNotes( response, $thread ) )
				.catch( () => {
					$thread.html( '<p class="awts_customer_notes_error">' + this.getString( 'saveFailed' ) + '</p>' );
				} );
		},

		viewCustomerNotes( event ) {
			this.viewNotes( event );
		},

		renderCustomerNotes( response, $thread ) {
			const notes        = ( response && response.notes ) ? response.notes : [];
			const hasMore      = !! ( response && response.has_more );
			const updateId     = parseInt( $thread.data( 'awts-update-id' ), 10 );
			const emailEnabled = response && response.email_notifications_enabled !== undefined
				? !! response.email_notifications_enabled
				: true;
			const orderId      = parseInt( ( response && response.order_id ) || 0, 10 );

			$thread.data( 'awts-email-pref', emailEnabled );
			$thread.data( 'awts-order-id', orderId );
			$thread.data( 'awts-thread-kind', 'customer' );

			// A fresh render is always the live view — clear any windowed state.
			$thread.data( 'awts-windowed', false );
			this.removeJumpToLatest( $thread );

			if ( ! notes.length ) {
				$thread.html( this.buildEmailPrefHtml( emailEnabled ) + '<p class="awts_customer_notes_empty">' + this.getString( 'customerNotesEmpty' ) + '</p>' );
				$thread.data( 'awts-loaded', true );
				this.updateCustomerNotesBadge( $thread, 0 );
				return;
			}

			const lastReadId = this.getLastReadCustomerNoteId( updateId );
			let newCount     = 0;
			let html         = this.buildEmailPrefHtml( emailEnabled );

			if ( hasMore ) {
				html += this.buildLoadPreviousButton();
			}

			notes.forEach( note => {
				const isFromCustomer = !! note.from_customer;
				const isUnread       = isFromCustomer && parseInt( note.id, 10 ) > lastReadId;
				if ( isUnread ) newCount++;
				html += this.buildCustomerNoteHtml( note, isUnread );
			} );

			$thread.html( html ).data( 'awts-loaded', true );

			// Anchor at the bottom — latest message is at the foot of the
			// list, that's what the staff member needs to see first.
			this.scrollThreadToBottom( $thread );
			this.pruneShowMoreButtons( $thread );

			// Advance the high-water mark so the heartbeat only asks for newer notes.
			const maxId = notes.reduce( ( m, n ) => Math.max( m, parseInt( n.id, 10 ) ), 0 );
			if ( maxId > 0 ) this.noteHighWater[ updateId ] = maxId;

			const $wrap = $thread.closest( '.awts_customer_notes_wrap' );
			$wrap.find( '.awts_mark_customer_notes_read' ).remove();

			if ( newCount > 0 ) {
				$wrap.prepend( $( '<button type="button" class="awts_mark_customer_notes_read">' + this.getString( 'markAsReadLabel' ) + '</button>' ) );
			}

			this.updateCustomerNotesBadge( $thread, newCount );
		},

		saveStaffEmailPref( event ) {
			const checkbox = event.currentTarget;
			const updateId = parseInt( $( checkbox ).data( 'awts-update-id' ), 10 );
			const muted    = ! checkbox.checked;

			if ( ! updateId ) {
				return;
			}

			this.request( {
				url:    awtsData.customerNotesEndpointBase + updateId + '/staff-email-preference',
				method: 'POST',
				data:   { muted: muted ? 1 : 0 },
			} ).catch( () => {
				// Revert on failure.
				checkbox.checked = ! checkbox.checked;
			} );
		},

		buildEmailPrefHtml( enabled ) {
			const label  = this.getString( 'customerEmailPrefLabel' );
			const status = enabled
				? this.getString( 'customerEmailPrefOn' )
				: this.getString( 'customerEmailPrefOff' );

			return '<div class="awts_customer_email_pref" data-awts-email-pref="' + ( enabled ? '1' : '0' ) + '">'
				+ '<span class="awts_customer_email_pref__label">' + label + ': </span>'
				+ '<button type="button" class="awts_customer_email_pref__toggle awts_customer_email_pref__toggle--' + ( enabled ? 'on' : 'off' ) + '">'
				+ status
				+ '</button>'
				+ '</div>';
		},

		toggleCustomerEmailPref( event ) {
			event.preventDefault();

			const $btn    = $( event.currentTarget );
			const $wrap   = $btn.closest( '.awts_customer_email_pref' );
			const $thread = $btn.closest( '.awts_customer_notes_thread' );
			const current = $wrap.data( 'awts-email-pref' ) === '1' || $wrap.data( 'awts-email-pref' ) === 1;
			const next    = ! current;
			const orderId = parseInt( $thread.data( 'awts-order-id' ) || '0', 10 );

			if ( ! orderId || $btn.prop( 'disabled' ) ) {
				return;
			}

			if ( next ) {
				if ( ! window.confirm( this.getString( 'customerEmailPrefOverrideConfirm' ) ) ) {
					return;
				}
			}

			this.withDisabled( $btn, this.request( {
				url:    awtsData.emailPrefUrl,
				method: 'POST',
				data:   { order_id: orderId, enabled: next ? 1 : 0 },
			} ) )
				.then( () => {
					$wrap.data( 'awts-email-pref', next ? 1 : 0 );
					const status = next
						? this.getString( 'customerEmailPrefOn' )
						: this.getString( 'customerEmailPrefOff' );
					$btn.text( status )
						.removeClass( 'awts_customer_email_pref__toggle--on awts_customer_email_pref__toggle--off' )
						.addClass( 'awts_customer_email_pref__toggle--' + ( next ? 'on' : 'off' ) );
				} )
				.catch( () => {} );
		},

		buildSystemNoteHtml( note ) {
			const noteId = parseInt( note.id, 10 );
			const color  = String( note.status || '#94a3b8' );
			const text   = note.note ? $( '<span>' ).text( note.note ).html() : '';
			const date   = note.created_at ? $( '<span>' ).text( note.created_at ).html() : '';
			const name   = note.created_by_name ? $( '<span>' ).text( note.created_by_name ).html() : '';

			const meta = ( name || date )
				? '<span class="awts_system_note_meta">'
					+ ( name ? '<strong>' + name + '</strong>' : '' )
					+ ( name && date ? ' &middot; ' : '' )
					+ date
				+ '</span>'
				: '';

			return '<div class="awts_customer_note_item awts_customer_note_item--system" data-awts-note-id="' + noteId + '">'
				+ '<span class="awts_system_note_dot" style="background:' + this.escapeAttribute( color ) + ';"></span>'
				+ '<span class="awts_system_note_text">' + text + '</span>'
				+ meta
				+ '</div>';
		},

		buildCustomerNoteHtml( note, isUnread = false ) {
			// Status-change rows are rendered as compact centered markers, not
			// full bubbles. The presenter sets `is_system = true` (and `status`
			// to the new color hex) on these rows; everything else falls
			// through to the regular message layout below.
			if ( note.is_system ) {
				return this.buildSystemNoteHtml( note );
			}

			// Customer-authored notes show "Name (Customer)" with an initials
			// disc; staff notes show the staff name. Falls back to "Customer"
			// when a guest left no name on the order.
			const isCust      = !! note.from_customer;
			const rawAuthor   = note.created_by_name
				? note.created_by_name
				: ( isCust ? ( this.getString( 'customerFallbackName' ) || 'Customer' ) : '' );
			const displayName = ( isCust && note.created_by_name )
				? note.created_by_name + ' (' + ( this.getString( 'customerTag' ) || 'Customer' ) + ')'
				: rawAuthor;
			const name        = displayName ? $( '<span>' ).text( displayName ).html() : '';
			const date        = note.created_at ? $( '<span>' ).text( note.created_at ).html() : '';
			const text        = note.note ? $( '<span>' ).text( this.convertEmoticons( note.note ) ).html() : '';
			const noteId      = parseInt( note.id, 10 );
			const isNotified  = !! note.notified_at;
			const isQueued    = ! isNotified && !! note.queued_at;
			const isPending   = isNotified || isQueued;
			const long        = ( note.note || '' ).length > 150;
			const textCls     = 'awts_customer_note_text' + ( long ? ' awts_truncated' : '' );
			const attachments = Array.isArray( note.attachments ) ? note.attachments : [];
			const canRemoveAttachments = ! isPending;
			const fromCustomer = !! note.from_customer;
			const avatarHtml   = this.avatarDiscHtml( rawAuthor, 'awts_customer_note_avatar', note.avatar_url );
			const itemCls      = 'awts_customer_note_item'
				+ ( fromCustomer ? ' awts_customer_note_item--from_customer' : ' awts_customer_note_item--from_staff' )
				+ ( isUnread ? ' awts_customer_note_item--new' : '' );
			const editedHtml = note.edited_at
				? ' &middot; <button type="button" class="awts_view_customer_note_history" data-awts-note-id="' + noteId + '" aria-expanded="false">'
					+ this.getString( 'editedLabel' ) + ' ' + $( '<span>' ).text( note.edited_at ).html()
					+ ' &middot; ' + ( $( '<span>' ).text( this.getString( 'noteHistoryHeading' ) || 'View history' ).html() )
					+ '</button>'
				: '';

			let notifiedHtml;
			if ( isNotified ) {
				notifiedHtml = '<span class="awts_customer_note_sent">' + this.getString( 'sentToCustomerLabel' ) + ' &middot; ' + $( '<span>' ).text( note.notified_at ).html() + '</span>';
			} else if ( isQueued ) {
				notifiedHtml = '<span class="awts_customer_note_sent">' + this.getString( 'queuedToCustomerLabel' ) + ' &middot; ' + $( '<span>' ).text( note.queued_at ).html() + '</span>';
			} else {
				notifiedHtml = '<button type="button" class="awts_notify_customer_note" data-awts-note-id="' + noteId + '">' + this.getString( 'sendToCustomerAction' ) + '</button>';
			}

			const editHtml = note.can_edit
				? this.buildEditIconButtonHtml( 'awts_edit_customer_note', noteId )
				: '';

			// Hide "Send to customer" on notes authored by the customer —
			// there's nothing to notify them about, it was their own message.
			const actionsHtml = fromCustomer
				? ( editHtml ? '<div class="awts_customer_note_actions">' + editHtml + '</div>' : '' )
				: '<div class="awts_customer_note_actions">' + notifiedHtml + editHtml + '</div>';

			const badgeHtml = fromCustomer
				? '<span class="awts_customer_note_badge">' + this.getString( 'fromCustomerBadge' ) + '</span>'
				: '';

			const unreadBadgeHtml = isUnread
				? '<span class="awts_notes_badge_new">' + this.getString( 'newLabel' ) + '</span>'
				: '';

			return '<div class="' + itemCls + '" data-awts-note-id="' + noteId + '" data-awts-note-text="' + this.escapeAttribute( note.note || '' ) + '">'
				+ avatarHtml
				+ '<div class="awts_customer_note_bubble">'
					+ unreadBadgeHtml
					+ '<p class="awts_customer_note_meta">' + badgeHtml + '<strong>' + name + '</strong> &middot; ' + date + editedHtml + '</p>'
					+ '<p class="' + textCls + '">' + text + '</p>'
					+ ( long ? '<button type="button" class="awts_show_more">' + this.getString( 'showMore' ) + '</button>' : '' )
					+ this.buildAttachmentListHtml( attachments, canRemoveAttachments )
					+ actionsHtml
				+ '</div>'
				+ '</div>';
		},

		buildEditIconButtonHtml( className, noteId ) {
			const label = $( '<span>' ).text( this.getString( 'editNoteAction' ) || 'Edit' ).html();

			return '<button type="button" class="' + className + ' awts_note_icon_action" data-awts-note-id="' + noteId + '" aria-label="' + label + '" title="' + label + '">'
				+ '<svg viewBox="0 0 20 20" width="14" height="14" aria-hidden="true" focusable="false"><path d="M13.8 2.9a2.1 2.1 0 0 1 3 3L7.3 15.4 3 17l1.6-4.3 9.2-9.8Zm1 1-8.9 9.5-.7 1.8 1.8-.7 8.9-9.5a.7.7 0 0 0-1-1Z" fill="currentColor"/></svg>'
				+ '</button>';
		},

		notifyCustomerNote( event ) {
			event.preventDefault();

			const $button  = $( event.currentTarget );
			const noteId   = parseInt( $button.data( 'awts-note-id' ), 10 );
			const $item    = $button.closest( '.awts_customer_note_item' );
			const $thread  = $button.closest( '.awts_customer_notes_thread' );
			const updateId = parseInt( $thread.data( 'awts-update-id' ), 10 );

			if ( ! noteId || ! updateId || $button.prop( 'disabled' ) ) {
				return;
			}

			$button.prop( 'disabled', true );

			const doNotify = ( force = false ) => {
				this.request( {
					url:    awtsData.customerNotesEndpointBase + updateId + '/customer-notes/' + noteId + '/notify',
					method: 'POST',
					data:   force ? { force: 1 } : {},
				} )
					.then( response => {
						if ( response && response.opted_out ) {
							$button.prop( 'disabled', false );
							if ( window.confirm( this.getString( 'customerOptedOutConfirm' ) ) ) {
								$button.prop( 'disabled', true );
								doNotify( true );
							}
							return;
						}

						const queuedAt = response && response.queuedAt ? response.queuedAt : '';
						const $actions = $item.find( '.awts_customer_note_actions' );
						$actions.html( '<span class="awts_customer_note_sent">' + this.getString( 'sentToCustomerLabel' ) + ( queuedAt ? ' &middot; ' + $( '<span>' ).text( queuedAt ).html() : '' ) + '</span>' );
					} )
					.catch( ( { xhr } ) => {
						$button.prop( 'disabled', false );
						const message = this.getRequestErrorMessage( xhr, 'saveFailed' );
						window.alert( message );
					} );
			};

			doNotify();
		},

		viewCustomerNoteHistory( event ) {
			event.preventDefault();

			const $button  = $( event.currentTarget );
			const $item    = $button.closest( '.awts_customer_note_item' );
			const $thread  = $button.closest( '.awts_customer_notes_thread' );
			const updateId = parseInt( $thread.data( 'awts-update-id' ), 10 );
			const noteId   = parseInt( $button.data( 'awts-note-id' ), 10 );

			if ( ! updateId || ! noteId || $button.prop( 'disabled' ) ) {
				return;
			}

			const $existing = $item.find( '.awts_customer_note_history' );

			if ( $existing.length ) {
				$existing.remove();
				$button.attr( 'aria-expanded', 'false' );
				return;
			}

			$button.prop( 'disabled', true );
			$button.attr( 'aria-busy', 'true' );

			this.request( {
				url:    awtsData.customerNotesEndpointBase + updateId + '/customer-notes/' + noteId + '/history',
				method: 'GET',
			} )
				.then( response => {
					$button.prop( 'disabled', false ).removeAttr( 'aria-busy' );
					const revisions = ( response && Array.isArray( response.revisions ) ) ? response.revisions : [];
					$item.append( this.buildCustomerNoteHistoryHtml( revisions ) );
					$button.attr( 'aria-expanded', 'true' );
				} )
				.catch( ( { xhr } ) => {
					$button.prop( 'disabled', false ).removeAttr( 'aria-busy' );
					const message = this.getRequestErrorMessage( xhr, 'saveFailed' );
					window.alert( message );
				} );
		},

		buildCustomerNoteHistoryHtml( revisions ) {
			if ( ! revisions.length ) {
				return '<div class="awts_customer_note_history">'
					+ $( '<span>' ).text( this.getString( 'noteHistoryEmpty' ) ).html()
					+ '</div>';
			}

			const headingText = $( '<span>' ).text( this.getString( 'noteHistoryHeading' ) ).html();
			const items = revisions.map( rev => {
				const who  = rev.edited_by_name ? $( '<span>' ).text( rev.edited_by_name ).html() : '';
				const when = rev.edited_at ? $( '<span>' ).text( rev.edited_at ).html() : '';
				const text = $( '<span>' ).text( rev.prior_note || '' ).html();

				return '<li class="awts_customer_note_history_item">'
					+ '<p class="awts_customer_note_history_meta">' + ( who ? who + ' &middot; ' : '' ) + when + '</p>'
					+ '<blockquote class="awts_customer_note_history_body">' + text + '</blockquote>'
					+ '</li>';
			} ).join( '' );

			return '<div class="awts_customer_note_history">'
				+ '<p class="awts_customer_note_history_heading">' + headingText + '</p>'
				+ '<ol class="awts_customer_note_history_list">' + items + '</ol>'
				+ '</div>';
		},

		editCustomerNote( event ) {
			event.preventDefault();

			if ( ! awtsData.allowNoteEdit ) return;

			const $button  = $( event.currentTarget );
			const $item    = $button.closest( '.awts_customer_note_item' );
			const $thread  = $button.closest( '.awts_customer_notes_thread' );
			const updateId = parseInt( $thread.data( 'awts-update-id' ), 10 );
			const noteId   = parseInt( $item.data( 'awts-note-id' ), 10 );

			if ( ! updateId || ! noteId || $button.prop( 'disabled' ) ) {
				return;
			}

			const current = String( $item.attr( 'data-awts-note-text' ) || '' );
			const $wrap = $thread.closest( '.awts_customer_notes_wrap' );
			const $input = $wrap.find( '.awts_customer_notes_input' );
			const $submit = $wrap.find( '.awts_customer_notes_submit' );

			this.startNoteComposerEdit( $wrap, $input, $submit, noteId, current, 'awts_customer_notes_submit' );
		},

		addCustomerNote( event ) {
			event.preventDefault();

			const $button  = $( event.currentTarget );
			const $wrap    = $button.closest( '.awts_customer_notes_wrap' );
			const $thread  = $wrap.find( '.awts_customer_notes_thread' );
			const $input   = $wrap.find( '.awts_customer_notes_input' );
			const updateId = parseInt( $thread.data( 'awts-update-id' ), 10 );
			const note     = $input.val().trim();
			const pending  = this.getPendingFiles( $wrap );
			const editingNoteId = parseInt( $wrap.attr( 'data-awts-editing-note-id' ) || '0', 10 );

			if ( editingNoteId && ! note ) {
				window.alert( this.getString( 'noteRequired' ) );
				return;
			}

			if ( ! updateId || ( ! note && ! pending.length ) || $button.prop( 'disabled' ) ) {
				return;
			}

			$button.prop( 'disabled', true );

			if ( editingNoteId ) {
				this.request( {
					url:    awtsData.customerNotesEndpointBase + updateId + '/customer-notes/' + editingNoteId,
					method: 'POST',
					data:   { note },
				} )
					.then( () => {
						this.resetNoteComposerEdit( $wrap, $button, 'awts_customer_notes_submit' );
						$thread.data( 'awts-loaded', false );
						this.loadCustomerNotesForThread( $thread, updateId );
					} )
					.catch( ( { xhr } ) => {
						const message = this.getRequestErrorMessage( xhr, 'saveFailed' );
						window.alert( message );
						$button.prop( 'disabled', false );
					} );
				return;
			}

			this.request( {
				url:    awtsData.customerNotesEndpointBase + updateId + '/customer-notes',
				method: 'POST',
				data:   { note },
			} )
				.then( response => {
					const noteId = parseInt( response.note.id, 10 );

					return this.uploadPendingFiles( $wrap, updateId, noteId, 'customer' ).then( uploaded => {
						response.note.attachments = uploaded;

						if ( $thread.data( 'awts-windowed' ) ) {
							// Viewing an old window — the new note is the newest,
							// so jump back to the live latest batch (which includes
							// it) rather than appending into the window.
							this.jumpToLatest( $thread, updateId, 'customer' );
						} else {
							if ( ! $thread.data( 'awts-loaded' ) ) {
								$thread.empty();
							} else {
								$thread.find( '.awts_customer_notes_empty' ).remove();
							}
							$thread.append( this.buildCustomerNoteHtml( response.note ) ).data( 'awts-loaded', true );
							// Scroll the thread to show the message we just sent —
							// without this the new bubble lands below the scroll
							// viewport on long threads and the user thinks the
							// send silently failed.
							this.scrollThreadToBottom( $thread );
							this.pruneShowMoreButtons( $thread );
						}
						$input.val( '' );
						this.clearPendingFiles( $wrap );
						$button.prop( 'disabled', false );

						// First customer note auto-flips customer_visible on
						// the parent update. Keep the card's data attribute
						// in sync so the inline Delete / Mark-solved confirms
						// pick up the new state without a page refresh — and
						// drop the "Hidden from customer" notice that was
						// rendered server-side based on the old state.
						if ( response.customer_visible_changed ) {
							const $card = $wrap.closest( '.awts_card' );
							$card.attr( 'data-awts-customer-visible', '1' );
							$card.find( '.awts_customer_notes_warning--hidden' ).remove();
						}
					} );
				} )
				.catch( ( { xhr } ) => {
					const message = this.getRequestErrorMessage( xhr, 'saveFailed' );
					window.alert( message );
					$button.prop( 'disabled', false );
				} );
		},

		// -------------------------------------------------------------------------
		// Attachments
		// -------------------------------------------------------------------------

		getPendingFiles( $wrap ) {
			return $wrap.data( 'awts-pending-files' ) || [];
		},

		setPendingFiles( $wrap, files ) {
			$wrap.data( 'awts-pending-files', files );
		},

		clearPendingFiles( $wrap ) {
			$wrap.data( 'awts-pending-files', [] );
			const $container = $wrap.find( '.awts_pending_attachments' ).first();
			$container.empty().attr( 'hidden', true );
			$wrap.find( '.awts_attach_input' ).first().val( '' );
		},

		formatBytes( bytes ) {
			if ( bytes < 1024 ) {
				return bytes + ' B';
			}
			if ( bytes < 1048576 ) {
				return ( bytes / 1024 ).toFixed( 1 ) + ' KB';
			}
			return ( bytes / 1048576 ).toFixed( 1 ) + ' MB';
		},

		validateFile( file ) {
			const allowed = Array.isArray( awtsData.attachmentAllowedMime ) ? awtsData.attachmentAllowedMime : [];
			const max = parseInt( awtsData.attachmentMaxBytes, 10 ) || 10485760;

			if ( file.size > max ) {
				return this.sprintf( this.getString( 'attachmentTooLarge' ), [ this.formatBytes( max ) ] );
			}

			if ( allowed.length && file.type && allowed.indexOf( file.type ) === -1 ) {
				return this.getString( 'attachmentUnsupported' );
			}

			return null;
		},

		handleAttachClick( event ) {
			event.preventDefault();
			const $btn = $( event.currentTarget );
			$btn.closest( '.awts_notes_input_wrap, .awts_customer_notes_input_wrap' )
				.find( '.awts_attach_input' )
				.first()
				.trigger( 'click' );
		},

		handleAttachChange( event ) {
			const input    = event.currentTarget;
			const selected = input.files ? Array.from( input.files ) : [];

			if ( ! selected.length ) {
				return;
			}

			const $wrap = $( input ).closest( '.awts_notes_wrap, .awts_customer_notes_wrap' );
			this.queueFiles( $wrap, selected );

			input.value = '';
		},

		handleDragOver( event ) {
			event.preventDefault();
			event.stopPropagation();

			if ( event.originalEvent && event.originalEvent.dataTransfer ) {
				event.originalEvent.dataTransfer.dropEffect = 'copy';
			}

			$( event.currentTarget ).addClass( 'awts_dropzone_active' );
		},

		handleDragLeave( event ) {
			event.preventDefault();
			event.stopPropagation();

			const $target = $( event.currentTarget );
			const related = event.originalEvent ? event.originalEvent.relatedTarget : null;

			if ( related && $.contains( $target.get( 0 ), related ) ) {
				return;
			}

			$target.removeClass( 'awts_dropzone_active' );
		},

		handleDrop( event ) {
			event.preventDefault();
			event.stopPropagation();

			const $target = $( event.currentTarget );
			$target.removeClass( 'awts_dropzone_active' );

			const dt    = event.originalEvent && event.originalEvent.dataTransfer;
			const files = dt && dt.files ? Array.from( dt.files ) : [];

			if ( ! files.length ) {
				return;
			}

			const $wrap = $target.closest( '.awts_notes_wrap, .awts_customer_notes_wrap' );
			this.queueFiles( $wrap, files );
		},

		queueFiles( $wrap, incoming ) {
			if ( ! incoming || ! incoming.length ) {
				return;
			}

			const max     = parseInt( awtsData.attachmentMaxFiles, 10 ) || 5;
			const current = this.getPendingFiles( $wrap ).slice();
			const room    = Math.max( 0, max - current.length );

			if ( ! room ) {
				window.alert( this.sprintf( this.getString( 'attachmentTooManyFiles' ) || '', [ max ] ) );
				return;
			}

			const accepted = [];

			for ( let i = 0; i < incoming.length && accepted.length < room; i++ ) {
				const file  = incoming[ i ];
				const error = this.validateFile( file );

				if ( error ) {
					window.alert( error );
					continue;
				}

				accepted.push( file );
			}

			if ( accepted.length < incoming.length && accepted.length === room ) {
				window.alert( this.sprintf( this.getString( 'attachmentTooManyFiles' ) || '', [ max ] ) );
			}

			if ( accepted.length ) {
				this.setPendingFiles( $wrap, current.concat( accepted ) );
				this.renderPendingAttachments( $wrap );
			}
		},

		renderPendingAttachments( $wrap ) {
			const files = this.getPendingFiles( $wrap );
			const $container = $wrap.find( '.awts_pending_attachments' ).first();

			if ( ! files.length ) {
				$container.empty().attr( 'hidden', true );
				return;
			}

			let html = '';

			files.forEach( ( file, idx ) => {
				const name = $( '<span>' ).text( file.name ).html();
				const size = this.formatBytes( file.size );

				html += '<div class="awts_pending_attachment" data-awts-pending-index="' + idx + '">'
					+ '<span class="awts_pending_attachment_name">' + name + '</span>'
					+ '<span class="awts_pending_attachment_size">' + size + '</span>'
					+ '<button type="button" class="awts_pending_attachment_remove" aria-label="' + this.getString( 'attachmentRemoveLabel' ) + '">&times;</button>'
					+ '</div>';
			} );

			$container.html( html ).removeAttr( 'hidden' );
		},

		removePendingAttachment( event ) {
			event.preventDefault();
			const $btn = $( event.currentTarget );
			const $wrap = $btn.closest( '.awts_notes_wrap, .awts_customer_notes_wrap' );
			const idx = parseInt( $btn.closest( '.awts_pending_attachment' ).data( 'awts-pending-index' ), 10 );

			if ( isNaN( idx ) ) {
				return;
			}

			const files = this.getPendingFiles( $wrap ).slice();
			files.splice( idx, 1 );
			this.setPendingFiles( $wrap, files );
			this.renderPendingAttachments( $wrap );
		},

		uploadPendingFiles( $wrap, updateId, noteId, noteType ) {
			const files = this.getPendingFiles( $wrap );

			if ( ! files.length ) {
				return Promise.resolve( [] );
			}

			return files.reduce( ( chain, file ) => {
				return chain.then( acc => this.uploadOneFile( file, updateId, noteId, noteType ).then( att => {
					acc.push( att );
					return acc;
				} ) );
			}, Promise.resolve( [] ) );
		},

		uploadOneFile( file, updateId, noteId, noteType ) {
			const form = new FormData();
			form.append( 'file', file );
			form.append( 'update_id', updateId );
			form.append( 'note_id', noteId );
			form.append( 'note_type', noteType );

			return this.request( {
				url:         awtsData.attachmentsEndpoint,
				method:      'POST',
				data:        form,
				processData: false,
				contentType: false,
			} ).then( response => response.attachment ).catch( ( { xhr } ) => {
				const message = this.getRequestErrorMessage( xhr, 'attachmentUploadFailed' );
				window.alert( message );
				return null;
			} ).then( attachment => attachment || Promise.reject() );
		},

		buildAttachmentListHtml( attachments, canRemove ) {
			if ( ! attachments || ! attachments.length ) {
				return '';
			}

			let html = '<div class="awts_attachments">';

			attachments.forEach( att => {
				const attId = parseInt( att.id, 10 );
				const name = $( '<span>' ).text( att.name || '' ).html();
				const url  = $( '<span>' ).text( att.url || '' ).html();
				const size = att.size ? this.formatBytes( att.size ) : '';
				const isImage = !! att.is_image;
				const thumb = isImage
					? '<img class="awts_attachment_thumb" src="' + url + '" alt="' + name + '" loading="lazy">'
					: '<span class="awts_attachment_icon" aria-hidden="true">'
						+ '<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M4 1.5h5.3L13 5.2V14a.5.5 0 0 1-.5.5h-8a.5.5 0 0 1-.5-.5V1.5z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/><path d="M9 1.5V5h3.5" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>'
						+ '</span>';

				html += '<div class="awts_attachment" data-awts-attachment-id="' + attId + '">'
					+ '<a class="awts_attachment_link" href="' + url + '" target="_blank" rel="noopener">'
					+ thumb
					+ '<span class="awts_attachment_meta"><span class="awts_attachment_name">' + name + '</span>'
					+ ( size ? '<span class="awts_attachment_size">' + size + '</span>' : '' )
					+ '</span>'
					+ '</a>'
					+ ( canRemove ? '<button type="button" class="awts_attachment_remove" aria-label="' + this.getString( 'attachmentRemoveLabel' ) + '" data-awts-attachment-id="' + attId + '">&times;</button>' : '' )
					+ '</div>';
			} );

			html += '</div>';
			return html;
		},

		deleteAttachment( event ) {
			event.preventDefault();
			event.stopPropagation();

			const $btn = $( event.currentTarget );
			const attachmentId = parseInt( $btn.data( 'awts-attachment-id' ), 10 );

			if ( ! attachmentId || $btn.prop( 'disabled' ) ) {
				return;
			}

			if ( ! window.confirm( this.getString( 'attachmentDeleteConfirm' ) ) ) {
				return;
			}

			const $attachment = $btn.closest( '.awts_attachment' );

			this.withDisabled( $btn, this.request( {
				url:    awtsData.attachmentsEndpointBase + attachmentId,
				method: 'DELETE',
			} ) )
				.then( () => {
					const $container = $attachment.parent();
					$attachment.remove();
					if ( ! $container.children().length ) {
						$container.remove();
					}
				} )
				.catch( ( { xhr } ) => {
					const message = this.getRequestErrorMessage( xhr, 'attachmentDeleteFailed' );
					window.alert( message );
				} );
		},

		// -------------------------------------------------------------------------
		// Mentions
		// -------------------------------------------------------------------------

		// Walk the textarea text and return the IDs of any team members
		// whose "@FullName" appears in the body. Longest names match
		// first so "@John Smith" beats "@John".
		extractMentionedUserIdsFromText( text ) {
			const team = this.mentionTeam || [];
			if ( ! text || ! team.length ) {
				return [];
			}
			const sorted = team.slice().sort( ( a, b ) => ( b.name || '' ).length - ( a.name || '' ).length );
			const ids = [];
			for ( const member of sorted ) {
				const name = member.name || '';
				if ( ! name ) {
					continue;
				}
				const escaped = name.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
				const re = new RegExp( '(^|\\s)@' + escaped + '(?=\\s|$|[.,!?;:])', 'i' );
				if ( re.test( text ) ) {
					const id = parseInt( member.id, 10 );
					if ( id && ids.indexOf( id ) === -1 ) {
						ids.push( id );
					}
				}
			}
			return ids;
		},

		// Inspect the textarea around the caret. If a "@partial" token is
		// being typed, return its bounds + query; otherwise null.
		findMentionTrigger( el ) {
			if ( ! el || typeof el.selectionStart !== 'number' ) {
				return null;
			}
			const value = el.value;
			const caret = el.selectionStart;
			const before = value.slice( 0, caret );
			const at = before.lastIndexOf( '@' );
			if ( at === -1 ) {
				return null;
			}
			const charBefore = at === 0 ? '' : before.charAt( at - 1 );
			if ( charBefore && ! /\s/.test( charBefore ) ) {
				return null;
			}
			const fragment = before.slice( at + 1 );
			if ( /\s/.test( fragment ) ) {
				return null;
			}
			return { start: at, end: caret, query: fragment.toLowerCase() };
		},

		filterMentionTeam( query ) {
			const team = this.mentionTeam || [];
			const me   = this.mentionCurrentUser;
			const list = team.filter( m => parseInt( m.id, 10 ) !== me );
			if ( ! query ) {
				return list.slice( 0, 8 );
			}
			const q = query.toLowerCase();
			const matches = list.filter( m =>
				( m.name || '' ).toLowerCase().indexOf( q ) !== -1
				|| ( m.email || '' ).toLowerCase().indexOf( q ) !== -1
			);
			return matches.slice( 0, 8 );
		},

		handleMentionInput( event ) {
			const el = event.currentTarget;
			const trigger = this.findMentionTrigger( el );
			if ( ! trigger ) {
				this.closeMentionDropdown();
				return;
			}
			const matches = this.filterMentionTeam( trigger.query );
			if ( ! matches.length ) {
				this.closeMentionDropdown();
				return;
			}
			this.mentionContext = { el, trigger };
			this.mentionActiveIndex = 0;
			this.openMentionDropdown( matches );
		},

		/**
		 * Mentions are inserted into the textarea as plain "@FullName " runs.
		 * Without this guard, Backspace would chip one character off the end
		 * of the name and quietly break the mention. Detect when the cursor
		 * sits right after a known team member's mention and, on Backspace,
		 * delete the whole token in a single keystroke. Same for Delete on
		 * the leading edge.
		 */
		maybeAtomicDeleteMention( event ) {
			if ( event.defaultPrevented ) {
				return false;
			}
			if ( event.key !== 'Backspace' && event.key !== 'Delete' ) {
				return false;
			}

			const el = event.target;
			if ( ! el || typeof el.selectionStart !== 'number' || el.selectionStart !== el.selectionEnd ) {
				return false;
			}

			const team = this.mentionTeam || [];
			if ( ! team.length ) {
				return false;
			}

			// Sort longest-first so "John Smith" matches before "John".
			const sorted = team.slice().sort( ( a, b ) => ( b.name || '' ).length - ( a.name || '' ).length );
			const value  = el.value;
			const caret  = el.selectionEnd;

			for ( const member of sorted ) {
				const name = member.name || '';
				if ( ! name ) continue;
				const token = '@' + name;

				if ( event.key === 'Backspace' ) {
					// Cursor right after "@Name " or "@Name".
					for ( const suffix of [ token + ' ', token ] ) {
						const start = caret - suffix.length;
						if ( start < 0 ) continue;
						if ( value.slice( start, caret ) !== suffix ) continue;
						// Make sure the token isn't just incidentally suffixed
						// against another word (e.g. "foo@Name" — not a mention).
						if ( start > 0 && /[A-Za-z0-9_]/.test( value.charAt( start - 1 ) ) ) continue;

						event.preventDefault();
						el.value = value.slice( 0, start ) + value.slice( caret );
						el.selectionStart = el.selectionEnd = start;
						$( el ).trigger( 'input' );
						return true;
					}
				} else {
					// Delete key — cursor right at the start of "@Name".
					const start = caret;
					for ( const piece of [ token + ' ', token ] ) {
						const end = start + piece.length;
						if ( end > value.length ) continue;
						if ( value.slice( start, end ) !== piece ) continue;
						if ( start > 0 && /[A-Za-z0-9_]/.test( value.charAt( start - 1 ) ) ) continue;

						event.preventDefault();
						el.value = value.slice( 0, start ) + value.slice( end );
						el.selectionStart = el.selectionEnd = start;
						$( el ).trigger( 'input' );
						return true;
					}
				}
			}

			return false;
		},

		handleMentionKeydown( event ) {
			if ( this.maybeAtomicDeleteMention( event ) ) {
				return;
			}
			if ( ! this.mentionDropdown ) {
				return;
			}
			const $items = this.mentionDropdown.find( '.awts_mention_item' );
			if ( ! $items.length ) {
				return;
			}
			const key = event.key;
			if ( key === 'ArrowDown' ) {
				event.preventDefault();
				this.mentionActiveIndex = ( this.mentionActiveIndex + 1 ) % $items.length;
				this.refreshMentionActive( $items );
			} else if ( key === 'ArrowUp' ) {
				event.preventDefault();
				this.mentionActiveIndex = ( this.mentionActiveIndex - 1 + $items.length ) % $items.length;
				this.refreshMentionActive( $items );
			} else if ( key === 'Enter' || key === 'Tab' ) {
				event.preventDefault();
				// stopImmediatePropagation (not just stopPropagation) is
				// required because the Enter-to-Send handler is bound to the
				// same $panel via a separate jQuery delegate. stopPropagation
				// only blocks parents; sibling delegates on the same element
				// still fire — which would submit the note before this
				// handler's commitMentionAtIndex closes the dropdown.
				event.stopImmediatePropagation();
				this.commitMentionAtIndex( this.mentionActiveIndex );
			} else if ( key === 'Escape' ) {
				event.preventDefault();
				this.closeMentionDropdown();
			}
		},

		refreshMentionActive( $items ) {
			$items.removeClass( 'awts_mention_item--active' );
			$items.eq( this.mentionActiveIndex ).addClass( 'awts_mention_item--active' );
		},

		openMentionDropdown( matches ) {
			if ( ! this.mentionDropdown ) {
				this.mentionDropdown = $( '<div class="awts_mention_dropdown" role="listbox"></div>' );
				$( document.body ).append( this.mentionDropdown );
				this.mentionDropdown.on( this.pointerDownEvent, '.awts_mention_item', e => {
					e.preventDefault();
					const idx = parseInt( $( e.currentTarget ).data( 'awts-mention-index' ), 10 );
					this.commitMentionAtIndex( idx );
				} );
			}

			const html = matches.map( ( m, idx ) => {
				const cls = 'awts_mention_item' + ( idx === 0 ? ' awts_mention_item--active' : '' );
				const name = $( '<span>' ).text( m.name || ( '#' + m.id ) ).html();
				const email = $( '<span>' ).text( m.email || '' ).html();
				const avatar = m.avatar
					? '<img class="awts_mention_avatar" src="' + m.avatar + '" alt="" />'
					: '<span class="awts_mention_avatar awts_mention_avatar--placeholder"></span>';
				return '<div class="' + cls + '" role="option" data-awts-mention-index="' + idx + '" data-awts-mention-id="' + parseInt( m.id, 10 ) + '">'
					+ avatar
					+ '<span class="awts_mention_meta"><span class="awts_mention_name">' + name + '</span><span class="awts_mention_email">' + email + '</span></span>'
					+ '</div>';
			} ).join( '' );
			this.mentionDropdown.html( html );
			this.mentionDropdown.data( 'awts-matches', matches );
			this.positionMentionDropdown();
		},

		positionMentionDropdown() {
			if ( ! this.mentionDropdown || ! this.mentionContext ) {
				return;
			}
			const el = this.mentionContext.el;
			const rect = el.getBoundingClientRect();
			const top = window.scrollY + rect.bottom + 4;
			const left = window.scrollX + rect.left;
			this.mentionDropdown.css( { top: top + 'px', left: left + 'px', minWidth: rect.width + 'px' } );
		},

		commitMentionAtIndex( idx ) {
			if ( ! this.mentionDropdown || ! this.mentionContext ) {
				return;
			}
			const matches = this.mentionDropdown.data( 'awts-matches' ) || [];
			const choice = matches[ idx ];
			if ( ! choice ) {
				this.closeMentionDropdown();
				return;
			}
			const el = this.mentionContext.el;
			const trigger = this.mentionContext.trigger;
			const value = el.value;
			const insert = '@' + ( choice.name || ( '#' + choice.id ) ) + ' ';
			const before = value.slice( 0, trigger.start );
			const after  = value.slice( trigger.end );
			el.value = before + insert + after;
			const newCaret = before.length + insert.length;
			el.selectionStart = el.selectionEnd = newCaret;
			this.closeMentionDropdown();
			$( el ).trigger( 'input' );
			el.focus();
		},

		scheduleCloseMentionDropdown() {
			window.setTimeout( () => this.closeMentionDropdown(), 120 );
		},

		closeMentionDropdown() {
			if ( this.mentionDropdown ) {
				this.mentionDropdown.remove();
				this.mentionDropdown = null;
			}
			this.mentionContext = null;
			this.mentionActiveIndex = 0;
		},

	};

	$( '.awts_panel' ).each( function() {
		const instance = Object.create( updateMetaBox );
		instance.init( this );
	} );
} );
