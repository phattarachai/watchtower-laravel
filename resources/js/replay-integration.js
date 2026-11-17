import { addIntegration, getReplay, replayIntegration } from '@sentry/browser';
import { replayIntegrationOptions } from './replay-config.js';
import { linkRequestsToReplay } from './replay-link.js';

/**
 * Loaded by initWatchtower() through a dynamic import, only when
 * <meta name="watchtower-replay"> enables replay for the page.
 *
 * @param {{sessionSampleRate: number, onErrorSampleRate: number}} config
 * @param {string | undefined} tunnel
 */
export function startReplay(config, tunnel) {
    addIntegration(replayIntegration(replayIntegrationOptions()));

    linkRequestsToReplay(window, {
        getReplay,
        tunnel,
        onErrorSampleRate: config.onErrorSampleRate,
    });
}
