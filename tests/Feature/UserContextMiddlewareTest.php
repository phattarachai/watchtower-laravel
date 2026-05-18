<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Phattarachai\WatchtowerLaravel\Http\Middleware\WatchtowerUserContext;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\Scope;

class FakeGuard implements Guard
{
    private ?Authenticatable $user = null;

    public function check(): bool
    {
        return $this->user !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function user(): ?Authenticatable
    {
        return $this->user;
    }

    public function id(): mixed
    {
        return $this->user?->getAuthIdentifier();
    }

    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function setUser(Authenticatable $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function hasUser(): bool
    {
        return $this->user !== null;
    }
}

class FakeAuthUser implements Authenticatable
{
    public function __construct(
        public int $id = 1,
        public ?string $email = null,
        public ?string $name = null,
    ) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): int
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }
}

/**
 * Stand-in for an Eloquent user — exposes getAttributes() + getHidden() the way
 * Illuminate\Database\Eloquent\Concerns\HasAttributes does, so the auto-discover
 * branch can be exercised without booting Eloquent.
 */
class EloquentLikeUser implements Authenticatable
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>    $hidden
     */
    public function __construct(
        private array $attributes = [],
        private array $hidden = [],
    ) {}

    /** @return array<string, mixed> */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /** @return array<int, string> */
    public function getHidden(): array
    {
        return $this->hidden;
    }

    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->attributes['id'] ?? null;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return (string) ($this->attributes['password'] ?? '');
    }

    public function getRememberToken(): string
    {
        return (string) ($this->attributes['remember_token'] ?? '');
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}

beforeEach(function (): void {
    SentrySdk::setCurrentHub(new Hub);

    Auth::extend('fake', fn () => new FakeGuard);

    config(['auth.guards' => [
        'web'   => ['driver' => 'fake'],
        'admin' => ['driver' => 'fake'],
    ]]);

    // Default to the explicit-fields path for tests below. Auto-discover ([])
    // is exercised in its own dedicated test that uses EloquentLikeUser.
    config(['watchtower.user_context.fields' => ['id', 'email']]);

    $this->loginAs = function (string $guard, Authenticatable $user): void {
        auth()->guard($guard)->setUser($user);
    };

    $this->run = function (?Request $request = null): void {
        $request ??= Request::create('/test', 'GET');
        $middleware = new WatchtowerUserContext;
        $middleware->handle($request, fn () => response('ok'));
    };

    $this->capturedUser = function (): ?array {
        $captured = null;
        \Sentry\configureScope(function (Scope $scope) use (&$captured): void {
            $user = $scope->getUser();
            $captured = $user === null ? null : [
                'id'         => $user->getId(),
                'email'      => $user->getEmail(),
                'username'   => $user->getUsername(),
                'ip_address' => $user->getIpAddress(),
            ];
        });

        return $captured;
    };

    $this->capturedTag = function (string $name): ?string {
        $captured = null;
        \Sentry\configureScope(function (Scope $scope) use (&$captured, $name): void {
            $event = Event::createEvent();
            $scope->applyToEvent($event);
            $captured = $event->getTags()[$name] ?? null;
        });

        return $captured;
    };
});

it('attaches the authed user to the Sentry scope', function (): void {
    ($this->loginAs)('web', new FakeAuthUser(id: 42, email: 'a@b.com'));

    ($this->run)();

    expect(($this->capturedUser)())->toMatchArray([
        'id'    => '42',
        'email' => 'a@b.com',
    ]);
});

it('leaves the scope untouched when no guard is authenticated', function (): void {
    ($this->run)();

    expect(($this->capturedUser)())->toBeNull();
});

it('walks guards in configured order; first authed wins', function (): void {
    config(['watchtower.user_context.guards' => 'admin,web']);
    ($this->loginAs)('web', new FakeAuthUser(id: 1, email: 'web@x.com'));
    ($this->loginAs)('admin', new FakeAuthUser(id: 99, email: 'admin@x.com'));

    ($this->run)();

    expect(($this->capturedUser)())->toMatchArray([
        'id'    => '99',
        'email' => 'admin@x.com',
    ]);
    expect(($this->capturedTag)('auth.guard'))->toBe('admin');
});

it('records the winning guard as a Sentry tag', function (): void {
    ($this->loginAs)('web', new FakeAuthUser(id: 1, email: 'w@x.com'));

    ($this->run)();

    expect(($this->capturedTag)('auth.guard'))->toBe('web');
});

it('honors the fields config to limit attached attributes', function (): void {
    config(['watchtower.user_context.fields' => ['id']]);
    ($this->loginAs)('web', new FakeAuthUser(id: 7, email: 'a@b.com'));

    ($this->run)();

    $user = ($this->capturedUser)();
    expect($user['id'])->toBe('7');
    expect($user['email'])->toBeNull();
});

it('attaches ip_address from the request when configured', function (): void {
    config(['watchtower.user_context.fields' => ['id', 'ip_address']]);
    ($this->loginAs)('web', new FakeAuthUser(id: 1));

    $request = Request::create('/test', 'GET', server: ['REMOTE_ADDR' => '203.0.113.7']);
    ($this->run)($request);

    expect(($this->capturedUser)())->toMatchArray([
        'id'         => '1',
        'ip_address' => '203.0.113.7',
    ]);
});

it('is a no-op when disabled in config', function (): void {
    config(['watchtower.user_context.enabled' => false]);
    ($this->loginAs)('web', new FakeAuthUser(id: 1, email: 'a@b.com'));

    ($this->run)();

    expect(($this->capturedUser)())->toBeNull();
});

it('auto-discovers all model attributes when fields is empty, excluding $hidden + deny-list', function (): void {
    config(['watchtower.user_context.fields' => []]);

    $user = new EloquentLikeUser([
        'id'                        => 12,
        'email'                     => 'a@b.com',
        'name'                      => 'Alice',
        'google_id'                 => 'g-1',
        'is_super_admin'            => true,
        'created_at'                => '2026-01-01 00:00:00',
        'password'                  => 'should-never-leak',
        'remember_token'            => 'should-never-leak',
        'two_factor_secret'         => 'should-never-leak',
        'two_factor_recovery_codes' => 'should-never-leak',
        'api_token'                 => 'should-never-leak',
        'password_hash'             => 'should-never-leak',
        'phone'                     => '555-0100',     // app-defined; sits in $hidden below
    ], hidden: ['password', 'remember_token', 'phone']);

    ($this->loginAs)('web', $user);
    ($this->run)();

    $event = Event::createEvent();
    \Sentry\configureScope(fn (Scope $s) => $s->applyToEvent($event));
    $userBag = $event->getUser();

    expect($userBag->getId())->toBe('12');
    expect($userBag->getEmail())->toBe('a@b.com');
    expect($userBag->getUsername())->toBe('Alice');

    $metadata = $userBag->getMetadata();
    expect($metadata)->toHaveKey('google_id', 'g-1');
    expect($metadata)->toHaveKey('is_super_admin', '1');
    expect($metadata)->toHaveKey('created_at', '2026-01-01 00:00:00');

    foreach (['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'api_token', 'password_hash', 'phone'] as $secret) {
        expect($metadata)->not->toHaveKey($secret);
    }
});

it('swallows auth driver exceptions so the request still completes', function (): void {
    // Point at a guard that does not exist; auth()->guard('missing') throws.
    config(['watchtower.user_context.guards' => 'missing']);

    expect(fn () => ($this->run)())->not->toThrow(\Throwable::class);
    expect(($this->capturedUser)())->toBeNull();
});
