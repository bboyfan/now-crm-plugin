import apiFetch from '@wordpress/api-fetch';
import { Modal } from '@wordpress/components';
import { createRoot, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { ContentStep, SettingsStep, TextField, type Draft, type EmailPreview, type TemplateOption } from './composer';
import type { FormEvent } from 'react';
import { ScheduleSection, RecurringHistory, recurringFrequencyLabel, recurringStatusLabel, recurringStatusTone, type RecurringActions, type RecurringPayload, type ScheduleAction, type ScheduleValues } from './schedule';
import type { EditorBridge, EditorBridgeState, EmailDesign } from './bridge';
import {
	RecipientStep,
	type AdvancedCatalog,
	type Audience,
	type RecipientCount,
	type RecipientLookup,
	type RecipientPage,
	type Segment,
	type Term,
} from './recipients';

declare global {
	interface Window {
		nowcrmCampaignWorkspace: {
			endpoint: string;
			nonce: string;
			listUrl: string;
			listLabel: string;
			recurring: boolean;
			emailLogUrl: string;
			testLogUrl: string;
			currentEmail: string;
			timezone: string;
			contentFrameUrl: string;
			campaignId: number | string;
			tab: string;
			codeEditor: Record< string, unknown > | null;
			recurringActions: RecurringActions | null;
		};
	}
}

type State = 'scheduled' | 'sending' | 'paused' | 'sent' | 'cancelled';
type Base = { title: string; status_label: string; sending_enabled: boolean; tracking_enabled: boolean };
type Editing = Base & { state: 'draft'; draft: Draft; revision: string; lists: Term[]; tags: Term[]; segments: Segment[]; advanced?: AdvancedCatalog | null; templates?: TemplateOption[] };
type VariantStats = { subject: string; accepted: number; opened: number; clicked: number; unsubscribed: number };
type Report = Base & {
	state: State;
	subject: string;
	schedule: string;
	schedule_end: string;
	links: Array< { url: string; recipients: number } >;
	finished: string;
	variants?: Record< 'a' | 'b', VariantStats >;
	jobs: Record< 'total' | 'queued' | 'sending' | 'accepted' | 'suppressed' | 'failed' | 'unknown' | 'cancelled', number >;
	metrics: Record< 'opened' | 'clicked' | 'bounce' | 'complaint' | 'unsubscribe', number >;
};
type Workspace = Editing | Report;
type Review = { count: number; token: string; sender: string; audience: string; ready: boolean; sample: string[]; reply_to: string; preview: EmailPreview };
type Operation = 'unschedule' | 'pause' | 'resume' | 'cancel';
type Dialog = 'test' | 'confirm' | 'cancel' | 'preview' | 'template';

const config = window.nowcrmCampaignWorkspace;
const steps = config.recurring
	? [ __( '郵件內容', 'now-crm' ), __( '設定', 'now-crm' ), __( '寄送紀錄', 'now-crm' ) ]
	: [ __( '內容', 'now-crm' ), __( '主旨與設定', 'now-crm' ), __( '收件人', 'now-crm' ), __( '檢查與寄送', 'now-crm' ) ];
const initialStep = config.recurring
	? config.tab === 'settings' ? 1 : config.tab === 'history' ? 2 : 0
	: 0;

function messageOf( reason: unknown ): string {
	if ( reason && typeof reason === 'object' && 'message' in reason && typeof reason.message === 'string' ) {
		return reason.message;
	}

	return '';
}

function codeOf( reason: unknown ): string {
	if ( reason && typeof reason === 'object' && 'code' in reason && typeof reason.code === 'string' ) {
		return reason.code;
	}

	return '';
}

function request< T >( suffix = '', method = 'GET', data?: object, query: Record< string, string > = {}, signal?: AbortSignal ) {
	const url = new URL( config.endpoint );
	const route = url.searchParams.get( 'rest_route' );
	if ( route ) {
		url.searchParams.set( 'rest_route', route + suffix );
	} else {
		url.pathname = url.pathname.replace( /\/$/, '' ) + suffix;
	}
	for ( const [ key, value ] of Object.entries( query ) ) {
		url.searchParams.set( key, value );
	}
	return apiFetch< T >( {
		url: url.toString(),
		method,
		data,
		cache: 'no-store',
		signal,
		headers: { 'X-WP-Nonce': config.nonce },
	} );
}

/**
 * Render the raw HTML currently in the editor. The preview endpoint is
 * read-only, so the live pane never saves or advances the draft revision.
 */
function previewHtml( html: string, signal?: AbortSignal ) {
	return request< EmailPreview >( '/preview', 'POST', { html }, {}, signal );
}

/**
 * Draft-only, read-only recipient reads. The audience travels in the request
 * body, so previews never save the draft or touch the review snapshot.
 */
function fetchRecipients( audience: Audience, page: number ) {
	return request< RecipientPage >( '/recipients', 'POST', { audience, page } );
}

function fetchRecipientCount( audience: Audience, signal: AbortSignal ) {
	return request< RecipientCount >( '/recipients', 'POST', { audience, count_only: true }, {}, signal );
}

function fetchRecipientOptions( field: string, search: string, selected: string[] ) {
	return request< { options: RecipientLookup[] } >( '/recipient-options', 'GET', undefined, {
		field,
		search,
		selected: selected.join( ',' ),
	} );
}

function WorkspaceApp() {
	const [ data, setData ] = useState< Workspace | null >( null );
	const [ draft, setDraft ] = useState< Draft | null >( null );
	const [ step, setStep ] = useState( initialStep );
	const [ dirty, setDirty ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ notice, setNotice ] = useState( '' );
	const [ review, setReview ] = useState< Review | null >( null );
	const [ preview, setPreview ] = useState< EmailPreview | null >( null );
	const [ dialog, setDialog ] = useState< Dialog | null >( null );
	const [ mobile, setMobile ] = useState( false );
	const [ email, setEmail ] = useState( config.currentEmail );
	const [ schedule, setSchedule ] = useState( '' );
	const [ scheduleEnd, setScheduleEnd ] = useState( '' );
	const [ timing, setTiming ] = useState< 'now' | 'fixed' | 'range' >( 'now' );
	const timed = timing !== 'now';
	const [ confirmReady, setConfirmReady ] = useState( false );
	const [ templates, setTemplates ] = useState< TemplateOption[] >( [] );
	const [ templateId, setTemplateId ] = useState( 0 );
	const [ recurring, setRecurring ] = useState< RecurringPayload | null >( null );
	const selectedTemplate = templates.find( ( template ) => template.id === templateId ) ?? null;

	const frameRef = useRef< HTMLIFrameElement | null >( null );
	const bridgeRef = useRef< EditorBridge | null >( null );
	const [ frameKey, setFrameKey ] = useState( 0 );
	const [ frameLoads, setFrameLoads ] = useState( 0 );
	const [ frameState, setFrameState ] = useState< EditorBridgeState >( { ready: false, dirty: false, revision: '', design: { body: '', content: '', heading: '', text: '', link: '' } } );
	const [ frameError, setFrameError ] = useState( '' );
	const [ previewError, setPreviewError ] = useState( '' );
	const [ previewLoading, setPreviewLoading ] = useState( false );
	const [ autosaveState, setAutosaveState ] = useState< 'idle' | 'saving' | 'saved' >( 'idle' );
	const autosaveFeedback = autosaveState === 'saving'
		? <span className="description" role="status">{ __( '自動儲存中…', 'now-crm' ) }</span>
		: autosaveState === 'saved'
			? <span className="description">{ __( '已自動儲存。', 'now-crm' ) }</span>
			: null;
	const previewSeq = useRef( 0 );
	const editVersion = useRef( 0 );
	const pendingSave = useRef< Promise< Workspace | null > | null >( null );
	const autosaveFailed = useRef( false );

	const accept = ( next: Workspace ) => {
		setData( next );
		if ( next.state === 'draft' ) {
			setDraft( next.draft );
			if ( next.templates ) {
				setTemplates( next.templates );
			}
		}
		setDirty( false );
	};

	const showError = ( reason: unknown ) => {
		setError( messageOf( reason ) || __( '無法完成操作，請再試一次。', 'now-crm' ) );
	};

	useEffect( () => {
		request< Workspace >().then( accept ).catch( showError );
	}, [] );

	useEffect( () => {
		if ( ! config.recurring ) {
			return;
		}

		request< RecurringPayload >( '/recurring' ).then( setRecurring ).catch( showError );
	}, [] );

	useEffect( () => {
		if ( data?.state !== 'sending' && data?.state !== 'scheduled' ) {
			return;
		}
		let active = true;
		let inFlight = false;
		const timer = window.setInterval( async () => {
			if ( document.hidden || inFlight || busy ) {
				return;
			}
			inFlight = true;
			try {
				const next = await request< Workspace >();
				if ( active ) {
					accept( next );
				}
			} catch ( reason ) {
				if ( active ) {
					showError( reason );
				}
			} finally {
				inFlight = false;
			}
		}, 4000 );
		return () => {
			active = false;
			window.clearInterval( timer );
		};
	}, [ data?.state, busy ] );

	// Connect to the same-origin editor frame once its bundle exposes the bridge.
	useEffect( () => {
		if ( step !== 0 || draft?.mode !== 'blocks' ) {
			return;
		}

		let unsubscribe = () => {};
		let unsubscribeTemplate = () => {};
		let timer = 0;
		let tries = 0;
		const connect = () => {
			const bridge = frameRef.current?.contentWindow?.nowcrmEditorBridge;
			if ( bridge ) {
				if ( Number( bridge.campaignId() ) !== Number( config.campaignId ) ) {
					setFrameError( __( '編輯器與這封電子報不一致。請重新載入。', 'now-crm' ) );
					return;
				}
				bridgeRef.current = bridge;
				setFrameError( '' );
				unsubscribe = bridge.subscribe( ( state ) => {
					setFrameState( state );
					if ( state.dirty ) {
						autosaveFailed.current = false;
						setAutosaveState( 'idle' );
					}
				} );
				unsubscribeTemplate = bridge.subscribeTemplatePicker( () => {
					setError( '' );
					setTemplateId( 0 );
					setDialog( 'template' );
				} );
				return;
			}
			tries += 1;
			if ( tries > 40 ) {
				setFrameError( __( '無法載入 Gutenberg 編輯器。請重新載入。', 'now-crm' ) );
				return;
			}
			timer = window.setTimeout( connect, 250 );
		};

		setFrameState( { ready: false, dirty: false, revision: '', design: { body: '', content: '', heading: '', text: '', link: '' } } );
		connect();

		return () => {
			window.clearTimeout( timer );
			unsubscribe();
			unsubscribeTemplate();
			bridgeRef.current = null;
		};
	}, [ frameLoads, frameKey, step, draft?.mode ] );

	useEffect( () => {
		if ( ! dirty ) {
			return;
		}
		const protect = ( event: BeforeUnloadEvent ) => { event.preventDefault(); event.returnValue = ''; };
		window.addEventListener( 'beforeunload', protect );
		return () => window.removeEventListener( 'beforeunload', protect );
	}, [ dirty ] );

	const perform = async ( operation: () => Promise< void > ) => {
		setBusy( true );
		setError( '' );
		setNotice( '' );
		try {
			await operation();
		} catch ( reason ) {
			showError( reason );
		} finally {
			setBusy( false );
		}
	};

	const edit = < K extends keyof Draft >( field: K, value: Draft[ K ] ) => {
		editVersion.current += 1;
		autosaveFailed.current = false;
		setAutosaveState( 'idle' );
		setDraft( ( previous ) => previous ? { ...previous, [ field ]: value } : previous );
		setDirty( true );
		setReview( null );
		setConfirmReady( false );
	};

	/**
	 * Resolve the frame buffer for a guarded write.
	 *
	 * The frame must be ready and mounted at the parent revision. A clean stale
	 * frame must not be silently rebased, because it could later overwrite newer
	 * content. A clean matching frame keeps the saved parent blocks for the PUT,
	 * but its captured serialization is what markSaved compares against, since
	 * serialization can differ from persisted markup.
	 */
	const editableBlocks = ( revision: string ): { blocks: string; captured: string; dirty: boolean; design: EmailDesign } => {
		const bridge = bridgeRef.current;
		if ( ! draft || ! bridge || ! bridge.isReady() ) {
			throw new Error( __( 'Gutenberg 編輯器尚未載入完成，請稍候再試。', 'now-crm' ) );
		}
		if ( bridge.revision() !== revision ) {
			throw new Error( __( '編輯器內容來自較舊的版本。請重新載入頁面後再試。', 'now-crm' ) );
		}

		const captured = bridge.blocks();
		if ( captured === null ) {
			throw new Error( __( 'Gutenberg 編輯器尚未載入完成，請稍候再試。', 'now-crm' ) );
		}

		const frameDirty = bridge.isDirty();
		return { blocks: frameDirty ? captured : draft.blocks, captured, dirty: frameDirty, design: bridge.design() };
	};

	const save = async (): Promise< Workspace | null > => {
		if ( pendingSave.current ) {
			return pendingSave.current;
		}

		const run = async (): Promise< Workspace | null > => {
			if ( data?.state !== 'draft' || ! draft ) {
				return data;
			}

			let submitted: Draft = draft;
			let bridge = bridgeRef.current;
			let frameDirty = false;
			let frameResolved = false;
			let captured = '';
			let capturedDesign: EmailDesign | null = null;

			if ( draft.mode === 'blocks' && frameRef.current !== null ) {
				const resolved = editableBlocks( data.revision );
				submitted = { ...draft, blocks: resolved.blocks, design: resolved.design };
				frameDirty = resolved.dirty;
				frameResolved = true;
				captured = resolved.captured;
				capturedDesign = resolved.design;
			} else {
				bridge = null;
			}

			if ( ! dirty && ! frameDirty ) {
				return data;
			}

			const version = editVersion.current;
			const next = await request< Workspace >( '', 'PUT', { draft: submitted, revision: data.revision } );

			if ( bridge && frameResolved && capturedDesign && next.state === 'draft' && submitted.mode === 'blocks' ) {
				// Compare the frame against what it actually serialized, not the
				// persisted markup, and keep its revision in sync.
				bridge.markSaved( next.revision, captured, capturedDesign );
			}

			const frameStillDirty = !! ( bridge && frameResolved && bridge.isDirty() );
			if ( version === editVersion.current && ! frameStillDirty ) {
				accept( next );
			} else if ( next.state === 'draft' ) {
				// Do not clobber text or block edits made while the write was in
				// flight. The next save (manual or autosave) flushes them.
				setData( next );
				if ( next.templates ) {
					setTemplates( next.templates );
				}
				setDraft( ( previous ) => previous ? {
					...previous,
					...( submitted.mode === 'blocks' ? { blocks: submitted.blocks, design: submitted.design } : {} ),
				} : next.draft );
				setDirty( true );
			} else {
				accept( next );
			}

			return next;
		};

		const promise = run();
		pendingSave.current = promise;
		try {
			return await promise;
		} finally {
			if ( pendingSave.current === promise ) {
				pendingSave.current = null;
			}
		}
	};

	useEffect( () => {
		if ( busy || data?.state !== 'draft' || ! draft || ( ! dirty && ! frameState.dirty ) ) {
			return;
		}

		const autosaveNow = () => {
			if ( pendingSave.current || busy ) {
				return;
			}
			setAutosaveState( 'saving' );
			void perform( async () => {
				try {
					await save();
					autosaveFailed.current = false;
					setAutosaveState( 'saved' );
				} catch ( reason ) {
					autosaveFailed.current = true;
					setAutosaveState( 'idle' );
					throw reason;
				}
			} );
		};

		// FluentCRM waits five seconds after a change, then forces one write
		// per minute while edits remain. A failed write backs off to a minute
		// instead of hammering a broken connection.
		const timer = window.setTimeout( autosaveNow, autosaveFailed.current ? 60000 : 5000 );
		const interval = window.setInterval( autosaveNow, 60000 );
		return () => {
			window.clearTimeout( timer );
			window.clearInterval( interval );
		};
	}, [ dirty, frameState.dirty, busy, data?.state, data?.state === 'draft' ? data.revision : '', draft ] );

	const saveSchedule = ( values: ScheduleValues ) => void perform( async () => {
		setRecurring( await request< RecurringPayload >( '/recurring', 'PUT', values ) );
		setNotice( __( '已儲存排程。', 'now-crm' ) );
	} );

	const recurringAction = ( op: ScheduleAction ) => void perform( async () => {
		// Activation and child generation use the stored content, so flush
		// pending edits first instead of acting on the previous revision.
		if ( op === 'resume' || op === 'generate' ) {
			await save();
		}

		setRecurring( await request< RecurringPayload >( '/recurring', 'POST', { op } ) );
		setNotice(
			op === 'generate'
				? __( '已產生這一期的子電子報。', 'now-crm' )
				: op === 'pause'
				? __( '已暫停定期寄送。', 'now-crm' )
				: op === 'resume'
				? __( '已恢復定期寄送。', 'now-crm' )
				: __( '已停用定期寄送。', 'now-crm' )
		);
	} );

	const duplicateRecurring = ( event: FormEvent< HTMLFormElement > ) => {
		event.preventDefault();
		const form = event.currentTarget;
		void perform( async () => {
			await save();
			form.submit();
		} );
	};

	const deleteRecurring = ( event: FormEvent< HTMLFormElement > ) => {
		event.preventDefault();
		const form = event.currentTarget;

		if ( ! window.confirm( __( '刪除定期電子報會一併刪除子電子報。有子電子報在排程、寄送或暫停中時不會刪除。確定繼續？', 'now-crm' ) ) ) {
			return;
		}

		form.submit();
	};

	const loadReview = ( current: Editing ) => request< Review >( '/review', 'GET', undefined, { revision: current.revision } );

	const goTo = ( next: number ) => void perform( async () => {
		const current = await save();
		if ( ! config.recurring && next === 3 && current?.state === 'draft' ) {
			setReview( await loadReview( current ) );
		}
		setStep( next );
		window.requestAnimationFrame( () => document.querySelector< HTMLElement >( '.nowcrm-workspace__panel h2' )?.focus() );
	} );

	const switchMode = ( mode: Draft[ 'mode' ] ) => void perform( async () => {
		if ( data?.state !== 'draft' || ! draft || draft.mode === mode ) {
			return;
		}

		if ( draft.mode === 'blocks' ) {
			const { blocks, design } = editableBlocks( data.revision );
			// Persist the current blocks, design, and the new mode in one guarded write.
			accept( await request< Workspace >( '', 'PUT', { draft: { ...draft, blocks, design, mode }, revision: data.revision } ) );
			return;
		}

		accept( await request< Workspace >( '', 'PUT', { draft: { ...draft, mode }, revision: data.revision } ) );
	} );

	const closePreview = () => {
		previewSeq.current += 1;
		setPreviewLoading( false );
		setDialog( null );
	};

	const openPreview = () => {
		setError( '' );
		setPreview( null );
		setPreviewError( '' );
		setPreviewLoading( true );
		setMobile( false );
		setDialog( 'preview' );
		const sequence = ++previewSeq.current;

		let pending: Promise< EmailPreview >;
		if ( data?.state !== 'draft' || ! draft ) {
			// Reports and confirmed campaigns preview the frozen snapshot.
			pending = request< EmailPreview >( '/preview' );
		} else if ( draft.mode === 'html' ) {
			pending = request< EmailPreview >( '/preview', 'POST', { html: draft.html } );
		} else if ( frameRef.current !== null ) {
			const bridge = bridgeRef.current;
			if ( ! bridge || ! bridge.isReady() ) {
				setPreviewLoading( false );
				setPreviewError( __( 'Gutenberg 編輯器尚未載入完成，請稍候再試。', 'now-crm' ) );
				return;
			}
			if ( bridge.revision() !== data.revision ) {
				setPreviewLoading( false );
				setPreviewError( __( '編輯器內容來自較舊的版本。請重新載入頁面後再試。', 'now-crm' ) );
				return;
			}
			const blocks = bridge.blocks();
			if ( blocks === null ) {
				setPreviewLoading( false );
				setPreviewError( __( 'Gutenberg 編輯器尚未載入完成，請稍候再試。', 'now-crm' ) );
				return;
			}
			pending = request< EmailPreview >( '/preview', 'POST', { blocks, design: bridge.design() } );
		} else {
			// The content frame unmounts on other steps; preview the saved draft.
			pending = request< EmailPreview >( '/preview', 'POST', { blocks: draft.blocks, design: draft.design } );
		}

		pending
			.then( ( result ) => {
				if ( previewSeq.current === sequence ) {
					setPreview( result );
					setPreviewLoading( false );
				}
			} )
			.catch( ( reason: unknown ) => {
				if ( previewSeq.current !== sequence ) {
					return;
				}
				setPreviewError( messageOf( reason ) || __( '無法產生預覽，請稍後再試。', 'now-crm' ) );
				setPreviewLoading( false );
			} );
	};

	const delivery = ( op: Operation ) => void perform( async () => {
		accept( await request< Workspace >( '/delivery', 'POST', { op } ) );
		setDialog( null );
		setStep( 0 );
		setReview( null );
	} );

	const applyTemplate = () => void perform( async () => {
		if ( data?.state !== 'draft' || ! selectedTemplate ) {
			return;
		}
		const current = await save();
		if ( ! current || current.state !== 'draft' ) {
			return;
		}
		accept( await request< Workspace >( '', 'POST', { template_id: selectedTemplate.id, revision: current.revision } ) );
		// The applied template replaces the source body, so the frame must read
		// the stored campaign again.
		setFrameKey( ( value ) => value + 1 );
		setTemplateId( 0 );
		setDialog( null );
		setNotice( __( '已套用範本並儲存草稿。', 'now-crm' ) );
	} );

	const submit = () => void perform( async () => {
		if ( ! review ) {
			return;
		}
		try {
			accept( await request< Workspace >( '/delivery', 'POST', {
				op: 'confirm', token: review.token, schedule: timed ? schedule : '', schedule_end: timing === 'range' ? scheduleEnd : '',
			} ) );
			setDialog( null );
		} catch ( reason ) {
			if ( codeOf( reason ) === 'nowcrm_review_changed' ) {
				setDialog( null );
				const next = await request< Workspace >();
				accept( next );
				setReview( next.state === 'draft' ? await loadReview( next ) : null );
			}
			throw reason;
		}
	} );

	const feedback = (
		<>
			{ error && <p className="nowcrm-workspace__message" role="alert">{ error }</p> }
			{ notice && <p className="nowcrm-workspace__message" role="status">{ notice }</p> }
		</>
	);
	if ( ! data ) {
		return error ? feedback : <p role="status">{ __( '正在載入電子報…', 'now-crm' ) }</p>;
	}

	const frame = draft?.mode === 'blocks' && step === 0 ? (
		<iframe
			key={ frameKey }
			ref={ frameRef }
			className="nowcrm-workspace__frame-editor"
			title={ __( 'Gutenberg 內容編輯器', 'now-crm' ) }
			src={ config.contentFrameUrl }
			onLoad={ () => setFrameLoads( ( value ) => value + 1 ) }
		/>
	) : null;

	return (
		<div className="nowcrm-workspace">
			<header className="nowcrm-workspace__header">
				<div className="nowcrm-workspace__heading">
					<a href={ config.listUrl }>{ config.listLabel || __( '← 電子報', 'now-crm' ) }</a>
					<div className="nowcrm-workspace__title">
						<h1>{ data.title }</h1>
						{ config.recurring && recurring ? (
							<span className={ 'nowcrm-status-pill ' + recurringStatusTone( recurring.recurring.status ) }>{ recurringStatusLabel( recurring.recurring.status ) }</span>
						) : (
							<span className="nowcrm-status-pill">{ data.status_label }</span>
						) }
					</div>
					{ config.recurring && recurring && (
						<p className="nowcrm-workspace__meta">
							{ recurringFrequencyLabel( recurring.recurring ) } · { sprintf( __( '已產生 %d 封', 'now-crm' ), recurring.child_count ) }
						</p>
					) }
				</div>
				<div className="nowcrm-workspace__actions">
					{ ! ( data.state === 'draft' && step === 0 ) && <button type="button" className="button" disabled={ busy } onClick={ openPreview }>{ __( '預覽郵件', 'now-crm' ) }</button> }
					{ data.state === 'draft' && <button type="button" className="button" disabled={ busy || ! data.sending_enabled } onClick={ () => { setError( '' ); setDialog( 'test' ); } }>{ __( '寄測試信', 'now-crm' ) }</button> }
					{ ! config.recurring && <button type="button" className="button" disabled={ busy } onClick={ () => void perform( async () => {
						await save();
						const copy = await request< { url: string } >( '/duplicate', 'POST' );
						window.location.assign( copy.url );
					} ) }>{ __( '複製電子報', 'now-crm' ) }</button> }
				</div>
			</header>
			{ ! dialog && ! ( config.recurring && step === 1 ) && feedback }
			{ ! config.recurring && ! data.sending_enabled && <p className="nowcrm-workspace__message" role="status">{ __( 'NOW CRM 寄送已關閉。請到設定開啟後再寄送。', 'now-crm' ) }</p> }
			{ data.state === 'draft' && draft ? (
				<>
					<nav className="nowcrm-workspace__steps" aria-label={ config.recurring ? __( '定期寄送分頁', 'now-crm' ) : __( '電子報步驟', 'now-crm' ) }>
						{ steps.map( ( title, index ) => (
							<button type="button" key={ title } disabled={ busy } aria-current={ index === step ? 'step' : undefined } onClick={ () => goTo( index ) }>{ title }</button>
						) ) }
					</nav>
					<div className="nowcrm-workspace__form" aria-busy={ busy } inert={ busy }>
						{ config.recurring && recurring && (
							<div hidden={ step !== 1 }>
								<ScheduleSection
									data={ recurring }
									busy={ busy }
									error={ error }
									notice={ notice }
									actions={ config.recurringActions }
									onSave={ saveSchedule }
									onAction={ recurringAction }
									onDuplicate={ duplicateRecurring }
									onDelete={ deleteRecurring }
								/>
							</div>
						) }
						{ step === 0 && (
							<>
								{ config.recurring && (
									<section className="nowcrm-workspace__panel" aria-labelledby="nowcrm-message">
										<h2 id="nowcrm-message" tabIndex={ -1 }>{ __( '郵件主旨與預覽文字', 'now-crm' ) }</h2>
										<TextField
											label={ draft.ab.enabled ? __( '郵件主旨（A 版）', 'now-crm' ) : __( '郵件主旨', 'now-crm' ) }
											value={ draft.subject }
											onChange={ ( value ) => edit( 'subject', value ) }
										/>
										<TextField label={ __( '預覽文字', 'now-crm' ) } value={ draft.preheader } onChange={ ( value ) => edit( 'preheader', value ) } />
									</section>
								) }
								<ContentStep
									draft={ draft }
									change={ edit }
									onModeChange={ switchMode }
									busy={ busy }
									frame={ frame }
									frameReady={ frameState.ready }
									frameError={ frameError }
									onReloadFrame={ () => { setFrameError( '' ); setFrameKey( ( value ) => value + 1 ); } }
									onPreview={ openPreview }
									previewHtml={ previewHtml }
								/>
							</>
						) }
						{ step === 1 && ( config.recurring ? (
							<>
								{ ! recurring && <section className="nowcrm-workspace__panel"><p role="status">{ __( '正在載入排程…', 'now-crm' ) }</p></section> }
								<RecipientStep
									audience={ draft.audience }
									lists={ data.lists }
									tags={ data.tags }
									segments={ data.segments }
									catalog={ data.advanced ?? null }
									busy={ busy }
									onChange={ ( value ) => edit( 'audience', value ) }
									fetchRecipients={ fetchRecipients }
									fetchRecipientCount={ fetchRecipientCount }
									fetchOptions={ fetchRecipientOptions }
								/>
								<SettingsStep draft={ draft } change={ edit } showMessage={ false } />
							</>
						) : <SettingsStep draft={ draft } change={ edit } /> ) }
						{ step === 2 && ( config.recurring ? (
							recurring ? <RecurringHistory data={ recurring } /> : <section className="nowcrm-workspace__panel"><p role="status">{ __( '正在載入寄送紀錄…', 'now-crm' ) }</p></section>
						) : (
							<RecipientStep
								audience={ draft.audience }
								lists={ data.lists }
								tags={ data.tags }
								segments={ data.segments }
								catalog={ data.advanced ?? null }
								busy={ busy }
								onChange={ ( value ) => edit( 'audience', value ) }
								fetchRecipients={ fetchRecipients }
								fetchRecipientCount={ fetchRecipientCount }
								fetchOptions={ fetchRecipientOptions }
							/>
						) ) }
						{ step === 3 && (
							<section className="nowcrm-workspace__panel" aria-labelledby="nowcrm-review">
								<h2 id="nowcrm-review" tabIndex={ -1 }>{ __( '檢查與寄送', 'now-crm' ) }</h2>
								<dl className="nowcrm-workspace__summary">
									<dt>{ __( '郵件主旨', 'now-crm' ) }</dt><dd>{ draft.subject || '—' }</dd>
									{ draft.ab.enabled && <><dt>{ __( '郵件主旨（B 版）', 'now-crm' ) }</dt><dd>{ draft.ab.subject_b || '—' }</dd></> }
									<dt>{ __( '預覽文字', 'now-crm' ) }</dt><dd>{ draft.preheader || '—' }</dd>
									<dt>{ __( '寄件者', 'now-crm' ) }</dt><dd>{ review?.sender || '—' }</dd>
									<dt>{ __( '回覆地址', 'now-crm' ) }</dt><dd>{ review?.reply_to || '—' }</dd>
									<dt>{ __( '收件對象', 'now-crm' ) }</dt><dd>{ review?.audience || '—' }</dd>
									<dt>{ __( '符合人數', 'now-crm' ) }</dt><dd>{ review?.count.toLocaleString() ?? '—' }</dd>
								</dl>
								{ review && (
									<div className="nowcrm-workspace__review-preview">
										<h3>{ __( '郵件正文預覽', 'now-crm' ) }</h3>
										<iframe title={ __( '檢查寄送正文', 'now-crm' ) } sandbox="" srcDoc={ review.preview.html } />
										{ review.sample.length > 0 && (
											<details><summary>{ __( '查看部分符合的收件人', 'now-crm' ) }</summary><ul>{ review.sample.map( ( recipient ) => <li key={ recipient }>{ recipient }</li> ) }</ul></details>
										) }
									</div>
								) }
								<details>
									<summary>{ __( '開信與點擊追蹤', 'now-crm' ) }</summary>
									<div className="nowcrm-workspace__fields">
										{ ! data.tracking_enabled && <p>{ __( '網站設定已停用開信與點擊追蹤。', 'now-crm' ) }</p> }
										<label><input type="checkbox" disabled={ ! data.tracking_enabled } checked={ draft.tracking.open } onChange={ ( e ) => edit( 'tracking', { ...draft.tracking, open: e.target.checked } ) } /> { __( '記錄開信', 'now-crm' ) }</label>
										<label><input type="checkbox" disabled={ ! data.tracking_enabled } checked={ draft.tracking.click } onChange={ ( e ) => edit( 'tracking', { ...draft.tracking, click: e.target.checked } ) } /> { __( '記錄點擊', 'now-crm' ) }</label>
									</div>
								</details>
								<fieldset className="nowcrm-workspace__choices">
									<legend>{ __( '寄送時間', 'now-crm' ) }</legend>
									<label><input type="radio" name="timing" checked={ timing === 'now' } onChange={ () => setTiming( 'now' ) } /> { __( '立即寄送', 'now-crm' ) }</label>
									<label><input type="radio" name="timing" checked={ timing === 'fixed' } onChange={ () => setTiming( 'fixed' ) } /> { __( '指定日期與時間', 'now-crm' ) }</label>
									<label><input type="radio" name="timing" checked={ timing === 'range' } onChange={ () => setTiming( 'range' ) } /> { __( '在指定時段內分散寄送', 'now-crm' ) }</label>
									{ timed && <TextField label={ __( '開始時間', 'now-crm' ) + ' · ' + config.timezone } type="datetime-local" value={ schedule } onChange={ setSchedule } /> }
									{ timing === 'range' && <TextField label={ __( '結束時間', 'now-crm' ) + ' · ' + config.timezone } type="datetime-local" value={ scheduleEnd } onChange={ setScheduleEnd } /> }
								</fieldset>
								{ review && ! review.ready && <p className="description">{ __( '寄送前須填寫主旨和正文，並至少選出一位符合條件的收件人。', 'now-crm' ) }</p> }
								{ ! review && <button type="button" className="button" onClick={ () => goTo( 3 ) }>{ __( '更新檢查結果', 'now-crm' ) }</button> }
							</section>
						) }
					</div>
					<footer className={ 'nowcrm-workspace__footer' + ( ! config.recurring && step === 2 ? ' nowcrm-workspace__footer--recipients' : '' ) }>
						{ config.recurring ? (
							step === 0 && (
								<>
									<button type="button" className="button button-primary" disabled={ busy || ( ! dirty && ! frameState.dirty ) } onClick={ () => void perform( async () => { await save(); setNotice( __( '草稿已儲存。', 'now-crm' ) ); } ) }>{ __( '儲存草稿', 'now-crm' ) }</button>
									{ autosaveFeedback }
								</>
							)
						) : (
							<>
								<button type="button" className="button" disabled={ busy || ( ! dirty && ! frameState.dirty ) } onClick={ () => void perform( async () => { await save(); setNotice( __( '草稿已儲存。', 'now-crm' ) ); } ) }>{ __( '儲存草稿', 'now-crm' ) }</button>
								{ autosaveFeedback }
								<div className="nowcrm-workspace__actions">
									{ step > 0 && <button type="button" className="button" disabled={ busy } onClick={ () => goTo( step - 1 ) }>{ __( '上一步', 'now-crm' ) }</button> }
									{ step < 3
										? <button type="button" className="button button-primary" disabled={ busy } onClick={ () => goTo( step + 1 ) }>{ [ __( '下一步：主旨與設定', 'now-crm' ), __( '下一步：收件人', 'now-crm' ), __( '繼續檢查與寄送', 'now-crm' ) ][ step ] }</button>
										: <button type="button" className="button button-primary" disabled={ busy || ! review?.ready || ! data.sending_enabled || ( timed && ! schedule ) || ( timing === 'range' && scheduleEnd <= schedule ) } onClick={ () => { setError( '' ); setConfirmReady( false ); setDialog( 'confirm' ); } }>{ timed ? __( '確認排程', 'now-crm' ) : __( '確認寄送', 'now-crm' ) }</button> }
								</div>
							</>
						) }
					</footer>
				</>
			) : data.state !== 'draft' && (
				<DeliveryReport data={ data } busy={ busy } delivery={ delivery } cancel={ () => { setError( '' ); setDialog( 'cancel' ); } } />
			) }
			{ dialog === 'preview' && (
				<Modal title={ __( '郵件預覽', 'now-crm' ) } className="nowcrm-preview-modal" onRequestClose={ closePreview }>
					{ previewLoading && <p role="status" className="nowcrm-preview-modal__status">{ __( '正在產生預覽…', 'now-crm' ) }</p> }
					{ previewError && <p role="alert" className="nowcrm-workspace__message">{ previewError }</p> }
					{ preview && (
						<>
							<div className="nowcrm-workspace__actions">
								<strong>{ preview.subject || '—' }</strong>
								<button type="button" className="button" aria-pressed={ ! mobile } onClick={ () => setMobile( false ) }>{ __( '桌面', 'now-crm' ) }</button>
							</div>
							<iframe title={ __( '郵件正文預覽', 'now-crm' ) } sandbox="" srcDoc={ preview.html } className={ mobile ? 'nowcrm-preview--mobile' : '' } />
						</>
					) }
				</Modal>
			) }
			{ dialog === 'template' && draft && (
				<Modal title={ __( '加入電子報範本', 'now-crm' ) } onRequestClose={ () => { if ( ! busy ) setDialog( null ); } }>
					{ feedback }
					{ templates.length === 0 ? (
						<p className="description">{ __( '尚無已發佈的電子報範本。請先到「電子報範本」發佈後再回來。', 'now-crm' ) }</p>
					) : (
						<>
							<label className="nowcrm-workspace__field">
								<span>{ __( '已發佈範本', 'now-crm' ) }</span>
								<select value={ templateId } disabled={ busy } onChange={ ( event ) => setTemplateId( Number( event.target.value ) ) }>
									<option value={ 0 }>{ __( '選擇範本', 'now-crm' ) }</option>
									{ templates.map( ( template ) => <option key={ template.id } value={ template.id }>{ template.name }</option> ) }
								</select>
							</label>
							{ selectedTemplate ? (
								<>
									<p>{ sprintf( __( '會以「%s」取代對應編輯方式的正文、郵件主旨和預覽文字，並立即儲存草稿。', 'now-crm' ), selectedTemplate.name ) }</p>
									{ selectedTemplate.mode !== draft.mode && (
										<p className="description">{ sprintf( __( '此範本使用 %s，套用後編輯方式會切換。', 'now-crm' ), selectedTemplate.mode === 'html' ? 'Raw HTML' : 'Gutenberg' ) }</p>
									) }
									{ ( dirty || frameState.dirty ) && <p className="nowcrm-workspace__message">{ __( '目前有未儲存的變更。套用範本前會先儲存這些變更。', 'now-crm' ) }</p> }
								</>
							) : <p className="description">{ __( '選擇範本後會顯示套用內容。', 'now-crm' ) }</p> }
						</>
					) }
					<div className="nowcrm-workspace__actions">
						<button type="button" className="button" disabled={ busy } onClick={ () => setDialog( null ) }>{ __( '取消', 'now-crm' ) }</button>
						{ templates.length > 0 && (
							<button type="button" className="button button-primary" disabled={ busy || ! selectedTemplate } onClick={ applyTemplate }>{ __( '套用並儲存', 'now-crm' ) }</button>
						) }
					</div>
				</Modal>
			) }
			{ dialog === 'test' && (
				<Modal title={ __( '寄測試信', 'now-crm' ) } onRequestClose={ () => { if ( ! busy ) setDialog( null ); } }>
					{ feedback }
					<form onSubmit={ ( event ) => {
						event.preventDefault();
						void perform( async () => {
							await save();
							await request( '/test', 'POST', { email } );
							setDialog( null );
							setNotice( __( '測試信已加入寄送佇列。', 'now-crm' ) );
						} );
					} }>
						<TextField label={ __( '測試收件地址', 'now-crm' ) } type="email" value={ email } onChange={ setEmail } />
						<div className="nowcrm-workspace__actions">
							<button type="submit" className="button button-primary" disabled={ busy || ! email }>{ __( '寄出測試信', 'now-crm' ) }</button>
							<a href={ config.testLogUrl } target="_blank" rel="noreferrer">{ __( '測試信紀錄', 'now-crm' ) }</a>
						</div>
					</form>
				</Modal>
			) }
			{ dialog === 'confirm' && review && (
				<Modal title={ timed ? __( '確認排程', 'now-crm' ) : __( '確認寄送', 'now-crm' ) } onRequestClose={ () => { if ( ! busy ) setDialog( null ); } }>
					{ feedback }
					<p><strong>{ review.count.toLocaleString() }</strong> { __( '位收件人', 'now-crm' ) } · { timed ? [ schedule, timing === 'range' ? scheduleEnd : '' ].filter( Boolean ).map( ( value ) => value.replace( 'T', ' ' ) ).join( ' – ' ) + ' ' + config.timezone : __( '立即寄送', 'now-crm' ) }</p>
					<p><label><input type="checkbox" checked={ confirmReady } onChange={ ( e ) => setConfirmReady( e.target.checked ) } /> { __( '我已確認郵件與收件人。', 'now-crm' ) }</label></p>
					<button type="button" className="button button-primary" disabled={ busy || ! confirmReady } onClick={ submit }>{ timed ? __( '建立寄送排程', 'now-crm' ) : __( '開始寄送', 'now-crm' ) }</button>
				</Modal>
			) }
			{ dialog === 'cancel' && (
				<Modal title={ __( '取消這次寄送？', 'now-crm' ) } onRequestClose={ () => { if ( ! busy ) setDialog( null ); } }>
					{ feedback }
					<p>{ __( '停止等待中的郵件並保留寄送紀錄。', 'now-crm' ) }</p>
					<button type="button" className="button button-primary" disabled={ busy } onClick={ () => delivery( 'cancel' ) }>{ __( '取消寄送', 'now-crm' ) }</button>
				</Modal>
			) }
		</div>
	);
}

function DeliveryReport( { data, busy, delivery, cancel }: { data: Report; busy: boolean; delivery: ( op: Operation ) => void; cancel: () => void } ) {
	const jobs = data.jobs;
	const metrics = data.metrics;
	const cards: Array< [ string, number ] > = [
		[ __( '收件人', 'now-crm' ), jobs.total ],
		[ __( '等待寄送', 'now-crm' ), jobs.queued ],
		[ __( '處理中', 'now-crm' ), jobs.sending ],
		[ __( '已交付郵件系統', 'now-crm' ), jobs.accepted ],
		[ __( '已抑制', 'now-crm' ), jobs.suppressed ],
		[ __( '失敗', 'now-crm' ), jobs.failed ],
		[ __( '結果不明', 'now-crm' ), jobs.unknown ],
		[ __( '已取消', 'now-crm' ), jobs.cancelled ],
	];
	const rate = ( value: number ) => jobs.accepted ? ( value / jobs.accepted ).toLocaleString( undefined, { style: 'percent', maximumFractionDigits: 1 } ) : '—';
	return (
		<>
			<section className="nowcrm-workspace__panel">
				<h2>{ __( '寄送進度', 'now-crm' ) }</h2>
				<p>{ data.subject }</p>
				{ data.schedule && <p>{ __( '排程時間', 'now-crm' ) } · { data.schedule }{ data.schedule_end && ' – ' + data.schedule_end } { config.timezone }</p> }
				{ data.finished && <p>{ __( '結束時間', 'now-crm' ) } · { data.finished } { config.timezone }</p> }
				{ jobs.total > 0 && <progress aria-label={ __( '已處理收件人', 'now-crm' ) } max={ jobs.total } value={ jobs.total - jobs.queued - jobs.sending } /> }
				<dl className="nowcrm-workspace__stats">
					{ cards.map( ( [ label, value ] ) => <div key={ label }><dt>{ label }</dt><dd>{ value.toLocaleString() }</dd></div> ) }
				</dl>
				{ data.state === 'paused' && jobs.sending > 0 && <p className="description">{ __( '處理中的郵件可能仍會完成交付。', 'now-crm' ) }</p> }
				<div className="nowcrm-workspace__actions">
					{ data.state === 'scheduled' && <button type="button" className="button" disabled={ busy } onClick={ () => delivery( 'unschedule' ) }>{ __( '取消排程並回到草稿', 'now-crm' ) }</button> }
					{ data.state === 'sending' && <button type="button" className="button" disabled={ busy } onClick={ () => delivery( 'pause' ) }>{ __( '暫停寄送', 'now-crm' ) }</button> }
					{ data.state === 'paused' && <button type="button" className="button button-primary" disabled={ busy || ! data.sending_enabled } onClick={ () => delivery( 'resume' ) }>{ __( '繼續寄送', 'now-crm' ) }</button> }
					{ ( data.state === 'scheduled' || data.state === 'paused' ) && <button type="button" className="button" disabled={ busy } onClick={ cancel }>{ __( '取消寄送', 'now-crm' ) }</button> }
					<a href={ config.emailLogUrl }>{ __( '查看收件人寄送紀錄', 'now-crm' ) }</a>
				</div>
			</section>
			{ data.variants && (
				<section className="nowcrm-workspace__panel">
					<h2>{ __( '主旨 A／B 結果', 'now-crm' ) }</h2>
					<p className="description">{ __( '依寄送時固定的分組統計。系統不會自動選出優勝版本。', 'now-crm' ) }</p>
					<table className="widefat striped">
						<thead>
							<tr>
								<th scope="col">{ __( '版本', 'now-crm' ) }</th>
								<th scope="col">{ __( '主旨', 'now-crm' ) }</th>
								<th scope="col">{ __( '已交付', 'now-crm' ) }</th>
								<th scope="col">{ __( '開信', 'now-crm' ) }</th>
								<th scope="col">{ __( '點擊', 'now-crm' ) }</th>
								<th scope="col">{ __( '取消訂閱', 'now-crm' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ ( [ 'a', 'b' ] as const ).map( ( key ) => {
								const variant = data.variants![ key ];
								const share = ( value: number ) => variant.accepted ? value.toLocaleString( undefined, { style: 'percent', maximumFractionDigits: 1 } ) : '—';
								return (
									<tr key={ key }>
										<th scope="row">{ key.toUpperCase() }</th>
										<td>{ variant.subject || '—' }</td>
										<td>{ variant.accepted.toLocaleString() }</td>
										<td>{ variant.opened.toLocaleString() } · { share( variant.opened ) }</td>
										<td>{ variant.clicked.toLocaleString() } · { share( variant.clicked ) }</td>
										<td>{ variant.unsubscribed.toLocaleString() } · { share( variant.unsubscribed ) }</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>
				</section>
			) }
			<section className="nowcrm-workspace__panel">
				<h2>{ __( '互動與退信', 'now-crm' ) }</h2>
				<dl className="nowcrm-workspace__stats">
					<div><dt>{ __( '開信', 'now-crm' ) }</dt><dd>{ metrics.opened.toLocaleString() } <small>{ rate( metrics.opened ) }</small></dd></div>
					<div><dt>{ __( '點擊', 'now-crm' ) }</dt><dd>{ metrics.clicked.toLocaleString() } <small>{ rate( metrics.clicked ) }</small></dd></div>
					<div><dt>{ __( '退訂', 'now-crm' ) }</dt><dd>{ metrics.unsubscribe.toLocaleString() }</dd></div>
					<div><dt>{ __( '退信', 'now-crm' ) }</dt><dd>{ metrics.bounce.toLocaleString() }</dd></div>
					<div><dt>{ __( '檢舉', 'now-crm' ) }</dt><dd>{ metrics.complaint.toLocaleString() }</dd></div>
				</dl>
				<p className="description">{ __( '開信數依追蹤像素請求計算，包含郵件服務的圖片預載。', 'now-crm' ) }</p>
				{ data.links.length > 0 && (
					<table className="widefat">
						<caption>{ __( '點擊最多的連結', 'now-crm' ) }</caption>
						<thead><tr><th scope="col">{ __( '連結', 'now-crm' ) }</th><th scope="col">{ __( '點擊人數', 'now-crm' ) }</th></tr></thead>
						<tbody>{ data.links.map( ( link ) => <tr key={ link.url }><td><a href={ link.url } target="_blank" rel="noreferrer">{ link.url }</a></td><td>{ link.recipients.toLocaleString() }</td></tr> ) }</tbody>
					</table>
				) }
			</section>
		</>
	);
}

const root = document.getElementById( 'nowcrm-campaign-workspace' );
if ( root ) {
	createRoot( root ).render( <WorkspaceApp /> );
}
