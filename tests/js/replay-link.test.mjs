import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    REPLAY_HEADER,
    createReplayLink,
    flushOnServerError,
    linkRequestsToReplay,
    patchXhr,
    wrapFetch,
} from '../../resources/js/replay-link.js';

const REPLAY_ID = 'a'.repeat(32);
const location = { href: 'https://app.test/orders?page=2', origin: 'https://app.test' };

/** A stand-in for Sentry.getReplay(). */
function fakeReplay({ id = REPLAY_ID, mode = 'session' } = {}) {
    return {
        flushes: 0,
        getReplayId: () => id,
        getRecordingMode: () => mode,
        flush() {
            this.flushes++;

            return Promise.resolve();
        },
    };
}

function link(replay, options = {}) {
    return createReplayLink({
        getReplay: () => replay,
        location,
        tunnel: '/api/watchtower-relay',
        onErrorSampleRate: 1,
        ...options,
    });
}

/** A fetch that records each call and answers with the given status. */
function fakeFetch(status = 200) {
    const calls = [];
    const fetch = (input, init) => {
        calls.push({ input, init });

        return Promise.resolve(new Response(null, { status }));
    };

    return { fetch, calls };
}

class FakeXhr {
    constructor() {
        this.headers = {};
        this.listeners = {};
        this.status = 0;
    }

    open(method, url) {
        this.url = url;
    }

    setRequestHeader(name, value) {
        this.headers[name] = value;
    }

    addEventListener(type, listener) {
        this.listeners[type] = listener;
    }

    send() {
        this.sent = true;
    }

    respond(status) {
        this.status = status;
        this.listeners.loadend?.();
    }
}

test('tracks the app origin only, never the Sentry tunnel', () => {
    const tracked = link(fakeReplay());
    const cases = {
        '/livewire/update': true,
        'https://app.test/orders/5': true,
        'orders/5': true,
        '/api/watchtower-relay': false,
        'https://cdn.example.com/app.js': false,
        'https://app.test:8443/x': false,
        '': false,
        'http://[bad': false,
    };

    for (const [url, expected] of Object.entries(cases)) {
        assert.equal(tracked.tracks(url), expected, url);
    }
});

test('flushes on a 5xx and never on 419, 422 or other statuses', () => {
    const cases = { 500: true, 502: true, 503: true, 419: false, 422: false, 404: false, 200: false };

    for (const [status, expected] of Object.entries(cases)) {
        const replay = fakeReplay();

        assert.equal(flushOnServerError(replay, Number(status), 1), expected, status);
        assert.equal(replay.flushes, expected ? 1 : 0, status);
    }
});

test('does not flush, and so never starts a replay, when none is recording', () => {
    const replay = fakeReplay({ id: null });

    assert.equal(flushOnServerError(replay, 500, 1), false);
    assert.equal(flushOnServerError(undefined, 500, 1), false);
    assert.equal(replay.flushes, 0);
});

test('samples a buffer-mode flush by the on-error rate', () => {
    const buffer = fakeReplay({ mode: 'buffer' });

    assert.equal(flushOnServerError(buffer, 500, 0.25, () => 0.5), false);
    assert.equal(flushOnServerError(buffer, 500, 0.25, () => 0.1), true);
    assert.equal(flushOnServerError(fakeReplay({ mode: 'session' }), 500, 0, () => 0.9), true);
});

test('fetch to the app carries the replay id and flushes on a 5xx', async () => {
    const replay = fakeReplay();
    const { fetch, calls } = fakeFetch(500);
    const response = await wrapFetch(fetch, link(replay))('/livewire/update', {
        method: 'POST',
        headers: { 'X-Livewire': '1' },
    });

    const headers = new Headers(calls[0].init.headers);
    assert.equal(headers.get(REPLAY_HEADER), REPLAY_ID);
    assert.equal(headers.get('X-Livewire'), '1');
    assert.equal(calls[0].init.method, 'POST');
    assert.equal(response.status, 500);
    assert.equal(replay.flushes, 1);
});

test('fetch keeps the headers of a Request object', async () => {
    const { fetch, calls } = fakeFetch();
    await wrapFetch(fetch, link(fakeReplay()))(new Request('https://app.test/x', { headers: { Accept: 'application/json' } }));

    const headers = new Headers(calls[0].init.headers);
    assert.equal(headers.get(REPLAY_HEADER), REPLAY_ID);
    assert.equal(headers.get('Accept'), 'application/json');
});

test('fetch elsewhere, to the tunnel, or without a replay is left untouched', async () => {
    const cases = [
        ['cross-origin', 'https://api.example.com/x', fakeReplay()],
        ['tunnel', '/api/watchtower-relay', fakeReplay()],
        ['no replay', '/orders', fakeReplay({ id: null })],
    ];

    for (const [name, url, replay] of cases) {
        const { fetch, calls } = fakeFetch(500);
        const init = { method: 'GET' };
        await wrapFetch(fetch, link(replay))(url, init);

        assert.equal(calls[0].init, init, name);
        assert.equal(replay.flushes, 0, name);
    }
});

test('XHR to the app (Inertia, axios) carries the replay id and flushes on a 5xx', () => {
    class Xhr extends FakeXhr {}
    const replay = fakeReplay();
    patchXhr(Xhr, link(replay));

    const xhr = new Xhr();
    xhr.open('GET', '/orders');
    xhr.send();
    xhr.respond(500);

    assert.equal(xhr.headers[REPLAY_HEADER], REPLAY_ID);
    assert.equal(xhr.sent, true);
    assert.equal(replay.flushes, 1);
});

test('XHR elsewhere gets no header and never flushes', () => {
    class Xhr extends FakeXhr {}
    const replay = fakeReplay();
    patchXhr(Xhr, link(replay));

    const xhr = new Xhr();
    xhr.open('GET', 'https://api.example.com/x');
    xhr.send();
    xhr.respond(500);

    assert.deepEqual(xhr.headers, {});
    assert.equal(replay.flushes, 0);
});

test('XHR answered 419 or 422 never flushes', () => {
    class Xhr extends FakeXhr {}
    const replay = fakeReplay();
    patchXhr(Xhr, link(replay));

    for (const status of [419, 422]) {
        const xhr = new Xhr();
        xhr.open('POST', '/orders');
        xhr.send();
        xhr.respond(status);
    }

    assert.equal(replay.flushes, 0);
});

test('links the window fetch and XMLHttpRequest of a page', async () => {
    const replay = fakeReplay();
    const { fetch, calls } = fakeFetch();
    class Xhr extends FakeXhr {}
    const win = { location, fetch, XMLHttpRequest: Xhr };

    linkRequestsToReplay(win, { getReplay: () => replay, tunnel: '/api/watchtower-relay', onErrorSampleRate: 1 });
    await win.fetch('/orders');
    const xhr = new win.XMLHttpRequest();
    xhr.open('GET', '/orders');
    xhr.send();

    assert.equal(new Headers(calls[0].init.headers).get(REPLAY_HEADER), REPLAY_ID);
    assert.equal(xhr.headers[REPLAY_HEADER], REPLAY_ID);
});
