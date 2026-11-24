/**
 * Checkout capture for NOW CRM abandoned carts.
 *
 * Runs on Classic and Blocks checkout. The browser sends the checkout billing
 * email with the time it observed the value. The server reads the real cart
 * from the Woo session and owns the cart cycle, so this script never creates
 * or persists an identity.
 *
 * Only a server acknowledgement counts as captured. Transient failures are
 * retried a bounded number of times with the same observation; a newer
 * observation always supersedes an older one. Clearing the field sends an
 * explicit `email_state=none` intent for the same cart cycle.
 */

import { captureRetryPolicy } from './retry';
import {
	isAcknowledged,
	isAlreadyCaptured,
	mustClearEmail,
	type EmailState,
	type Intent,
	type IntentState,
} from './decide';

interface NowcrmCartConfig {
	endpoint: string;
	nonce?: string;
}

interface TrackResponse {
	ok?: boolean;
	cycle?: string;
	stale?: boolean;
	reason?: string;
}

interface JQueryCollection {
	on( event: string, handler: () => void ): void;
}

interface WindowWithJquery extends Window {
	jQuery?: ( target: Element ) => JQueryCollection;
}

declare global {
	interface Window {
		nowcrmCart?: NowcrmCartConfig;
	}
}

const DEBOUNCE_MS = 300;
const CLEAR_DEBOUNCE_MS = 900;
const HEARTBEAT_MS = 60000;
const STALE_REBIND_LIMIT = 2;
const EMAIL_PATTERN = /^[^@\s]+@[^@\s]+\.[^@\s]+$/;
const CLASSIC_SELECTOR = '#billing_email, input[name="billing_email"]';
const BLOCKS_ROOT_SELECTOR = '.wp-block-woocommerce-checkout, .wc-block-checkout';
const BLOCKS_SELECTORS = [
	'input#email',
	'input[name="email"]',
	'.wc-block-components-text-input input[autocomplete="email"]',
];
const CHECKOUT_SELECTOR =
	'.wp-block-woocommerce-checkout, .wc-block-checkout, form.checkout, form.woocommerce-checkout';

