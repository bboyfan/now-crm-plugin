import apiFetch from '@wordpress/api-fetch';
import type { Block } from '@wordpress/blocks';
import { Button, CheckboxControl, Modal } from '@wordpress/components';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
import { registerConditionalBlock } from '../campaign-editor/conditional';
import type { EditorBridge, EditorBridgeState, EmailDesign } from '../campaign-editor/bridge';
import { htmlToBlocks } from './import-html';

type Smartcode = { token: string; label: string };
type SmartcodeGroup = { label: string; items: Smartcode[] };

type DesignTokenPreset = { name: string; slug: string; size?: string; fontFamily?: string };

type Config = {
	postType: string;
	postId: number | string;
	settings: Record< string, unknown >;
	tokens: {
		fontSizes: DesignTokenPreset[];
		fontFamilies: DesignTokenPreset[];
		spacingSizes: DesignTokenPreset[];
	};
	initialEdits: Record< string, unknown >;
	saveUrl: string;
	returnUrl: string;
	nonce: string;
	revision: string;
	title: string;
	embed: boolean;
	campaignId: number | string;
	targetType?: string;
	design?: Partial< EmailDesign >;
	smartcodes?: SmartcodeGroup[];
	labels: { save: string; back: string; saving: string; failed: string; changed: string };
};

/** State the campaign workspace reads through the same-origin frame. */

type EditorSelect = {
	getEditedPostContent: () => string;
	getEditedPostAttribute: ( attribute: string ) => string;
	getCurrentPost: () => { id?: number } | undefined;
	isEditedPostDirty: () => boolean;
	getBlockCount?: () => number;
	getEditorSettings?: () => Record< string, any >;
};

type WordPressEditor = {
	editPost: {
		initializeEditor: (
			id: string,
			postType: string,
			postId: number,
			settings: Record< string, unknown >,
			initialEdits: Record< string, unknown >
		) => unknown;
	};
	data: {
		select: ( store: string ) => EditorSelect;
		subscribe: ( listener: () => void ) => () => void;
		dispatch: ( store: string ) => {
			removeEditorPanel?: ( panel: string ) => void;
			lockPostAutosaving?: ( key: string ) => void;
			lockPostSaving?: ( key: string ) => void;
			updateEditorSettings?: ( settings: Record< string, unknown > ) => void;
			resetEditorBlocks?: ( blocks: Array< unknown > ) => void;
			resetBlocks?: ( blocks: Block[] ) => void;
			insertBlocks?: ( blocks: Block[], index?: number ) => void;
		};
	};
	blocks?: { parse: ( content: string ) => Array< unknown > };
	domReady: ( callback: () => void ) => void;
	mediaUtils?: { uploadMedia?: unknown };
};

declare global {
	interface Window {
		wp?: WordPressEditor;
		nowcrmContentEditor?: Config;
	}
}

const EMPTY_DESIGN: EmailDesign = { body: '', content: '', heading: '', text: '', link: '' };
const DESIGN_DEFAULTS: EmailDesign = { body: '#f4f5f7', content: '#ffffff', heading: '#111827', text: '#1f2937', link: '#1f2937' };
const DESIGN_FIELDS: Array< { key: keyof EmailDesign; label: string } > = [
	{ key: 'body', label: __( 'Body background', 'now-crm' ) },
	{ key: 'content', label: __( 'Content background', 'now-crm' ) },
	{ key: 'heading', label: __( 'Heading', 'now-crm' ) },
	{ key: 'text', label: __( 'Text', 'now-crm' ) },
	{ key: 'link', label: __( 'Link', 'now-crm' ) },
];

// wp-admin loads wp before this bundle; the optional type only satisfies TS.
const editor = window.wp as WordPressEditor;
// wp-admin localizes this object before the bundle runs; the optional type only
// satisfies TS. Both editor modes need the same object shape.
const config = window.nowcrmContentEditor as Config;
// WP_Scripts::localize() stringifies top-level scalars, so normalize once and
// use numbers everywhere afterwards.
const postId = Number( config.postId );
const campaignId = Number( config.campaignId );

