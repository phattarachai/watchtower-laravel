import { test } from 'node:test';
import assert from 'node:assert/strict';

import { beforeSend } from '../../resources/js/livewire.js';

/** A capture as Sentry builds it for a non-Error value. */
const event = () => ({
    exception: {
        values: [{
            type: 'Error',
            value: 'Object captured as exception with keys: body, el, errors, expression, json, status',
        }],
    },
});

const send = (reason, e = event()) => beforeSend(e, { originalException: reason });

test('describes a Livewire response object by its HTTP status', () => {
    const result = send({
        status: 502,
        body: '<!DOCTYPE html>… 502: Bad gateway …',
        json: null,
        errors: null,
        el: {},
        expression: "$wire.__lazyLoad('…')",
    });

    assert.equal(result.exception.values[0].type, 'LivewireRequestFailed');
    assert.equal(result.exception.values[0].value, 'Livewire request failed with HTTP 502');
    assert.deepEqual(result.fingerprint, ['livewire-request-failed', '502']);
});

test('describes the unhandled-rejection twin, which carries no el/expression', () => {
    const result = send({ status: 502, body: 'x', json: null, errors: null });

    assert.equal(result.exception.values[0].value, 'Livewire request failed with HTTP 502');
    assert.deepEqual(result.fingerprint, ['livewire-request-failed', '502']);
});

test('fingerprints each status separately instead of collapsing them', () => {
    const a = send({ status: 500, body: 'x', json: null, errors: null });
    const b = send({ status: 503, body: 'x', json: null, errors: null });

    assert.notDeepEqual(a.fingerprint, b.fingerprint);
});

test('still reports a validation failure, with its status visible', () => {
    const result = send({ status: 422, body: '', json: {}, errors: { name: ['required'] } });

    assert.equal(result.exception.values[0].value, 'Livewire request failed with HTTP 422');
});

test('leaves an event that carries no exception values alone', () => {
    const message = { message: 'hi' };

    assert.equal(send({ status: 502, body: 'x', json: null, errors: null }, message), message);
    assert.deepEqual(message, { message: 'hi' });
});

test('drops a 419 page-expired rejection', () => {
    assert.equal(send({ status: 419, body: '', json: null, errors: null }), null);
});

test('drops an aborted wire:navigate transition', () => {
    assert.equal(send({ isFromCancelledTransition: true }), null);
});

test('drops a component torn down mid-navigation', () => {
    assert.equal(send('Component not found: undefined'), null);
});

test('drops an all-null response — the request was cancelled, not failed', () => {
    assert.equal(send({ status: null, body: null, json: null, errors: null }), null);
});

test('keeps a named missing component, which is a real registration error', () => {
    const e = event();

    assert.equal(send('Component not found: some-component', e), e);
});

test('passes unrelated errors through untouched', () => {
    const e = event();

    assert.equal(send(new TypeError('boom'), e), e);
    assert.equal(e.exception.values[0].type, 'Error');
    assert.equal(e.fingerprint, undefined);
});

test('tolerates a capture with no originalException', () => {
    const e = event();

    assert.equal(beforeSend(e, {}), e);
    assert.equal(beforeSend(e, undefined), e);
});
