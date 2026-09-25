<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Registration surface for the host application:
 *
 *   Watchtower::auth(fn ($request) => $request->user()?->isAdmin());
 */
final class Watchtower
{
    public const string VERSION = '1.1.0';

    /** @var (Closure(Request): bool)|null */
    private static ?Closure $authUsing = null;

    /**
     * @param  Closure(Request): bool  $callback
     */
    public static function auth(Closure $callback): void
    {
        self::$authUsing = $callback;
    }

    public static function check(Request $request): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        if (self::$authUsing instanceof Closure) {
            return (bool) call_user_func(self::$authUsing, $request);
        }

        return Gate::has('viewWatchtower') && Gate::allows('viewWatchtower');
    }

    /** Reset the registered callback. Test seam. */
    public static function flushAuth(): void
    {
        self::$authUsing = null;
    }
}
