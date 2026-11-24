/**
 * Regression checks for the capture clear/capture races.
 *
 * Run with `npm run test:unit`.
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
	isAcknowledged,
	isAlreadyCaptured,
	mustClearEmail,
	type Intent,
	type IntentState,
} from './decide.ts';

function capture( email: string, order: number ): Intent {
	return { state: 'valid', email, order, kind: 'capture', attempts: 0 };
}

function clear( order: number ): Intent {
	return { state: 'none', email: '', order, kind: 'capture', attempts: 0 };
}

function idle( ackedState: IntentState[ 'ackedState' ], ackedValue: string ): IntentState {
	return { ackedState, ackedValue, active: null, unacked: null, pending: null };
}

describe( 'mustClearEmail', () => {
	it( 'queues a clear when a capture is still in flight after a clear was acked', () => {
		const state: IntentState = {
			...idle( 'none', '' ),
			active: capture( 'b@example.com', 10 ),
		};

		assert.equal( mustClearEmail( state ), true );
	} );

	it( 'queues a clear when a failed capture is waiting for a retry', () => {
		const state: IntentState = {
			...idle( 'none', '' ),
			unacked: capture( 'b@example.com', 10 ),
		};

		assert.equal( mustClearEmail( state ), true );
	} );

	it( 'stays quiet when the server already holds no address', () => {
		assert.equal( mustClearEmail( idle( 'none', '' ) ), false );
		assert.equal(
			mustClearEmail( {
				...idle( 'none', '' ),
				active: clear( 10 ),
			} ),
			false
		);
	} );

	it( 'clears a previously acknowledged address', () => {
		assert.equal( mustClearEmail( idle( 'valid', 'a@example.com' ) ), true );
	} );
} );

describe( 'isAlreadyCaptured', () => {
	it( 'resends the address while a clear is in flight', () => {
		const state: IntentState = {
			...idle( 'valid', 'a@example.com' ),
			active: clear( 10 ),
		};

		assert.equal( isAlreadyCaptured( state, 'a@example.com' ), false );
	} );

	it( 'resends the address while a different capture is in flight', () => {
		const state: IntentState = {
			...idle( 'valid', 'a@example.com' ),
			active: capture( 'b@example.com', 10 ),
		};

		assert.equal( isAlreadyCaptured( state, 'a@example.com' ), false );
	} );

	it( 'resends the address while a clear is queued', () => {
		const state: IntentState = {
			...idle( 'valid', 'a@example.com' ),
			pending: clear( 10 ),
		};

		assert.equal( isAlreadyCaptured( state, 'a@example.com' ), false );
	} );

	it( 'skips a resend when the acknowledged value is idle', () => {
		assert.equal(
			isAlreadyCaptured( idle( 'valid', 'a@example.com' ), 'a@example.com' ),
			true
		);
	} );

	it( 'skips a resend while a heartbeat is in flight', () => {
		const state: IntentState = {
			...idle( 'valid', 'a@example.com' ),
			active: {
				state: 'valid',
				email: 'a@example.com',
				order: 10,
				kind: 'activity',
				attempts: 0,
			},
		};

		assert.equal( isAlreadyCaptured( state, 'a@example.com' ), true );
	} );

	it( 'skips a resend while the same value is in flight', () => {
		const state: IntentState = {
			...idle( 'valid', 'a@example.com' ),
			active: capture( 'a@example.com', 10 ),
		};

		assert.equal( isAlreadyCaptured( state, 'a@example.com' ), true );
	} );
} );

describe( 'isAcknowledged', () => {
	it( 'rejects an empty body on HTTP 200', () => {
		assert.equal( isAcknowledged( {} ), false );
	} );

	it( 'rejects an explicit failure', () => {
		assert.equal( isAcknowledged( { ok: false } ), false );
	} );

	it( 'accepts an explicit success', () => {
		assert.equal( isAcknowledged( { ok: true } ), true );
	} );
} );
