/**
 * Retry policy checks for the checkout capture request.
 *
 * Run with `npm run test:unit`; Node executes the TypeScript directly.
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
	captureRetryPolicy,
	MAX_CAPTURE_RETRIES,
	RETRY_DELAYS,
} from './retry.ts';

describe( 'captureRetryPolicy', () => {
	it( 'retries a transient network failure with a bounded delay', () => {
		assert.deepEqual( captureRetryPolicy( 'network', 0, 0, 0 ), {
			retry: true,
			delay: RETRY_DELAYS[ 0 ],
		} );
	} );

	it( 'retries 429 using Retry-After when present', () => {
		assert.deepEqual( captureRetryPolicy( 'rate_limited', 429, 1, 4000 ), {
			retry: true,
			delay: 4000,
		} );
	} );

	it( 'caps a long Retry-After', () => {
		assert.equal(
			captureRetryPolicy( 'rate_limited', 429, 0, 120000 ).delay,
			30000
		);
	} );

	it( 'retries server errors', () => {
		assert.deepEqual( captureRetryPolicy( 'http_503', 503, 0, 0 ), {
			retry: true,
			delay: RETRY_DELAYS[ 0 ],
		} );
	} );

	it( 'retries a session that was not ready yet', () => {
		assert.equal( captureRetryPolicy( 'no_session', 200, 0, 0 ).retry, true );
	} );

	it( 'does not retry a stale nonce or a forbidden origin', () => {
		assert.equal( captureRetryPolicy( 'invalid_nonce', 403, 0, 0 ).retry, false );
		assert.equal(
			captureRetryPolicy( 'forbidden_origin', 403, 0, 0 ).retry,
			false
		);
	} );

	it( 'does not spin on an empty cart or a rotated cycle', () => {
		assert.equal( captureRetryPolicy( 'empty_cart', 200, 0, 0 ).retry, false );
		assert.equal( captureRetryPolicy( 'stale_cycle', 200, 0, 0 ).retry, false );
		assert.equal( captureRetryPolicy( 'cycle_rotated', 200, 0, 0 ).retry, false );
	} );

	it( 'does not retry a disabled feature or an invalid address', () => {
		assert.equal( captureRetryPolicy( 'disabled', 200, 0, 0 ).retry, false );
		assert.equal( captureRetryPolicy( 'invalid_email', 200, 0, 0 ).retry, false );
	} );

	it( 'retries a 200 response without an explicit ok', () => {
		assert.equal(
			captureRetryPolicy( 'invalid_response', 200, 0, 0 ).retry,
			true
		);
	} );

	it( 'stops after the bounded number of attempts', () => {
		assert.equal(
			captureRetryPolicy( 'network', 0, MAX_CAPTURE_RETRIES, 0 ).retry,
			false
		);
	} );
} );
