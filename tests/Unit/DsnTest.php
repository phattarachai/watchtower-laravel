<?php

declare(strict_types=1);

use Phattarachai\WatchtowerLaravel\Support\Dsn;

it('parses canonical DSN', function (): void {
    $parsed = Dsn::parse('http://abc123@watchtower.test/42');

    expect($parsed)->not->toBeNull()
        ->and($parsed['scheme'])->toBe('http')
        ->and($parsed['host'])->toBe('watchtower.test')
        ->and($parsed['host_with_port'])->toBe('watchtower.test')
        ->and($parsed['port'])->toBeNull()
        ->and($parsed['public_key'])->toBe('abc123')
        ->and($parsed['project_id'])->toBe(42)
        ->and($parsed['relay_url'])->toBe('http://watchtower.test/api/watchtower-relay');
});

it('returns null on empty DSN', function (): void {
    expect(Dsn::parse(''))->toBeNull();
    expect(Dsn::parse(null))->toBeNull();
});

it('returns null on missing public key', function (): void {
    expect(Dsn::parse('http://watchtower.test/42'))->toBeNull();
});

it('returns null on missing host', function (): void {
    expect(Dsn::parse('http://abc123@/42'))->toBeNull();
});

it('returns null on non-numeric project id', function (): void {
    expect(Dsn::parse('http://abc@watchtower.test/sentry'))->toBeNull();
    expect(Dsn::parse('http://abc@watchtower.test/42a'))->toBeNull();
});

it('returns null on empty project path', function (): void {
    expect(Dsn::parse('http://abc@watchtower.test'))->toBeNull();
});

it('preserves port in host_with_port and relay_url', function (): void {
    $parsed = Dsn::parse('http://abc@watchtower.test:8080/7');

    expect($parsed['host'])->toBe('watchtower.test')
        ->and($parsed['port'])->toBe(8080)
        ->and($parsed['host_with_port'])->toBe('watchtower.test:8080')
        ->and($parsed['relay_url'])->toBe('http://watchtower.test:8080/api/watchtower-relay');
});

it('handles https scheme', function (): void {
    $parsed = Dsn::parse('https://abc@watchtower.example.com/9');

    expect($parsed['scheme'])->toBe('https')
        ->and($parsed['relay_url'])->toBe('https://watchtower.example.com/api/watchtower-relay');
});

it('honors custom relay path', function (): void {
    $parsed = Dsn::parse('http://abc@watchtower.test/42', '/_/wt');

    expect($parsed['relay_url'])->toBe('http://watchtower.test/_/wt');
});
