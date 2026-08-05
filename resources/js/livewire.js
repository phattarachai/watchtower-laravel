/**
 * Livewire/Filament rules for the Watchtower browser SDK's beforeSend hook.
 *
 * Kept free of imports (no Sentry, no DOM) so it is directly unit-testable —
 * see tests/js/livewire.test.mjs in the package repo.
 */

/**
 * Keys Livewire always sets on the response object it rejects a request with.
 * Livewire rejects with this raw object rather than an Error, which is why
 * Sentry can only describe it as "Object captured as exception with keys: …".
 */
const LIVEWIRE_RESPONSE_KEYS = ['status', 'body', 'json', 'errors'];

/**
 * Whether a thrown/rejected value is a Livewire request-response object.
 *
 * @param {unknown} reason
 * @returns {boolean}
 */
export function isLivewireResponse(reason) {
    return !! reason
        && typeof reason === 'object'
        && LIVEWIRE_RESPONSE_KEYS.every((key) => key in reason);
}

/**
 * Whether the captured value is one of the transient, non-actionable failures
 * Livewire/Filament emit during normal SPA navigation and session lifecycle.
 * These are pure noise — never a real application bug:
 *
 *   - 419 "Page Expired": an idle session / stale CSRF token makes Livewire
 *     reject its request promise with the raw response object ({status: 419}).
 *   - wire:navigate transition aborted because the user clicked through before
 *     it settled ({isFromCancelledTransition: true}).
 *   - a component torn down mid-navigation resolves to an undefined name
 *     ("Component not found: undefined") — a race, never a registration error
 *     (a genuinely missing component is named in the message).
 *   - a Livewire response object with every key null: the request was
 *     superseded or aborted before it settled (Request.invokeOnCancel /
 *     invokeOnFailure), e.g. clicking a table filter twice inside one
 *     round-trip. A real HTTP error populates status/body and a validation
 *     failure populates errors, so those still report.
 *
 * @param {unknown} reason
 * @returns {boolean}
 */
export function isLivewireTransientNoise(reason) {
    if (typeof reason === 'string') {
        return reason.includes('Component not found: undefined');
    }

    if (! reason || typeof reason !== 'object') {
        return false;
    }

    if (reason.status === 419 || reason.isFromCancelledTransition === true) {
        return true;
    }

    return isLivewireResponse(reason)
        && LIVEWIRE_RESPONSE_KEYS.every((key) => reason[key] === null);
}

/**
 * Restate a Livewire response-object capture as a legible, well-grouped event.
 *
 * Because Livewire rejects with the raw response instead of an Error, Sentry
 * titles every server-side failure identically ("Object captured as exception
 * with keys: body, el, errors, expression, json, status") and fingerprints on
 * that key list — so a 502 on a lazy-load and a 500 on a table filter collapse
 * into one meaningless issue, with the actual status buried in
 * `extra.__serialized__`. The stack is no help either: it is entirely the
 * minified Sentry SDK's own capture machinery, which makes it look like the
 * tracker is crashing rather than the server.
 *
 * Rewriting the exception to "LivewireRequestFailed: HTTP 502" and
 * fingerprinting by status gives each failure mode its own issue. This adds no
 * filtering — it only surfaces data the event already carries.
 *
 * @param {import('@sentry/browser').ErrorEvent} event
 * @param {{status: ?number}} reason
 * @returns {import('@sentry/browser').ErrorEvent}
 */
export function describeLivewireFailure(event, reason) {
    const status = reason.status ?? 'no response';
    const exception = event.exception?.values?.[0];

    if (! exception) {
        return event;
    }

    exception.type = 'LivewireRequestFailed';
    exception.value = `Livewire request failed with HTTP ${status}`;

    event.fingerprint = ['livewire-request-failed', String(status)];

    return event;
}

/**
 * Sentry beforeSend hook: drop Livewire's transient noise, and make its
 * genuine request failures readable instead of leaving them as opaque objects.
 *
 * @param {import('@sentry/browser').ErrorEvent} event
 * @param {import('@sentry/browser').EventHint} hint
 * @returns {import('@sentry/browser').ErrorEvent | null}
 */
export function beforeSend(event, hint) {
    const reason = hint?.originalException;

    if (isLivewireTransientNoise(reason)) {
        return null;
    }

    if (isLivewireResponse(reason)) {
        return describeLivewireFailure(event, reason);
    }

    return event;
}
