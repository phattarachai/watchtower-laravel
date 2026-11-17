/**
 * Links server-side errors to the browser's Session Replay. Import-free so it
 * is unit-testable without a bundler: see tests/js/replay-link.test.mjs.
 */

export const REPLAY_HEADER = 'X-Watchtower-Replay-Id';

/**
 * @param {unknown} status
 * @returns {boolean}
 */
export function shouldFlush(status) {
    return typeof status === 'number' && status >= 500;
}

/**
 * @param {{getReplayId?: () => (string | undefined), getRecordingMode?: () => (string | undefined), flush: () => Promise<void>} | undefined} replay
 * @param {unknown} status
 * @param {number} onErrorSampleRate
 * @param {() => number} random
 * @returns {boolean}
 */
export function flushOnServerError(replay, status, onErrorSampleRate, random = Math.random) {
    if (! shouldFlush(status) || ! replay?.getReplayId?.()) {
        return false;
    }

    if (replay.getRecordingMode?.() === 'buffer' && random() >= onErrorSampleRate) {
        return false;
    }

    Promise.resolve(replay.flush()).catch(() => {});

    return true;
}

/**
 * @param {{getReplay: () => any, location: {href: string, origin: string}, tunnel?: string, onErrorSampleRate: number, random?: () => number}} options
 */
export function createReplayLink({ getReplay, location, tunnel, onErrorSampleRate, random = Math.random }) {
    const tunnelPath = tunnel ? new URL(tunnel, location.href).pathname : null;

    return {
        /** @param {unknown} url */
        tracks(url) {
            if (! url) {
                return false;
            }

            try {
                const target = new URL(String(url), location.href);

                return target.origin === location.origin && target.pathname !== tunnelPath;
            } catch {
                return false;
            }
        },

        /** @returns {string | null} */
        replayId() {
            return getReplay()?.getReplayId?.() || null;
        },

        /** @param {unknown} status */
        onStatus(status) {
            flushOnServerError(getReplay(), status, onErrorSampleRate, random);
        },
    };
}

/**
 * @param {unknown} input
 * @returns {boolean}
 */
function isRequest(input) {
    return typeof Request !== 'undefined' && input instanceof Request;
}

/**
 * @param {unknown} input
 * @param {RequestInit | undefined} init
 * @param {string} replayId
 * @returns {RequestInit}
 */
function withReplayHeader(input, init, replayId) {
    const headers = new Headers(init?.headers ?? (isRequest(input) ? input.headers : undefined));
    headers.set(REPLAY_HEADER, replayId);

    return { ...init, headers };
}

/**
 * @param {typeof fetch} fetch
 * @param {ReturnType<typeof createReplayLink>} link
 * @returns {typeof fetch}
 */
export function wrapFetch(fetch, link) {
    return function watchtowerFetch(input, init) {
        const url = isRequest(input) ? input.url : input;

        if (! link.tracks(url)) {
            return fetch(input, init);
        }

        const replayId = link.replayId();

        return fetch(input, replayId ? withReplayHeader(input, init, replayId) : init).then((response) => {
            link.onStatus(response?.status);

            return response;
        });
    };
}

/**
 * @param {typeof XMLHttpRequest} Xhr
 * @param {ReturnType<typeof createReplayLink>} link
 */
export function patchXhr(Xhr, link) {
    const { open, send } = Xhr.prototype;
    const tracked = new WeakSet();

    Xhr.prototype.open = function (method, url, ...rest) {
        if (link.tracks(url)) {
            tracked.add(this);
        }

        return open.call(this, method, url, ...rest);
    };

    Xhr.prototype.send = function (body) {
        if (tracked.has(this)) {
            const replayId = link.replayId();

            if (replayId) {
                this.setRequestHeader(REPLAY_HEADER, replayId);
            }

            this.addEventListener('loadend', () => link.onStatus(this.status));
        }

        return send.call(this, body);
    };
}

/**
 * Send X-Watchtower-Replay-Id on every same-origin fetch and XHR (Livewire,
 * Inertia, axios) while a replay runs, and flush the replay on a 5xx.
 *
 * @param {Window} win
 * @param {{getReplay: () => any, tunnel?: string, onErrorSampleRate: number}} options
 */
export function linkRequestsToReplay(win, options) {
    const link = createReplayLink({ ...options, location: win.location });

    if (typeof win.fetch === 'function') {
        win.fetch = wrapFetch(win.fetch.bind(win), link);
    }

    if (typeof win.XMLHttpRequest === 'function') {
        patchXhr(win.XMLHttpRequest, link);
    }

    return link;
}
