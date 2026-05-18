<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Support;

/**
 * Best-effort scan for root Blade layouts that contain a `<head>` block —
 * candidates for hosting the `<meta name="watchtower-user-*">` tags.
 *
 * Looks in the two conventional locations Laravel apps use:
 *
 *   - `resources/views/components/layouts/` (modern, anonymous component layouts)
 *   - `resources/views/layouts/`            (legacy `@extends('layouts.app')`)
 *
 * Filament admin panels do NOT use these layouts — they render through panel
 * providers and need a `renderHook('panels::head.end', …)`. See FilamentPanelDetector.
 */
final class LayoutDetector
{
    /** @var list<string> */
    private const SEARCH_DIRS = [
        'resources/views/components/layouts',
        'resources/views/layouts',
    ];

    public function __construct(private readonly string $basePath) {}

    /**
     * Project-relative paths of root layouts that contain a `<head>` tag.
     *
     * @return list<string>
     */
    public function layouts(): array
    {
        $matches = [];

        foreach (self::SEARCH_DIRS as $relative) {
            $dir = $this->basePath.DIRECTORY_SEPARATOR.$relative;

            if (! is_dir($dir)) {
                continue;
            }

            foreach (glob($dir.'/*.blade.php') ?: [] as $file) {
                if ($this->hasHead($file)) {
                    $matches[] = $this->relative($file);
                }
            }
        }

        return $matches;
    }

    private function hasHead(string $absolutePath): bool
    {
        $contents = @file_get_contents($absolutePath);

        return is_string($contents) && str_contains($contents, '<head');
    }

    private function relative(string $absolutePath): string
    {
        $prefix = $this->basePath.DIRECTORY_SEPARATOR;

        return str_starts_with($absolutePath, $prefix)
            ? substr($absolutePath, strlen($prefix))
            : $absolutePath;
    }
}
