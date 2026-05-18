<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Support;

/**
 * Idempotent inserters for the watchtower JS init block (into a Vite entry)
 * and the watchtower-user-* meta tags (into a Blade layout's <head>).
 *
 * Both forms use sentinel comments so reruns detect "already patched" and
 * skip cleanly. The same markers are consumed by `watchtower:test` to verify
 * the snippets have actually landed.
 */
final class FrontendPatcher
{
    public const MARKER_JS_OPEN  = '// watchtower:sentry-init';

    public const MARKER_JS_CLOSE = '// /watchtower:sentry-init';

    public const MARKER_BLADE_OPEN  = '{{-- watchtower:user-meta --}}';

    public const MARKER_BLADE_CLOSE = '{{-- /watchtower:user-meta --}}';

    public static function renderJsSnippet(): string
    {
        $open  = self::MARKER_JS_OPEN;
        $close = self::MARKER_JS_CLOSE;

        return <<<JS
        {$open}
        import * as Sentry from '@sentry/browser';
        import { applyWatchtowerUser } from './vendor/watchtower-user-context.js';

        if (import.meta.env.VITE_SENTRY_DSN) {
            Sentry.init({
                dsn: import.meta.env.VITE_SENTRY_DSN,
                tunnel: import.meta.env.VITE_SENTRY_TUNNEL,
                environment: import.meta.env.VITE_SENTRY_ENVIRONMENT,
                sendDefaultPii: false,
                tracesSampleRate: 0,
                denyUrls: [
                    /^chrome-extension:\\/\\//i,
                    /^moz-extension:\\/\\//i,
                    /^safari-extension:\\/\\//i,
                    /^safari-web-extension:\\/\\//i,
                ],
            });
            applyWatchtowerUser();
        }
        {$close}
        JS;
    }

    public static function renderBladeSnippet(): string
    {
        $open  = self::MARKER_BLADE_OPEN;
        $close = self::MARKER_BLADE_CLOSE;

        return <<<BLADE
        {$open}
        <meta name="watchtower-user-id" content="{{ auth()->id() ?? '' }}">
        <meta name="watchtower-user-email" content="{{ auth()->user()?->email ?? '' }}">
        <meta name="watchtower-user-name" content="{{ auth()->user()?->name ?? '' }}">
        {$close}
        BLADE;
    }

    /**
     * Prepend the JS snippet to a Vite entry. No-op when the file already
     * contains the open marker. Returns true when the file was modified.
     */
    public static function patchJsEntry(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        $contents = (string) file_get_contents($path);

        if (str_contains($contents, self::MARKER_JS_OPEN)) {
            return false;
        }

        $patched = self::renderJsSnippet()."\n\n".ltrim($contents);

        return file_put_contents($path, $patched) !== false;
    }

    /**
     * Insert the meta-tag snippet into a Blade layout, immediately before the
     * closing `</head>` tag. No-op when the marker is already present or
     * when no </head> exists.
     */
    public static function patchBladeLayout(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        $contents = (string) file_get_contents($path);

        if (str_contains($contents, self::MARKER_BLADE_OPEN)) {
            return false;
        }

        if (! preg_match('/<\/head>/i', $contents)) {
            return false;
        }

        $snippet = self::renderBladeSnippet();
        $patched = (string) preg_replace('/(\s*)<\/head>/i', "\n    {$snippet}\n$1</head>", $contents, 1);

        if ($patched === $contents) {
            return false;
        }

        return file_put_contents($path, $patched) !== false;
    }
}
