import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    SDK_MASKED_ATTRIBUTES,
    readReplayConfig,
    replayIntegrationOptions,
} from '../../resources/js/replay-config.js';

test('reads both sample rates from the meta tag content', () => {
    assert.deepEqual(
        readReplayConfig('{"sessionSampleRate":0.1,"onErrorSampleRate":1}'),
        { sessionSampleRate: 0.1, onErrorSampleRate: 1 },
    );
});

test('returns null when replay is off or the tag is absent or broken', () => {
    const cases = {
        'no tag': undefined,
        'empty content': '',
        'both rates zero': '{"sessionSampleRate":0,"onErrorSampleRate":0}',
        'not JSON': 'session=1',
        'JSON null': 'null',
        'rates not numbers': '{"sessionSampleRate":"1","onErrorSampleRate":true}',
    };

    for (const [name, content] of Object.entries(cases)) {
        assert.equal(readReplayConfig(content), null, name);
    }
});

test('clamps rates into 0..1 and treats a missing rate as 0', () => {
    assert.deepEqual(
        readReplayConfig('{"sessionSampleRate":7,"onErrorSampleRate":-1}'),
        { sessionSampleRate: 1, onErrorSampleRate: 0 },
    );
    assert.deepEqual(readReplayConfig('{"onErrorSampleRate":0.5}'), { sessionSampleRate: 0, onErrorSampleRate: 0.5 });
});

test('masks Livewire and Inertia server state on top of the SDK defaults', () => {
    const { maskAttributes } = replayIntegrationOptions();

    for (const attribute of [...SDK_MASKED_ATTRIBUTES, 'wire:snapshot', 'wire:effects', 'wire:initial-data', 'data-page']) {
        assert.ok(maskAttributes.includes(attribute), attribute);
    }
});

test('keeps every privacy default of the SDK on', () => {
    const options = replayIntegrationOptions();

    assert.equal(options.maskAllText, true);
    assert.equal(options.maskAllInputs, true);
    assert.equal(options.blockAllMedia, true);
    assert.deepEqual(options.networkDetailAllowUrls, []);
});
