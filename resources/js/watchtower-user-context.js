import * as Sentry from '@sentry/browser';

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
