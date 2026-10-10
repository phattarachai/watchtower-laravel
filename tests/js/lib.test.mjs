import { test } from 'node:test';
import assert from 'node:assert/strict';

import { frameLocation } from '../../resources/js/watchtower/lib.js';

test('frameLocation shows the column a minified bundle needs', () => {
    assert.equal(frameLocation({ lineno: 1, colno: 48213 }), ':1:48213');
});

test('frameLocation shows the line alone when the frame has no column', () => {
    assert.equal(frameLocation({ lineno: 47 }), ':47');
    assert.equal(frameLocation({ lineno: 47, colno: null }), ':47');
});

test('frameLocation is empty without a line', () => {
    assert.equal(frameLocation({ colno: 3 }), '');
    assert.equal(frameLocation({}), '');
    assert.equal(frameLocation(undefined), '');
});