/**
 * Core's global-styles renderer replaces `__experimentalFeatures` after
 * `initializeEditor()`. Re-apply the email tokens whenever the store changes,
 * but only when one of the three theme token arrays actually differs.
 */
function applyDesignTokens(): void {
	const select = editor.data.select( 'core/editor' );
	const dispatch = editor.data.dispatch( 'core/editor' );
	const getSettings = select.getEditorSettings;
	const updateSettings = dispatch.updateEditorSettings;

	if ( ! config.tokens || ! getSettings || ! updateSettings ) {
		return;
	}

	const settings = getSettings();
	const features = ( settings.__experimentalFeatures ?? {} ) as Record< string, any >;
	const typography = ( features.typography ?? {} ) as Record< string, any >;
	const spacing = ( features.spacing ?? {} ) as Record< string, any >;

	if (
		JSON.stringify( typography.fontSizes?.theme ) === JSON.stringify( config.tokens.fontSizes ) &&
		JSON.stringify( typography.fontFamilies?.theme ) === JSON.stringify( config.tokens.fontFamilies ) &&
		JSON.stringify( spacing.spacingSizes?.theme ) === JSON.stringify( config.tokens.spacingSizes )
	) {
		return;
	}

	updateSettings( {
		...settings,
		fontSizes: config.tokens.fontSizes,
		__experimentalFeatures: {
			...features,
			typography: {
				...typography,
				fontSizes: { ...( typography.fontSizes ?? {} ), theme: config.tokens.fontSizes },
				fontFamilies: { ...( typography.fontFamilies ?? {} ), theme: config.tokens.fontFamilies },
			},
			spacing: {
				...spacing,
				spacingSizes: { ...( spacing.spacingSizes ?? {} ), theme: config.tokens.spacingSizes },
			},
		},
	} );
}

function normalizeDesign( value?: Partial< EmailDesign > | null ): EmailDesign {
	const design = { ...EMPTY_DESIGN };
	( Object.keys( EMPTY_DESIGN ) as Array< keyof EmailDesign > ).forEach( ( key ) => {
		const color = value?.[ key ];
		design[ key ] = typeof color === 'string' && /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i.test( color.trim() )
			? color.trim().toLowerCase()
			: '';
	} );
	return design;
}

const designState = normalizeDesign( config?.design );
let designBaseline = JSON.stringify( designState );

function designCss( design: EmailDesign ): string {
	return [
		design.body ? `.editor-styles-wrapper{background-color:${ design.body };}` : '',
		design.content ? `.editor-styles-wrapper .is-root-container{background-color:${ design.content };}` : '',
		design.text ? `.editor-styles-wrapper{color:${ design.text };}` : '',
		design.heading ? `.editor-styles-wrapper :where(h1,h2,h3,h4,h5,h6){color:${ design.heading };}` : '',
		design.link ? `.editor-styles-wrapper a{color:${ design.link };}` : '',
	].filter( Boolean ).join( '\n' );
}

function setDesignStyle( doc: Document, css: string ): void {
	let style = doc.getElementById( 'nowcrm-design-canvas' ) as HTMLStyleElement | null;
	if ( ! css ) {
		style?.remove();
		return;
	}
	if ( ! style ) {
		style = doc.createElement( 'style' );
		style.id = 'nowcrm-design-canvas';
		doc.head.appendChild( style );
	}
	style.textContent = css;
}

function applyCanvasDesign(): void {
	const css = designCss( designState );
	setDesignStyle( document, css );
	document.querySelectorAll< HTMLIFrameElement >( 'iframe[name="editor-canvas"]' ).forEach( ( frame ) => {
		try {
			if ( frame.contentDocument ) {
				setDesignStyle( frame.contentDocument, css );
			}
		} catch ( error ) {
			// A non-readable frame cannot show the design; never block editing.
		}
	} );
}

function commitDesign( next: EmailDesign ): void {
	Object.assign( designState, normalizeDesign( next ) );
	applyCanvasDesign();
	emit( true );
}

