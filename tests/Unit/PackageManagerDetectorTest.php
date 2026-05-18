<?php

declare(strict_types=1);

use Phattarachai\WatchtowerLaravel\Support\PackageManagerDetector;

beforeEach(function (): void {
    $this->base = sys_get_temp_dir().'/wt-pm-'.uniqid();
    mkdir($this->base, 0777, true);
});

afterEach(function (): void {
    foreach (glob($this->base.'/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($this->base);
});

it('returns null when no package.json exists', function (): void {
    $detector = new PackageManagerDetector($this->base);

    expect($detector->detect())->toBeNull();
});

it('detects bun via bun.lockb', function (): void {
    file_put_contents($this->base.'/package.json', '{}');
    file_put_contents($this->base.'/bun.lockb', '');

    $detector = new PackageManagerDetector($this->base);

    expect($detector->detect())->toBe('bun')
        ->and($detector->installCommand('@sentry/browser'))->toBe('bun add @sentry/browser');
});

it('detects bun via bun.lock (text format)', function (): void {
    file_put_contents($this->base.'/package.json', '{}');
    file_put_contents($this->base.'/bun.lock', '');

    $detector = new PackageManagerDetector($this->base);

    expect($detector->detect())->toBe('bun');
});

it('detects pnpm via pnpm-lock.yaml', function (): void {
    file_put_contents($this->base.'/package.json', '{}');
    file_put_contents($this->base.'/pnpm-lock.yaml', '');

    $detector = new PackageManagerDetector($this->base);

    expect($detector->detect())->toBe('pnpm')
        ->and($detector->installCommand('@sentry/browser'))->toBe('pnpm add @sentry/browser');
});

it('detects yarn via yarn.lock', function (): void {
    file_put_contents($this->base.'/package.json', '{}');
    file_put_contents($this->base.'/yarn.lock', '');

    $detector = new PackageManagerDetector($this->base);

    expect($detector->detect())->toBe('yarn')
        ->and($detector->installCommand('@sentry/browser'))->toBe('yarn add @sentry/browser');
});

it('detects npm via package-lock.json', function (): void {
    file_put_contents($this->base.'/package.json', '{}');
    file_put_contents($this->base.'/package-lock.json', '');

    $detector = new PackageManagerDetector($this->base);

    expect($detector->detect())->toBe('npm')
        ->and($detector->installCommand('@sentry/browser'))->toBe('npm install --save @sentry/browser');
});

it('falls back to npm when package.json exists without a lockfile', function (): void {
    file_put_contents($this->base.'/package.json', '{}');

    $detector = new PackageManagerDetector($this->base);

    expect($detector->detect())->toBe('npm');
});

it('prefers bun over pnpm/yarn/npm when multiple lockfiles coexist', function (): void {
    file_put_contents($this->base.'/package.json', '{}');
    file_put_contents($this->base.'/bun.lockb', '');
    file_put_contents($this->base.'/pnpm-lock.yaml', '');
    file_put_contents($this->base.'/yarn.lock', '');
    file_put_contents($this->base.'/package-lock.json', '');

    $detector = new PackageManagerDetector($this->base);

    expect($detector->detect())->toBe('bun');
});

it('prefers pnpm over yarn and npm when bun is absent', function (): void {
    file_put_contents($this->base.'/package.json', '{}');
    file_put_contents($this->base.'/pnpm-lock.yaml', '');
    file_put_contents($this->base.'/yarn.lock', '');
    file_put_contents($this->base.'/package-lock.json', '');

    $detector = new PackageManagerDetector($this->base);

    expect($detector->detect())->toBe('pnpm');
});

it('defaults installCommand to npm when no package.json exists', function (): void {
    $detector = new PackageManagerDetector($this->base);

    expect($detector->installCommand('@sentry/browser'))->toBe('npm install --save @sentry/browser');
});

it('finds a dependency declared under dependencies', function (): void {
    file_put_contents($this->base.'/package.json', json_encode([
        'dependencies' => ['@sentry/browser' => '^9.0.0'],
    ]));

    $detector = new PackageManagerDetector($this->base);

    expect($detector->hasDependency('@sentry/browser'))->toBeTrue();
});

it('finds a dependency declared under devDependencies', function (): void {
    file_put_contents($this->base.'/package.json', json_encode([
        'devDependencies' => ['@sentry/browser' => '^9.0.0'],
    ]));

    $detector = new PackageManagerDetector($this->base);

    expect($detector->hasDependency('@sentry/browser'))->toBeTrue();
});

it('returns false for a dependency not present in package.json', function (): void {
    file_put_contents($this->base.'/package.json', json_encode([
        'dependencies' => ['vue' => '^3.0.0'],
    ]));

    $detector = new PackageManagerDetector($this->base);

    expect($detector->hasDependency('@sentry/browser'))->toBeFalse();
});

it('returns false for hasDependency when package.json is missing', function (): void {
    $detector = new PackageManagerDetector($this->base);

    expect($detector->hasDependency('@sentry/browser'))->toBeFalse();
});

it('returns false when package.json is not valid JSON', function (): void {
    file_put_contents($this->base.'/package.json', '{not json');

    $detector = new PackageManagerDetector($this->base);

    expect($detector->hasDependency('@sentry/browser'))->toBeFalse();
});
