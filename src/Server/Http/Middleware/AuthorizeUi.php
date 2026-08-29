<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Phattarachai\WatchtowerLaravel\Watchtower;
use Symfony\Component\HttpFoundation\Response;

/**
 * Appended to the UI middleware stack by the service provider, so it cannot be
 * dropped by editing `watchtower.server.ui.middleware`.
 */
final class AuthorizeUi
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Watchtower::check($request)) {
            return $next($request);
        }

        if ($request->user() === null && ! $request->expectsJson()) {
            $login = $this->loginUrl();

            if ($login !== null) {
                return redirect()->guest($login);
            }
        }

        abort(403);
    }

    private function loginUrl(): ?string
    {
        $target = config('watchtower.server.ui.redirect_guests_to');

        if (! is_string($target) || $target === '') {
            return null;
        }

        if (Route::has($target)) {
            return route($target);
        }

        return str_contains($target, '/') ? url($target) : null;
    }
}
