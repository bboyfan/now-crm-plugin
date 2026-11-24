/**
 * Shared email tools for templates, automation steps and sequence steps.
 *
 * Each [data-workflow-email] scope owns one persisted source ID and four
 * [data-workflow-field] controls. Clicks are delegated from the document so
 * automation step cards keep working after they are cloned.
 */
( function () {
	'use strict';

	var config = window.nowcrmWorkflowEmail;
	var sources = [ 'template', 'automation', 'sequence' ];

	if ( ! config ) {
		return;
	}

	function status( scope, message, isError ) {
		var node = scope.querySelector( '[data-workflow-status]' );
		if ( ! node ) {
			return;
		}
		node.textContent = message;
		node.classList.toggle( 'is-error', !! isError );
	}

	function post( action, data ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', config.nonce );
		Object.keys( data ).forEach( function ( key ) {
			body.set( key, data[ key ] === null || data[ key ] === undefined ? '' : String( data[ key ] ) );
		} );

		return window.fetch( config.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( function ( response ) {
			return response.json().catch( function () {
				throw new Error( config.labels.failed );
			} ).then( function ( payload ) {
				if ( ! response.ok || ! payload || ! payload.success ) {
					throw new Error( payload && payload.data && payload.data.message ? payload.data.message : config.labels.failed );
				}
				return payload.data;
			} );
		} );
	}

	function sourceOf( scope ) {
		var value = ( scope.getAttribute( 'data-workflow-email' ) || '' ).trim();
		if ( sources.indexOf( value ) !== -1 ) {
			return value;
		}
		var marker = scope.querySelector( '[data-workflow-tools][data-workflow-tools-source]' );
		if ( marker ) {
			var toolSource = ( marker.getAttribute( 'data-workflow-tools-source' ) || '' ).trim();
			if ( sources.indexOf( toolSource ) !== -1 ) {
				return toolSource;
			}
		}
		marker = scope.querySelector( '[data-workflow-source]' );
		return marker ? String( marker.value || '' ).trim() : '';
	}

	function sourceIdOf( scope ) {
		var value = ( scope.getAttribute( 'data-workflow-source-id' ) || '' ).trim();
		if ( value ) {
			return value;
		}
		var marker = scope.querySelector( '[data-workflow-source-id]' );
		return marker ? String( marker.value || '' ).trim() : '';
	}

	function controls( scope, name ) {
		return scope.querySelectorAll( '[data-workflow-field="' + name + '"]' );
	}

	function isHidden( element ) {
		return !! ( element.closest( '[hidden]' ) || element.disabled );
	}

	function readField( scope, name ) {
		var nodes = controls( scope, name );

		for ( var i = 0; i < nodes.length; i += 1 ) {
			var node = nodes[ i ];
			if ( 'radio' === node.type ) {
				if ( node.checked ) {
					return node.value;
				}
				continue;
			}
			if ( 'checkbox' === node.type ) {
				if ( node.checked ) {
					return node.value;
				}
				continue;
			}
			if ( isHidden( node ) ) {
				continue;
			}
			return node.value;
		}

		return '';
	}

	function writeField( scope, name, value ) {
		var nodes = controls( scope, name );
		var handled = false;

		for ( var i = 0; i < nodes.length; i += 1 ) {
			var node = nodes[ i ];
			if ( 'radio' === node.type ) {
				node.checked = node.value === value;
				handled = handled || node.checked;
				continue;
			}
			node.value = value;
			node.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			node.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			handled = true;
		}

		return handled;
	}

	function bridgeOf( scope ) {
		var frame = scope.querySelector( '[data-workflow-frame]' );
		if ( ! frame ) {
			return null;
		}
		try {
			return frame.contentWindow ? frame.contentWindow.nowcrmEditorBridge || null : null;
		} catch ( error ) {
			return null;
		}
	}

	function contentOf( scope, mode ) {
		var source = sourceOf( scope );
		var bridge = bridgeOf( scope );

		if ( ( 'template' === source || 'sequence' === source || 'automation' === source ) && 'blocks' === mode && bridge && bridge.isReady() ) {
			var blocks = bridge.blocks();
			if ( typeof blocks === 'string' ) {
				return blocks;
			}
		}

		var value = readField( scope, 'content' );
		var hidden = scope.querySelector( '[data-workflow-blocks]' );

		return value || ( hidden ? hidden.value : '' );
	}

	function collect( scope ) {
		var mode = readField( scope, 'mode' );

		return {
			source: sourceOf( scope ),
			source_id: sourceIdOf( scope ),
			subject: readField( scope, 'subject' ),
			preheader: readField( scope, 'preheader' ),
			mode: mode,
			content: contentOf( scope, mode )
		};
	}

	function syncBlocks( scope ) {
		var hidden = scope.querySelector( '[data-workflow-blocks]' );
		var bridge = bridgeOf( scope );

		if ( hidden && bridge && bridge.isReady() ) {
			var blocks = bridge.blocks();
			if ( typeof blocks === 'string' ) {
				hidden.value = blocks;
			}
		}
	}

	// A sequence or automation step owns one shared, blank block canvas. Writing
	// content from the template picker must re-seed the live frame; when it is
	// not ready yet the page seeds from the textarea instead.
	function seedSequenceBlocks( scope, value ) {
		if ( 'sequence' !== sourceOf( scope ) && 'automation' !== sourceOf( scope ) ) {
			return;
		}

		var bridge = bridgeOf( scope );
		var mode = readField( scope, 'mode' );

		if ( 'blocks' === mode && bridge && bridge.isReady() && typeof bridge.replaceBlocks === 'function' ) {
			bridge.replaceBlocks( value );
		}
	}

	function syncMode( scope ) {
		var mode = readField( scope, 'mode' );
		var blocks = scope.querySelector( '[data-workflow-mode-pane="blocks"]' );
		var html = scope.querySelector( '[data-workflow-mode-pane="html"]' );

		if ( blocks ) {
			blocks.hidden = 'blocks' !== mode;
		}
		if ( html ) {
			html.hidden = 'html' !== mode;
		}
	}

	function previewDialog() {
		var dialog = document.querySelector( '[data-workflow-preview-dialog]' );

		if ( dialog ) {
			return dialog;
		}

		dialog = document.createElement( 'dialog' );
		dialog.className = 'nowcrm-workflow-preview';
		dialog.setAttribute( 'data-workflow-preview-dialog', '' );
		dialog.innerHTML = '<div class="nowcrm-workflow-preview__bar">' +
			'<strong data-workflow-preview-subject></strong>' +
			'<button type="button" class="button" data-workflow-preview-close></button>' +
			'</div>' +
			'<iframe title="" sandbox="" data-workflow-preview-frame></iframe>';
		document.body.appendChild( dialog );

		dialog.querySelector( '[data-workflow-preview-close]' ).addEventListener( 'click', function () {
			if ( typeof dialog.close === 'function' ) {
				dialog.close();
			}
		} );

		return dialog;
	}

	function openPreview( result ) {
		var dialog = previewDialog();
		var subject = dialog.querySelector( '[data-workflow-preview-subject]' );
		var frame = dialog.querySelector( '[data-workflow-preview-frame]' );
		var close = dialog.querySelector( '[data-workflow-preview-close]' );

		subject.textContent = result.subject || '';
		close.textContent = config.labels.close;
		frame.title = config.labels.preview;
		frame.srcdoc = result.html || '';

		if ( typeof dialog.showModal === 'function' ) {
			dialog.showModal();
		} else {
			dialog.setAttribute( 'open', '' );
		}
	}

	function nearestScope( element ) {
		return element.closest( '[data-workflow-email]' );
	}

	document.addEventListener( 'click', function ( event ) {
		var trigger = event.target.closest( '[data-workflow-apply], [data-workflow-preview], [data-workflow-test], [data-workflow-preview-close]' );
		if ( ! trigger ) {
			return;
		}

		if ( trigger.hasAttribute( 'data-workflow-preview-close' ) ) {
			var open = trigger.closest( 'dialog' );
			if ( open && typeof open.close === 'function' ) {
				open.close();
			}
			return;
		}

		var scope = nearestScope( trigger );
		if ( ! scope ) {
			return;
		}

		if ( trigger.hasAttribute( 'data-workflow-apply' ) ) {
			event.preventDefault();
			var select = scope.querySelector( '[data-workflow-template]' );
			var templateId = select ? select.value : '';

			if ( ! templateId ) {
				status( scope, config.labels.choose, true );
				return;
			}

			var current = collect( scope );
			var hasContent = [ current.subject, current.preheader, current.content ].some( function ( value ) {
				return String( value || '' ).trim() !== '';
			} );

			if ( hasContent && ! window.confirm( config.labels.overwrite ) ) {
				return;
			}

			status( scope, config.labels.loading, false );
			post( config.applyAction, {
				source: current.source,
				source_id: current.source_id,
				template_id: templateId
			} ).then( function ( result ) {
				writeField( scope, 'mode', result.mode );
				writeField( scope, 'subject', result.subject );
				writeField( scope, 'preheader', result.preheader );
				writeField( scope, 'content', result.content );
				seedSequenceBlocks( scope, result.content );
				syncMode( scope );
				status( scope, config.labels.applied, false );
			} ).catch( function ( error ) {
				status( scope, error.message, true );
			} );
			return;
		}

		if ( trigger.hasAttribute( 'data-workflow-preview' ) ) {
			event.preventDefault();
			status( scope, config.labels.loading, false );
			post( config.previewAction, collect( scope ) ).then( function ( result ) {
				status( scope, '', false );
				openPreview( result );
			} ).catch( function ( error ) {
				status( scope, error.message, true );
			} );
			return;
		}

		if ( trigger.hasAttribute( 'data-workflow-test' ) ) {
			event.preventDefault();
			if ( ! window.confirm( config.labels.test.replace( '%s', config.email ) ) ) {
				return;
			}
			status( scope, config.labels.loading, false );
			var payload = collect( scope );
			payload.confirm = '1';
			post( config.testAction, payload ).then( function () {
				status( scope, config.labels.queued, false );
			} ).catch( function ( error ) {
				status( scope, error.message, true );
			} );
		}
	} );

	document.addEventListener( 'change', function ( event ) {
		var mode = event.target.closest( '[data-workflow-field="mode"]' );
		if ( ! mode ) {
			return;
		}
		var scope = nearestScope( mode );
		if ( scope ) {
			syncBlocks( scope );
			syncMode( scope );
		}
	} );

	document.addEventListener( 'submit', function ( event ) {
		var scope = event.target.closest( '[data-workflow-email]' );
		if ( ! scope ) {
			return;
		}
		syncBlocks( scope );
	}, true );

	function initScope( scope ) {
		if ( ! scope || ! scope.matches || ! scope.matches( '[data-workflow-email]' ) ) {
			return;
		}
		syncMode( scope );
	}

	function initAll() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-workflow-email]' ), initScope );
	}

	// Editors that clone step cards call this after inserting the clone, or fire
	// nowcrm:workflow-email:init on the new card. Binding is delegated above, so
	// this only restores the per-card mode panes.
	window.NowCRMWorkflowEmail = { init: initScope };

	document.addEventListener( 'nowcrm:workflow-email:init', function ( event ) {
		var scope = event.detail && event.detail.scope ? event.detail.scope : event.target.closest( '[data-workflow-email]' );
		initScope( scope );
	} );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initAll );
	} else {
		initAll();
	}
}() );
