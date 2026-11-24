/**
 * Lists and tags: dialog lifecycle, selection, and REST saves.
 */
( function () {
	'use strict';

	var root = document.querySelector( '[data-nowcrm-terms]' );

	if ( ! root ) {
		return;
	}

	var dialog = document.getElementById( 'nowcrm-terms-dialog' );
	var importDialog = document.getElementById( 'nowcrm-terms-import' );
	var form = document.getElementById( 'nowcrm-terms-form' );
	var tableUrl = root.getAttribute( 'data-table-url' ) || window.location.href;
	var resource = root.getAttribute( 'data-resource' ) || 'lists';
	var restUrl = root.getAttribute( 'data-rest-url' ) || '';
	var restNonce = root.getAttribute( 'data-rest-nonce' ) || '';

	function show( element ) {
		if ( ! element ) {
			return;
		}

		if ( typeof element.showModal === 'function' ) {
			if ( element.open ) {
				element.close();
			}

			element.showModal();
		} else {
			element.setAttribute( 'open', '' );
		}
	}

	function hide( element ) {
		if ( ! element ) {
			return;
		}

		if ( typeof element.close === 'function' ) {
			if ( element.open ) {
				element.close();
			}
		} else {
			element.removeAttribute( 'open' );
		}
	}

	function replaceTableUrl() {
		if ( window.history && typeof window.history.replaceState === 'function' ) {
			window.history.replaceState( null, '', tableUrl );
		}
	}

	function closeMenus() {
		document.querySelectorAll( '.nowcrm-more[open]' ).forEach( function ( menu ) {
			menu.removeAttribute( 'open' );
		} );
	}

	function errorBox() {
		return dialog ? dialog.querySelector( '[data-nowcrm-terms-error]' ) : null;
	}

	function showError( message ) {
		var box = errorBox();

		if ( ! box ) {
			return;
		}

		box.textContent = message;
		box.hidden = false;
	}

	function clearError() {
		var box = errorBox();

		if ( ! box ) {
			return;
		}

		box.textContent = '';
		box.hidden = true;

		if ( dialog ) {
			dialog.querySelectorAll( '[aria-invalid="true"]' ).forEach( function ( field ) {
				field.removeAttribute( 'aria-invalid' );
			} );
		}
	}

	function focusName() {
		var field = dialog ? dialog.querySelector( '[name="name"]' ) : null;

		if ( field ) {
			field.focus();
			field.select();
		}
	}

	function openCreate() {
		if ( ! dialog || ! form ) {
			return;
		}

		// On an edit page the create link reloads the table so the dialog action
		// and nonce are the create contract, not the update one.
		if ( 'edit' === dialog.getAttribute( 'data-mode' ) ) {
			return false;
		}

		clearError();
		form.reset();
		show( dialog );
		focusName();

		return true;
	}

	function openImport() {
		if ( ! importDialog ) {
			return false;
		}

		show( importDialog );

		var file = importDialog.querySelector( '[name="nowcrm_terms_csv"]' );

		if ( file ) {
			file.focus();
		}

		return true;
	}

	function selectedRows() {
		return Array.prototype.slice.call( document.querySelectorAll( '[data-nowcrm-terms-row-check]' ) );
	}

	function initSelection() {
		var bulk = document.querySelector( '[data-nowcrm-terms-bulk]' );
		var pageBox = document.querySelector( '[data-nowcrm-terms-check-page]' );
		var label = document.querySelector( '[data-nowcrm-terms-selection]' );
		var clear = document.querySelector( '[data-nowcrm-terms-clear]' );
		var rows = selectedRows();

		if ( ! bulk || ! rows.length ) {
			return;
		}

		var submit = bulk.querySelector( 'button[type="submit"]' );
		var itemLabel = root.getAttribute( 'data-item-label' ) || '個項目';

		function sync() {
			var checked = rows.filter( function ( box ) {
				return box.checked;
			} ).length;

			bulk.hidden = checked === 0;

			if ( pageBox ) {
				pageBox.checked = checked === rows.length;
				pageBox.indeterminate = checked > 0 && checked < rows.length;
			}

			if ( label ) {
				label.textContent = '已選取 ' + checked + ' ' + itemLabel;
			}

			if ( submit ) {
				submit.disabled = checked === 0;
			}
		}

		function clearSelection() {
			rows.forEach( function ( box ) {
				box.checked = false;
			} );

			sync();
		}

		rows.forEach( function ( box ) {
			box.addEventListener( 'change', sync );
		} );

		if ( pageBox ) {
			pageBox.addEventListener( 'change', function () {
				rows.forEach( function ( box ) {
					box.checked = pageBox.checked;
				} );

				sync();
			} );
		}

		if ( clear ) {
			clear.addEventListener( 'click', clearSelection );
		}

		document.addEventListener( 'keydown', function ( event ) {
			var dialogOpen = ( dialog && dialog.open ) || ( importDialog && importDialog.open );

			if ( 'Escape' === event.key && ! bulk.hidden && ! dialogOpen ) {
				clearSelection();
			}
		} );

		sync();
	}

	function initMenus() {
		document.addEventListener( 'click', function ( event ) {
			if ( event.target.closest && event.target.closest( '.nowcrm-more' ) ) {
				return;
			}

			closeMenus();
		} );

		document.querySelectorAll( '.nowcrm-terms__row-more' ).forEach( function ( menu ) {
			menu.addEventListener( 'toggle', function () {
				if ( ! menu.open ) {
					return;
				}

				document.querySelectorAll( '.nowcrm-terms__row-more[open]' ).forEach( function ( other ) {
					if ( other !== menu ) {
						other.removeAttribute( 'open' );
					}
				} );
			} );
		} );
	}

	function initCreate() {
		document.querySelectorAll( '[data-nowcrm-open-create]' ).forEach( function ( link ) {
			link.addEventListener( 'click', function ( event ) {
				if ( openCreate() ) {
					event.preventDefault();
					closeMenus();
				}
			} );
		} );
	}

	function initImport() {
		document.querySelectorAll( '[data-nowcrm-open-import]' ).forEach( function ( link ) {
			link.addEventListener( 'click', function ( event ) {
				if ( openImport() ) {
					event.preventDefault();
					closeMenus();
				}
			} );
		} );

		if ( ! importDialog ) {
			return;
		}

		importDialog.querySelectorAll( '[data-nowcrm-close-import]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				hide( importDialog );
				replaceTableUrl();
			} );
		} );

		importDialog.addEventListener( 'click', function ( event ) {
			if ( event.target === importDialog ) {
				hide( importDialog );
				replaceTableUrl();
			}
		} );

		importDialog.addEventListener( 'cancel', function ( event ) {
			event.preventDefault();
			hide( importDialog );
			replaceTableUrl();
		} );
	}

	function redirectWithNotice( notice ) {
		var target = new URL( tableUrl, window.location.href );
		target.searchParams.set( 'nowcrm_notice', notice );
		window.location.assign( target.toString() );
	}

	function request( method, url, payload ) {
		return window.fetch( url, {
			method: method,
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/json',
				'X-WP-Nonce': restNonce
			},
			body: JSON.stringify( payload )
		} ).then( function ( response ) {
			return response.text().then( function ( text ) {
				var data = {};

				try {
					data = JSON.parse( text );
				} catch ( error ) {
					// Keep the generic failure message.
				}

				return {
					ok: response.ok,
					data: data
				};
			} );
		} );
	}

	function initDialogClose() {
		if ( ! dialog ) {
			return;
		}

		dialog.querySelectorAll( '[data-nowcrm-close-terms]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				hide( dialog );
				replaceTableUrl();
			} );
		} );

		dialog.addEventListener( 'click', function ( event ) {
			if ( event.target === dialog ) {
				hide( dialog );
				replaceTableUrl();
			}
		} );

		dialog.addEventListener( 'cancel', function ( event ) {
			event.preventDefault();
			hide( dialog );
			replaceTableUrl();
		} );
	}

	function initSave() {
		if ( ! dialog || ! form || ! window.fetch ) {
			return;
		}

		var submit = form.querySelector( 'button[type="submit"]' );
		var nameField = form.querySelector( '[name="name"]' );
		var slugField = form.querySelector( '[name="slug"]' );
		var descriptionField = form.querySelector( '[name="description"]' );
		var submitting = false;

		if ( nameField ) {
			nameField.addEventListener( 'input', clearError );
		}

		if ( slugField ) {
			slugField.addEventListener( 'input', clearError );
		}

		form.addEventListener( 'submit', function ( event ) {
			if ( submitting ) {
				event.preventDefault();
				return;
			}

			var name = nameField ? nameField.value.trim() : '';

			if ( '' === name ) {
				event.preventDefault();
				showError( '請輸入名稱。' );

				if ( nameField ) {
					nameField.setAttribute( 'aria-invalid', 'true' );
					nameField.focus();
				}

				return;
			}

			event.preventDefault();
			clearError();

			var mode = dialog.getAttribute( 'data-mode' );
			var id = parseInt( dialog.getAttribute( 'data-id' ), 10 ) || 0;
			var payload = {
				name: name,
				description: descriptionField ? descriptionField.value : ''
			};
			var slug = slugField ? slugField.value.trim() : '';

			// An empty slug means "derive" on create and "keep" on update. The
			// update contract rejects an empty slug, so omit it.
			if ( '' !== slug ) {
				payload.slug = slug;
			}

			var base = restUrl;

			if ( ! /\/$/.test( base ) ) {
				base += '/';
			}

			var url = base + resource + ( 'edit' === mode && id > 0 ? '/' + id : '' );
			var originalLabel = submit ? submit.textContent : '';

			submitting = true;

			if ( submit ) {
				submit.disabled = true;
				submit.textContent = '儲存中…';
			}

			request( 'edit' === mode && id > 0 ? 'PUT' : 'POST', url, payload )
				.then( function ( result ) {
					if ( ! result.ok ) {
						var error = new Error( ( result.data && result.data.message ) || '儲存失敗，請再試一次。' );
						error.code = result.data && result.data.code ? result.data.code : '';
						throw error;
					}

					redirectWithNotice( 'edit' === mode ? 'saved' : 'created' );
				} )
				.catch( function ( error ) {
					submitting = false;

					if ( submit ) {
						submit.disabled = false;
						submit.textContent = originalLabel;
					}

					showError( error.message || '儲存失敗，請再試一次。' );

					if ( /duplicate_term|invalid_term_name/.test( error.code || '' ) && nameField ) {
						nameField.setAttribute( 'aria-invalid', 'true' );
						nameField.focus();
					} else if ( /invalid_term_slug/.test( error.code || '' ) && slugField ) {
						slugField.setAttribute( 'aria-invalid', 'true' );
						slugField.focus();
					}
				} );
		} );
	}

	function initOpenDialogs() {
		if ( dialog && dialog.hasAttribute( 'open' ) ) {
			show( dialog );
			focusName();
		}

		if ( importDialog && importDialog.hasAttribute( 'open' ) ) {
			show( importDialog );
		}
	}

	initMenus();
	initSelection();
	initCreate();
	initImport();
	initDialogClose();
	initSave();
	initOpenDialogs();
}() );
