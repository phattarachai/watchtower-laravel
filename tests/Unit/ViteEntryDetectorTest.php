<?php

declare(strict_types=1);

use Phattarachai\WatchtowerLaravel\Support\ViteEntryDetector;

beforeEach(function (): void {
    $this->base = sys_get_temp_dir().'/wt-vite-'.uniqid();
    mkdir($this->base, 0777, true);
});

afterEach(function (): void {
    foreach (glob($this->base.'/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($this->base);
});

it('returns no entries when no vite config is present', function (): void {
    $detector = new ViteEntryDetector($this->base);

    expect($detector->configPath())->toBeNull()
        ->and($detector->jsEntries())->toBe([]);
});

it('extracts entries from a laravel({ input: [...] }) array', function (): void {
    file_put_contents($this->base.'/vite.config.js', <<<'JS'
        import { defineConfig } from 'vite';
        import laravel from 'laravel-vite-plugin';

        export default defineConfig({
            plugins: [
                laravel({
                    input: ['resources/css/app.css', 'resources/js/app.js'],
                    refresh: true,
                }),
            ],
        });
        JS);

    $detector = new ViteEntryDetector($this->base);

    expect($detector->jsEntries())->toBe(['resources/js/app.js']);
});

it('extracts a single string input', function (): void {
    file_put_contents($this->base.'/vite.config.js', <<<'JS'
        export default {
            plugins: [
                laravel({ input: 'resources/js/admin.ts' }),
            ],
        };
        JS);

    $detector = new ViteEntryDetector($this->base);

    expect($detector->jsEntries())->toBe(['resources/js/admin.ts']);
});

it('dedupes entries that appear in multiple laravel() calls', function (): void {
    file_put_contents($this->base.'/vite.config.js', <<<'JS'
        export default {
            plugins: [
                laravel({ input: ['resources/js/app.js'] }),
                laravel({ input: ['resources/js/app.js', 'resources/js/filament/app.js'] }),
            ],
        };
        JS);

    $detector = new ViteEntryDetector($this->base);

    expect($detector->jsEntries())->toBe(['resources/js/app.js', 'resources/js/filament/app.js']);
});

it('prefers vite.config.ts when both exist', function (): void {
    file_put_contents($this->base.'/vite.config.js', 'export default {};');
    file_put_contents($this->base.'/vite.config.ts', "laravel({ input: ['resources/js/app.ts'] });");

    $detector = new ViteEntryDetector($this->base);

    expect($detector->configPath())->toEndWith('vite.config.js');
});

it('returns an empty list when the laravel() input cannot be parsed', function (): void {
    file_put_contents($this->base.'/vite.config.js', 'export default {};');

    $detector = new ViteEntryDetector($this->base);

    expect($detector->jsEntries())->toBe([]);
});
