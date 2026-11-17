<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Support;

use Illuminate\Http\Request;
use Phattarachai\WatchtowerLaravel\Watchtower;

final class ReplayConfig
{
    public const string HEADER = 'X-Watchtower-Replay-Id';

    public const string TAG = 'replayId';

    public static function sessionSampleRate(): float
    {
        return self::rate('watchtower.replay.session_sample_rate');
    }

    public static function onErrorSampleRate(): float
    {
        return self::rate('watchtower.replay.on_error_sample_rate');
    }

    public static function configured(): bool
    {
        return self::sessionSampleRate() > 0 || self::onErrorSampleRate() > 0;
    }

    public static function enabled(): bool
    {
        return self::configured() && config('watchtower.mode', 'relay') === 'relay';
    }

    /**
     * @return array{sessionSampleRate: float, onErrorSampleRate: float}|null
     */
    public static function forRequest(Request $request): ?array
    {
        if (! self::enabled() || self::isPackageRoute($request) || ! Watchtower::recordsReplay($request)) {
            return null;
        }

        return [
            'sessionSampleRate' => self::sessionSampleRate(),
            'onErrorSampleRate' => self::onErrorSampleRate(),
        ];
    }

    public static function replayIdFrom(Request $request): ?string
    {
        $value = strtolower(trim((string) $request->headers->get(self::HEADER, '')));

        return preg_match('/^[0-9a-f]{32}$/', $value) === 1 ? $value : null;
    }

    public static function publishedHelperLacksReplay(): bool
    {
        $helper = resource_path('js/vendor/watchtower.js');

        return is_file($helper) && ! str_contains((string) file_get_contents($helper), 'replay-integration');
    }

    private static function isPackageRoute(Request $request): bool
    {
        return $request->routeIs('watchtower.*');
    }

    private static function rate(string $key): float
    {
        return max(0.0, min(1.0, (float) config($key, 0)));
    }
}
