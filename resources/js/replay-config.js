/**
 * Session Replay settings for the Watchtower browser helper. Import-free so it
 * is unit-testable without a bundler: see tests/js/replay-config.test.mjs.
 */

/** The attributes @sentry/replay masks by default. */
export const SDK_MASKED_ATTRIBUTES = ['title', 'placeholder', 'aria-label'];

/** Server state that Livewire and Inertia ≤2 keep in DOM attributes. */
export const SERVER_STATE_ATTRIBUTES = ['wire:snapshot', 'wire:effects', 'wire:initial-data', 'data-page'];

/**
 * @param {unknown} value
 * @returns {number}
 */
function sampleRate(value) {
    return typeof value === 'number' && Number.isFinite(value)
        ? Math.min(1, Math.max(0, value))
        : 0;
}

/**
 * Parse the content of <meta name="watchtower-replay">.
 *
 * @param {unknown} content
 * @returns {{sessionSampleRate: number, onErrorSampleRate: number} | null}
 */
export function readReplayConfig(content) {
    if (typeof content !== 'string' || content.trim() === '') {
        return null;
    }

    let parsed;

    try {
        parsed = JSON.parse(content);
    } catch {
        return null;
    }

    const sessionSampleRate = sampleRate(parsed?.sessionSampleRate);
    const onErrorSampleRate = sampleRate(parsed?.onErrorSampleRate);

    if (sessionSampleRate === 0 && onErrorSampleRate === 0) {
        return null;
    }

    return { sessionSampleRate, onErrorSampleRate };
}

/**
 * Options for Sentry's replayIntegration().
 *
 * @returns {Record<string, unknown>}
 */
export function replayIntegrationOptions() {
    return {
        maskAllText: true,
        maskAllInputs: true,
        blockAllMedia: true,
        maskAttributes: [...SDK_MASKED_ATTRIBUTES, ...SERVER_STATE_ATTRIBUTES],
        networkDetailAllowUrls: [],
    };
}
