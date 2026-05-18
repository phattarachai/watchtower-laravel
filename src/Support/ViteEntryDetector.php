<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Support;

/**
 * Best-effort static parser for `laravel({ input: [...] })` entries in a
 * project's `vite.config.js` / `vite.config.ts`. Returns project-relative
 * paths that end in `.js` / `.ts` / `.jsx` / `.tsx` (CSS / SCSS entries are
 * filtered — only JS-capable entries can host the Sentry init snippet).
 *
 * Falls back to an empty list when no Vite config is present or the parser
 * can't recognize the shape, in which case the install command should still
 * emit the generic JS snippet so the user can paste it manually.
 */
final class ViteEntryDetector
{
    public function __construct(private readonly string $basePath) {}

    /**
     * Absolute path of the project's vite config, or null when neither exists.
     */
    public function configPath(): ?string
    {
        foreach (['vite.config.js', 'vite.config.ts'] as $candidate) {
            $path = $this->basePath.DIRECTORY_SEPARATOR.$candidate;

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Detected JS-capable Vite entries as project-relative paths.
     *
     * @return list<string>
     */
    public function jsEntries(): array
    {
        $configPath = $this->configPath();

        if ($configPath === null) {
            return [];
        }

        $contents = (string) file_get_contents($configPath);

        if (! preg_match_all('/laravel\s*\(\s*\{(?P<options>[^}]*)\}\s*\)/s', $contents, $blocks)) {
            return [];
        }

        $entries = [];

        foreach ($blocks['options'] as $block) {
            foreach ($this->extractInputs($block) as $entry) {
                $entries[] = $entry;
            }
        }

        $entries = array_values(array_unique($entries));

        return array_values(array_filter($entries, $this->isJsLike(...)));
    }

    /**
     * @return list<string>
     */
    private function extractInputs(string $block): array
    {
        if (! preg_match('/input\s*:\s*(?P<value>\[[^\]]*\]|"[^"]+"|\'[^\']+\')/s', $block, $match)) {
            return [];
        }

        $value = trim($match['value']);

        if (str_starts_with($value, '[')) {
            preg_match_all('/["\']([^"\']+)["\']/', $value, $items);

            return $items[1];
        }

        return [trim($value, "\"'")];
    }

    private function isJsLike(string $entry): bool
    {
        return (bool) preg_match('/\.(js|ts|jsx|tsx|mjs)$/i', $entry);
    }
}
