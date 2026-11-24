import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { EmailDesign } from './bridge';
import type { Audience } from './recipients';


export type Draft = {
	title: string;
	subject: string;
	preheader: string;
	mode: 'blocks' | 'html';
	blocks: string;
	html: string;
	from_name: string;
	from_address: string;
	reply_to: string;
	footer_text: string;
	audience: Audience;
	ab: { enabled: boolean; subject_b: string };
	tracking: { open: boolean; click: boolean };
	utm: { enabled: boolean; source: string; medium: string; campaign: string; term: string; content: string };
	design: EmailDesign;
};
export type TemplateOption = { id: number; name: string; mode: Draft[ 'mode' ]; subject: string; preheader: string };
export type EmailPreview = { html: string; subject: string };

export function TextField( { label, value, onChange, type = 'text', placeholder }: {
	label: string;
	value: string;
	onChange: ( value: string ) => void;
	type?: string;
	placeholder?: string;
} ) {
	return (
		<label className="nowcrm-workspace__field">
			<span>{ label }</span>
			<input type={ type } value={ value } onInput={ ( e ) => onChange( e.currentTarget.value ) } placeholder={ placeholder } />
		</label>
	);
}

type CodeMirrorInstance = {
	getValue: () => string;
	setValue: ( value: string ) => void;
	on: ( event: string, handler: () => void ) => void;
	off?: ( event: string, handler: () => void ) => void;
	toTextArea: () => void;
};

type CodeEditorWindow = Window & {
	wp?: {
		codeEditor?: {
			initialize: ( element: HTMLElement, settings: Record< string, unknown > ) => { codemirror?: CodeMirrorInstance } | null;
		};
	};
};

/**
 * Core CodeMirror surface for the raw HTML body. WordPress supplies the
 * settings, so the user's indent/syntax preferences are respected and no
 * second editor library ships with the plugin.
 */
function HtmlSourceEditor( { value, onChange }: { value: string; onChange: ( value: string ) => void } ) {
	const textareaRef = useRef< HTMLTextAreaElement >( null );
	const mirrorRef = useRef< CodeMirrorInstance | null >( null );
	const valueRef = useRef( value );
	valueRef.current = value;

	useEffect( () => {
		const textarea = textareaRef.current;
		const codeEditor = ( window as CodeEditorWindow ).wp?.codeEditor;
		const settings = window.nowcrmCampaignWorkspace?.codeEditor;

		if ( ! textarea || ! codeEditor || ! settings ) {
			return;
		}

		const instance = codeEditor.initialize( textarea, settings );
		const mirror = instance?.codemirror;

		if ( ! mirror ) {
			return;
		}

		mirrorRef.current = mirror;

		const sync = () => {
			const next = mirror.getValue();

			if ( next !== valueRef.current ) {
				onChange( next );
			}
		};

		mirror.on( 'change', sync );

		return () => {
			mirror.off?.( 'change', sync );
			mirror.toTextArea();
			mirrorRef.current = null;
		};
	}, [] );

	// Keep external writes (template apply, mode switch, draft reload) in sync
	// without resetting the cursor on local keystrokes.
	useEffect( () => {
		const mirror = mirrorRef.current;

		if ( mirror && mirror.getValue() !== value ) {
			mirror.setValue( value );
		}
	}, [ value ] );

	return (
		<textarea
			ref={ textareaRef }
			className="nowcrm-workspace__html nowcrm-workspace__html--full"
			defaultValue={ value }
			spellCheck={ false }
			aria-label={ __( '郵件 HTML 正文', 'now-crm' ) }
		/>
	);
}

function reasonMessage( reason: unknown ): string {
	if ( reason && typeof reason === 'object' && 'message' in reason && typeof reason.message === 'string' ) {
		return reason.message;
	}

	return '';
}

