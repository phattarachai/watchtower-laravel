import * as Sentry from '@sentry/browser';

let initialized = false;

/**
 * Sentry beforeSend hook that drops the transient, non-actionable promise
 * rejections Livewire/Filament emit during normal SPA navigation and session
 * lifecycle. These arrive frame-less via the global onunhandledrejection
 * handler and are pure noise — never a real application bug:
 *
 *   - 419 "Page Expired": an idle session / stale CSRF token makes Livewire
 *     reject its request promise with the raw response object ({status: 419}).
 *   - wire:navigate transition aborted because the user clicked through before
 *     it settled ({isFromCancelledTransition: true}).
 *   - a component torn down mid-navigation resolves to an undefined name
 *     ("Component not found: undefined") — a race, never a registration error
 *     (a genuinely missing component is named in the message).
 *
 * @param {import('@sentry/browser').ErrorEvent} event
 * @param {import('@sentry/browser').EventHint} hint
 * @returns {import('@sentry/browser').ErrorEvent | null}
 */
function dropLivewireTransientNoise(event, hint) {
    const reason = hint?.originalException;

    if (reason && typeof reason === 'object') {
        if (reason.status === 419 || reason.isFromCancelledTransition === true) {
            return null;
        }
    }

    if (typeof reason === 'string' && reason.includes('Component not found: undefined')) {
        return null;
    }

    return event;
}

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
            beforeSend: dropLivewireTransientNoise,
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