function hideCoreControls(): void {
	// The working post is private scaffolding, never the campaign. Core save and
	// publish controls would either be misleading or write the wrong record.
	document
		.querySelectorAll< HTMLElement >(
			'.editor-post-publish-button__button, .editor-post-publish-panel__toggle, .editor-post-preview'
		)
		.forEach( ( element ) => {
			element.hidden = true;
		} );

	if ( config?.embed ) {
		// The campaign workspace owns the internal title, so the dummy title
		// stays read-only and out of the way.
		document.querySelectorAll< HTMLElement >( '.editor-post-title' ).forEach( ( element ) => {
			element.hidden = true;
		} );
	}
}

function status( message: string ): void {
	const node = document.querySelector< HTMLElement >( '[data-nowcrm-editor-status]' );
	if ( node ) {
		node.textContent = message;
	}
}

function save(): void {
	const button = document.querySelector< HTMLButtonElement >( '[data-nowcrm-editor-save]' );
	if ( ! button || button.disabled ) {
		return;
	}

	button.disabled = true;
	status( config.labels.saving );

	const select = editor.data.select( 'core/editor' );

	apiFetch( {
		url: config.saveUrl,
		method: 'PUT',
		data: {
			title: select.getEditedPostAttribute( 'title' ),
			blocks: select.getEditedPostContent(),
			revision: config.revision,
		},
		headers: { 'X-WP-Nonce': config.nonce },
	} )
		.then( () => {
			window.location.assign( config.returnUrl );
		} )
		.catch( ( reason: { code?: string; message?: string } ) => {
			button.disabled = false;
			status(
				reason.code === 'nowcrm_draft_changed' || reason.code === 'nowcrm_template_changed'
					? config.labels.changed
					: reason.message || config.labels.failed
			);
		} );
}

const bridgeState = {
	ready: false,
	baseline: '',
	revision: config?.revision ?? '',
};
const listeners = new Set< ( state: EditorBridgeState ) => void >();
const templateListeners = new Set< () => void >();
let last: EditorBridgeState | null = null;

function content(): string {
	try {
		const value = editor.data.select( 'core/editor' ).getEditedPostContent();
		return typeof value === 'string' ? value : '';
	} catch ( error ) {
		return '';
	}
}

function snapshot(): EditorBridgeState {
	return {
		ready: bridgeState.ready,
		dirty:
			bridgeState.ready &&
			( content() !== bridgeState.baseline || JSON.stringify( designState ) !== designBaseline ),
		revision: bridgeState.revision,
		design: { ...designState },
	};
}

function emit( force = false ): void {
	if ( ! bridgeState.ready ) {
		return;
	}

	const next = snapshot();
	if (
		! force &&
		last &&
		last.ready === next.ready &&
		last.dirty === next.dirty &&
		last.revision === next.revision &&
		JSON.stringify( last.design ) === JSON.stringify( next.design )
	) {
		return;
	}

	last = next;
	listeners.forEach( ( listener ) => listener( next ) );
}

function hasLoadedPost(): boolean {
	try {
		const post = editor.data.select( 'core/editor' ).getCurrentPost();
		return !! post && Number( post.id ) === postId;
	} catch ( error ) {
		return false;
	}
}

function markReady(): void {
	if ( bridgeState.ready || ! hasLoadedPost() ) {
		return;
	}

	bridgeState.baseline = content();
	designBaseline = JSON.stringify( designState );
	bridgeState.ready = true;
	emit( true );
}