( function () {
	'use strict';

	const maybeConfig = window.nowcrmCart;

	if ( ! maybeConfig ) {
		return;
	}

	const config: NowcrmCartConfig = maybeConfig;

	let currentValue = '';
	let pending: Intent | null = null;
	let active: Intent | null = null;
	let unacked: Intent | null = null;
	let ackedState: EmailState | '' = '';
	let ackedValue = '';
	let latestOrder = 0;
	let lastIssuedOrder = 0;
	let cycle = '';
	let rebinds = 0;
	let lastHeartbeat = 0;
	let debounceTimer: number | undefined;
	let retryTimer: number | undefined;
	let pageHideSent = false;
	let boundInput: HTMLInputElement | null = null;

	/** Find the billing email input in Classic or Blocks checkout markup. */
	function findEmailInput(): HTMLInputElement | null {
		const blocks =
			document.querySelector< HTMLElement >( BLOCKS_ROOT_SELECTOR );

		if ( blocks ) {
			for ( let i = 0; i < BLOCKS_SELECTORS.length; i++ ) {
				const input = blocks.querySelector< HTMLInputElement >(
					BLOCKS_SELECTORS[ i ]
				);

				if ( input ) {
					return input;
				}
			}
		}

		return document.querySelector< HTMLInputElement >( CLASSIC_SELECTOR );
	}

	/** Observation time, kept strictly increasing for capture order. */
	function nextOrder(): number {
		const now = Date.now();

		latestOrder = now > latestOrder ? now : latestOrder + 1;

		return latestOrder;
	}

	/** Snapshot of every intent that may still change the stored address. */
	function intentState(): IntentState {
		return { ackedState, ackedValue, active, unacked, pending };
	}

	/** Queue a clear intent only when a valid address may exist server-side. */
	function observeInvalid( flush: boolean ): void {
		if ( ! mustClearEmail( intentState() ) ) {
			pending = null;

			return;
		}

		if ( pending?.state === 'none' ) {
			if ( flush ) {
				flushPending();
			}

			return;
		}

		pending = {
			state: 'none',
			email: '',
			order: nextOrder(),
			kind: 'capture',
			attempts: 0,
		};

		scheduleFlush( flush, CLEAR_DEBOUNCE_MS );
	}

	/** Record a genuine observation. A newer value always supersedes older work. */
	function observe( rawValue: string, flush: boolean, force = false ): void {
		const value = rawValue.trim();
		const changed = value !== currentValue;

		currentValue = value;

		if ( ! changed && ! force ) {
			if ( flush && pending ) {
				flushPending();
			}

			return;
		}

		window.clearTimeout( debounceTimer );
		debounceTimer = undefined;

		if ( ! EMAIL_PATTERN.test( value ) ) {
			observeInvalid( flush );

			return;
		}

		if ( ! force && isAlreadyCaptured( intentState(), value ) ) {
			// The same address is already captured for this cycle.
			pending = null;

			return;
		}

		if ( pending?.state === 'valid' && pending.email === value ) {
			scheduleFlush( flush, DEBOUNCE_MS );

			return;
		}

		pending = {
			state: 'valid',
			email: value,
			order: nextOrder(),
			kind: 'capture',
			attempts: 0,
		};

		scheduleFlush( flush, DEBOUNCE_MS );
	}

	function scheduleFlush( flush: boolean, delay: number ): void {
		window.clearTimeout( debounceTimer );

		if ( flush ) {
			debounceTimer = undefined;
			flushPending();

			return;
		}

		debounceTimer = window.setTimeout( flushPending, delay );
	}

	/** One POST in flight; the newest pending observation goes after it. */
	function flushPending(): void {
		if ( debounceTimer !== undefined ) {
			window.clearTimeout( debounceTimer );
			debounceTimer = undefined;
		}

		const intent = pending;

		if ( ! intent || active ) {
			return;
		}

		if ( intent.order < lastIssuedOrder ) {
			pending = null;

			return;
		}

		pending = null;
		active = intent;
		lastIssuedOrder = intent.order;

		void post( intent );
	}

	function payload( intent: Intent ): Record< string, string > {
		const body: Record< string, string > = {
			email: intent.email,
			email_state: intent.state,
			kind: intent.kind,
			activity_order: String( intent.order ),
		};

		// The server uses this only to reject a snapshot from an old session.
		if ( cycle ) {
			body.previous_cycle = cycle;
		}

		return body;
	}

	function headers(): Record< string, string > {
		const result: Record< string, string > = {
			'Content-Type': 'application/json',
		};

		// WordPress REST treats a cookie-authenticated request without a nonce
		// as user 0. Keep it in the header; never put it in the URL.
		if ( config.nonce ) {
			result[ 'X-WP-Nonce' ] = config.nonce;
		}

		return result;
	}

	function retryAfterMs( response: Response ): number {
		const header = response.headers.get( 'Retry-After' );

		if ( ! header ) {
			return 0;
		}

		const seconds = Number( header );

		return Number.isFinite( seconds ) && seconds > 0
			? Math.min( seconds * 1000, 30000 )
			: 0;
	}

	async function post( intent: Intent ): Promise< void > {
		let failed = false;
		let status = 0;
		let reason = 'network';
		let retryAfter = 0;

		try {
			const response = await fetch( config.endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: headers(),
				body: JSON.stringify( payload( intent ) ),
			} );

			status = response.status;

			let result: TrackResponse = {};

			try {
				result = ( await response.json() ) as TrackResponse;
			} catch {
				result = {};
			}

			reason =
				result.reason ||
				( response.ok ? 'invalid_response' : 'http_' + response.status );

			if ( response.ok && isAcknowledged( result ) ) {
				if ( result.stale && intent.kind === 'capture' ) {
					// A newer observation already owns the row. Resend the
					// current field as a fresh observation, never as a replay.
					rebind( intent );
				} else {
					acknowledge( intent, result );
				}
			} else {
				failed = true;

				if ( response.status === 429 ) {
					retryAfter = retryAfterMs( response );
				}
			}
		} catch {
			failed = true;
		} finally {
			if ( active === intent ) {
				active = null;
			}

			if ( failed ) {
				fail( intent, reason, status, retryAfter );
			}

			flushPending();
		}
	}

	function acknowledge( intent: Intent, result: TrackResponse ): void {
		window.clearTimeout( retryTimer );
		retryTimer = undefined;

		if ( typeof result.cycle === 'string' && result.cycle ) {
			cycle = result.cycle;
		}

		if ( intent.kind !== 'capture' ) {
			return;
		}

		ackedState = intent.state;
		ackedValue = intent.email;
		rebinds = 0;

		if ( unacked && unacked.order <= intent.order ) {
			unacked = null;
		}
	}

	/**
	 * Failed request bookkeeping. Permanent reasons keep the intent available
	 * for a later user-driven retry; transient ones schedule one bounded retry.
	 */
	function fail(
		intent: Intent,
		reason: string,
		status: number,
		retryAfter: number
	): void {
		if ( reason === 'stale_cycle' || reason === 'cycle_rotated' ) {
			// Never replay this intent. A fresh observation binds a new cycle.
			cycle = '';

			if ( intent.kind === 'capture' ) {
				rebind( intent );
			}

			return;
		}

		if ( intent.kind !== 'capture' ) {
			return;
		}

		intent.lastReason = reason;
		intent.lastStatus = status;
		unacked = intent;

		const decision = captureRetryPolicy(
			reason,
			status,
			intent.attempts,
			retryAfter
		);

		if ( ! decision.retry ) {
			return;
		}

		intent.attempts += 1;

		window.clearTimeout( retryTimer );
		retryTimer = window.setTimeout( () => {
			retryTimer = undefined;

			if ( intent.order < latestOrder || pending || active ) {
				return;
			}

			pending = intent;
			flushPending();
		}, decision.delay );
	}

	/**
	 * The server kept someone else's newer state. Re-observe the current field
	 * as a new intent so the newest value wins without replaying an old one.
	 */
	function rebind( intent: Intent ): void {
		if ( intent.order < latestOrder || rebinds >= STALE_REBIND_LIMIT ) {
			return;
		}

		const input = findEmailInput();

		if ( ! input ) {
			return;
		}

		rebinds += 1;

		// Force a fresh order even when the field text did not change.
		currentValue = '';

		observe( input.value, true, true );
	}

	/** Send the newest observation that was not submitted yet. */
	function sendPendingOnHide(): void {
		if ( pageHideSent ) {
			return;
		}

		pageHideSent = true;

		// A request the browser may abort still needs a later retry.
		if ( active?.kind === 'capture' ) {
			unacked = active;
		}

		if ( debounceTimer !== undefined ) {
			window.clearTimeout( debounceTimer );
			debounceTimer = undefined;
		}

		const intent = pending;

		if ( ! intent || intent.order < lastIssuedOrder ) {
			return;
		}

		pending = null;
		lastIssuedOrder = intent.order;

		if ( intent.kind === 'capture' ) {
			unacked = intent;
		}

		void fetch( config.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: headers(),
			body: JSON.stringify( payload( intent ) ),
			keepalive: true,
		} ).catch( () => {
			/* The page is leaving; there is nothing else to do. */
		} );
	}

	/** Retry an unacknowledged capture after returning to the page. */
	function retryUnacked(): void {
		if ( ! unacked || pending || active ) {
			return;
		}

		if ( unacked.order < latestOrder ) {
			unacked = null;

			return;
		}

		const decision = captureRetryPolicy(
			unacked.lastReason ?? 'network',
			unacked.lastStatus ?? 0,
			unacked.attempts,
			0
		);

		if ( ! decision.retry ) {
			return;
		}

		unacked.attempts += 1;
		pending = unacked;
		flushPending();
	}

	function onInput( event: Event ): void {
		const input = findEmailInput();

		if ( input && event.target === input ) {
			observe( input.value, false );
		}
	}

	function onFocusOut( event: Event ): void {
		const input = findEmailInput();

		if ( input && event.target === input ) {
			observe( input.value, true );
		}
	}

	/** Real checkout interaction extends the idle window; idle time does not. */
	function onInteraction( event: Event ): void {
		const target = event.target;

		if ( ! ( target instanceof Element ) || ! target.closest( CHECKOUT_SELECTOR ) ) {
			return;
		}

		if ( document.hidden || pending || active || unacked ) {
			return;
		}

		if ( ackedState !== 'valid' || ! ackedValue || currentValue !== ackedValue ) {
			return;
		}

		const now = Date.now();

		if ( now - lastHeartbeat < HEARTBEAT_MS ) {
			return;
		}

		lastHeartbeat = now;

		const intent: Intent = {
			state: 'valid',
			email: ackedValue,
			order: nextOrder(),
			kind: 'activity',
			attempts: 0,
		};

		active = intent;
		lastIssuedOrder = intent.order;

		void post( intent );
	}

	/**
	 * Pick up a prefilled value and a replaced input without rebinding events.
	 * Password managers and Safari autofill can change the value without
	 * firing an input event, so the current value is compared here too.
	 */
	function refresh(): void {
		const input = findEmailInput();

		if ( ! input ) {
			boundInput = null;

			return;
		}

		const replaced = input !== boundInput;
		boundInput = input;

		if ( replaced || input.value.trim() !== currentValue ) {
			observe( input.value, false );
		}
	}

	function onCheckoutUpdate(): void {
		refresh();
		retryUnacked();
	}

	// Blocks re-renders checkout frequently; Classic replaces fields after AJAX.
	const observer = new MutationObserver( () => {
		refresh();
	} );

	function start(): void {
		refresh();
		observer.observe( document.body, { childList: true, subtree: true } );

		// Classic triggers `updated_checkout` as a jQuery event, which never
		// reaches addEventListener. Use the global Woo already loads.
		const jquery = ( window as WindowWithJquery ).jQuery;

		if ( jquery ) {
			jquery( document.body ).on( 'updated_checkout', onCheckoutUpdate );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}

	document.addEventListener( 'input', onInput, true );
	document.addEventListener( 'change', onInput, true );
	document.addEventListener( 'focusout', onFocusOut, true );
	document.addEventListener( 'click', onInteraction, true );

	// Typing in any checkout field is interaction; without this the idle timer
	// only moved on clicks. The same 60-second throttle applies.
	document.addEventListener( 'input', onInteraction, true );
	document.addEventListener( 'change', onInteraction, true );

	// Blocks re-renders mutate the DOM; the observer and this hint both work.
	// Classic `updated_checkout` is jQuery-only and binds in start().
	document.body.addEventListener( 'wc-blocks_checkout_updated', onCheckoutUpdate );

	window.addEventListener( 'pagehide', sendPendingOnHide );

	window.addEventListener( 'pageshow', () => {
		// A restored page may have lost a request the browser stopped.
		active = null;
		pageHideSent = false;
		refresh();
		retryUnacked();
	} );

	document.addEventListener( 'visibilitychange', () => {
		if ( ! document.hidden ) {
			retryUnacked();
		}
	} );

	// Autofill often lands after the checkout render settles.
	window.setTimeout( refresh, 1500 );
} )();

export {};
