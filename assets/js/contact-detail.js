/**
 * Contact detail interactions: term picker search, inline profile save, and the
 * notes composer drawer.
 *
 * Every form still submits natively when JavaScript is unavailable. When fetch
 * is available, an error response keeps the draft in place so the operator does
 * not lose it.
 */
(function () {
	'use strict';

	var root = document.querySelector('.nowcrm-contact-detail');

	if (!root) {
		return;
	}

	function initTermPickers() {
		root.querySelectorAll('[data-nowcrm-term-picker]').forEach(function (picker) {
			var input = picker.querySelector('[data-nowcrm-term-search]');
			var options = picker.querySelectorAll('[data-nowcrm-term-option]');
			var empty = picker.querySelector('[data-nowcrm-term-empty]');

			if (!input || !options.length) {
				return;
			}

			input.addEventListener('input', function () {
				var query = input.value.trim().toLowerCase();
				var visible = 0;

				options.forEach(function (option) {
					var match = '' === query || (option.textContent || '').toLowerCase().indexOf(query) !== -1;

					option.hidden = !match;

					if (match) {
						visible += 1;
					}
				});

				if (empty) {
					empty.hidden = visible > 0;
				}
			});
		});
	}

	function initProfileForm() {
		var form = root.querySelector('[data-nowcrm-profile-form]');
		var notice = root.querySelector('[data-nowcrm-profile-error]');

		if (!form || !notice || typeof window.fetch !== 'function') {
			return;
		}

		function showError(message) {
			notice.textContent = message;
			notice.hidden = false;
			notice.focus();
		}

		form.addEventListener('submit', function (event) {
			event.preventDefault();

			var button = form.querySelector('[type="submit"]');

			notice.hidden = true;
			notice.textContent = '';

			if (button) {
				button.disabled = true;
			}

			window.fetch(form.action, {
				method: 'POST',
				body: new FormData(form),
				credentials: 'same-origin'
			})
				.then(function (response) {
					if (!response.ok) {
						throw new Error('request failed');
					}

					var target = new URL(response.url, window.location.href);

					if (target.searchParams.get('nowcrm_notice') === 'contact_saved') {
						window.location.assign(target.href);
						return null;
					}

					return response.text().then(function (html) {
						var doc = new DOMParser().parseFromString(html, 'text/html');
						var node = doc.querySelector('.notice-error p, .notice p');
						var message = node && node.textContent ? node.textContent.trim() : '';

						showError(message || form.getAttribute('data-error-text') || '');
					});
				})
				.catch(function () {
					showError(form.getAttribute('data-error-text') || '');
				})
				.finally(function () {
					if (button) {
						button.disabled = false;
					}
				});
		});
	}

	function initNoteComposer() {
		var dialog = document.getElementById('nowcrm-note-drawer');
		var form = document.getElementById('nowcrm-note-form');

		if (!dialog || !form) {
			return;
		}

		var heading = dialog.querySelector('[data-nowcrm-note-heading]');
		var submit = dialog.querySelector('[data-nowcrm-note-submit]');
		var error = dialog.querySelector('[data-nowcrm-note-error]');
		var titleField = dialog.querySelector('[data-nowcrm-note-title]');
		var fields = {
			noteId: form.querySelector('[name="note_id"]'),
			kind: form.querySelector('[name="kind"]'),
			createdAt: form.querySelector('[name="created_at"]'),
			title: form.querySelector('[name="title"]'),
			body: form.querySelector('[name="body"]')
		};
		var opener = null;
		var snapshot = '';

		function values() {
			return JSON.stringify([
				fields.noteId ? fields.noteId.value : '',
				fields.kind ? fields.kind.value : '',
				fields.createdAt ? fields.createdAt.value : '',
				fields.title ? fields.title.value : '',
				fields.body ? fields.body.value : ''
			]);
		}

		function setMode(mode) {
			var editing = mode === 'edit';

			form.setAttribute('data-mode', mode);

			if (heading) {
				heading.textContent = form.getAttribute(editing ? 'data-label-edit' : 'data-label-create') || '';
			}

			if (submit) {
				submit.textContent = form.getAttribute(editing ? 'data-label-save' : 'data-label-create') || '';
			}
		}

		function hideError() {
			if (!error) {
				return;
			}

			error.hidden = true;
			error.textContent = '';
		}

		function showError(message) {
			if (!error) {
				return;
			}

			error.textContent = message;
			error.hidden = false;
			error.focus();
		}

		function resetFields() {
			fields.noteId.value = '0';
			fields.kind.value = form.getAttribute('data-default-kind') || 'note';
			fields.createdAt.value = '';
			fields.title.value = '';
			fields.body.value = '';
			setMode('create');
			hideError();
		}

		function show(source) {
			opener = source || null;
			snapshot = values();

			if (typeof dialog.showModal === 'function') {
				if (dialog.open) {
					dialog.removeAttribute('open');
				}

				dialog.showModal();
			} else {
				dialog.setAttribute('open', '');
			}

			if (titleField) {
				titleField.focus();
			}
		}

		function isDirty() {
			return values() !== snapshot;
		}

		function clearUrlState() {
			if (!window.history || typeof window.history.replaceState !== 'function') {
				return;
			}

			var url = new URL(window.location.href);

			if (!url.searchParams.has('note')) {
				return;
			}

			url.searchParams.delete('note');
			window.history.replaceState(null, '', url.href);
		}

		function close() {
			if (typeof dialog.close === 'function' && dialog.open) {
				dialog.close();
			} else {
				dialog.removeAttribute('open');
			}

			clearUrlState();

			if (opener && document.contains(opener)) {
				opener.focus();
			}

			opener = null;
			snapshot = '';
		}

		function requestClose() {
			if (isDirty() && !window.confirm(dialog.getAttribute('data-confirm-discard') || '')) {
				return;
			}

			resetFields();
			close();
		}

		root.querySelectorAll('[data-nowcrm-note-create]').forEach(function (trigger) {
			trigger.addEventListener('click', function (event) {
				event.preventDefault();
				resetFields();
				show(trigger);
			});
		});

		root.querySelectorAll('[data-nowcrm-note-edit]').forEach(function (trigger) {
			trigger.addEventListener('click', function (event) {
				event.preventDefault();

				resetFields();
				fields.noteId.value = trigger.getAttribute('data-note-id') || '0';
				fields.kind.value = trigger.getAttribute('data-note-kind') || '';
				fields.createdAt.value = trigger.getAttribute('data-note-created') || '';
				fields.title.value = trigger.getAttribute('data-note-title') || '';
				fields.body.value = trigger.getAttribute('data-note-body') || '';
				setMode('edit');
				hideError();
				show(trigger);
			});
		});

		dialog.querySelectorAll('[data-nowcrm-close-note]').forEach(function (closer) {
			closer.addEventListener('click', function (event) {
				event.preventDefault();
				requestClose();
			});
		});

		dialog.addEventListener('cancel', function (event) {
			event.preventDefault();
			requestClose();
		});

		// The server renders the drawer open for note=new and note=<id>, so the
		// composer also works as a plain link target without JavaScript.
		if (dialog.hasAttribute('open')) {
			var noteId = fields.noteId.value;
			var row = noteId !== '0'
				? root.querySelector('[data-nowcrm-note-edit][data-note-id="' + noteId + '"]')
				: null;

			snapshot = values();

			if (typeof dialog.showModal === 'function') {
				dialog.removeAttribute('open');
				dialog.showModal();
			}

			if (titleField) {
				titleField.focus();
			}

			opener = row || root.querySelector('[data-nowcrm-note-create]');
		}

		form.addEventListener('submit', function (event) {
			var button = submit || form.querySelector('[type="submit"]');

			if (typeof window.fetch !== 'function') {
				return;
			}

			event.preventDefault();
			hideError();

			if (button) {
				button.disabled = true;
			}

			window.fetch(form.action, {
				method: 'POST',
				body: new FormData(form),
				credentials: 'same-origin'
			})
				.then(function (response) {
					if (!response.ok) {
						throw new Error('request failed');
					}

					var target = new URL(response.url, window.location.href);

					if (target.searchParams.get('nowcrm_note_notice') === 'saved') {
						window.location.assign(target.href);
						return null;
					}

					return response.text().then(function (html) {
						var doc = new DOMParser().parseFromString(html, 'text/html');
						var node = doc.querySelector('.notice-error p, .notice p');
						var message = node && node.textContent ? node.textContent.trim() : '';

						showError(message || dialog.getAttribute('data-error-text') || '');
					});
				})
				.catch(function () {
					showError(dialog.getAttribute('data-error-text') || '');
				})
				.finally(function () {
					if (button) {
						button.disabled = false;
					}
				});
		});
	}

	initTermPickers();
	initProfileForm();
	initNoteComposer();
})();
