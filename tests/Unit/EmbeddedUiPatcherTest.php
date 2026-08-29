<?php

declare(strict_types=1);

use Phattarachai\WatchtowerLaravel\Support\EmbeddedUiPatcher;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/wt-patcher-'.bin2hex(random_bytes(4));
    mkdir($this->dir.'/resources/css', 0777, true);
    $this->vite = $this->dir.'/vite.config.js';
    $this->css = $this->dir.'/resources/css/watchtower.css';
});

afterEach(function (): void {
    @unlink($this->vite);
    @unlink($this->css);
    @rmdir($this->dir.'/resources/css');
    @rmdir($this->dir.'/resources');
    @rmdir($this->dir);
});

it('injects the alias into an existing resolve.alias block', function (): void {
    file_put_contents($this->vite, <<<'JS'
        export default defineConfig({
            resolve: {
                alias: {
                    '@': '/resources/js',
                },
            },
        });
        JS);

    expect(EmbeddedUiPatcher::patchViteAlias($this->vite))->toBeTrue();

    $contents = (string) file_get_contents($this->vite);

    expect($contents)->toContain("'@watchtower'")
        ->toContain("'@': '/resources/js'")
        ->and(substr_count($contents, 'alias: {'))->toBe(1);
});

it('appends a resolve block when the config has none', function (): void {
    file_put_contents($this->vite, "export default defineConfig({\n    plugins: [],\n});\n");

    expect(EmbeddedUiPatcher::patchViteAlias($this->vite))->toBeTrue();

    expect((string) file_get_contents($this->vite))
        ->toContain('resolve: {')
        ->toContain("'@watchtower'");
});

it('leaves a config that already declares the alias untouched', function (): void {
    $original = "export default { resolve: { alias: { '@watchtower': './x' } } };\n";
    file_put_contents($this->vite, $original);

    expect(EmbeddedUiPatcher::patchViteAlias($this->vite))->toBeTrue()
        ->and(file_get_contents($this->vite))->toBe($original);
});

it('writes the standalone stylesheet with all three lines', function (): void {
    expect(EmbeddedUiPatcher::writeCssFile($this->dir))->toBeTrue();

    $contents = (string) file_get_contents(EmbeddedUiPatcher::cssFilePath($this->dir));

    expect(substr_count($contents, 'prefix(tw)'))->toBe(1)
        ->and(substr_count($contents, 'source(none)'))->toBe(1)
        ->and(substr_count($contents, '@source'))->toBe(1)
        ->and(substr_count($contents, '@custom-variant dark'))->toBe(1);
});

it('is a no-op once the stylesheet carries both markers', function (): void {
    EmbeddedUiPatcher::writeCssFile($this->dir);
    $path = EmbeddedUiPatcher::cssFilePath($this->dir);
    $original = (string) file_get_contents($path);

    expect(EmbeddedUiPatcher::writeCssFile($this->dir))->toBeTrue()
        ->and(file_get_contents($path))->toBe($original);
});

it('injects the node:path import exactly once when patching the alias', function (): void {
    file_put_contents($this->vite, "export default defineConfig({\n    plugins: [],\n});\n");

    EmbeddedUiPatcher::patchViteAlias($this->vite);

    $contents = (string) file_get_contents($this->vite);

    expect(substr_count($contents, "import path from 'node:path';"))->toBe(1)
        ->and($contents)->toContain("path.resolve(__dirname, '".EmbeddedUiPatcher::MODULE_PATH."')");
});

it('finds vite configs under a project root', function (): void {
    file_put_contents($this->vite, '');

    expect(EmbeddedUiPatcher::viteConfigPaths($this->dir))->toBe([$this->vite])
        ->and(EmbeddedUiPatcher::cssFilePath($this->dir))->toBe($this->css)
        ->and(is_file(EmbeddedUiPatcher::cssFilePath($this->dir)))->toBeFalse();
});
