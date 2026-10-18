/**
 * Crawler and chunk-load rules for the Watchtower browser SDK's beforeSend hook.
 *
 * Kept free of imports (no Sentry, no DOM) so it is directly unit-testable —
 * see tests/js/noise.test.mjs in the package repo. The caller passes the user
 * agent in.
 *
 * watchtower-core applies the same two rules at ingest (BotDetector and
 * Fingerprinter), so clients that have not updated are covered too. Keep
 * BOT_USER_AGENT in step with core's BotDetector::BOT_USER_AGENT.
 */

/**
 * A `+http://` contact link is the crawler convention ("+http://www.google.com/bot.html");
 * a `…bot/2.1` version token and a `compatible; …bot` comment cover the rest of the
 * generic ones. The named tokens are crawlers that follow neither convention. A bare
 * "bot" is not enough on its own: phone models such as Cubot contain it.
 *
 * HeadlessChrome is deliberately not matched. Automated browser tests use it,
 * and the errors they find are real.
 */
export const BOT_USER_AGENT = /\+https?:\/\/|(?:bot|crawler|spider)\/\d|compatible;\s*[\w.-]*(?:bot|crawler|spider)\b|GoogleOther|Google-InspectionTool|AdsBot-Google|Mediapartners-Google|bingpreview|facebookexternalhit|meta-externalagent|Bytespider|PetalBot/i;

/**
 * A browser failing to load a code-split chunk, in each engine's wording:
 * Chrome, Firefox, Safari, and Vite's CSS preload helper. Chrome, Firefox and
 * Vite append the asset URL, whose content hash changes on every deploy.
 */
const CHUNK_LOAD_ERROR = /Failed to fetch dynamically imported module|error loading dynamically imported module|Importing a module script failed|Unable to preload CSS/i;

/** Matches watchtower-core's Fingerprinter::CHUNK_LOAD_FINGERPRINT. */
export const CHUNK_LOAD_FINGERPRINT = ['chunk-load-error'];

/**
 * Whether the page is being rendered by a crawler (Googlebot, GoogleOther,
 * bingbot, …). Crawlers run the site's JavaScript with a frozen clock and a
 * stale asset cache, so the errors they hit are ones no visitor sees.
 *
 * @param {unknown} userAgent
 * @returns {boolean}
 */
export function isBotUserAgent(userAgent) {
    return typeof userAgent === 'string' && BOT_USER_AGENT.test(userAgent);
}

/**
 * Whether the event is a failed load of a code-split chunk. After a deploy the
 * old hashed chunk is gone, so each hash would otherwise open its own issue.
 *
 * @param {import('@sentry/browser').ErrorEvent} event
 * @returns {boolean}
 */
export function isChunkLoadError(event) {
    const values = event?.exception?.values;
    const message = values?.[values.length - 1]?.value;

    return typeof message === 'string' && CHUNK_LOAD_ERROR.test(message);
}

/**
 * Sentry beforeSend hook: drop events from crawlers, and send every chunk-load
 * failure under one fingerprint.
 *
 * @param {import('@sentry/browser').ErrorEvent} event
 * @param {unknown} userAgent navigator.userAgent
 * @returns {import('@sentry/browser').ErrorEvent | null}
 */
export function beforeSend(event, userAgent) {
    if (isBotUserAgent(userAgent)) {
        return null;
    }

    if (isChunkLoadError(event)) {
        event.fingerprint = [...CHUNK_LOAD_FINGERPRINT];
    }

    return event;
}
