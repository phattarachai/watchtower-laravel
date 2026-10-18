import { test } from 'node:test';
import assert from 'node:assert/strict';

import { beforeSend, isBotUserAgent, isChunkLoadError } from '../../resources/js/noise.js';

const CHROME = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';

/** A browser capture as @sentry/browser builds it. */
const event = (value = 'x is not defined', type = 'ReferenceError') => ({
    exception: { values: [{ type, value }] },
});

test('recognises crawler user agents', () => {
    for (const userAgent of [
        'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.8010.52 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.8010.52 Mobile Safari/537.36 (compatible; GoogleOther)',
        'Mozilla/5.0 (compatible; Google-InspectionTool/1.0;)',
        'AdsBot-Google (+http://www.google.com/adsbot.html)',
        'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm) Chrome/116.0.1938.76 Safari/537.36',
        'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)',
    ]) {
        assert.equal(isBotUserAgent(userAgent), true, userAgent);
    }
});

test('lets real browsers and automated browser tests through', () => {
    for (const userAgent of [
        CHROME,
        'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/27.0 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/154.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Linux; Android 12; CUBOT_X50 Build/SP1A) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
        '',
        undefined,
    ]) {
        assert.equal(isBotUserAgent(userAgent), false, String(userAgent));
    }
});

test('drops any event from a crawler', () => {
    assert.equal(beforeSend(event(), 'Mozilla/5.0 (compatible; GoogleOther)'), null);
});

test('recognises chunk-load failures in each browser\'s wording', () => {
    for (const value of [
        'Failed to fetch dynamically imported module: https://site.com/build/assets/Show-CMSx3ifN.js',
        'Importing a module script failed.',
        'error loading dynamically imported module: https://site.com/build/assets/Show-CMSx3ifN.js',
        'Unable to preload CSS for /build/assets/app-Bx12cd34.css',
    ]) {
        assert.equal(isChunkLoadError(event(value, 'TypeError')), true, value);
    }

    assert.equal(isChunkLoadError(event()), false);
    assert.equal(isChunkLoadError({ message: 'hi' }), false);
});

test('sends every chunk-load failure under one fingerprint', () => {
    const a = beforeSend(event('Failed to fetch dynamically imported module: https://site.com/build/assets/Show-CMSx3ifN.js', 'TypeError'), CHROME);
    const b = beforeSend(event('Failed to fetch dynamically imported module: https://site.com/build/assets/Index-BG_wbAkN.js', 'TypeError'), CHROME);

    assert.deepEqual(a.fingerprint, ['chunk-load-error']);
    assert.deepEqual(a.fingerprint, b.fingerprint);
});

test('reads the top of an exception chain', () => {
    const chained = { exception: { values: [
        { type: 'Error', value: 'cause' },
        { type: 'TypeError', value: 'Importing a module script failed.' },
    ] } };

    assert.deepEqual(beforeSend(chained, CHROME).fingerprint, ['chunk-load-error']);
});

test('passes other events from real browsers through untouched', () => {
    const e = event();

    assert.equal(beforeSend(e, CHROME), e);
    assert.equal(e.fingerprint, undefined);
});