function HtmlPreviewPane( { html, previewHtml }: { html: string; previewHtml: ( html: string, signal?: AbortSignal ) => Promise< EmailPreview > } ) {
	const [ preview, setPreview ] = useState< EmailPreview | null >( null );
	const [ error, setError ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const sequence = useRef( 0 );

	useEffect( () => {
		const id = ++sequence.current;
		const controller = new AbortController();
		setLoading( true );

		const timer = window.setTimeout( () => {
			previewHtml( html, controller.signal )
				.then( ( next ) => {
					if ( sequence.current === id ) {
						setPreview( next );
						setError( '' );
					}
				} )
				.catch( ( reason: unknown ) => {
					const aborted = !! reason && typeof reason === 'object' && 'name' in reason && reason.name === 'AbortError';

					if ( sequence.current === id && ! aborted ) {
						setError( reasonMessage( reason ) || __( '無法產生預覽，請稍後再試。', 'now-crm' ) );
					}
				} )
				.finally( () => {
					if ( sequence.current === id ) {
						setLoading( false );
					}
				} );
		}, 600 );

		return () => {
			window.clearTimeout( timer );
			controller.abort();
		};
	}, [ html, previewHtml ] );

	return (
		<aside className="nowcrm-workspace__html-preview" aria-label={ __( '即時預覽', 'now-crm' ) }>
			<div className="nowcrm-workspace__html-preview-head">
				<strong>{ __( '即時預覽', 'now-crm' ) }</strong>
				<span role="status">{ loading ? __( '更新中…', 'now-crm' ) : '' }</span>
			</div>
			{ error && <p className="nowcrm-workspace__message" role="alert">{ error }</p> }
			{ preview ? (
				<iframe title={ __( '郵件即時預覽', 'now-crm' ) } sandbox="" srcDoc={ preview.html } />
			) : (
				<p className="nowcrm-workspace__html-preview-empty" role="status">{ __( '輸入 HTML 後會顯示預覽。', 'now-crm' ) }</p>
			) }
		</aside>
	);
}

export function ContentStep( { draft, change, onModeChange, busy, frame, frameReady, frameError, onReloadFrame, onPreview, previewHtml }: {
	draft: Draft;
	change: < K extends keyof Draft >( field: K, value: Draft[ K ] ) => void;
	onModeChange: ( mode: Draft[ 'mode' ] ) => void;
	busy: boolean;
	frame: ReactNode | null;
	frameReady: boolean;
	frameError: string;
	onReloadFrame: () => void;
	onPreview: () => void;
	previewHtml: ( html: string, signal?: AbortSignal ) => Promise< EmailPreview >;
} ) {
	return (
		<section className="nowcrm-workspace__panel nowcrm-workspace__panel--content" aria-labelledby="nowcrm-content">
			<h2 id="nowcrm-content" tabIndex={ -1 }>{ __( '內容', 'now-crm' ) }</h2>
			<div className="nowcrm-workspace__content-toolbar">
				<label className="nowcrm-workspace__field nowcrm-workspace__title-field">
					<span>{ __( '內部標題', 'now-crm' ) }</span>
					<input value={ draft.title } onInput={ ( event ) => change( 'title', event.currentTarget.value ) } />
				</label>
				<div className="nowcrm-workspace__mode">
					<label className="nowcrm-workspace__mode-select">
						<span>{ __( '編輯方式', 'now-crm' ) }</span>
						<select value={ draft.mode } disabled={ busy } onChange={ ( event ) => onModeChange( event.target.value === 'html' ? 'html' : 'blocks' ) }>
							<option value="blocks">Gutenberg</option>
							<option value="html">Raw HTML</option>
						</select>
					</label>
					<button
						type="button"
						className="button nowcrm-workspace__preview-button"
						disabled={ busy }
						onClick={ onPreview }
						aria-label={ __( '預覽郵件', 'now-crm' ) }
						title={ __( '預覽未儲存的內容', 'now-crm' ) }
					>
						<svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="18" height="18">
							<path d="M12 5c-5 0-9 4.6-10 7 1 2.4 5 7 10 7s9-4.6 10-7c-1-2.4-5-7-10-7Zm0 12a5 5 0 1 1 0-10 5 5 0 0 1 0 10Zm0-2a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" fill="currentColor" />
						</svg>
						<span>{ __( '預覽', 'now-crm' ) }</span>
					</button>
				</div>
			</div>
			{ draft.mode === 'blocks' ? (
				<div className="nowcrm-workspace__frame">
					{ frame }
					{ ! frameReady && ! frameError && <p role="status" className="nowcrm-workspace__frame-status">{ __( '正在載入 Gutenberg 編輯器…', 'now-crm' ) }</p> }
					{ frameError && (
						<div className="nowcrm-workspace__frame-status" role="alert">
							<p>{ frameError }</p>
							<button type="button" className="button" onClick={ onReloadFrame }>{ __( '重新載入編輯器', 'now-crm' ) }</button>
						</div>
					) }
				</div>
			) : (
				<div className="nowcrm-workspace__html-split">
					<div className="nowcrm-workspace__html-source">
						<span className="nowcrm-workspace__html-label">{ __( '郵件 HTML 正文', 'now-crm' ) }</span>
						<HtmlSourceEditor value={ draft.html } onChange={ ( value ) => change( 'html', value ) } />
					</div>
					<HtmlPreviewPane html={ draft.html } previewHtml={ previewHtml } />
				</div>
			) }
			<p className="description">{ __( '兩種模式各自儲存正文。完整的 HTML 文件會原樣寄出，並支援 FluentCRM 的 ##crm.unsubscribe_url## 等連結；HTML 片段則會套用 NOW 外框。預覽只讀取目前輸入，不會儲存草稿。', 'now-crm' ) }</p>
			<details>
				<summary>{ draft.mode === 'html' ? __( '頁尾補充文字（僅 HTML 片段）', 'now-crm' ) : __( '頁尾補充文字', 'now-crm' ) }</summary>
				<div className="nowcrm-workspace__fields">
					<label className="nowcrm-workspace__field">
						<span>{ __( '頁尾補充文字', 'now-crm' ) }</span>
						<textarea rows={ 2 } value={ draft.footer_text } onChange={ ( event ) => change( 'footer_text', event.target.value ) } />
					</label>
					{ draft.mode === 'html' && (
						<p className="description">{ __( '完整 HTML 文件會使用自己的頁尾；這裡的內容只用於 HTML 片段。', 'now-crm' ) }</p>
					) }
				</div>
			</details>
		</section>
	);
}

export function SettingsStep( { draft, change, showMessage = true }: {
	draft: Draft;
	change: < K extends keyof Draft >( field: K, value: Draft[ K ] ) => void;
	showMessage?: boolean;
} ) {
	return (
		<section className="nowcrm-workspace__panel" aria-labelledby="nowcrm-settings">
			<h2 id="nowcrm-settings" tabIndex={ -1 }>{ showMessage ? __( '主旨與設定', 'now-crm' ) : __( '其他設定', 'now-crm' ) }</h2>
			{ showMessage && (
				<>
					<TextField
						label={ draft.ab.enabled ? __( '郵件主旨（A 版）', 'now-crm' ) : __( '郵件主旨', 'now-crm' ) }
						value={ draft.subject }
						onChange={ ( value ) => change( 'subject', value ) }
					/>
					<TextField label={ __( '預覽文字', 'now-crm' ) } value={ draft.preheader } onChange={ ( value ) => change( 'preheader', value ) } />
				</>
			) }
			<fieldset className="nowcrm-workspace__choices">
				<legend>{ __( '主旨 A／B 測試', 'now-crm' ) }</legend>
				<label>
					<input
						type="checkbox"
						checked={ draft.ab.enabled }
						onChange={ ( e ) => change( 'ab', { ...draft.ab, enabled: e.target.checked } ) }
					/> { __( '啟用主旨 A／B 測試', 'now-crm' ) }
				</label>
				{ draft.ab.enabled && (
					<>
						<p className="description">{ __( '收件人依固定雜湊平均分成兩組，佇列會依分組寄出對應主旨。系統不會自動選出優勝版本；寄送後可在報告查看兩組結果。', 'now-crm' ) }</p>
						<TextField label={ __( '郵件主旨（B 版）', 'now-crm' ) } value={ draft.ab.subject_b } onChange={ ( value ) => change( 'ab', { ...draft.ab, subject_b: value } ) } />
					</>
				) }
			</fieldset>
			<fieldset>
				<legend>{ __( '寄件者', 'now-crm' ) }</legend>
				<div className="nowcrm-workspace__fields">
					<TextField label={ __( '寄件者名稱', 'now-crm' ) } value={ draft.from_name } onChange={ ( value ) => change( 'from_name', value ) } placeholder={ __( '使用網站設定', 'now-crm' ) } />
					<TextField label={ __( '寄件地址', 'now-crm' ) } type="email" value={ draft.from_address } onChange={ ( value ) => change( 'from_address', value ) } placeholder={ __( '使用網站設定', 'now-crm' ) } />
					<TextField label={ __( '回覆地址', 'now-crm' ) } type="email" value={ draft.reply_to } onChange={ ( value ) => change( 'reply_to', value ) } placeholder={ __( '使用網站設定', 'now-crm' ) } />
				</div>
			</fieldset>
			<details>
				<summary>{ __( 'UTM 參數', 'now-crm' ) }</summary>
				<div className="nowcrm-workspace__fields">
					<label><input type="checkbox" checked={ draft.utm.enabled } onChange={ ( e ) => change( 'utm', { ...draft.utm, enabled: e.target.checked } ) } /> { __( '加入 UTM 參數', 'now-crm' ) }</label>
					{ draft.utm.enabled && ( [ 'source', 'medium', 'campaign', 'term', 'content' ] as const ).map( ( key ) => (
						<TextField key={ key } label={ 'utm_' + key } value={ draft.utm[ key ] } onChange={ ( value ) => change( 'utm', { ...draft.utm, [ key ]: value } ) } />
					) ) }
				</div>
			</details>
		</section>
	);
}
