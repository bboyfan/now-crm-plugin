/**
 * Workflow catalog: row selection, contextual bar, and scope switching.
 */
( function () {
	'use strict';

	var form = document.querySelector( '[data-nowcrm-catalog-bulk]' );

	if ( ! form ) {
		return;
	}

	var pageBox    = document.querySelector( '[data-nowcrm-check-page]' );
	var boxes      = Array.prototype.slice.call( document.querySelectorAll( '[data-nowcrm-row-check]' ) );
	var count      = form.querySelector( '[data-nowcrm-count]' );
	var clear      = form.querySelector( '[data-nowcrm-clear]' );
	var selectAll  = form.querySelector( '[data-nowcrm-select-all]' );
	var selectPage = form.querySelector( '[data-nowcrm-select-page]' );
	var scopeInput = form.querySelector( '[data-nowcrm-scope]' );
	var scopeLabel = form.querySelector( '[data-nowcrm-scope-label]' );
	var operation  = form.querySelector( 'select[name="bulk_operation"]' );
	var submit     = form.querySelector( 'button[type="submit"]' );
	var total      = parseInt( form.getAttribute( 'data-total' ), 10 ) || 0;
	var pageCount  = parseInt( form.getAttribute( 'data-page-count' ), 10 ) || boxes.length;
	var allScope   = false;

	function checked() {
		return boxes.filter( function ( box ) {
			return box.checked;
		} );
	}

	function setScope( value ) {
		allScope = 'all' === value;

		if ( scopeInput ) {
			scopeInput.value = allScope ? 'all' : 'page';
		}
	}

	function sync() {
		var selected = checked().length;

		form.hidden = ! ( allScope || selected > 0 );

		if ( pageBox ) {
			pageBox.checked = pageCount > 0 && selected === pageCount;
			pageBox.indeterminate = selected > 0 && selected < pageCount;
		}

		if ( count ) {
			count.textContent = allScope
				? '全部 ' + total + ' 筆符合篩選'
				: selected + ' 已選取';
		}

		if ( scopeLabel ) {
			scopeLabel.textContent = allScope ? '套用至全部符合篩選的項目' : '';
		}

		if ( selectAll ) {
			selectAll.hidden = allScope || selected !== pageCount || pageCount === 0 || total <= pageCount;
		}

		if ( selectPage ) {
			selectPage.hidden = ! allScope;
		}

		if ( submit && operation ) {
			submit.disabled = ! operation.value;
		}
	}

	if ( pageBox ) {
		pageBox.addEventListener( 'change', function () {
			boxes.forEach( function ( box ) {
				box.checked = pageBox.checked;
			} );
			setScope( 'page' );
			sync();
		} );
	}

	boxes.forEach( function ( box ) {
		box.addEventListener( 'change', function () {
			setScope( 'page' );
			sync();
		} );
	} );

	if ( clear ) {
		clear.addEventListener( 'click', function () {
			boxes.forEach( function ( box ) {
				box.checked = false;
			} );

			if ( pageBox ) {
				pageBox.checked = false;
			}

			setScope( 'page' );
			sync();

			var target = boxes[ 0 ] || pageBox;

			if ( target ) {
				target.focus();
			}
		} );
	}

	if ( selectAll ) {
		selectAll.addEventListener( 'click', function () {
			setScope( 'all' );
			sync();
		} );
	}

	if ( selectPage ) {
		selectPage.addEventListener( 'click', function () {
			boxes.forEach( function ( box ) {
				box.checked = true;
			} );
			setScope( 'page' );
			sync();
		} );
	}

	if ( operation ) {
		operation.addEventListener( 'change', sync );
	}

	setScope( 'page' );
	sync();
}() );
