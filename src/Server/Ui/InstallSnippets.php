<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Ui;

use Phattarachai\WatchtowerLaravel\Server\Models\Project;

/**
 * Copy-paste wiring for each platform a project can be created as. The tunnel
 * URL is the same-origin relay path, which is what dodges ad blockers.
 */
final class InstallSnippets
{
    /**
     * @return list<array{key: string, label: string, language: string, code: string}>
     */
    public static function for(Project $project, string $dsn): array
    {
        $tunnel = (string) config('watchtower.relay.path', '/api/watchtower-relay');

        return [
            [
                'key' => 'laravel',
                'label' => 'Laravel (.env)',
                'language' => 'ini',
                'code' => self::laravel($dsn),
            ],
            [
                'key' => 'browser',
                'label' => 'Browser JavaScript',
                'language' => 'javascript',
                'code' => self::browser($dsn, $tunnel),
            ],
            [
                'key' => 'nextjs',
                'label' => 'Next.js',
                'language' => 'ini',
                'code' => self::nextjs($dsn, $tunnel),
            ],
            [
                'key' => 'wordpress',
                'label' => 'WordPress',
                'language' => 'php',
                'code' => self::wordpress($dsn),
            ],
        ];
    }

    private static function laravel(string $dsn): string
    {
        return <<<INI
        WATCHTOWER_DSN={$dsn}
        SENTRY_LARAVEL_DSN={$dsn}
        SENTRY_TRACES_SAMPLE_RATE=0
        SENTRY_SEND_DEFAULT_PII=true
        INI;
    }

    private static function browser(string $dsn, string $tunnel): string
    {
        return <<<JS
        import * as Sentry from '@sentry/browser'

        Sentry.init({
            dsn: '{$dsn}',
            tunnel: '{$tunnel}',
            environment: import.meta.env.MODE,
        })
        JS;
    }

    private static function nextjs(string $dsn, string $tunnel): string
    {
        return <<<INI
        # npm i @sentry/nextjs — then in sentry.client.config.ts:
        #   Sentry.init({ dsn: process.env.NEXT_PUBLIC_SENTRY_DSN, tunnel: '{$tunnel}' })
        NEXT_PUBLIC_SENTRY_DSN={$dsn}
        SENTRY_DSN={$dsn}
        INI;
    }

    private static function wordpress(string $dsn): string
    {
        return <<<PHP
        // Install the WP Sentry Integration plugin, then in wp-config.php:
        define('WP_SENTRY_PHP_DSN', '{$dsn}');
        define('WP_SENTRY_BROWSER_DSN', '{$dsn}');
        define('WP_SENTRY_ENV', 'production');
        PHP;
    }
}
