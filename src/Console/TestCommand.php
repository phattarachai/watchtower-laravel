<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Phattarachai\WatchtowerLaravel\Support\Dsn;

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

        $sentryOk = $this->runSentryProbe();
        $relayOk  = $this->runRelayProbe($dsn, $relayPath);

        return $sentryOk && $relayOk ? self::SUCCESS : self::FAILURE;
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
