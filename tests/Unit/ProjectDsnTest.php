<?php

declare(strict_types=1);

use Phattarachai\WatchtowerCore\Support\DsnParser;
use Phattarachai\WatchtowerLaravel\Server\Models\Project;
use Sentry\Dsn;

function embeddedProject(): Project
{
    return new Project(['id' => 42, 'public_key' => str_repeat('a', 32)]);
}

it('builds a DSN whose Sentry envelope endpoint matches the embedded route', function (): void {
    config()->set('watchtower.server.path', 'watchtower');

    $dsn = embeddedProject()->buildDsn('https://app.test');

    expect($dsn)->toBe('https://'.str_repeat('a', 32).'@app.test/watchtower/42')
        ->and(Dsn::createFromString($dsn)->getEnvelopeApiEndpointUrl())
        ->toBe('https://app.test/watchtower/api/42/envelope/');
});

it('keeps the port when the app URL carries one', function (): void {
    config()->set('watchtower.server.path', 'watchtower');

    $dsn = embeddedProject()->buildDsn('http://app.test:8080');

    expect(Dsn::createFromString($dsn)->getEnvelopeApiEndpointUrl())
        ->toBe('http://app.test:8080/watchtower/api/42/envelope/');
});

it('parses with both the core parser and the Sentry SDK when no prefix is set', function (): void {
    config()->set('watchtower.server.path', '');

    $dsn = embeddedProject()->buildDsn('https://app.test');

    $parsed = DsnParser::parse($dsn);

    expect($parsed)->not->toBeNull()
        ->and($parsed['project_id'])->toBe(42)
        ->and($parsed['public_key'])->toBe(str_repeat('a', 32))
        ->and(Dsn::createFromString($dsn)->getEnvelopeApiEndpointUrl())
        ->toBe('https://app.test/api/42/envelope/');
});

it('needs the prefix stripped before the core parser accepts a prefixed DSN', function (): void {
    config()->set('watchtower.server.path', 'watchtower');

    $dsn = embeddedProject()->buildDsn('https://app.test');

    expect(DsnParser::parse($dsn))->toBeNull()
        ->and(DsnParser::parse(str_replace('/watchtower/42', '/42', $dsn)))->not->toBeNull();
});
