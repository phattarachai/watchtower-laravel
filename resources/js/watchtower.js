import * as Sentry from '@sentry/browser';

let initialized = false;

/**
 * Initialize the Watchtower browser SDK and apply the logged-in user.
 *
 * Wraps Sentry.init() with the Watchtower-tuned defaults (same-origin tunnel,
 * no PII, browser-extension denyUrls) and forwards <meta name="watchtower-user-*">
 * tags into Sentry's user scope.
 *
 * Idempotent — only the first call performs Sentry.init(); subsequent calls
 * just re-apply user context (useful after a SPA navigation that swaps the
 * meta tags).
 *
 * No-op when VITE_SENTRY_DSN is not set (e.g. local without the env var).
 */
export function initWatchtower() {
    if (!import.meta.env.VITE_SENTRY_DSN) {
        return;
    }

    if (!initialized) {
        Sentry.init({
            dsn: import.meta.env.VITE_SENTRY_DSN,
            tunnel: import.meta.env.VITE_SENTRY_TUNNEL,
            environment: import.meta.env.VITE_SENTRY_ENVIRONMENT,
            sendDefaultPii: false,
            tracesSampleRate: 0,
            denyUrls: [
                /^chrome-extension:\/\//i,
                /^moz-extension:\/\//i,
                /^safari-extension:\/\//i,
                /^safari-web-extension:\/\//i,
            ],
        });
        initialized = true;
    }

    applyWatchtowerUser();
}

/**
 * Read <meta name="watchtower-user-{id,email,name}"> tags from <head> and
 * apply them to Sentry's scope via setUser(). Safe to call any time after
 * Sentry.init(). No-op when no meta tags are present (e.g. logged-out pages).
 *
 * Pairs with the Laravel-side WatchtowerUserContext middleware so that
 * browser-thrown exceptions populate the same User tab on Watchtower as
 * server-side ones.
 */
export function applyWatchtowerUser() {
    const get = (key) => {
        const el = document.querySelector(`meta[name="watchtower-user-${key}"]`);
        const value = el?.content?.trim();
        return value || null;
    };

    const payload = {};
    const id    = get('id');
    const email = get('email');
    const name  = get('name');

    if (id)    payload.id       = id;
    if (email) payload.email    = email;
    if (name)  payload.username = name;

    if (Object.keys(payload).length > 0) {
        Sentry.setUser(payload);
    }
}
