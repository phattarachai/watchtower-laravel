<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Sentry\State\Scope;
use Throwable;

class WatchtowerUserContext
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (config('watchtower.user_context.enabled') === false) {
            return $next($request);
        }

        try {
            $this->attachUser($request);
        } catch (Throwable) {
            // A broken auth driver must never break the request. Swallow.
        }

        return $next($request);
    }

    private function attachUser(Request $request): void
    {
        [$user, $guard] = $this->resolveAuthenticatedUser();

        if ($user === null) {
            return;
        }

        $payload = $this->buildPayload($user, $request);

        if ($payload === []) {
            return;
        }

        \Sentry\configureScope(function (Scope $scope) use ($payload, $guard): void {
            $scope->setUser($payload);

            if ($guard !== null) {
                $scope->setTag('auth.guard', $guard);
            }
        });
    }

    /**
     * @return array{0: ?Authenticatable, 1: ?string}
     */
    private function resolveAuthenticatedUser(): array
    {
        foreach ($this->resolveGuards() as $name) {
            $guard = auth()->guard($name);

            if ($guard->check()) {
                return [$guard->user(), $name];
            }
        }

        return [null, null];
    }

    /**
     * @return array<int, string>
     */
    private function resolveGuards(): array
    {
        $configured = config('watchtower.user_context.guards', 'auto');

        if (is_array($configured)) {
            return array_values(array_filter(array_map('strval', $configured)));
        }

        if ($configured === 'auto' || $configured === null || $configured === '') {
            return array_keys((array) config('auth.guards', []));
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $configured))));
    }

    /**
     * Always-excluded keys, on top of whatever the Eloquent model already lists
     * in $hidden. Belt-and-braces for secrets that absolutely must not be
     * shipped to Sentry, even if an app forgets to hide them.
     */
    private const array DENY = [
        'password',
        'password_hash',
        'remember_token',
        'api_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(Authenticatable $user, Request $request): array
    {
        $fields = (array) config('watchtower.user_context.fields', ['id', 'email']);

        return $fields === []
            ? $this->buildAutoPayload($user, $request)
            : $this->buildExplicitPayload($user, $request, $fields);
    }

    /**
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    private function buildExplicitPayload(Authenticatable $user, Request $request, array $fields): array
    {
        $payload = [];

        foreach ($fields as $field) {
            $value = match ($field) {
                'id'         => $user->getAuthIdentifier(),
                'email'      => $user->email ?? null,
                'name'       => $user->name ?? null,
                'ip_address' => $request->ip(),
                default      => null,
            };

            if ($value === null || $value === '') {
                continue;
            }

            // Sentry's UserDataBag uses 'username' for display names.
            $key = $field === 'name' ? 'username' : $field;

            $payload[$key] = is_scalar($value) ? (string) $value : $value;
        }

        return $payload;
    }

    /**
     * Auto-discover every attribute on the Eloquent model, minus the model's
     * own $hidden list and our package-level deny-list. Sentry maps `id` /
     * `email` / `username` natively; anything else lands in user.metadata.
     *
     * @return array<string, mixed>
     */
    private function buildAutoPayload(Authenticatable $user, Request $request): array
    {
        $attributes = method_exists($user, 'getAttributes')
            ? $user->getAttributes()
            : [];

        $hidden = method_exists($user, 'getHidden')
            ? array_map('strval', $user->getHidden())
            : [];

        $excluded = array_unique(array_merge($hidden, self::DENY));
        $payload  = [];

        foreach ($attributes as $key => $value) {
            if (in_array($key, $excluded, true)) {
                continue;
            }

            if ($value === null || $value === '' || ! is_scalar($value)) {
                continue;
            }

            // Map Laravel's conventional 'name' column to Sentry's 'username'.
            $sentryKey = $key === 'name' ? 'username' : $key;

            $payload[$sentryKey] = (string) $value;
        }

        // Always prefer the auth identifier for 'id' — survives renamed PKs.
        $payload['id'] = (string) $user->getAuthIdentifier();
        $payload['ip_address'] = $request->ip();

        return $payload;
    }
}
