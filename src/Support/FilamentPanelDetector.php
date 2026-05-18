<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Support;

/**
 * Best-effort scan for Filament panel providers. Filament admin panels render
 * outside the standard Blade layouts, so the `watchtower-user-*` meta tags
 * have to be injected via a `renderHook('panels::head.end', …)` registration
 * in each panel's provider.
 */
final class FilamentPanelDetector
{
    public function __construct(private readonly string $basePath) {}

    /**
     * Project-relative paths of panel providers (`app/Providers/Filament/*PanelProvider.php`).
     *
     * @return list<string>
     */
    public function panels(): array
    {
        $dir = $this->basePath.'/app/Providers/Filament';

        if (! is_dir($dir)) {
            return [];
        }

        $matches = glob($dir.'/*PanelProvider.php') ?: [];

        return array_map(fn (string $absolute): string => $this->relative($absolute), $matches);
    }

    private function relative(string $absolutePath): string
    {
        $prefix = $this->basePath.DIRECTORY_SEPARATOR;

        return str_starts_with($absolutePath, $prefix)
            ? substr($absolutePath, strlen($prefix))
            : $absolutePath;
    }
}
