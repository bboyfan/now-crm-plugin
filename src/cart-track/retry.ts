/**
 * Retry policy for the checkout capture request.
 *
 * Only failures that a later attempt can plausibly fix are retried. The order
 * of an observation never changes between attempts, so a retry can never
 * overwrite a newer value; the server treats an equal order as already stored.
 */

export interface RetryDecision {
	retry: boolean;
	delay: number;
}

/** One entry per allowed retry. */
export const RETRY_DELAYS = [ 1000, 3000, 9000 ];

export const MAX_CAPTURE_RETRIES = RETRY_DELAYS.length;

export function captureRetryPolicy(
	reason: string,
	status: number,
	attempt: number,
	retryAfterMs: number
): RetryDecision {
	if ( attempt >= MAX_CAPTURE_RETRIES ) {
		return { retry: false, delay: 0 };
	}

	const retryable =
		status === 429 ||
		status >= 500 ||
		reason === 'network' ||
		reason === 'no_session' ||
		reason === 'rate_limited' ||
		reason === 'invalid_response';

	if ( ! retryable ) {
		return { retry: false, delay: 0 };
	}

	const fallback = RETRY_DELAYS[ attempt ];

	if ( status === 429 && retryAfterMs > 0 ) {
		return { retry: true, delay: Math.min( retryAfterMs, 30000 ) };
	}

	return { retry: true, delay: fallback };
}
