/**
 * State checks for the checkout capture intent.
 *
 * These are the conditions that decide whether a clear or a capture is still
 * needed. They live apart from the DOM and network glue so the exact race
 * sequences (clear acknowledged, another value in flight, field cleared again)
 * stay testable.
 */

export type EmailState = 'valid' | 'none';
export type IntentKind = 'capture' | 'activity';

export interface Intent {
	state: EmailState;
	email: string;
	order: number;
	kind: IntentKind;
	attempts: number;
	lastReason?: string;
	lastStatus?: number;
}

/** Everything that can still change the stored address. */
export interface IntentState {
	ackedState: EmailState | '';
	ackedValue: string;
	active: Intent | null;
	unacked: Intent | null;
	pending: Intent | null;
}

/**
 * A clear is only useful when the server may hold a valid address. A newer
 * valid request that is in flight or waiting for a retry counts, even when the
 * last acknowledgement was already a clear.
 */
export function mustClearEmail( state: IntentState ): boolean {
	return (
		state.ackedState === 'valid' ||
		state.active?.state === 'valid' ||
		state.unacked?.state === 'valid'
	);
}

/**
 * Skip resending an address only when nothing in flight can replace it. A
 * pending clear, a clear in flight, or a different capture still owes the
 * server a value, so the field value must be sent again.
 */
export function isAlreadyCaptured(
	state: IntentState,
	value: string
): boolean {
	if ( state.ackedState !== 'valid' || state.ackedValue !== value ) {
		return false;
	}

	if ( state.pending?.state === 'none' ) {
		return false;
	}

	const inflight = state.active ?? state.unacked;

	if ( ! inflight ) {
		return true;
	}

	// A heartbeat never changes the stored address.
	if ( inflight.kind === 'activity' ) {
		return true;
	}

	return inflight.state === 'valid' && inflight.email === value;
}

/** Only an explicit `ok: true` body acknowledges a capture. */
export function isAcknowledged( result: { ok?: boolean } ): boolean {
	return result.ok === true;
}
