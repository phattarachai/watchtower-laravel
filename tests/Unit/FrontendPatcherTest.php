<?php

declare(strict_types=1);

use Phattarachai\WatchtowerLaravel\Support\FrontendPatcher;

beforeEach(function (): void {
    $this->jsPath    = tempnam(sys_get_temp_dir(), 'wt-js-');
    $this->bladePath = tempnam(sys_get_temp_dir(), 'wt-blade-');
});

afterEach(function (): void {
    @unlink($this->jsPath);
    @unlink($this->bladePath);
});

it('prepends the Sentry init block above existing JS contents', function (): void {
    file_put_contents($this->jsPath, "import './bootstrap';\n\nconsole.log('app');\n");

    $patched = FrontendPatcher::patchJsEntry($this->jsPath);

    $contents = (string) file_get_contents($this->jsPath);

    expect($patched)->toBeTrue()
        ->and($contents)->toContain(FrontendPatcher::MARKER_JS_OPEN)
        ->and($contents)->toContain(FrontendPatcher::MARKER_JS_CLOSE)
        ->and($contents)->toContain('applyWatchtowerUser();')
        ->and($contents)->toEndWith("console.log('app');\n");
});

it('is idempotent — second JS patch is a no-op', function (): void {
    file_put_contents($this->jsPath, "console.log('app');\n");

    FrontendPatcher::patchJsEntry($this->jsPath);
    $afterFirst = (string) file_get_contents($this->jsPath);

    $secondReturn = FrontendPatcher::patchJsEntry($this->jsPath);

    expect($secondReturn)->toBeFalse()
        ->and(file_get_contents($this->jsPath))->toBe($afterFirst)
        ->and(substr_count($afterFirst, FrontendPatcher::MARKER_JS_OPEN))->toBe(1);
});

it('inserts the meta block immediately before </head>', function (): void {
    $layout = <<<'BLADE'
        <html>
        <head>
            <meta charset="utf-8">
        </head>
        <body>@yield('content')</body>
        </html>
        BLADE;

    file_put_contents($this->bladePath, $layout);

    expect(FrontendPatcher::patchBladeLayout($this->bladePath))->toBeTrue();

    $contents = (string) file_get_contents($this->bladePath);

    expect($contents)->toContain(FrontendPatcher::MARKER_BLADE_OPEN)
        ->and($contents)->toContain('<meta name="watchtower-user-id"')
        ->and($contents)->toContain(FrontendPatcher::MARKER_BLADE_CLOSE);

    $headEnd  = strpos($contents, '</head>');
    $blockEnd = strpos($contents, FrontendPatcher::MARKER_BLADE_CLOSE);

    expect($blockEnd)->toBeLessThan($headEnd);
});

it('is idempotent — second Blade patch is a no-op', function (): void {
    file_put_contents($this->bladePath, "<html><head></head><body></body></html>\n");

    FrontendPatcher::patchBladeLayout($this->bladePath);
    $afterFirst = (string) file_get_contents($this->bladePath);

    $secondReturn = FrontendPatcher::patchBladeLayout($this->bladePath);

    expect($secondReturn)->toBeFalse()
        ->and(file_get_contents($this->bladePath))->toBe($afterFirst);
});

it('fails Blade patching when there is no </head>', function (): void {
    file_put_contents($this->bladePath, '<div>headless</div>');

    expect(FrontendPatcher::patchBladeLayout($this->bladePath))->toBeFalse();
});
