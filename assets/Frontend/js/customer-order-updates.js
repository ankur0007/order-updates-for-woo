/*
 * Customer-facing "Write a note" modal.
 *
 * Vanilla JS (no jQuery) so it also runs cleanly on guest pages where a theme
 * may not enqueue jQuery on the frontend. Submits multipart/form-data to the
 * REST endpoint so the subject, message, and file attachments all travel in a
 * single request.
 */
(function () {
	'use strict';

	var config = window.AWTS_COU_CONFIG || null;

	if (!config || !config.restUrl || !config.orderId) {
		return;
	}

	markUnreadUpdates();
	openHashedUpdate();
	scrollAllThreadsToLatest();
	isolateChatScroll();
	bindEnterToSend();

	// Inline-update bindings (reply form, emoji picker, ratings, etc.) must
	// fire even on the guest URL, where the "new update" modal doesn't exist.
	bindReplyForms();
	bindNoteActions();
	bindRatingForms();
	bindEmailPref();
	bindEmojiPickers();
	injectAssigneeDividers();

	var modal = document.querySelector('[data-awts-cou-modal]');
	var trigger = document.querySelector('[data-awts-cou-open]');
	// "Write new update" modal may or may not be on the page (depends on the
	// `allow_customer_create_update` setting). Everything below is written to
	// tolerate either presence — the rest of the script keeps running so
	// reply forms, emoji pickers, ratings, etc. still work on guest URLs.

	function readKey(updateId) {
		return 'awts_cou_read_' + updateId;
	}

	/**
	 * Wraps a file input so multiple picker sessions accumulate rather than
	 * replace. The drop zone already handles accumulation on its own side;
	 * we detect whether change fired from a user gesture (e.isTrusted = true)
	 * or from the drop handler's dispatchEvent (isTrusted = false) and act
	 * accordingly without touching setupDropZone.
	 *
	 * Returns { reset } — call reset() after a successful submission.
	 */
	function makeFileAccumulator(input, onUpdate) {
		var queue = [];

		function syncToInput() {
			if (typeof DataTransfer !== 'function') return;
			var dt = new DataTransfer();
			queue.forEach(function (f) { dt.items.add(f); });
			input.files = dt.files;
		}

		function reset() {
			queue = [];
			if (typeof DataTransfer === 'function') {
				syncToInput();
			} else {
				input.value = '';
			}
			if (onUpdate) onUpdate();
		}

		// Drop a single queued file (used by the per-row remove button) so the
		// customer can ditch a rejected attachment and resend the message
		// text-only without re-picking the rest of the queue.
		function removeAt(index) {
			if (index < 0 || index >= queue.length) {
				return;
			}
			queue.splice(index, 1);
			if (typeof DataTransfer === 'function') {
				syncToInput();
			} else {
				input.value = '';
			}
			if (onUpdate) onUpdate();
		}

		input.addEventListener('change', function (e) {
			if (e.isTrusted) {
				// User opened the file picker — merge new selection into queue.
				queue = queue.concat(Array.prototype.slice.call(input.files || []));
				syncToInput();
			} else {
				// Programmatic change from the drop handler — input.files is
				// already the merged result, just sync queue to match it.
				queue = Array.prototype.slice.call(input.files || []);
			}

			if (onUpdate) onUpdate();
		});

		return { reset: reset, removeAt: removeAt };
	}

	function setupDropZone(zone, fileInput) {
		if (!zone || !fileInput || typeof DataTransfer !== 'function') {
			return;
		}

		var depth = 0;

		zone.addEventListener('dragenter', function (event) {
			event.preventDefault();
			depth++;
			zone.classList.add('awts_dropzone_active');
		});

		zone.addEventListener('dragover', function (event) {
			event.preventDefault();
			if (event.dataTransfer) {
				event.dataTransfer.dropEffect = 'copy';
			}
		});

		zone.addEventListener('dragleave', function (event) {
			event.preventDefault();
			depth = Math.max(0, depth - 1);
			if (depth === 0) {
				zone.classList.remove('awts_dropzone_active');
			}
		});

		zone.addEventListener('drop', function (event) {
			event.preventDefault();
			depth = 0;
			zone.classList.remove('awts_dropzone_active');

			var dropped = event.dataTransfer && event.dataTransfer.files ? event.dataTransfer.files : null;

			if (!dropped || !dropped.length) {
				return;
			}

			var dt = new DataTransfer();

			if (fileInput.files) {
				for (var i = 0; i < fileInput.files.length; i++) {
					dt.items.add(fileInput.files[i]);
				}
			}

			for (var j = 0; j < dropped.length; j++) {
				dt.items.add(dropped[j]);
			}

			fileInput.files = dt.files;
			fileInput.dispatchEvent(new Event('change', { bubbles: true }));
		});
	}

	function getStoredReadId(updateId) {
		try {
			var v = window.localStorage.getItem(readKey(updateId));
			return v ? parseInt(v, 10) : 0;
		} catch (e) {
			return 0;
		}
	}

	function setStoredReadId(updateId, noteId) {
		try {
			var current = getStoredReadId(updateId);
			if (noteId > current) {
				window.localStorage.setItem(readKey(updateId), String(noteId));
			}
		} catch (e) {}
	}

	// Single per-browser preference for "Enter = Send" across every
	// reply composer on the page. Default ON (Slack / Discord pattern).
	// Same localStorage key as the admin meta box so a customer / staff
	// member toggling on one surface gets a consistent experience on
	// the other (when they're the same person on the same browser).
	function bindEnterToSend() {
		var KEY = 'awts_enter_to_send';
		var enabled = function () { return '0' !== localStorage.getItem(KEY); };
		var setEnabled = function (on) {
			localStorage.setItem(KEY, on ? '1' : '0');
			document.querySelectorAll('[data-awts-enter-to-send]').forEach(function (cb) {
				cb.checked = on;
			});
		};

		// Sync checkboxes to stored value, then listen for changes.
		document.querySelectorAll('[data-awts-enter-to-send]').forEach(function (cb) {
			cb.checked = enabled();
			cb.addEventListener('change', function () { setEnabled(cb.checked); });
		});

		// Customer reply composer: Enter (no Shift) submits the form.
		document.querySelectorAll('[data-awts-cou-reply-message]').forEach(function (textarea) {
			textarea.addEventListener('keydown', function (event) {
				if ('Enter' !== event.key || event.shiftKey) return;

				// Read live state from THIS composer's checkbox, not the
				// localStorage default. The template renders the checkbox
				// unchecked by default, so if any reply composer mounts
				// after the initial sync (e.g. AJAX-loaded update card on
				// the portal), localStorage could say "enabled" while the
				// user sees an unchecked box. Trust what they see.
				var form = textarea.closest('[data-awts-cou-reply-form]');
				var pref = form ? form.querySelector('[data-awts-enter-to-send]') : null;
				if (pref && ! pref.checked) return;

				event.preventDefault();
				if (form && typeof form.requestSubmit === 'function') {
					form.requestSubmit();
				} else if (form) {
					// Older Safari fallback.
					var submit = form.querySelector('[data-awts-cou-reply-submit]');
					if (submit) submit.click();
				}
			});
		});
	}

	// Stop wheel and touchmove events on each thread from chaining to the
	// page once the thread hits a scroll boundary — or when the thread
	// can't scroll at all (shorter than max-height). CSS
	// `overscroll-behavior: contain` covers the boundary case in modern
	// browsers, but does nothing when the inner has no overflow; this
	// handler closes that gap so hovering over a short chat never moves
	// the page underneath.
	function isolateChatScroll() {
		document.querySelectorAll('.awts_cou_notes').forEach(function (container) {
			container.addEventListener('wheel', function (event) {
				var scrollTop    = container.scrollTop;
				var scrollHeight = container.scrollHeight;
				var clientHeight = container.clientHeight;
				var atTop        = scrollTop <= 0;
				var atBottom     = Math.ceil(scrollTop + clientHeight) >= scrollHeight;
				var deltaY       = event.deltaY;

				if (
					(atBottom && deltaY > 0) ||
					(atTop && deltaY < 0) ||
					scrollHeight <= clientHeight
				) {
					event.preventDefault();
				}
			}, { passive: false });
		});
	}

	// Animated scroll-to-bottom for note threads. Used on initial page
	// render, when a heartbeat-polled reply arrives, and after the customer
	// sends a message. NOT used for the "Load previous" re-anchor — that's
	// an instant assignment because animating it would defeat the
	// keep-position-steady contract.
	function smoothScrollToBottom(el) {
		if (! el) return;
		if (typeof el.scrollTo === 'function') {
			el.scrollTo({ top: el.scrollHeight, behavior: 'smooth' });
		} else {
			el.scrollTop = el.scrollHeight;
		}
	}

	// Each `.awts_cou_notes` thread has its own scroll viewport (max-height +
	// overflow-y). Without this, an update with several notes lands scrolled
	// to the top — the customer sees the oldest message and thinks the latest
	// one never arrived.
	function scrollAllThreadsToLatest() {
		var threads = document.querySelectorAll('.awts_cou_notes');
		Array.prototype.forEach.call(threads, function (thread) {
			smoothScrollToBottom(thread);
		});

		// Collapsed details have no laid-out scroll viewport yet — scroll on the
		// first open, then leave the user's scroll position alone after.
		var collapsedItems = document.querySelectorAll('.awts_cou_item details:not([open])');
		Array.prototype.forEach.call(collapsedItems, function (details) {
			details.addEventListener('toggle', function onFirstOpen() {
				if (! details.open) {
					return;
				}
				details.removeEventListener('toggle', onFirstOpen);
				var thread = details.querySelector('.awts_cou_notes');
				smoothScrollToBottom(thread);
			});
		});
	}

	function markUnreadUpdates() {
		var items = document.querySelectorAll('.awts_cou_item[data-awts-update-id]');
		var firstUnread = null;
		Array.prototype.forEach.call(items, function (item) {
			var updateId = parseInt(item.getAttribute('data-awts-update-id'), 10);
			var latest = parseInt(item.getAttribute('data-awts-latest-note-id') || '0', 10);
			if (!updateId || !latest) {
				return;
			}
			var stored = getStoredReadId(updateId);
			if (latest <= stored) {
				return;
			}
			var unreadCount = countUnreadNotes(item, stored);
			if (unreadCount <= 0) {
				return;
			}
			item.classList.add('awts_cou_item--unread');
			var badge = item.querySelector('[data-awts-cou-unread-badge]');
			if (badge) {
				var template = (config.labels && config.labels.newBadgeCount) || '%d new';
				badge.textContent = unreadCount > 1
					? template.replace('%d', String(unreadCount))
					: (config.labels && config.labels.newBadge);
				badge.hidden = false;
			}
			var details = item.querySelector('details');
			if (details && !details.open) {
				details.open = true;
			}

			function clearUnreadState() {
				setStoredReadId(updateId, latest);
				item.classList.remove('awts_cou_item--unread');
				if (badge) {
					badge.hidden = true;
					badge.textContent = '';
				}
			}

			window.setTimeout(clearUnreadState, 4000);

			item.addEventListener('click', function onClick(event) {
				if (event.target && event.target.closest && event.target.closest('summary')) {
					return;
				}
				clearUnreadState();
				item.removeEventListener('click', onClick);
			});

			if (!firstUnread) {
				firstUnread = item;
			}
		});

		if (firstUnread && !/^#awts-update-\d+$/.test(window.location.hash || '')) {
			window.requestAnimationFrame(function () {
				firstUnread.scrollIntoView({ behavior: 'smooth', block: 'start' });
				firstUnread.classList.add('awts_cou_item--highlight');
				window.setTimeout(function () {
					firstUnread.classList.remove('awts_cou_item--highlight');
				}, 2500);
			});
		}
	}

	function countUnreadNotes(item, storedId) {
		var notes = item.querySelectorAll('.awts_cou_note[data-awts-note-id]');
		var count = 0;
		Array.prototype.forEach.call(notes, function (n) {
			var id = parseInt(n.getAttribute('data-awts-note-id'), 10);
			if (id > storedId) {
				count += 1;
			}
		});
		return count;
	}

	function openHashedUpdate() {
		var hash = (window.location.hash || '').replace(/^#/, '');

		if (!/^awts-update-\d+$/.test(hash)) {
			return;
		}

		var target = document.getElementById(hash);

		if (!target) {
			return;
		}

		var details = target.querySelector('details');

		if (details && !details.open) {
			details.open = true;
		}

		window.requestAnimationFrame(function () {
			target.scrollIntoView({ behavior: 'smooth', block: 'start' });
			target.classList.add('awts_cou_item--highlight');
			window.setTimeout(function () {
				target.classList.remove('awts_cou_item--highlight');
			}, 2500);
		});
	}


	// Setting location.href to a URL that only differs by hash is treated as a
	// same-document navigation by the browser — the page does NOT reload, so a
	// freshly submitted note never appears and the submit button stays stuck on
	// its "Sending..." label. Set the hash first (so the new update is targeted
	// after reload), then explicitly reload to fetch the server-rendered HTML.
	function reloadToUpdate(updateId) {
		if (updateId) {
			window.location.hash = 'awts-update-' + updateId;
		}

		window.location.reload();
	}

	var form = modal ? modal.querySelector('[data-awts-cou-form]') : null;
	var subjectField = modal ? modal.querySelector('[data-awts-cou-subject]') : null;
	var messageField = modal ? modal.querySelector('[data-awts-cou-message]') : null;
	var filesField = modal ? modal.querySelector('[data-awts-cou-files]') : null;
	var fileList = modal ? modal.querySelector('[data-awts-cou-file-list]') : null;
	var submitButton = modal ? modal.querySelector('[data-awts-cou-submit]') : null;
	var feedback = modal ? modal.querySelector('[data-awts-cou-feedback]') : null;
	var closeTriggers = modal ? modal.querySelectorAll('[data-awts-cou-close]') : [];

	var originalSubmitLabel = submitButton ? submitButton.textContent : '';
	var acceptMime = (config.acceptMime || '').split(',').filter(Boolean);

	function getRequestErrorMessage(resultBody) {
		var code = resultBody && resultBody.code ? String(resultBody.code) : '';
		var message = resultBody && resultBody.message ? String(resultBody.message) : '';

		if ('order_updates_for_woo_invalid_nonce' === code || /cookie check failed/i.test(message)) {
			return config.labels.sessionExpired || config.labels.genericFail;
		}

		return message || config.labels.genericFail;
	}

	function openModal() {
		modal.hidden = false;
		modal.setAttribute('aria-hidden', 'false');
		document.body.classList.add('awts_cou_modal_open');

		window.setTimeout(function () {
			if (subjectField) {
				subjectField.focus();
			}
		}, 30);
	}

	function closeModal() {
		modal.hidden = true;
		modal.setAttribute('aria-hidden', 'true');
		document.body.classList.remove('awts_cou_modal_open');
		setFeedback('', null);
	}

	function setFeedback(message, tone) {
		if (!feedback) {
			return;
		}

		feedback.textContent = message || '';
		feedback.className = 'awts_cou_modal__feedback';

		if (tone) {
			feedback.classList.add('awts_cou_modal__feedback--' + tone);
		}
	}

	function renderFileList(onRemove) {
		if (!fileList || !filesField) {
			return;
		}

		fileList.innerHTML = '';

		var files = filesField.files ? Array.prototype.slice.call(filesField.files) : [];

		files.forEach(function (file, index) {
			var li = document.createElement('li');
			li.className = 'awts_cou_file_list__item';

			var name = document.createElement('span');
			name.className = 'awts_cou_file_list__name';
			name.textContent = file.name;

			var size = document.createElement('span');
			size.className = 'awts_cou_file_list__size';
			size.textContent = formatBytes(file.size);

			li.appendChild(name);
			li.appendChild(size);

			if (typeof onRemove === 'function') {
				var removeBtn = document.createElement('button');
				removeBtn.type = 'button';
				removeBtn.className = 'awts_cou_file_list__remove';
				removeBtn.setAttribute('aria-label', config.labels.removeFile || 'Remove');
				removeBtn.title = config.labels.removeFile || 'Remove';
				removeBtn.textContent = '×';
				removeBtn.addEventListener('click', function () {
					onRemove(index);
				});
				li.appendChild(removeBtn);
			}

			fileList.appendChild(li);
		});
	}

	function formatBytes(bytes) {
		if (!bytes && bytes !== 0) {
			return '';
		}

		var units = ['B', 'KB', 'MB'];
		var size = bytes;
		var unit = 0;

		while (size >= 1024 && unit < units.length - 1) {
			size /= 1024;
			unit++;
		}

		return size.toFixed(unit === 0 ? 0 : 1) + ' ' + units[unit];
	}

	function validateFiles() {
		if (!filesField || !filesField.files) {
			return true;
		}

		var files = filesField.files;

		if (files.length > config.maxFiles) {
			setFeedback(interpolate(config.labels.tooManyFiles, [ config.maxFiles ]), 'error');
			return false;
		}

		for (var i = 0; i < files.length; i++) {
			var file = files[i];

			if (file.size > config.maxBytes) {
				setFeedback(interpolate(config.labels.attachmentTooLarge, [ formatBytes(config.maxBytes) ]), 'error');
				return false;
			}

			if (acceptMime.length && file.type && acceptMime.indexOf(file.type) === -1) {
				setFeedback(config.labels.attachmentUnsupported, 'error');
				return false;
			}
		}

		// Stale "unsupported"/"too large" feedback would otherwise stick around
		// after the customer removes the offending file — looks like the form
		// is still in an error state when it isn't.
		clearStaleFileFeedback(feedback);
		return true;
	}

	function clearStaleFileFeedback(el) {
		if (!el || !el.classList || !el.classList.contains) {
			return;
		}
		if (el.classList.contains('awts_cou_modal__feedback--error') || el.classList.contains('awts_cou_reply__feedback--error')) {
			el.textContent = '';
			el.className = el.classList.contains('awts_cou_reply__feedback') ? 'awts_cou_reply__feedback' : 'awts_cou_modal__feedback';
		}
	}

	function interpolate(template, values) {
		var i = 0;
		return String(template || '').replace(/%(\d+\$)?[ds]/g, function () {
			var value = values[i];
			i++;
			return value === undefined ? '' : String(value);
		});
	}

	function submit(event) {
		event.preventDefault();

		var subject = subjectField ? subjectField.value.trim() : '';
		var message = messageField ? messageField.value.trim() : '';

		if (!subject) {
			setFeedback(config.labels.subjectRequired, 'error');
			if (subjectField) {
				subjectField.focus();
			}
			return;
		}

		if (!message) {
			setFeedback(config.labels.messageRequired, 'error');
			if (messageField) {
				messageField.focus();
			}
			return;
		}

		if (!validateFiles()) {
			return;
		}

		var payload = new FormData();
		payload.append('order_id', String(config.orderId));
		payload.append('title', subject);
		payload.append('message', message);

		if (config.orderKey) {
			payload.append('order_key', config.orderKey);
		}

		if (filesField && filesField.files) {
			for (var i = 0; i < filesField.files.length; i++) {
				payload.append('files[]', filesField.files[i]);
			}
		}

		submitButton.disabled = true;
		submitButton.textContent = config.labels.submitting;
		setFeedback('', null);

		fetch(config.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce },
			body: payload,
		})
			.then(function (response) {
				return response.json().then(function (body) {
					return { ok: response.ok, body: body };
				});
			})
			.then(function (result) {
				if (result.ok) {
					setFeedback(result.body.message || config.labels.success, 'success');
					var newUpdateId = result.body && result.body.updateId ? parseInt(result.body.updateId, 10) : 0;
					window.setTimeout(function () {
						reloadToUpdate(newUpdateId);
					}, 800);
					return;
				}

				var message = getRequestErrorMessage(result.body);
				setFeedback(message, 'error');
				submitButton.disabled = false;
				submitButton.textContent = originalSubmitLabel;
			})
			.catch(function () {
				setFeedback(config.labels.genericFail, 'error');
				submitButton.disabled = false;
				submitButton.textContent = originalSubmitLabel;
			});
	}

	if (trigger) {
		trigger.addEventListener('click', function (event) {
			event.preventDefault();
			openModal();
		});
	}

	closeTriggers.forEach(function (element) {
		element.addEventListener('click', closeModal);
	});

	if (filesField) {
		var modalAccumulator = makeFileAccumulator(filesField, function () {
			renderFileList(function (index) {
				modalAccumulator.removeAt(index);
			});
			validateFiles();
		});

		setupDropZone(form, filesField);
	}

	if (form) {
		form.addEventListener('submit', submit);
	}

	if (modal) {
		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape' && !modal.hidden) {
				closeModal();
			}
		});
	}

	// ---------- Inline reply composer per update ---------- //
	// (bindings called at top of IIFE so they fire on the guest URL too).

	function bindNoteActions() {
		document.querySelectorAll('[data-awts-cou-note-edit]').forEach(function (button) {
			button.addEventListener('click', function () {
				handleNoteEdit(button);
			});
		});

		document.querySelectorAll('[data-awts-cou-note-history]').forEach(function (button) {
			button.addEventListener('click', function () {
				handleNoteHistory(button);
			});
		});

		Array.prototype.forEach.call(document.querySelectorAll('[data-awts-cou-load-prev]'), function (button) {
			button.addEventListener('click', function () {
				loadPreviousNotes(button);
			});
		});

		// Reveal the "Load previous" button only when the customer has
		// scrolled to the very top of the chat thread — at the bottom (the
		// default landing position) it's clutter. Threshold is a few px so
		// the sticky-position button shows the moment the customer pulls
		// past the topmost message.
		Array.prototype.forEach.call(document.querySelectorAll('.awts_cou_notes'), function (thread) {
			var button = thread.querySelector('[data-awts-cou-load-prev]');
			if (!button) return;

			var sync = function () {
				// Respect the "no more older notes" state — loadPreviousNotes
				// sets `hidden = true` when the server reports hasMore=false.
				// Don't fight that with the scroll-based reveal.
				if (button.hidden) {
					button.classList.remove('awts_cou_load_prev--visible');
					return;
				}
				if (thread.scrollTop <= 4) {
					button.classList.add('awts_cou_load_prev--visible');
				} else {
					button.classList.remove('awts_cou_load_prev--visible');
				}
			};

			sync();
			thread.addEventListener('scroll', sync, { passive: true });
		});
	}

	function loadPreviousNotes(button) {
		var notesWrap = button.closest('.awts_cou_notes');
		var updateItem = button.closest('[data-awts-update-id]');
		var updateId = updateItem ? parseInt(updateItem.getAttribute('data-awts-update-id') || '0', 10) : 0;
		var earliestId = notesWrap ? parseInt(notesWrap.getAttribute('data-awts-cou-earliest-note-id') || '0', 10) : 0;

		if (!notesWrap || !updateId || !earliestId || button.disabled) {
			return;
		}

		button.disabled = true;
		var originalLabel = button.textContent;
		button.textContent = config.labels.loadingLabel;

		var url = config.customerNotesEndpointBase + updateId + '/customer-notes/previous'
			+ '?before_note_id=' + earliestId
			+ '&limit=' + (config.notesPageSize || 10);

		if (config.orderKey) {
			url += '&order_key=' + encodeURIComponent(config.orderKey);
		}

		fetch(url, {
			method: 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce },
		})
			.then(function (response) {
				return response.json().then(function (body) {
					return { ok: response.ok, body: body };
				});
			})
			.then(function (result) {
				if (!result.ok) {
					button.disabled = false;
					button.textContent = originalLabel;
					return;
				}

				var notes = Array.isArray(result.body.notes) ? result.body.notes : [];
				var hasMore = !!result.body.has_more;

				if (notes.length > 0) {
					var firstNote = notesWrap.querySelector('.awts_cou_note');
					var frag = document.createDocumentFragment();
					var insertedNodes = [];

					notes.forEach(function (note) {
						var node = createCustomerNoteElement(note, updateId);
						node.classList.add('awts_cou_note--just_loaded');
						frag.appendChild(node);
						insertedNodes.push(node);
					});

					// Capture scroll metrics BEFORE insert so we can hold the
					// reading position steady. Inserting at the top of a
					// scrolled container would otherwise shift everything
					// visually downward — confusing for a returning reader.
					var prevScrollTop    = notesWrap.scrollTop;
					var prevScrollHeight = notesWrap.scrollHeight;

					if (firstNote) {
						notesWrap.insertBefore(frag, firstNote);
					} else {
						notesWrap.appendChild(frag);
					}

					// Re-anchor: scrollTop + (new height - old height) keeps
					// the same DOM node under the same pixel position.
					notesWrap.scrollTop = prevScrollTop + (notesWrap.scrollHeight - prevScrollHeight);

					var newEarliestId = parseInt(notes[0].id || '0', 10);
					if (newEarliestId > 0) {
						notesWrap.setAttribute('data-awts-cou-earliest-note-id', String(newEarliestId));
					}

					// Drop the just-loaded highlight after 2s so the rows
					// blend back in. Keeps customer attention on what was
					// fetched without leaving them coloured forever.
					window.setTimeout(function () {
						insertedNodes.forEach(function (node) {
							node.classList.remove('awts_cou_note--just_loaded');
						});
					}, 2000);
				}

				if (hasMore) {
					button.disabled = false;
					button.textContent = originalLabel;
				} else {
					button.hidden = true;
				}
			})
			.catch(function () {
				button.disabled = false;
				button.textContent = originalLabel;
			});
	}

	function handleNoteEdit(button) {
		// Stale-DOM guard — see the Up-arrow handler comment for context.
		if (!config.allowNoteEdit) return;

		var noteItem = button.closest('.awts_cou_note');
		var updateId = parseInt(button.getAttribute('data-awts-update-id') || '0', 10);
		var noteId = noteItem ? parseInt(noteItem.getAttribute('data-awts-note-id') || '0', 10) : 0;

		if (!noteItem || !updateId || !noteId || button.disabled) {
			return;
		}

		var currentText = String(noteItem.getAttribute('data-awts-note-text') || '');
		var replyForm = document.querySelector('[data-awts-cou-reply-form][data-awts-cou-update-id="' + updateId + '"]');

		if (!replyForm) {
			return;
		}

		var textarea = replyForm.querySelector('[data-awts-cou-reply-message]');
		var submitBtn = replyForm.querySelector('[data-awts-cou-reply-submit]');
		var toolbar = replyForm.querySelector('.awts_cou_reply__toolbar');
		var feedbackEl = replyForm.querySelector('[data-awts-cou-reply-feedback]');

		if (!textarea || !submitBtn || !toolbar) {
			return;
		}

		replyForm.setAttribute('data-awts-cou-editing-note-id', String(noteId));
		submitBtn.textContent = config.labels.saveNoteAction;
		textarea.value = currentText;

		if (feedbackEl) {
			setReplyFeedback(feedbackEl, '', null);
		}

		var cancelButton = replyForm.querySelector('[data-awts-cou-edit-cancel]');

		if (!cancelButton) {
			cancelButton = document.createElement('button');
			cancelButton.type = 'button';
			cancelButton.className = 'awts_cou_btn awts_cou_btn--ghost awts_cou_reply__cancel_edit';
			cancelButton.setAttribute('data-awts-cou-edit-cancel', 'true');
			cancelButton.textContent = config.labels.cancelNoteAction;
			toolbar.appendChild(cancelButton);
		}

		cancelButton.hidden = false;
		cancelButton.onclick = function () {
			resetReplyEdit(replyForm, textarea, submitBtn, feedbackEl);
		};

		replyForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
		textarea.focus();
		textarea.setSelectionRange(textarea.value.length, textarea.value.length);
	}

	function handleNoteHistory(button) {
		var noteItem = button.closest('.awts_cou_note');
		var updateId = parseInt(button.getAttribute('data-awts-update-id') || '0', 10);
		var noteId = noteItem ? parseInt(noteItem.getAttribute('data-awts-note-id') || '0', 10) : 0;

		if (!noteItem || !updateId || !noteId || button.disabled) {
			return;
		}

		var existing = noteItem.querySelector('.awts_cou_note__history');

		if (existing) {
			existing.parentNode.removeChild(existing);
			button.setAttribute('aria-expanded', 'false');
			return;
		}

		button.disabled = true;
		button.setAttribute('aria-busy', 'true');

		var requestUrl = config.customerNotesEndpointBase + updateId + '/customer-notes/' + noteId + '/history';

		if (config.orderKey) {
			requestUrl += '?order_key=' + encodeURIComponent(config.orderKey);
		}

		fetch(requestUrl, {
			method: 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce },
		})
			.then(function (response) {
				return response.json().then(function (body) {
					return { ok: response.ok, body: body };
				});
			})
			.then(function (result) {
				button.disabled = false;
				button.removeAttribute('aria-busy');

				if (!result.ok) {
					window.alert(getRequestErrorMessage(result.body));
					return;
				}

				var revisions = (result.body && Array.isArray(result.body.revisions)) ? result.body.revisions : [];
				renderNoteHistoryPanel(noteItem, revisions);
				button.setAttribute('aria-expanded', 'true');
			})
			.catch(function () {
				button.disabled = false;
				button.removeAttribute('aria-busy');
				window.alert(config.labels.genericFail);
			});
	}

	function renderNoteHistoryPanel(noteItem, revisions) {
		var panel = document.createElement('div');
		panel.className = 'awts_cou_note__history';

		if (!revisions.length) {
			panel.textContent = config.labels.historyEmpty;
			noteItem.appendChild(panel);
			return;
		}

		var heading = document.createElement('p');
		heading.className = 'awts_cou_note__history_heading';
		heading.textContent = config.labels.historyHeading;
		panel.appendChild(heading);

		var list = document.createElement('ol');
		list.className = 'awts_cou_note__history_list';

		revisions.forEach(function (rev) {
			var li = document.createElement('li');
			li.className = 'awts_cou_note__history_item';

			var meta = document.createElement('p');
			meta.className = 'awts_cou_note__history_meta';
			var who = rev.edited_by_name ? String(rev.edited_by_name) : '';
			meta.textContent = (who ? who + ' · ' : '') + (rev.edited_at || '');
			li.appendChild(meta);

			var body = document.createElement('blockquote');
			body.className = 'awts_cou_note__history_body';
			body.textContent = String(rev.prior_note || '');
			li.appendChild(body);

			list.appendChild(li);
		});

		panel.appendChild(list);
		noteItem.appendChild(panel);
	}

	function bindReplyForms() {
		var forms = document.querySelectorAll('[data-awts-cou-reply-form]');

		for (var i = 0; i < forms.length; i++) {
			setupReplyForm(forms[i]);
		}
	}

	function setupReplyForm(replyForm) {
		var updateId = parseInt(replyForm.getAttribute('data-awts-cou-update-id'), 10);
		var messageField = replyForm.querySelector('[data-awts-cou-reply-message]');
		var filesInput = replyForm.querySelector('[data-awts-cou-reply-files]');
		var fileListEl = replyForm.querySelector('[data-awts-cou-reply-file-list]');
		var submitBtn = replyForm.querySelector('[data-awts-cou-reply-submit]');
		var feedbackEl = replyForm.querySelector('[data-awts-cou-reply-feedback]');
		var defaultSubmitLabel = submitBtn ? submitBtn.textContent : '';

		if (!updateId || !messageField || !submitBtn) {
			return;
		}

		submitBtn.setAttribute('data-awts-default-label', defaultSubmitLabel);

		var fileAccumulator = null;

		if (filesInput) {
			fileAccumulator = makeFileAccumulator(filesInput, function () {
				renderReplyFileList(filesInput, fileListEl, function (index) {
					if (fileAccumulator) {
						fileAccumulator.removeAt(index);
					}
				});
				validateReplyFiles(filesInput, feedbackEl);
			});

			setupDropZone(replyForm, filesInput);
		}

		messageField.addEventListener('keydown', function (e) {
			// Older WebKit (pre-modern Safari) reported 'Up' instead of
			// 'ArrowUp', and some keyboard layouts on iPad still do.
			// Match both names plus the keyCode fallback so this works on
			// every browser the customer might land in.
			var isArrowUp = e.key === 'ArrowUp' || e.key === 'Up' || e.keyCode === 38 || e.which === 38;
			if (! isArrowUp) return;
			// Master gate — short-circuit if the admin has disabled note
			// editing in Restricted settings. Without this, a tab opened
			// before the toggle flipped would still trigger the edit flow
			// from cached DOM and the server would 403 on save.
			if (! config.allowNoteEdit) return;
			if (messageField.value.trim() !== '') return;
			if (replyForm.getAttribute('data-awts-cou-editing-note-id')) return;

			var notesWrap = replyForm.closest('.awts_cou_notes');
			var editBtns = notesWrap ? notesWrap.querySelectorAll('[data-awts-cou-note-edit]') : [];
			var lastBtn = editBtns.length ? editBtns[editBtns.length - 1] : null;

			if (!lastBtn) return;

			e.preventDefault();
			handleNoteEdit(lastBtn);
		});

		replyForm.addEventListener('submit', function (event) {
			event.preventDefault();
			submitReply();
		});

		function submitReply() {
			var message = messageField.value.trim();
			var editingNoteId = parseInt(replyForm.getAttribute('data-awts-cou-editing-note-id') || '0', 10);

			if (!message) {
				setReplyFeedback(feedbackEl, config.labels.messageRequired, 'error');
				messageField.focus();
				return;
			}

			if (editingNoteId) {
				submitEditedReply(editingNoteId, message);
				return;
			}

			if (!validateReplyFiles(filesInput, feedbackEl)) {
				return;
			}

			var payload = new FormData();
			payload.append('order_id', String(config.orderId));
			payload.append('update_id', String(updateId));
			payload.append('message', message);

			if (config.orderKey) {
				payload.append('order_key', config.orderKey);
			}

			if (filesInput && filesInput.files) {
				for (var i = 0; i < filesInput.files.length; i++) {
					payload.append('files[]', filesInput.files[i]);
				}
			}

			submitBtn.disabled = true;
			submitBtn.textContent = config.labels.submitting;
			setReplyFeedback(feedbackEl, '', null);

			fetch(config.restUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': config.nonce },
				body: payload,
			})
				.then(function (response) {
					return response.json().then(function (body) {
						return { ok: response.ok, body: body };
					});
				})
				.then(function (result) {
					if (result.ok) {
						setReplyFeedback(feedbackEl, result.body.message || config.labels.replySuccess, 'success');
						if (result.body && result.body.note) {
							appendCustomerNote(replyForm, result.body.note, updateId);
						}
						messageField.value = '';
						if (fileAccumulator) {
							fileAccumulator.reset();
						} else if (filesInput) {
							filesInput.value = '';
						}
						if (fileListEl) {
							fileListEl.innerHTML = '';
						}
						submitBtn.disabled = false;
						submitBtn.textContent = defaultSubmitLabel;
						return;
					}

					var msg = getRequestErrorMessage(result.body);
					setReplyFeedback(feedbackEl, msg, 'error');
					submitBtn.disabled = false;
					submitBtn.textContent = defaultSubmitLabel;
				})
				.catch(function () {
					setReplyFeedback(feedbackEl, config.labels.genericFail, 'error');
					submitBtn.disabled = false;
					submitBtn.textContent = defaultSubmitLabel;
				});
		}

		function submitEditedReply(noteId, message) {
			var payload = new FormData();
			payload.append('note', message);

			if (config.orderKey) {
				payload.append('order_key', config.orderKey);
			}

			submitBtn.disabled = true;
			submitBtn.textContent = config.labels.submitting;
			setReplyFeedback(feedbackEl, '', null);

			fetch(config.customerNotesEndpointBase + updateId + '/customer-notes/' + noteId, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': config.nonce },
				body: payload,
			})
				.then(function (response) {
					return response.json().then(function (body) {
						return { ok: response.ok, body: body };
					});
				})
				.then(function (result) {
					if (result.ok) {
						setReplyFeedback(feedbackEl, result.body.message || config.labels.noteUpdated, 'success');
						updateCustomerNoteElement(noteId, message, result.body && result.body.note ? result.body.note : null);
						resetReplyEdit(replyForm, messageField, submitBtn, feedbackEl);
						return;
					}

					var msg = getRequestErrorMessage(result.body);
					setReplyFeedback(feedbackEl, msg, 'error');
					submitBtn.disabled = false;
					submitBtn.textContent = config.labels.saveNoteAction;
				})
				.catch(function () {
					setReplyFeedback(feedbackEl, config.labels.genericFail, 'error');
					submitBtn.disabled = false;
					submitBtn.textContent = config.labels.saveNoteAction;
				});
		}
	}

	function resetReplyEdit(replyForm, messageField, submitBtn, feedbackEl) {
		replyForm.removeAttribute('data-awts-cou-editing-note-id');
		messageField.value = '';
		submitBtn.textContent = submitBtn.getAttribute('data-awts-default-label') || submitBtn.textContent;

		var cancelButton = replyForm.querySelector('[data-awts-cou-edit-cancel]');
		if (cancelButton) {
			cancelButton.hidden = true;
		}

		if (feedbackEl) {
			setReplyFeedback(feedbackEl, '', null);
		}
	}

	function appendCustomerNote(replyForm, note, updateId) {
		var notesWrap = replyForm.closest('.awts_cou_notes');

		if (!notesWrap) {
			return;
		}

		var empty = notesWrap.querySelector('.awts_cou_notes__empty');
		if (empty) {
			empty.remove();
		}

		var article = createCustomerNoteElement(note, updateId);
		notesWrap.insertBefore(article, replyForm);
		setStoredReadId(updateId, parseInt(note.id || '0', 10));
		article.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	}

	function updateCustomerNoteElement(noteId, message, note) {
		var noteItem = document.querySelector('.awts_cou_note[data-awts-note-id="' + noteId + '"]');

		if (!noteItem) {
			return;
		}

		var body = noteItem.querySelector('.awts_cou_note__body');
		if (body) {
			body.textContent = message;
		}

		noteItem.setAttribute('data-awts-note-text', message);

		var meta = noteItem.querySelector('.awts_cou_note__meta');
		var editedAt = note && (note.edited_at || note.editedAt) ? String(note.edited_at || note.editedAt) : '';

		if (! meta || ! editedAt) {
			return;
		}

		var updateId = 0;
		var article = noteItem.closest('[data-awts-cou-update-id]');

		if (article) {
			updateId = parseInt(article.getAttribute('data-awts-cou-update-id') || '0', 10);
		}

		var existing = meta.querySelector('.awts_cou_note__edited');

		if (existing) {
			existing.textContent = (config.labels.editedLabel || '(edited)') + ' ' + editedAt;
			return;
		}

		meta.appendChild(createCustomerHistoryButton(updateId, editedAt));

		// A previously open history panel reflects pre-edit state — drop it so
		// the next click refetches the now-longer revision list.
		var openPanel = noteItem.querySelector('.awts_cou_note__history');

		if (openPanel) {
			openPanel.parentNode.removeChild(openPanel);
		}
	}

	function createSystemNoteElement(note) {
		var article = document.createElement('article');
		var noteId = parseInt(note.id || '0', 10);
		var color = String(note.status || '#94a3b8');

		article.className = 'awts_cou_note awts_cou_note--system';
		article.setAttribute('data-awts-note-id', String(noteId));

		var dot = document.createElement('span');
		dot.className = 'awts_cou_system__dot';
		dot.style.background = color;
		article.appendChild(dot);

		var text = document.createElement('span');
		text.className = 'awts_cou_system__text';
		text.textContent = String(note.note || '');
		article.appendChild(text);

		var when = String(note.createdAt || note.created_at || '');
		if (when) {
			var meta = document.createElement('span');
			meta.className = 'awts_cou_system__meta';
			meta.textContent = when;
			article.appendChild(meta);
		}

		return article;
	}

	function createCustomerNoteElement(note, updateId) {
		// Status-change rows are system events, not real messages — render
		// them as a compact centered marker so they don't break the chat
		// rhythm. Everything below is the normal bubble layout.
		if (note.is_system || note.isSystem) {
			return createSystemNoteElement(note);
		}

		var article = document.createElement('article');
		var noteId = parseInt(note.id || '0', 10);
		var canEdit = !!(note.canEdit || note.can_edit);
		var text = String(note.note || '');
		var editedAt = String(note.editedAt || note.edited_at || '');

		var isStaff = !!(note.is_staff || note.isStaff);
		var avatarUrl = String(note.avatar_url || note.avatarUrl || '');
		article.className = 'awts_cou_note awts_cou_note--' + (isStaff ? 'staff' : 'customer');
		article.setAttribute('data-awts-note-id', String(noteId));
		article.setAttribute('data-awts-note-text', text);

		if (isStaff && avatarUrl) {
			var avatar = document.createElement('img');
			avatar.className = 'awts_cou_note__avatar';
			avatar.src = avatarUrl;
			avatar.alt = '';
			avatar.width = 28;
			avatar.height = 28;
			avatar.loading = 'lazy';
			article.appendChild(avatar);
		}

		var bubble = document.createElement('div');
		bubble.className = 'awts_cou_note__bubble';

		var header = document.createElement('header');
		header.className = 'awts_cou_note__header';

		var meta = document.createElement('div');
		meta.className = 'awts_cou_note__meta';

		var author = document.createElement('span');
		author.className = 'awts_cou_note__author';
		// Use the server-masked label (author_display = "By {store}" when the
		// "Show assignee to customers" setting is off). Never fall back to the
		// raw created_by_name for a staff note — that would leak on live refresh
		// what the page render hides. The raw name is only the customer's own.
		author.textContent = String(note.author_display || note.authorDisplay || (isStaff ? '' : note.created_by_name) || '');

		var time = document.createElement('time');
		time.className = 'awts_cou_note__time';
		time.textContent = String(note.createdAt || note.created_at || '');

		meta.appendChild(author);
		meta.appendChild(time);

		if (editedAt) {
			meta.appendChild(createCustomerHistoryButton(updateId, editedAt));
		}

		header.appendChild(meta);

		if (canEdit) {
			var actions = document.createElement('div');
			actions.className = 'awts_cou_note__actions';
			actions.appendChild(createCustomerEditButton(updateId));
			header.appendChild(actions);
		}

		var body = document.createElement('div');
		body.className = 'awts_cou_note__body';
		body.textContent = text;

		bubble.appendChild(header);
		bubble.appendChild(body);

		var attachments = Array.isArray(note.attachments) ? note.attachments : [];

		if (attachments.length) {
			bubble.appendChild(buildAttachmentList(attachments));
		}

		article.appendChild(bubble);

		return article;
	}

	function buildAttachmentList(attachments) {
		var ul = document.createElement('ul');
		ul.className = 'awts_cou_attachments';

		attachments.forEach(function (att) {
			var li  = document.createElement('li');
			var a   = document.createElement('a');

			a.href      = String(att.url || att.serve_url || '');
			a.target    = '_blank';
			a.rel       = 'noopener noreferrer';
			a.className = 'awts_cou_attachment__link';

			if (att.is_image) {
				var img        = document.createElement('img');
				img.className  = 'awts_cou_attachment__thumb';
				img.src        = a.href;
				img.alt        = String(att.name || '');
				img.loading    = 'lazy';
				a.appendChild(img);
			} else {
				var icon = document.createElement('span');
				icon.className   = 'awts_cou_attachment__icon';
				icon.setAttribute('aria-hidden', 'true');
				icon.innerHTML   = '<svg width="18" height="18" viewBox="0 0 16 16" fill="none"><path d="M9 1H3.5A1.5 1.5 0 0 0 2 2.5v11A1.5 1.5 0 0 0 3.5 15h9a1.5 1.5 0 0 0 1.5-1.5V6L9 1Z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/><path d="M9 1v5h5" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>';
				a.appendChild(icon);
			}

			var name = document.createElement('span');
			name.className   = 'awts_cou_attachment__name';
			name.textContent = String(att.name || '');
			a.appendChild(name);

			li.appendChild(a);
			ul.appendChild(li);
		});

		return ul;
	}

	function createCustomerHistoryButton(updateId, editedAt) {
		var button = document.createElement('button');
		button.type = 'button';
		button.className = 'awts_cou_note__edited';
		button.setAttribute('data-awts-cou-note-history', 'true');
		button.setAttribute('data-awts-update-id', String(updateId));
		button.setAttribute('aria-expanded', 'false');
		button.textContent = (config.labels.editedLabel || '(edited)') + ' ' + editedAt;
		button.addEventListener('click', function () {
			handleNoteHistory(button);
		});

		return button;
	}

	function createCustomerEditButton(updateId) {
		var button = document.createElement('button');
		button.type = 'button';
		button.className = 'awts_cou_note__action awts_cou_note__action--icon';
		button.setAttribute('data-awts-cou-note-edit', 'true');
		button.setAttribute('data-awts-update-id', String(updateId));
		button.setAttribute('aria-label', config.labels.editNoteAction);
		button.setAttribute('title', config.labels.editNoteAction);
		button.innerHTML = '<svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true" focusable="false"><path d="M13.8 2.9a2.1 2.1 0 0 1 3 3L7.3 15.4 3 17l1.6-4.3 9.2-9.8Zm1 1-8.9 9.5-.7 1.8 1.8-.7 8.9-9.5a.7.7 0 0 0-1-1Z" fill="currentColor"/></svg>';
		button.addEventListener('click', function () {
			handleNoteEdit(button);
		});

		return button;
	}

	function renderReplyFileList(input, listEl, onRemove) {
		if (!listEl || !input) {
			return;
		}

		listEl.innerHTML = '';

		var files = input.files ? Array.prototype.slice.call(input.files) : [];

		files.forEach(function (file, index) {
			var li = document.createElement('li');
			li.className = 'awts_cou_file_list__item';

			var nameEl = document.createElement('span');
			nameEl.className = 'awts_cou_file_list__name';
			nameEl.textContent = file.name;

			var sizeEl = document.createElement('span');
			sizeEl.className = 'awts_cou_file_list__size';
			sizeEl.textContent = formatBytes(file.size);

			li.appendChild(nameEl);
			li.appendChild(sizeEl);

			if (typeof onRemove === 'function') {
				var removeBtn = document.createElement('button');
				removeBtn.type = 'button';
				removeBtn.className = 'awts_cou_file_list__remove';
				removeBtn.setAttribute('aria-label', config.labels.removeFile || 'Remove');
				removeBtn.title = config.labels.removeFile || 'Remove';
				removeBtn.textContent = '×';
				removeBtn.addEventListener('click', function () {
					onRemove(index);
				});
				li.appendChild(removeBtn);
			}

			listEl.appendChild(li);
		});
	}

	function validateReplyFiles(input, feedbackEl) {
		if (!input || !input.files) {
			return true;
		}

		var files = input.files;

		if (files.length > config.maxFiles) {
			setReplyFeedback(feedbackEl, interpolate(config.labels.tooManyFiles, [ config.maxFiles ]), 'error');
			return false;
		}

		for (var i = 0; i < files.length; i++) {
			var file = files[i];

			if (file.size > config.maxBytes) {
				setReplyFeedback(feedbackEl, interpolate(config.labels.attachmentTooLarge, [ formatBytes(config.maxBytes) ]), 'error');
				return false;
			}

			if (acceptMime.length && file.type && acceptMime.indexOf(file.type) === -1) {
				setReplyFeedback(feedbackEl, config.labels.attachmentUnsupported, 'error');
				return false;
			}
		}

		// Clear stale "unsupported"/"too large" feedback once the offending
		// file is gone — otherwise the form keeps looking like it's broken.
		clearStaleFileFeedback(feedbackEl);
		return true;
	}

	function setReplyFeedback(el, text, tone) {
		if (!el) {
			return;
		}

		el.textContent = text || '';
		el.className = 'awts_cou_reply__feedback';

		if (tone) {
			el.classList.add('awts_cou_reply__feedback--' + tone);
		}
	}

	// ---------- Email notification preference ---------- //
	function bindEmailPref() {
		var checkbox = document.querySelector('[data-awts-cou-email-pref]');

		if (!checkbox || !config.emailPrefUrl) {
			return;
		}

		checkbox.addEventListener('change', function () {
			var enabled = checkbox.checked;
			var payload = new FormData();
			payload.append('order_id', String(config.orderId));
			payload.append('enabled', enabled ? '1' : '0');

			if (config.orderKey) {
				payload.append('order_key', config.orderKey);
			}

			fetch(config.emailPrefUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': config.nonce },
				body: payload,
			}).catch(function () {
				// Silently revert on failure.
				checkbox.checked = !enabled;
			});
		});
	}

	// ---------- Star rating per resolved update ---------- //
	function bindRatingForms() {
		var forms = document.querySelectorAll('[data-awts-cou-rating-form]');

		for (var i = 0; i < forms.length; i++) {
			setupRatingForm(forms[i]);
		}
	}

	function setupRatingForm(form) {
		var updateId = parseInt(form.getAttribute('data-awts-cou-update-id'), 10);
		var stars = form.querySelectorAll('[data-awts-cou-rating-star]');
		var hidden = form.querySelector('[data-awts-cou-rating-value]');
		var commentField = form.querySelector('[data-awts-cou-rating-comment]');
		var submitBtn = form.querySelector('[data-awts-cou-rating-submit]');
		var feedbackEl = form.querySelector('[data-awts-cou-rating-feedback]');
		var defaultSubmitLabel = submitBtn ? submitBtn.textContent : '';

		if (!updateId || !stars.length || !hidden || !submitBtn) {
			return;
		}

		stars.forEach(function (star, idx) {
			star.addEventListener('click', function () {
				selectRating(stars, hidden, parseInt(star.getAttribute('data-awts-cou-rating-star'), 10));
				star.focus();
			});

			star.addEventListener('keydown', function (event) {
				var key = event.key;

				if (key === 'ArrowRight' || key === 'ArrowDown') {
					event.preventDefault();
					var nextIdx = (idx + 1) % stars.length;
					selectRating(stars, hidden, parseInt(stars[nextIdx].getAttribute('data-awts-cou-rating-star'), 10));
					stars[nextIdx].focus();
				} else if (key === 'ArrowLeft' || key === 'ArrowUp') {
					event.preventDefault();
					var prevIdx = (idx - 1 + stars.length) % stars.length;
					selectRating(stars, hidden, parseInt(stars[prevIdx].getAttribute('data-awts-cou-rating-star'), 10));
					stars[prevIdx].focus();
				} else if (key === ' ' || key === 'Enter') {
					event.preventDefault();
					selectRating(stars, hidden, parseInt(star.getAttribute('data-awts-cou-rating-star'), 10));
				}
			});
		});

		form.addEventListener('submit', function (event) {
			event.preventDefault();
			submitRating();
		});

		function submitRating() {
			var value = parseInt(hidden.value, 10) || 0;

			if (value < 1 || value > 5) {
				setRatingFeedback(feedbackEl, config.labels.ratingMissing, 'error');
				return;
			}

			var payload = {
				stars: value,
				comment: commentField ? commentField.value.trim() : '',
			};

			if (config.orderKey) {
				payload.order_key = config.orderKey;
			}

			submitBtn.disabled = true;
			submitBtn.textContent = config.labels.ratingSubmitting;
			setRatingFeedback(feedbackEl, '', null);

			fetch(config.ratingUrlBase + updateId + '/rating', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'X-WP-Nonce': config.nonce,
					'Content-Type': 'application/json',
				},
				body: JSON.stringify(payload),
			})
				.then(function (response) {
					return response.json().then(function (body) {
						return { ok: response.ok, body: body };
					});
				})
				.then(function (result) {
					if (result.ok) {
						replaceWithThanks(form, value, payload.comment);
						return;
					}

					var msg = getRequestErrorMessage(result.body) || config.labels.ratingSaveFailed;
					setRatingFeedback(feedbackEl, msg, 'error');
					submitBtn.disabled = false;
					submitBtn.textContent = defaultSubmitLabel;
				})
				.catch(function () {
					setRatingFeedback(feedbackEl, config.labels.ratingSaveFailed || config.labels.genericFail, 'error');
					submitBtn.disabled = false;
					submitBtn.textContent = defaultSubmitLabel;
				});
		}
	}

	function selectRating(stars, hidden, value) {
		if (value < 1) {
			value = 1;
		}

		if (value > stars.length) {
			value = stars.length;
		}

		hidden.value = String(value);

		stars.forEach(function (star) {
			var starValue = parseInt(star.getAttribute('data-awts-cou-rating-star'), 10);
			var checked = starValue === value;
			var filled = starValue <= value;

			star.setAttribute('aria-checked', checked ? 'true' : 'false');
			star.setAttribute('tabindex', checked ? '0' : '-1');
			star.classList.toggle('awts_cou_rating__star_btn--filled', filled);
		});
	}

	function replaceWithThanks(form, stars, comment) {
		var notesContainer = form.parentNode;
		var wrapper = document.createElement('div');
		wrapper.className = 'awts_cou_rating awts_cou_rating--submitted';

		var thanksTemplate = config.labels.ratingThanks || '';
		var thanks = document.createElement('p');
		thanks.className = 'awts_cou_rating__thanks';
		thanks.textContent = interpolate(thanksTemplate, [ stars ]);
		wrapper.appendChild(thanks);

		var starsRow = document.createElement('div');
		starsRow.className = 'awts_cou_rating__stars';

		for (var i = 1; i <= 5; i++) {
			var span = document.createElement('span');
			span.className = 'awts_cou_rating__star' + (i <= stars ? ' awts_cou_rating__star--filled' : '');
			span.setAttribute('aria-hidden', 'true');
			span.textContent = '★';
			starsRow.appendChild(span);
		}

		wrapper.appendChild(starsRow);

		if (comment) {
			var quote = document.createElement('blockquote');
			quote.className = 'awts_cou_rating__comment';
			quote.textContent = comment;
			wrapper.appendChild(quote);
		}

		notesContainer.replaceChild(wrapper, form);

		// Thread is now rated and locked — hide the reply composer.
		var replyForm = notesContainer.querySelector('[data-awts-cou-reply-form]');
		if (replyForm) {
			replyForm.hidden = true;
		}

		// And drop the "Still has issue?" button — once rated, the reopen
		// endpoint will 409 anyway, so leaving the button visible just sets
		// the customer up for a confusing error.
		var reopenBtn = notesContainer.querySelector('[data-awts-cou-reopen]');
		if (reopenBtn) {
			reopenBtn.parentNode.removeChild(reopenBtn);
		}
	}

	function setRatingFeedback(el, text, tone) {
		if (!el) {
			return;
		}

		el.textContent = text || '';
		el.className = 'awts_cou_rating__feedback';

		if (tone) {
			el.classList.add('awts_cou_rating__feedback--' + tone);
		}
	}

	// ---------- Emoji picker (customer reply form) ---------- //

	function bindEmojiPickers() {
		// Defined inside the function so it's available even when the early
		// `return` for the missing new-update modal short-circuits the rest
		// of the IIFE on guest URLs.
		var emojiPickerGroups = [
			{ label: 'Smileys', items: [ '\u{1F642}', '\u{1F600}', '\u{1F602}', '\u{1F605}', '\u{1F609}', '\u{1F60A}', '\u{1F60D}', '\u{1F618}', '\u{1F60E}', '\u{1F914}', '\u{1F644}', '\u{1F60F}', '\u{1F612}', '\u{1F615}', '\u{1F641}', '\u{1F622}', '\u{1F62D}', '\u{1F621}', '\u{1F631}', '\u{1F607}', '\u{1F973}', '\u{1F929}', '\u{1F92F}', '\u{1F913}' ] },
			{ label: 'Hands',   items: [ '\u{1F44D}', '\u{1F44E}', '\u{1F44C}', '\u{1F44B}', '\u{1F44F}', '\u{1F64C}', '\u{1F64F}', '\u{1F91D}', '\u{1F4AA}', '\u{1F91E}', '\u{270C}\u{FE0F}', '\u{1F918}', '\u{261D}\u{FE0F}', '\u{1F449}' ] },
			{ label: 'Symbols', items: [ '\u{2764}\u{FE0F}', '\u{1F494}', '\u{1F525}', '\u{2B50}', '\u{2728}', '\u{26A1}', '\u{1F4A5}', '\u{1F389}', '\u{1F3C6}', '\u{1F451}', '\u{1F4A1}', '\u{1F4AF}', '\u{2705}', '\u{274C}', '\u{26A0}\u{FE0F}', '\u{1F6D1}', '\u{2753}', '\u{2757}', '\u{1F534}', '\u{1F7E2}', '\u{1F7E1}' ] },
			{ label: 'Objects', items: [ '\u{1F680}', '\u{1F4E6}', '\u{1F4DD}', '\u{1F4CB}', '\u{1F4CC}', '\u{1F517}', '\u{1F527}', '\u{2699}\u{FE0F}', '\u{1F512}', '\u{1F511}', '\u{1F4E7}', '\u{1F514}', '\u{1F4B0}', '\u{1F4BB}', '\u{1F4F1}', '\u{2615}', '\u{23F0}', '\u{1F4C5}', '\u{1F4C8}', '\u{1F41B}', '\u{1F3C1}' ] },
		];

		function buildEmojiPickerHtml() {
			var html = '<div class="awts_cou_emoji_picker" role="dialog" aria-label="Emoji picker">';
			emojiPickerGroups.forEach(function (group) {
				html += '<div class="awts_cou_emoji_group_label">' + group.label + '</div>';
				html += '<div class="awts_cou_emoji_grid">';
				group.items.forEach(function (emoji) {
					html += '<button type="button" class="awts_cou_emoji_item" aria-label="' + emoji + '" data-awts-emoji="' + emoji + '">' + emoji + '</button>';
				});
				html += '</div>';
			});
			html += '</div>';
			return html;
		}

		document.addEventListener('click', function (event) {
			var trigger = event.target.closest('[data-awts-cou-emoji-trigger]');
			var emojiBtn = event.target.closest('.awts_cou_emoji_item');
			var existingPicker = document.querySelector('.awts_cou_emoji_picker');

			if (emojiBtn) {
				// Insert emoji into the textarea in the same reply form.
				var reply = (existingPicker && existingPicker._awtsOwnerForm)
					? existingPicker._awtsOwnerForm
					: emojiBtn.closest('.awts_cou_reply');
				if (reply) {
					var textarea = reply.querySelector('textarea');
					if (textarea) {
						var start = textarea.selectionStart || 0;
						var end   = textarea.selectionEnd || 0;
						var val   = textarea.value;
						var emoji = emojiBtn.getAttribute('data-awts-emoji') || emojiBtn.textContent.trim();
						if (!emoji) { return; }
						textarea.value = val.substring(0, start) + emoji + val.substring(end);
						textarea.selectionStart = textarea.selectionEnd = start + emoji.length;
						textarea.focus();
					}
				}
				if (existingPicker) existingPicker.parentNode.removeChild(existingPicker);
				return;
			}

			if (trigger) {
				event.preventDefault();
				if (existingPicker) {
					existingPicker.parentNode.removeChild(existingPicker);
					if (existingPicker._awtsOwnerForm === trigger.closest('[data-awts-cou-reply-form]')) {
						return;
					}
				}
				var dummy = document.createElement('div');
				dummy.innerHTML = buildEmojiPickerHtml();
				var picker = dummy.firstChild;
				picker._awtsOwnerForm = trigger.closest('[data-awts-cou-reply-form]');
				document.body.appendChild(picker);

				var rect = trigger.getBoundingClientRect();
				var pickerH = 320;
				// position:fixed is relative to the viewport, so use raw getBoundingClientRect()
				// values without adding scroll offsets.
				var top = (rect.top - pickerH - 8 >= 0)
					? (rect.top - pickerH - 8)
					: (rect.bottom + 8);
				var left = Math.min(
					rect.left,
					window.innerWidth - 268
				);
				picker.style.top  = top + 'px';
				picker.style.left = left + 'px';
				return;
			}

			// Click outside — close any open picker.
			if (existingPicker && !existingPicker.contains(event.target)) {
				existingPicker.parentNode.removeChild(existingPicker);
			}
		});
	}

	// ---------- Assignee-change system dividers (Item 6) ---------- //

	function injectAssigneeDividers() {
		document.querySelectorAll('[data-awts-assignee-since]').forEach(function (item) {
			var sinceNoteId  = parseInt(item.getAttribute('data-awts-assignee-since') || '0', 10);
			var assigneeName = item.getAttribute('data-awts-assignee-name') || '';

			if (!sinceNoteId || !assigneeName) {
				return;
			}

			var anchor = item.querySelector('[data-awts-note-id="' + sinceNoteId + '"]');
			if (!anchor || !anchor.parentNode) {
				return;
			}

			var divider = document.createElement('div');
			divider.className = 'awts_cou_system_msg';
			divider.textContent = interpolate(
				'\u{1F464} We’ve re-assigned this to %s.',
				[ assigneeName ]
			);

			var intro = document.createElement('div');
			intro.className = 'awts_cou_system_msg awts_cou_system_msg--intro';
			intro.textContent = interpolate(
				'Hi, I’m %s — I’m looking into this for you.',
				[ assigneeName ]
			);

			anchor.parentNode.insertBefore(divider, anchor.nextSibling);
			divider.parentNode.insertBefore(intro, divider.nextSibling);
		});
	}

	// ---------- Resolved-state injection for Bug 2 ---------- //

	function buildRatingForm(updateId) {
		var form = document.createElement('form');
		form.className = 'awts_cou_rating awts_cou_rating--form';
		form.setAttribute('data-awts-cou-rating-form', '');
		form.setAttribute('data-awts-cou-update-id', String(updateId));
		form.setAttribute('novalidate', '');

		var h3 = document.createElement('h3');
		h3.className = 'awts_cou_rating__heading';
		h3.textContent = config.labels.ratingHeading || 'How did we do?';
		form.appendChild(h3);

		var introP = document.createElement('p');
		introP.className = 'awts_cou_rating__intro';
		introP.textContent = config.labels.ratingIntro || '';
		form.appendChild(introP);

		var starsRow = document.createElement('div');
		starsRow.className = 'awts_cou_rating__stars';
		starsRow.setAttribute('role', 'radiogroup');
		starsRow.setAttribute('aria-label', config.labels.ratingHeading || '');

		for (var i = 1; i <= 5; i++) {
			var starBtn = document.createElement('button');
			starBtn.type = 'button';
			starBtn.className = 'awts_cou_rating__star_btn';
			starBtn.setAttribute('role', 'radio');
			starBtn.setAttribute('aria-checked', 'false');
			starBtn.setAttribute('tabindex', i === 1 ? '0' : '-1');
			starBtn.setAttribute('data-awts-cou-rating-star', String(i));
			starBtn.setAttribute('aria-label', i === 1
				? (config.labels.ratingStar1Label || '1 star')
				: interpolate(config.labels.ratingStarLabel || '%d stars', [i]));
			starBtn.textContent = '★';
			starsRow.appendChild(starBtn);
		}
		form.appendChild(starsRow);

		var hiddenInput = document.createElement('input');
		hiddenInput.type = 'hidden';
		hiddenInput.name = 'stars';
		hiddenInput.value = '0';
		hiddenInput.setAttribute('data-awts-cou-rating-value', '');
		form.appendChild(hiddenInput);

		if (config.rating && config.rating.comment_enabled) {
			var commentLabel = document.createElement('label');
			commentLabel.className = 'awts_cou_rating__comment_label';
			var labelSpan = document.createElement('span');
			labelSpan.className = 'awts_cou_field__label';
			labelSpan.textContent = config.labels.ratingCommentLabel || 'Comment (optional)';
			var commentArea = document.createElement('textarea');
			commentArea.rows = 3;
			commentArea.maxLength = 500;
			commentArea.placeholder = config.labels.ratingCommentPh || '';
			commentArea.setAttribute('data-awts-cou-rating-comment', '');
			commentLabel.appendChild(labelSpan);
			commentLabel.appendChild(commentArea);
			form.appendChild(commentLabel);
		}

		var toolbar = document.createElement('div');
		toolbar.className = 'awts_cou_rating__toolbar';
		var submitBtn = document.createElement('button');
		submitBtn.type = 'submit';
		submitBtn.className = 'awts_cou_btn awts_cou_btn--primary';
		submitBtn.setAttribute('data-awts-cou-rating-submit', '');
		submitBtn.textContent = config.labels.ratingSubmitLabel || 'Submit rating';
		toolbar.appendChild(submitBtn);
		form.appendChild(toolbar);

		var feedbackEl = document.createElement('div');
		feedbackEl.className = 'awts_cou_rating__feedback';
		feedbackEl.setAttribute('data-awts-cou-rating-feedback', '');
		feedbackEl.setAttribute('aria-live', 'polite');
		form.appendChild(feedbackEl);

		setupRatingForm(form);
		return form;
	}

	function markUpdateResolved(updateId) {
		var item = document.querySelector('[data-awts-update-id="' + updateId + '"]');
		if (!item || item.classList.contains('awts_cou_item--resolved')) {
			return;
		}

		item.classList.add('awts_cou_item--resolved');

		var replyForm = item.querySelector('[data-awts-cou-reply-form]');
		if (replyForm) {
			replyForm.hidden = true;
		}

		// The "Still has issue?" escape hatch: if no rating has been left
		// yet, drop a button into the notes container so the customer can
		// re-open without staff involvement. Skipped after a rating exists
		// (post-rating thread is truly closed).
		ensureReopenButton(item, updateId);

		if (!(config.rating && config.rating.enabled)) {
			return;
		}

		var notesContainer = item.querySelector('.awts_cou_notes');
		if (!notesContainer || notesContainer.querySelector('[data-awts-cou-rating-form]')) {
			return;
		}

		notesContainer.appendChild(buildRatingForm(updateId));

		// Bring the freshly-revealed rating box into view — it lands at the foot
		// of the thread, so the customer would otherwise have to scroll to find
		// it. Runs only on the live solve (this path is guarded as idempotent).
		var ratingForm = notesContainer.querySelector('[data-awts-cou-rating-form]');
		if (ratingForm) {
			window.requestAnimationFrame(function () {
				ratingForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
			});
		}
	}

	// Injects the "Still has issue?" reopen button into the notes container
	// for $item, if one is not already mounted. Idempotent so the poll loop
	// + the server-rendered HTML don't double up.
	function ensureReopenButton(item, updateId) {
		var notesContainer = item.querySelector('.awts_cou_notes');
		if (!notesContainer || notesContainer.querySelector('[data-awts-cou-reopen]')) {
			return;
		}

		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'awts_cou_reopen_btn';
		btn.setAttribute('data-awts-cou-reopen', '');
		btn.setAttribute('data-awts-cou-update-id', String(updateId));
		btn.textContent = (config.labels && config.labels.reopenButton) || 'Still has issue?';

		// Slot the button immediately before any rating form/snapshot so the
		// rating CTA stays the visually dominant element and the "Still has
		// issue?" sits adjacent to it as a clear secondary action.
		var ratingNode = notesContainer.querySelector('[data-awts-cou-rating-form], .awts_cou_rating--submitted');
		if (ratingNode) {
			notesContainer.insertBefore(btn, ratingNode);
		} else {
			notesContainer.appendChild(btn);
		}
	}

	// Inverse of markUpdateResolved — invoked from the poll loop when an
	// update we previously rendered as resolved is now open again (admin hit
	// "Re-open"). Drops the rating form, restores the reply composer, and
	// strips the resolved-state class. Idempotent: only acts when the item
	// is currently in resolved state, otherwise no-ops so it's safe to call
	// every poll for every open update.
	function markUpdateReopened(updateId) {
		var item = document.querySelector('[data-awts-update-id="' + updateId + '"]');
		if (!item || !item.classList.contains('awts_cou_item--resolved')) {
			return;
		}

		item.classList.remove('awts_cou_item--resolved');

		// Drop any rating UI we mounted on resolve. Covers both the unrated
		// form and the post-submit "thanks" wrapper — neither should linger
		// once the thread is open again. Same for the "Still has issue?"
		// button — it has no purpose once the update is open.
		item.querySelectorAll(
			'[data-awts-cou-rating-form], .awts_cou_rating--submitted, [data-awts-cou-reopen]'
		).forEach(function (el) {
			if (el.parentNode) {
				el.parentNode.removeChild(el);
			}
		});

		var replyForm = item.querySelector('[data-awts-cou-reply-form]');
		if (replyForm) {
			replyForm.hidden = false;
		}
	}

	// Click handler for the "Still has issue?" button. POSTs to the existing
	// reopen endpoint (which gates on no-rating-exists, matching the
	// $can_reopen check in the view template) and on success flips the UI
	// back to compose mode via markUpdateReopened. Errors surface inline
	// as button text so the customer sees what happened without a modal.
	function handleReopenClick(event) {
		var btn = event.target.closest('[data-awts-cou-reopen]');
		if (!btn) {
			return;
		}

		var updateId = parseInt(btn.getAttribute('data-awts-cou-update-id') || '0', 10);
		if (!updateId) {
			return;
		}

		var originalLabel = btn.textContent;
		btn.disabled       = true;
		btn.textContent    = (config.labels && config.labels.reopenSubmitting) || 'Re-opening…';

		var url = config.ratingUrlBase + updateId + '/reopen';
		if (config.orderKey) {
			url += '?order_key=' + encodeURIComponent(config.orderKey);
		}

		fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': config.nonce,
				'Content-Type': 'application/json',
			},
		})
			.then(function (response) {
				return response.json().then(function (body) {
					return { ok: response.ok, body: body };
				});
			})
			.then(function (result) {
				if (!result.ok) {
					var msg = getRequestErrorMessage(result.body)
						|| (config.labels && config.labels.reopenFailed)
						|| 'Could not re-open. Try again.';
					btn.textContent = msg;
					btn.disabled    = false;
					return;
				}

				markUpdateReopened(updateId);
				// Focus the now-visible composer so the customer can type
				// without a second click.
				var item     = document.querySelector('[data-awts-update-id="' + updateId + '"]');
				var textarea = item && item.querySelector('[data-awts-cou-reply-form] textarea');
				if (textarea) {
					textarea.focus();
				}
			})
			.catch(function () {
				btn.textContent = (config.labels && config.labels.reopenFailed) || 'Could not re-open. Try again.';
				btn.disabled    = false;
			});
	}

	document.addEventListener('click', handleReopenClick);

	// ---------- 30-second poll for new / edited notes ---------- //

	// ---------- Realtime driver abstraction ---------- //
	//
	// By default this plugin uses 30s polling. A Pusher/WebSocket addon can
	// replace it entirely by setting window.AWTS_COU_DRIVER before this
	// script runs. The driver receives the page config and a callbacks object
	// with the same render helpers that the poller uses, so it plugs in with
	// no changes to this file.
	//
	// Minimal addon example:
	//   window.AWTS_COU_DRIVER = {
	//     init: function(config, callbacks) {
	//       // subscribe to Pusher, call callbacks.appendNote(note) on event
	//       return { destroy: function() { pusher.unsubscribe(...); } };
	//     }
	//   };

	var realtimeCallbacks = {
		appendNote: function (note, updateId) {
			appendPolledNote(note, updateId, parseInt(note.id || '0', 10));
		},
		updateNote: function (noteId, text, note) {
			updateCustomerNoteElement(noteId, text, note);
		},
		reloadPage: function () {
			window.location.reload();
		},
	};

	var externalDriver = window.AWTS_COU_DRIVER || null;

	if (externalDriver && typeof externalDriver.init === 'function') {
		externalDriver.init(config, realtimeCallbacks);
	} else if (config.pollUrl) {
		startPolling();
	}

	function startPolling() {
		// Intervals come from PHP config so addons can override via filter.
		var MIN_INTERVAL = config.pollIntervalMin || 30000;
		var MID_INTERVAL = config.pollIntervalMid || 60000;
		var MAX_INTERVAL = config.pollIntervalMax || 120000;
		var interval     = MIN_INTERVAL;
		var emptyStreak  = 0;
		var timer         = null;

		var latestNoteId = getLatestRenderedNoteId();
		var lastPollTime = utcNow();

		function getLatestRenderedNoteId() {
			var max = 0;
			document.querySelectorAll('.awts_cou_note[data-awts-note-id]').forEach(function (el) {
				var id = parseInt(el.getAttribute('data-awts-note-id') || '0', 10);
				if (id > max) max = id;
			});
			return max;
		}

		function getLatestRenderedUpdateId() {
			var max = 0;
			document.querySelectorAll('[data-awts-update-id]').forEach(function (el) {
				var id = parseInt(el.getAttribute('data-awts-update-id') || '0', 10);
				if (id > max) max = id;
			});
			return max;
		}

		function utcNow() {
			return new Date().toISOString().replace('T', ' ').slice(0, 19);
		}

		function buildPollUrl() {
			var url = config.pollUrl
				+ '?order_id='     + encodeURIComponent(String(config.orderId))
				+ '&since_note_id=' + encodeURIComponent(String(latestNoteId))
				+ '&since_time='    + encodeURIComponent(lastPollTime);

			if (config.orderKey) {
				url += '&order_key=' + encodeURIComponent(config.orderKey);
			}

			return url;
		}

		function resetBackoff() {
			emptyStreak = 0;
			interval    = MIN_INTERVAL;
		}

		function stepBackoff() {
			emptyStreak++;
			if (emptyStreak >= 6) {
				interval = MAX_INTERVAL;
			} else if (emptyStreak >= 3) {
				interval = MID_INTERVAL;
			}
		}

		function poll() {
			fetch(buildPollUrl(), {
				method:      'GET',
				credentials: 'same-origin',
				headers:     { 'X-WP-Nonce': config.nonce },
			})
				.then(function (response) {
					return response.json().then(function (body) {
						return { ok: response.ok, body: body };
					});
				})
				.then(function (result) {
					if (!result.ok) {
						stepBackoff();
						return;
					}

					var data        = result.body;
					var notes       = Array.isArray(data.notes) ? data.notes : [];
					var latestUpdId = parseInt(data.latest_update_id || '0', 10);
					var serverTime  = String(data.server_time || '');

					if (latestUpdId > getLatestRenderedUpdateId()) {
						window.location.reload();
						return;
					}

					if (notes.length === 0) {
						stepBackoff();
					} else {
						resetBackoff();
					}

					notes.forEach(function (note) {
						var noteId   = parseInt(note.id || '0', 10);
						var updateId = parseInt(note.update_id || '0', 10);
						var existing = noteId
							? document.querySelector('.awts_cou_note[data-awts-note-id="' + noteId + '"]')
							: null;

						if (existing) {
							updateCustomerNoteElement(noteId, String(note.note || ''), note);
						} else if (updateId) {
							appendPolledNote(note, updateId, noteId);
						}
					});

					// Apply any resolved-state transitions discovered this poll.
					var resolvedIds = Array.isArray(data.resolved_update_ids) ? data.resolved_update_ids : [];
					resolvedIds.forEach(function (updateId) {
						markUpdateResolved(parseInt(String(updateId), 10));
					});

					// Inverse — reopened updates flip back to compose mode.
					// Both lists are sent every poll so this stays idempotent
					// and survives clients that miss intermediate transitions.
					var openIds = Array.isArray(data.open_update_ids) ? data.open_update_ids : [];
					openIds.forEach(function (updateId) {
						markUpdateReopened(parseInt(String(updateId), 10));
					});

					if (serverTime) {
						lastPollTime = serverTime;
					}
				})
				.catch(function () {
					stepBackoff();
				});
		}

		function appendPolledNote(note, updateId, noteId) {
			// The notes container holds both note articles AND the reply form.
			// Insert the new note just before the reply form so it appears above
			// the text input, not below it.
			var notesContainer = document.querySelector(
				'#awts-update-' + updateId + ' .awts_cou_notes, ' +
				'[data-awts-update-id="' + updateId + '"] .awts_cou_notes'
			);

			if (!notesContainer) {
				return;
			}

			var empty = notesContainer.querySelector('.awts_cou_notes__empty');
			if (empty) empty.parentNode.removeChild(empty);

			var el          = createCustomerNoteElement(note, updateId);
			var replyForm   = notesContainer.querySelector('[data-awts-cou-reply-form]');

			if (replyForm) {
				notesContainer.insertBefore(el, replyForm);
			} else {
				notesContainer.appendChild(el);
			}

			if (noteId > latestNoteId) latestNoteId = noteId;

			// Latest-only rule: a newer note locks every previous one.
			// Strip edit buttons from older bubbles in this thread so the UI
			// matches the server-side gate (which would 403 a click on an
			// older bubble anyway). Keep the brand-new bubble's button intact.
			var bubbles = notesContainer.querySelectorAll('.awts_cou_note[data-awts-note-id]');
			if (bubbles.length > 1) {
				var maxId = 0;
				bubbles.forEach(function (b) {
					var id = parseInt(b.getAttribute('data-awts-note-id') || '0', 10);
					if (id > maxId) maxId = id;
				});
				bubbles.forEach(function (b) {
					var id = parseInt(b.getAttribute('data-awts-note-id') || '0', 10);
					if (id === maxId) return;
					b.querySelectorAll('[data-awts-cou-note-edit]').forEach(function (btn) { btn.remove(); });
				});
			}

			el.classList.add('awts_cou_item--highlight');
			window.setTimeout(function () {
				el.classList.remove('awts_cou_item--highlight');
			}, 2500);

			// Wait one frame so the new article is laid out before reading
			// `scrollHeight` — otherwise the container scrolls to a stale
			// target and the reply form ends up just below the visible area.
			window.requestAnimationFrame(function () {
				smoothScrollToBottom(notesContainer);
			});
		}

		function scheduleNext() {
			timer = window.setTimeout(function () {
				if (!document.hidden) {
					poll();
				}
				scheduleNext();
			}, interval);
		}

		// Tab regains focus → user is actively looking; reset backoff and
		// fetch immediately rather than waiting for the next scheduled tick.
		document.addEventListener('visibilitychange', function () {
			if (!document.hidden) {
				window.clearTimeout(timer);
				resetBackoff();
				poll();
				scheduleNext();
			}
		});

		scheduleNext();
	}
})();