function publishBridge(): void {
	const bridge: EditorBridge = {
		subscribe( listener ) {
			listeners.add( listener );
			if ( last ) {
				listener( last );
			} else if ( bridgeState.ready ) {
				listener( snapshot() );
			}

			return () => {
				listeners.delete( listener );
			};
		},
		blocks: () => ( bridgeState.ready ? content() : null ),
		design: () => ( { ...designState } ),
		isReady: () => bridgeState.ready,
		isDirty: () => snapshot().dirty,
		revision: () => bridgeState.revision,
		campaignId: () => campaignId,
		markSaved( revision, blocks, design ) {
			// The submitted serialization is always the new baseline. Live content
			// is compared against it, so retained edits (or an undo back to older
			// content while the write was in flight) stay dirty without touching
			// the editor buffer. The same rule applies to the colour design.
			bridgeState.revision = revision;
			bridgeState.baseline = blocks;
			designBaseline = JSON.stringify( normalizeDesign( design ) );
			emit( true );
		},
		subscribeTemplatePicker( listener ) {
			templateListeners.add( listener );
			return () => {
				templateListeners.delete( listener );
			};
		},
		requestTemplatePicker() {
			templateListeners.forEach( ( listener ) => listener() );
		},
	};

	if ( 'sequence' === config?.targetType || 'automation' === config?.targetType ) {
		// Sequences and automations reuse one blank working post for many steps.
		// The page seeds the canvas per step and reads it back; core
		// resetEditorBlocks updates the entity that getEditedPostContent reads.
		bridge.replaceBlocks = ( serialized: string ) => {
			if ( ! bridgeState.ready ) {
				return { ready: false, blocks: null };
			}

			const dispatch = editor.data.dispatch( 'core/editor' );
			const parse = editor.blocks?.parse;

			if ( ! parse || typeof dispatch.resetEditorBlocks !== 'function' ) {
				return { ready: true, blocks: null };
			}

			try {
				dispatch.resetEditorBlocks( parse( serialized ) );
				// Seeding is not an edit: the parsed serialization becomes the new
				// baseline so untouched content keeps its persisted bytes.
				const actual = content();
				bridgeState.baseline = actual;
				emit( true );
				return { ready: true, blocks: actual };
			} catch ( error ) {
				return { ready: true, blocks: null };
			}
		};
	}

	window.nowcrmEditorBridge = bridge;
}

function DesignPanel() {
	const [ design, setDesign ] = useState< EmailDesign >( () => ( { ...designState } ) );

	useEffect( () => {
		applyCanvasDesign();
	}, [] );

	const update = ( key: keyof EmailDesign, value: string ) => {
		const next = { ...design, [ key ]: value };
		setDesign( next );
		commitDesign( next );
	};

	const reset = () => {
		const next = { ...EMPTY_DESIGN };
		setDesign( next );
		commitDesign( next );
	};

	return (
		<PluginDocumentSettingPanel name="nowcrm-design" title={ __( '全域設計', 'now-crm' ) }>
			<p className="nowcrm-panel-help">
				{ __( '只套用到 Gutenberg 正文與寄送郵件；Raw HTML 使用自己的樣式。', 'now-crm' ) }
			</p>
			{ DESIGN_FIELDS.map( ( field ) => (
				<div className="nowcrm-design-field" key={ field.key }>
					<span>{ field.label }</span>
					<div className="nowcrm-design-field__control">
						<input
							type="color"
							aria-label={ field.label }
							value={ design[ field.key ] || DESIGN_DEFAULTS[ field.key ] }
							onChange={ ( event ) => update( field.key, event.target.value.toLowerCase() ) }
						/>
						<code>{ design[ field.key ] || __( '預設', 'now-crm' ) }</code>
						<button
							type="button"
							className="button-link"
							disabled={ ! design[ field.key ] }
							onClick={ () => update( field.key, '' ) }
						>
							{ __( '重設', 'now-crm' ) }
						</button>
					</div>
				</div>
			) ) }
			<button type="button" className="button" disabled={ ! Object.values( design ).some( Boolean ) } onClick={ reset }>
				{ __( '全部重設', 'now-crm' ) }
			</button>
		</PluginDocumentSettingPanel>
	);
}

async function copyToken( token: string ): Promise< void > {
	if ( navigator.clipboard?.writeText ) {
		await navigator.clipboard.writeText( token );
		return;
	}

	const area = document.createElement( 'textarea' );
	area.value = token;
	area.style.position = 'fixed';
	area.style.opacity = '0';
	document.body.appendChild( area );
	area.select();
	const copied = document.execCommand( 'copy' );
	area.remove();
	if ( ! copied ) {
		throw new Error( 'copy failed' );
	}
}

