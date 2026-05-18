<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Phattarachai\WatchtowerLaravel\Support\Dsn;
use Phattarachai\WatchtowerLaravel\Support\FrontendPatcher;
use Phattarachai\WatchtowerLaravel\Support\LayoutDetector;
use Phattarachai\WatchtowerLaravel\Support\ViteEntryDetector;

class TestCommand extends Command
{
    protected $signature = 'watchtower:test';

    protected $description = 'Probe Watchtower wiring: print resolved config, run sentry:test, POST a synthetic envelope through the relay.';

    public function handle(): int
    {
        $dsn       = (string) (config('watchtower.dsn') ?? '');
        $relayPath = (string) config('watchtower.relay.path', '/api/watchtower-relay');
        $async     = (bool) config('watchtower.relay.async', false);
        $parsed    = Dsn::parse($dsn, $relayPath);

        if ($parsed === null) {
            $this->error('WATCHTOWER_DSN is missing or invalid.');

            return self::FAILURE;
        }

        $this->line('DSN host:   '.$parsed['host_with_port']);
        $this->line('Project ID: '.$parsed['project_id']);
        $this->line('Relay path: '.$relayPath);
        $this->line('Async:      '.($async ? 'true' : 'false'));

        $sentryOk   = $this->runSentryProbe();
        $relayOk    = $this->runRelayProbe($dsn, $relayPath);
        $frontendOk = $this->verifyFrontendWiring();

        return $sentryOk && $relayOk && $frontendOk ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Surface common "install said it succeeded but the snippets never got
     * pasted" failure modes. Returns true when there are no Vite frontend
     * entries to verify against (backend-only apps) so the test command
     * remains useful in that environment.
     */
    private function verifyFrontendWiring(): bool
    {
        $vite = new ViteEntryDetector(base_path());

        if ($vite->configPath() === null) {
            return true;
        }

        $this->line('');
        $this->line('Verifying frontend wiring...');

        $entries        = $vite->jsEntries();
        $layouts        = (new LayoutDetector(base_path()))->layouts();
        $missingEntries = $this->entriesMissingMarker($entries, FrontendPatcher::MARKER_JS_OPEN);
        $missingLayouts = $this->entriesMissingMarker($layouts, FrontendPatcher::MARKER_BLADE_OPEN);
        $envBugLine     = $this->literalAppEnvLine();

        $problems = [];

        if ($missingEntries !== []) {
            $problems[] = 'Vite entries missing watchtower:sentry-init marker: '.implode(', ', $missingEntries);
        }

        if ($entries !== [] && count($missingEntries) === count($entries)) {
            $problems[] = 'No detected Vite entry has been patched yet — run `php artisan watchtower:install --patch-js`.';
        }

        if ($missingLayouts !== []) {
            $problems[] = 'Blade layouts missing watchtower:user-meta marker: '.implode(', ', $missingLayouts);
        }

        if ($layouts !== [] && count($missingLayouts) === count($layouts)) {
            $problems[] = 'No detected Blade layout has been patched yet — run `php artisan watchtower:install --patch-views`.';
        }

        if ($envBugLine !== null) {
            $problems[] = "VITE_SENTRY_ENVIRONMENT looks unexpanded in .env ({$envBugLine}); remove the backslash so dotenv-expand resolves it.";
        }

        if ($problems === []) {
            $this->info('Frontend wiring looks good.');

            return true;
        }

        foreach ($problems as $problem) {
            $this->warn('  ✗ '.$problem);
        }

        return false;
    }

    /**
     * @param  list<string>  $relativePaths
     * @return list<string>
     */
    private function entriesMissingMarker(array $relativePaths, string $marker): array
    {
        $missing = [];

        foreach ($relativePaths as $relative) {
            $absolute = base_path($relative);

            if (! is_file($absolute)) {
                $missing[] = $relative;

                continue;
            }

            $contents = (string) file_get_contents($absolute);

            if (! str_contains($contents, $marker)) {
                $missing[] = $relative;
            }
        }

        return $missing;
    }

    private function literalAppEnvLine(): ?string
    {
        $envPath = base_path('.env');

        if (! is_file($envPath)) {
            return null;
        }

        $contents = (string) file_get_contents($envPath);

        if (! preg_match('/^(VITE_SENTRY_ENVIRONMENT\s*=.*)$/m', $contents, $match)) {
            return null;
        }

        // The bug shape: `\${APP_ENV}` with a literal backslash that escapes
        // dotenv-expand. The fixed value is `${APP_ENV}` with no backslash.
        return str_contains($match[1], '\\${APP_ENV}') ? trim($match[1]) : null;
    }

    private function runSentryProbe(): bool
    {
        $this->line('');
        $this->line('Running sentry:test...');

        try {
            $exit = $this->call('sentry:test');

            return $exit === 0;
        } catch (\Throwable $e) {
            $this->error('sentry:test failed: '.$e->getMessage());

            return false;
        }
    }

    private function runRelayProbe(string $dsn, string $relayPath): bool
    {
        $this->line('');
        $this->line('Posting synthetic envelope to relay...');

        $eventId   = (string) Str::replace('-', '', (string) Str::uuid());
        $sentAt    = gmdate('Y-m-d\TH:i:s\Z');
        $timestamp = time();

        $envelopeHeader = json_encode([
            'event_id' => $eventId,
            'sent_at'  => $sentAt,
            'dsn'      => $dsn,
            'sdk'      => ['name' => 'watchtower.cli', 'version' => '0.1.0'],
        ], JSON_THROW_ON_ERROR);

        $event = json_encode([
            'event_id'  => $eventId,
            'message'   => 'watchtower:test relay probe',
            'platform'  => 'php',
            'level'     => 'info',
            'timestamp' => $timestamp,
        ], JSON_THROW_ON_ERROR);

        $itemHeader = json_encode([
            'type'         => 'event',
            'length'       => strlen($event),
            'content_type' => 'application/json',
        ], JSON_THROW_ON_ERROR);

        $body = $envelopeHeader."\n".$itemHeader."\n".$event;

        try {
            $url = url($relayPath);

            $response = Http::withHeaders(['Content-Type' => 'application/x-sentry-envelope'])
                ->withBody($body, 'application/x-sentry-envelope')
                ->post($url);

            $this->line('Status: '.$response->status());
            $this->line('Body:   '.$response->body());

            return $response->successful() || $response->status() === 202;
        } catch (\Throwable $e) {
            $this->error('Relay probe failed: '.$e->getMessage());

            return false;
        }
    }
}
