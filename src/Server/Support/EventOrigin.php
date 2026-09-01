<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Support;

class EventOrigin
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{kind: 'web'|'console'|'job', icon: string, primary: string, secondary: ?string}|null
     */
    public function resolve(array $payload): ?array
    {
        $jobName = data_get($payload, 'contexts.job.name');
        if ($jobName) {
            $jobQueue = data_get($payload, 'contexts.job.queue');

            return [
                'kind' => 'job',
                'icon' => 'queue-list',
                'primary' => (string) $jobName,
                'secondary' => $jobQueue ? "queue: {$jobQueue}" : null,
            ];
        }

        $sapi = data_get($payload, 'contexts.runtime.sapi');
        if ($sapi === 'cli') {
            $cmd = data_get($payload, 'contexts.command.name')
                ?? data_get($payload, 'extra.command');

            return [
                'kind' => 'console',
                'icon' => 'command-line',
                'primary' => $cmd ? "php artisan {$cmd}" : 'php artisan (CLI)',
                'secondary' => null,
            ];
        }

        $url = data_get($payload, 'request.url');
        $method = data_get($payload, 'request.method');
        $transaction = data_get($payload, 'transaction');
        if ($url || $transaction) {
            $path = $transaction ?: (parse_url((string) $url, PHP_URL_PATH) ?: $url);

            // Livewire funnels every component action through /livewire/update, so the
            // request URL and transaction only ever point at that endpoint. Fall back to
            // the Referer header, which carries the actual page the user was on.
            $referer = $this->livewireReferer($path, $payload);
            if ($referer !== null) {
                $url = $referer;
                $path = parse_url($referer, PHP_URL_PATH) ?: $referer;
            }

            return [
                'kind' => 'web',
                'icon' => 'globe-alt',
                'primary' => trim(($method ? strtoupper($method).' ' : '').$path),
                'secondary' => $url ?: null,
            ];
        }

        return null;
    }

    /**
     * Resolve the Referer for a Livewire update request, or null when the request
     * is not a Livewire update or no Referer header is present.
     *
     * @param  array<string, mixed>  $payload
     */
    private function livewireReferer(?string $path, array $payload): ?string
    {
        if ($path === null || ! preg_match('#/livewire(-[^/]+)?/update$#', $path)) {
            return null;
        }

        $headers = data_get($payload, 'request.headers');
        if (! is_array($headers)) {
            return null;
        }

        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'referer') !== 0) {
                continue;
            }

            $referer = is_array($value) ? ($value[0] ?? null) : $value;

            return $referer !== null && $referer !== '' ? (string) $referer : null;
        }

        return null;
    }
}