function SmartcodesPanel() {
	const groups = config.smartcodes ?? [];
	const [ query, setQuery ] = useState( '' );
	const [ copied, setCopied ] = useState( '' );
	const search = query.trim().toLowerCase();
	const visible = groups
		.map( ( group ) => ( {
			label: group.label,
			items: group.items.filter(
				( item ) => ! search || item.token.toLowerCase().includes( search ) || item.label.toLowerCase().includes( search )
			),
		} ) )
		.filter( ( group ) => group.items.length > 0 );

	const copy = ( token: string ) => {
		copyToken( token )
			.then( () => {
				setCopied( token );
			} )
			.catch( () => {
				setCopied( '' );
			} );
	};

	return (
		<PluginDocumentSettingPanel name="nowcrm-smartcodes" title={ __( 'Smartcodes', 'now-crm' ) }>
			<p className="nowcrm-panel-help">
				{ __( '寄送與預覽時會換成實際內容。點選即可複製。', 'now-crm' ) }
			</p>
			<input
				type="search"
				className="nowcrm-smartcodes__search"
				placeholder={ __( '搜尋 Smartcodes', 'now-crm' ) }
				value={ query }
				onChange={ ( event ) => setQuery( event.target.value ) }
			/>
			{ visible.map( ( group ) => (
				<div className="nowcrm-smartcodes__group" key={ group.label }>
					<h3>{ group.label }</h3>
					{ group.items.map( ( item ) => (
						<div className="nowcrm-smartcodes__item" key={ item.token }>
							<span>{ item.label } <code>{ item.token }</code></span>
							<button type="button" className="button-link" onClick={ () => copy( item.token ) }>
								{ __( '複製', 'now-crm' ) }
							</button>
						</div>
					) ) }
				</div>
			) ) }
			{ visible.length === 0 && <p className="nowcrm-panel-help">{ __( '沒有符合的 Smartcodes。', 'now-crm' ) }</p> }
			<p className="nowcrm-smartcodes__status" role="status" aria-live="polite">
				{ copied ? `${ __( '已複製', 'now-crm' ) } ${ copied }` : '' }
			</p>
		</PluginDocumentSettingPanel>
	);
}

function TemplatePanel() {
	return (
		<PluginDocumentSettingPanel name="nowcrm-template" title={ __( '範本', 'now-crm' ) }>
			<p className="nowcrm-panel-help">
				{ __( '加入已發佈的電子報範本會取代目前編輯方式的正文、主旨和預覽文字。', 'now-crm' ) }
			</p>
			<button type="button" className="button" onClick={ () => window.nowcrmEditorBridge?.requestTemplatePicker() }>
				{ __( '加入範本', 'now-crm' ) }
			</button>
		</PluginDocumentSettingPanel>
	);
}

function ImportHtmlPanel() {
	const [ open, setOpen ] = useState( false );
	const [ source, setSource ] = useState( '' );
	const [ replace, setReplace ] = useState( true );
	const [ error, setError ] = useState( '' );

	const runImport = () => {
		setError( '' );

		let blocks: Block[];

		try {
			blocks = htmlToBlocks( source );
		} catch {
			setError( __( 'HTML 無法解析，請確認內容是否完整。', 'now-crm' ) );
			return;
		}

		if ( blocks.length === 0 ) {
			setError( __( '沒有找到可匯入的標題、段落、清單、圖片或按鈕。', 'now-crm' ) );
			return;
		}

		const dispatch = editor.data.dispatch( 'core/block-editor' );

		if ( replace ) {
			dispatch.resetBlocks?.( blocks );
		} else {
			const count = editor.data.select( 'core/block-editor' ).getBlockCount?.() ?? 0;
			dispatch.insertBlocks?.( blocks, count );
		}

		setSource( '' );
		setOpen( false );
	};

	return (
		<>
			<PluginDocumentSettingPanel name="nowcrm-import-html" title={ __( '匯入 HTML', 'now-crm' ) }>
				<p className="nowcrm-panel-help">
					{ __( '把現有電子報 HTML 的標題、段落、清單、圖片與按鈕轉成可編輯區塊。表格與原本的版面樣式不會保留；需要像素級原樣時請用 Raw HTML。', 'now-crm' ) }
				</p>
				<Button variant="secondary" onClick={ () => { setError( '' ); setOpen( true ); } }>
					{ __( '匯入 HTML', 'now-crm' ) }
				</Button>
			</PluginDocumentSettingPanel>
			{ open && (
				<Modal
					title={ __( '匯入 HTML 為區塊', 'now-crm' ) }
					className="nowcrm-import-modal"
					onRequestClose={ () => setOpen( false ) }
				>
					<p className="nowcrm-import-modal__note">
						{ __( '貼上完整 HTML 或內容片段。匯入後可直接在 Gutenberg 編輯；表格會拆成文字與圖片，不保留格線。', 'now-crm' ) }
					</p>
					<textarea
						className="nowcrm-import-modal__source"
						value={ source }
						onChange={ ( event ) => setSource( event.target.value ) }
						spellCheck={ false }
						placeholder="<h2>標題</h2><p>段落…</p>"
						aria-label={ __( 'HTML 原始碼', 'now-crm' ) }
					/>
					<CheckboxControl
						checked={ replace }
						onChange={ setReplace }
						label={ __( '取代目前內容（取消則附加到最後）', 'now-crm' ) }
					/>
					{ error && <p className="nowcrm-import-modal__error" role="alert">{ error }</p> }
					<div className="nowcrm-import-modal__actions">
						<Button variant="tertiary" onClick={ () => setOpen( false ) }>{ __( '取消', 'now-crm' ) }</Button>
						<Button variant="primary" disabled={ '' === source.trim() } onClick={ runImport }>{ __( '匯入', 'now-crm' ) }</Button>
					</div>
				</Modal>
			) }
		</>
	);
}

