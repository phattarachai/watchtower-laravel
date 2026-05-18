<?php

declare(strict_types=1);

use Phattarachai\WatchtowerLaravel\Support\LayoutDetector;

beforeEach(function (): void {
    $this->base = sys_get_temp_dir().'/wt-layout-'.uniqid();
    mkdir($this->base.'/resources/views/components/layouts', 0777, true);
    mkdir($this->base.'/resources/views/layouts', 0777, true);
});

afterEach(function (): void {
    deleteRecursive($this->base);
});

it('finds layouts with <head> in both conventional locations', function (): void {
    file_put_contents(
        $this->base.'/resources/views/components/layouts/app.blade.php',
        '<html><head><title>x</title></head><body>{{ $slot }}</body></html>'
    );

    file_put_contents(
        $this->base.'/resources/views/layouts/guest.blade.php',
        '<html><head></head><body>@yield(\'content\')</body></html>'
    );

    $detector = new LayoutDetector($this->base);

    expect($detector->layouts())->toContain('resources/views/components/layouts/app.blade.php')
        ->and($detector->layouts())->toContain('resources/views/layouts/guest.blade.php');
});

it('skips layouts that have no <head>', function (): void {
    file_put_contents(
        $this->base.'/resources/views/components/layouts/headless.blade.php',
        '<div>{{ $slot }}</div>'
    );

    $detector = new LayoutDetector($this->base);

    expect($detector->layouts())->toBe([]);
});

it('returns an empty list when no layout dirs exist', function (): void {
    $base = sys_get_temp_dir().'/wt-layout-empty-'.uniqid();
    mkdir($base);

    $detector = new LayoutDetector($base);

    expect($detector->layouts())->toBe([]);

    rmdir($base);
});

function deleteRecursive(string $path): void
{
    if (! is_dir($path)) {
        @unlink($path);

        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        deleteRecursive($path.DIRECTORY_SEPARATOR.$entry);
    }

    @rmdir($path);
}
