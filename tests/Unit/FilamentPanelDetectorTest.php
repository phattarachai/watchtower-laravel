<?php

declare(strict_types=1);

use Phattarachai\WatchtowerLaravel\Support\FilamentPanelDetector;

beforeEach(function (): void {
    $this->base = sys_get_temp_dir().'/wt-filament-'.uniqid();
    mkdir($this->base.'/app/Providers/Filament', 0777, true);
});

afterEach(function (): void {
    deleteRecursive($this->base);
});

it('finds *PanelProvider files in app/Providers/Filament', function (): void {
    file_put_contents($this->base.'/app/Providers/Filament/AdminPanelProvider.php', "<?php\n");
    file_put_contents($this->base.'/app/Providers/Filament/StaffPanelProvider.php', "<?php\n");
    file_put_contents($this->base.'/app/Providers/Filament/Helper.php', "<?php\n");

    $detector = new FilamentPanelDetector($this->base);

    expect($detector->panels())->toContain('app/Providers/Filament/AdminPanelProvider.php')
        ->and($detector->panels())->toContain('app/Providers/Filament/StaffPanelProvider.php')
        ->and($detector->panels())->not->toContain('app/Providers/Filament/Helper.php');
});

it('returns empty when the panel dir is missing', function (): void {
    $base = sys_get_temp_dir().'/wt-filament-empty-'.uniqid();
    mkdir($base);

    $detector = new FilamentPanelDetector($base);

    expect($detector->panels())->toBe([]);

    rmdir($base);
});