function CampaignPanels() {
	return (
		<>
			<TemplatePanel />
			<ImportHtmlPanel />
			<DesignPanel />
			<SmartcodesPanel />
		</>
	);
}

if ( config ) {
	registerConditionalBlock();

	if ( config.embed ) {
		publishBridge();
		document.addEventListener( 'keydown', ( event ) => {
			// Core treats Ctrl/Cmd+S as a save. The parent owns persistence.
			if ( ( event.ctrlKey || event.metaKey ) && 's' === event.key.toLowerCase() ) {
				event.preventDefault();
			}
		} );
		// Re-apply design colours when a device preview reloads the canvas.
		document.addEventListener(
			'load',
			( event ) => {
				if ( event.target instanceof HTMLIFrameElement && 'editor-canvas' === event.target.name ) {
					applyCanvasDesign();
				}
			},
			true
		);
	}

	if ( config.embed && campaignId > 0 ) {
		registerPlugin( 'nowcrm-campaign-panels', { render: CampaignPanels } );
	} else if ( config.embed && config.smartcodes && config.smartcodes.length > 0 ) {
		registerPlugin( 'nowcrm-workflow-panels', { render: SmartcodesPanel } );
	}

	editor.domReady( () => {
		editor.editPost.initializeEditor(
			'editor',
			config.postType,
			postId,
			{ ...config.settings, mediaUpload: editor.mediaUtils?.uploadMedia },
			config.initialEdits
		);

		applyDesignTokens();
		editor.data.subscribe( applyDesignTokens );

		try {
			const dispatch = editor.data.dispatch( 'core/editor' );
			dispatch.removeEditorPanel?.( 'post-status' );
			// The working post is not the campaign. Disable core autosave/save so
			// the only write-back path is the NOW CRM save.
			dispatch.lockPostAutosaving?.( 'nowcrm-working-copy' );
			dispatch.lockPostSaving?.( 'nowcrm-working-copy' );
		} catch ( error ) {
			// Optional editor controls; never block the editor for them.
		}

		hideCoreControls();
		window.setTimeout( hideCoreControls, 1000 );

		if ( config.embed ) {
			// The campaign workspace reads the blocks and writes the campaign.
			editor.data.subscribe( () => {
				markReady();
				emit();
			} );
			markReady();
			return;
		}

		document
			.querySelector( '[data-nowcrm-editor-save]' )
			?.addEventListener( 'click', save );

		window.addEventListener( 'beforeunload', ( event ) => {
			if ( editor.data.select( 'core/editor' ).isEditedPostDirty() ) {
				event.preventDefault();
				event.returnValue = '';
			}
		} );
	} );
}
