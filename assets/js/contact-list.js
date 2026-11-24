/**
 * Contacts list: view preferences, selection, and the bulk bar.
 */
( function () {
	'use strict';

	var COLUMNS_KEY = 'nowcrm_contacts_columns';
	var COMPACT_KEY = 'nowcrm_contacts_compact';
	var DEFAULT_COLUMNS = [ 'lists', 'tags', 'status' ];

	function readColumns() {
		try {
			var stored = JSON.parse( window.localStorage.getItem( COLUMNS_KEY ) || 'null' );

			if ( Array.isArray( stored ) ) {
				return stored;
			}
		} catch ( error ) {
			// Ignore malformed preferences.
		}

		return DEFAULT_COLUMNS.slice();
	}

	function columnInputs() {
		return Array.prototype.slice.call( document.querySelectorAll( '[data-nowcrm-column]' ) );
	}

	function applyColumns( columns ) {
		document.querySelectorAll( '[data-nowcrm-col]' ).forEach( function ( cell ) {
			var key = cell.getAttribute( 'data-nowcrm-col' );

			if ( 'contact' === key ) {
				return;
			}

			cell.hidden = columns.indexOf( key ) === -1;
		} );

		columnInputs().forEach( function ( input ) {
			input.checked = columns.indexOf( input.getAttribute( 'data-nowcrm-column' ) ) !== -1;
		} );
	}

	function applyCompact( compact ) {
		var surface = document.querySelector( '[data-nowcrm-contacts-surface]' );
		var toggle = document.querySelector( '[data-nowcrm-compact-view]' );

		if ( toggle ) {
			toggle.checked = compact;
		}

		if ( surface ) {
			surface.classList.toggle( 'nowcrm-contacts--compact', compact );
		}

		document.querySelectorAll( '[data-nowcrm-chip-list]' ).forEach( function ( list ) {
			var chips = Array.prototype.slice.call( list.querySelectorAll( '[data-nowcrm-chip]' ) );
			var more = list.querySelector( '[data-nowcrm-chip-more]' );

			chips.forEach( function ( chip, index ) {
				chip.hidden = compact && index >= 2;
			} );

			if ( more ) {
				more.hidden = ! compact || chips.length <= 2;
				more.textContent = compact && chips.length > 2 ? '+' + ( chips.length - 2 ) : '';
			}
		} );
	}

	function resetTable() {
		try {
			window.localStorage.removeItem( COLUMNS_KEY );
			window.localStorage.removeItem( COMPACT_KEY );
		} catch ( error ) {
			// Storage can be unavailable; the visible state still resets.
		}

		applyColumns( DEFAULT_COLUMNS );
		applyCompact( false );
	}

	function initView() {
		applyColumns( readColumns() );
		applyCompact( 'yes' === window.localStorage.getItem( COMPACT_KEY ) );

		columnInputs().forEach( function ( input ) {
			input.addEventListener( 'change', function () {
				var columns = columnInputs().filter( function ( item ) {
					return item.checked;
				} ).map( function ( item ) {
					return item.getAttribute( 'data-nowcrm-column' );
				} );

				try {
					window.localStorage.setItem( COLUMNS_KEY, JSON.stringify( columns ) );
				} catch ( error ) {
					// Ignore storage failures.
				}

				applyColumns( columns );
			} );
		} );

		var compact = document.querySelector( '[data-nowcrm-compact-view]' );

		if ( compact ) {
			compact.addEventListener( 'change', function () {
				try {
					window.localStorage.setItem( COMPACT_KEY, compact.checked ? 'yes' : 'no' );
				} catch ( error ) {
					// Ignore storage failures.
				}

				applyCompact( compact.checked );
			} );
		}

		document.querySelectorAll( '[data-nowcrm-reset-table]' ).forEach( function ( button ) {
			button.addEventListener( 'click', resetTable );
		} );

		document.querySelectorAll( '.nowcrm-filter-popover.is-disabled > summary' ).forEach( function ( summary ) {
			summary.addEventListener( 'click', function ( event ) {
				event.preventDefault();
			} );
		} );

		document.addEventListener( 'click', function ( event ) {
			if ( event.target.closest && event.target.closest( '.nowcrm-more' ) ) {
				return;
			}

			document.querySelectorAll( '.nowcrm-more[open]' ).forEach( function ( menu ) {
				menu.removeAttribute( 'open' );
			} );
		} );
	}

	function initStatusFilter() {
		var select = document.querySelector( '[data-nowcrm-status-filter]' );

		if ( select && ! select.disabled ) {
			select.addEventListener( 'change', function () {
				var form = select.closest( 'form' );

				if ( form && typeof form.requestSubmit === 'function' ) {
					form.requestSubmit();
				} else if ( form ) {
					form.submit();
				}
			} );
		}
	}

	function openExport( scope, ids ) {
		var dialog = document.getElementById( 'nowcrm-export-dialog' );

		if ( ! dialog ) {
			return;
		}

		var scopeInput = dialog.querySelector( '[data-nowcrm-export-scope]' );
		var idsInput = dialog.querySelector( '[data-nowcrm-export-ids]' );
		var summary = dialog.querySelector( '[data-nowcrm-export-summary]' );
		var selected = 'selected' === scope && ids && ids.length;

		if ( scopeInput ) {
			scopeInput.value = selected ? 'selected' : 'filtered';
		}

		if ( idsInput ) {
			idsInput.value = selected ? ids.join( ',' ) : '';
		}

		if ( summary ) {
			summary.textContent = selected
				? '匯出選取的 ' + ids.length + ' 位聯絡人。'
				: '匯出符合目前篩選的聯絡人。';
		}

		if ( typeof dialog.showModal === 'function' && ! dialog.open ) {
			dialog.showModal();
		} else {
			dialog.setAttribute( 'open', '' );
		}
	}

	function initExport() {
		var dialog = document.getElementById( 'nowcrm-export-dialog' );

		if ( ! dialog ) {
			return;
		}

		document.querySelectorAll( '[data-nowcrm-open-export]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var menu = button.closest( 'details' );

				if ( menu ) {
					menu.removeAttribute( 'open' );
				}

				openExport( button.getAttribute( 'data-nowcrm-open-export' ) || 'filtered', [] );
			} );
		} );

		function close() {
			if ( typeof dialog.close === 'function' && dialog.open ) {
				dialog.close();
			} else {
				dialog.removeAttribute( 'open' );
			}
		}

		dialog.querySelectorAll( '[data-nowcrm-close-export]' ).forEach( function ( button ) {
			button.addEventListener( 'click', close );
		} );

		dialog.addEventListener( 'click', function ( event ) {
			if ( event.target === dialog ) {
				close();
			}
		} );
	}

	function initSelection() {
		var form = document.querySelector( '[data-nowcrm-bulk-form]' );
		var pageBox = document.querySelector( '[data-nowcrm-check-page]' );
		var rowBoxes = Array.prototype.slice.call( document.querySelectorAll( '[data-nowcrm-row-check]' ) );
		var label = document.querySelector( '[data-nowcrm-selection-label]' );
		var scopeLabel = document.querySelector( '[data-nowcrm-scope-label]' );
		var selectAll = document.querySelector( '[data-nowcrm-select-all]' );
		var selectPage = document.querySelector( '[data-nowcrm-select-page]' );
		var clear = document.querySelector( '[data-nowcrm-clear-selection]' );
		var submit = form ? form.querySelector( 'button[type="submit"]' ) : null;
		var operation = form ? form.querySelector( '[name="bulk_operation"]' ) : null;
		var exportSelected = document.querySelector( '[data-nowcrm-export-selected]' );
		var allSelected = false;
		var total = form ? parseInt( form.getAttribute( 'data-total' ), 10 ) || 0 : 0;
		var hasMore = form ? '1' === form.getAttribute( 'data-has-more' ) : false;
		var fields = form ? {
			list: form.querySelector( '[name="bulk_list_ids[]"]' ),
			tag: form.querySelector( '[name="bulk_tag_ids[]"]' ),
			status: form.querySelector( '[name="bulk_status"]' )
		} : {};

		if ( ! form || ! rowBoxes.length ) {
			return;
		}

		function setScope( value ) {
			var input = form.querySelector( '[name="bulk_scope"]' );

			if ( input ) {
				input.value = value;
			}
		}

		function selectedIds() {
			return rowBoxes.filter( function ( box ) {
				return box.checked;
			} ).map( function ( box ) {
				return box.value;
			} );
		}

		function sync() {
			var checked = selectedIds().length;
			var pageCount = rowBoxes.length;
			var active = allSelected || checked > 0;

			form.hidden = ! active;

			if ( pageBox ) {
				pageBox.checked = checked === pageCount;
				pageBox.indeterminate = checked > 0 && checked < pageCount;
			}

			if ( label ) {
				label.textContent = allSelected
					? total + ' 位符合目前篩選的聯絡人'
					: checked + ' 已選取';
			}

			if ( scopeLabel ) {
				scopeLabel.textContent = allSelected ? '套用至全部符合篩選的聯絡人' : '';
			}

			if ( selectAll ) {
				selectAll.hidden = allSelected || checked !== pageCount || ! hasMore;
			}

			if ( selectPage ) {
				selectPage.hidden = ! allSelected;
			}

			if ( submit ) {
				submit.disabled = ! operation || ! operation.value;
			}

			if ( exportSelected ) {
				exportSelected.hidden = ! ( allSelected || checked > 0 );
				exportSelected.textContent = allSelected ? '匯出全部符合篩選' : '匯出選取';
			}
		}

		function clearSelection() {
			allSelected = false;
			rowBoxes.forEach( function ( box ) {
				box.checked = false;
			} );
			setScope( 'selected' );
			sync();
		}

		rowBoxes.forEach( function ( box ) {
			box.addEventListener( 'change', function () {
				if ( allSelected ) {
					allSelected = false;
					setScope( 'selected' );
				}

				sync();
			} );
		} );

		if ( pageBox ) {
			pageBox.addEventListener( 'change', function () {
				if ( allSelected ) {
					allSelected = false;
					setScope( 'selected' );
				}

				rowBoxes.forEach( function ( box ) {
					box.checked = pageBox.checked;
				} );

				sync();
			} );
		}

		if ( selectAll ) {
			selectAll.addEventListener( 'click', function () {
				allSelected = true;
				rowBoxes.forEach( function ( box ) {
					box.checked = true;
				} );
				setScope( 'all' );
				sync();
			} );
		}

		if ( selectPage ) {
			selectPage.addEventListener( 'click', function () {
				allSelected = false;
				setScope( 'selected' );
				sync();
			} );
		}

		if ( clear ) {
			clear.addEventListener( 'click', clearSelection );
		}

		if ( exportSelected ) {
			exportSelected.addEventListener( 'click', function () {
				if ( allSelected ) {
					openExport( 'filtered', [] );
					return;
				}

				openExport( 'selected', selectedIds() );
			} );
		}

		if ( operation ) {
			operation.addEventListener( 'change', sync );
		}

		document.addEventListener( 'keydown', function ( event ) {
			var exportDialog = document.getElementById( 'nowcrm-export-dialog' );

			if ( 'Escape' === event.key && ! form.hidden && ! ( exportDialog && exportDialog.open ) ) {
				clearSelection();
			}
		} );

		// Reveal only the inputs for the chosen operation.
		if ( operation ) {
			var groups = [
				{ value: [ 'attach_list', 'detach_list' ], field: fields.list },
				{ value: [ 'attach_tag', 'detach_tag' ], field: fields.tag },
				{ value: [ 'set_status' ], field: fields.status }
			];

			groups.forEach( function ( group ) {
				if ( ! group.field ) {
					return;
				}

				var wrapper = group.field.closest( 'label' );

				if ( ! wrapper ) {
					return;
				}

				var update = function () {
					var active = group.value.indexOf( operation.value ) !== -1;

					wrapper.hidden = ! active;
					wrapper.style.display = active ? '' : 'none';
					group.field.disabled = ! active;
				};

				operation.addEventListener( 'change', update );
				update();
			} );
		}

		sync();
	}

	initView();
	initStatusFilter();
	initExport();
	initSelection();
}() );
