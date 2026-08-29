<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Support;

/**
 * Host build-tool wiring for the embedded Inertia UI: the `@watchtower` Vite
 * alias and the two Tailwind v4 lines the module's `tw:`-prefixed markup needs.
 *
 * Every method is idempotent — a second run finds its own marker and no-ops.
 */
final class EmbeddedUiPatcher
{
    public const string ALIAS = '@watchtower';

    public const string MODULE_PATH = 'vendor/phattarachai/watchtower-laravel/resources/js/watchtower/index.js';

    public const string TAILWIND_IMPORT = "@import 'tailwindcss' prefix(tw) source(none);";

    public const string TAILWIND_SOURCE = "@source '../../vendor/phattarachai/watchtower-laravel/resources/js/watchtower/**/*.jsx';";

    public const string DARK_VARIANT = '@custom-variant dark (&:where(.dark, .dark *));';

    /**
     * @return list<string>
     */
    public static function viteConfigPaths(string $basePath): array
    {
        return array_values(array_filter(
            [$basePath.'/vite.config.js', $basePath.'/vite.config.ts'],
            is_file(...),
        ));
    }

    public static function cssFilePath(string $basePath): string
    {
        return $basePath.'/resources/css/watchtower.css';
    }

    public static function cssContents(): string
    {
        return self::TAILWIND_IMPORT."\n".self::TAILWIND_SOURCE."\n".self::DARK_VARIANT."\n";
    }

    /**
     * TypeScript hosts glob `./pages/**\/*.tsx`, so the published stub must
     * match the host's page extension or Inertia never finds it. The stub is
     * plain JS, which is valid TSX as-is.
     */
    public static function pageExtension(string $basePath): string
    {
        $tsxMarkers = [
            $basePath.'/resources/js/app.tsx',
            $basePath.'/resources/js/pages/Watchtower.tsx',
            $basePath.'/tsconfig.json',
        ];

        return array_any($tsxMarkers, fn (string $path): bool => is_file($path)) ? 'tsx' : 'jsx';
    }

    public static function publishedPagePath(string $basePath): ?string
    {
        foreach (['tsx', 'jsx'] as $extension) {
            $candidate = $basePath.'/resources/js/pages/Watchtower.'.$extension;

            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public static function hasAlias(string $path): bool
    {
        return self::contains($path, self::ALIAS);
    }

    public static function hasTailwindPrefix(string $path): bool
    {
        return self::contains($path, 'prefix(tw)');
    }

    public static function hasTailwindSource(string $path): bool
    {
        return self::contains($path, 'watchtower-laravel/resources/js/watchtower');
    }

    /**
     * Insert `'@watchtower': fileURLToPath(...)` into an existing
     * `resolve: { alias: { … } }`, or append a whole `resolve` block to the
     * exported config object when there is none.
     */
    public static function patchViteAlias(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        $contents = (string) file_get_contents($path);

        if (str_contains($contents, self::ALIAS)) {
            return true;
        }

        $entry = "            '".self::ALIAS."': path.resolve(__dirname, '".self::MODULE_PATH."'),";
        $patched = self::insertIntoExistingAlias($contents, $entry) ?? self::appendResolveBlock($contents, $entry);

        if ($patched === null) {
            return false;
        }

        file_put_contents($path, self::ensureNodePathImport($patched));

        return true;
    }

    /**
     * Write the standalone stylesheet the published page stub imports. The
     * prefixed Tailwind import lives in its own file so the host's unprefixed
     * entry CSS (and its `@apply` rules) are never touched.
     */
    public static function writeCssFile(string $basePath): bool
    {
        $path = self::cssFilePath($basePath);

        if (self::hasTailwindPrefix($path) && self::hasTailwindSource($path)) {
            return true;
        }

        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            return false;
        }

        return file_put_contents($path, self::cssContents()) !== false;
    }

    private static function ensureNodePathImport(string $contents): string
    {
        if (str_contains($contents, "from 'node:path'") || str_contains($contents, 'require(\'node:path\')')) {
            return $contents;
        }

        return "import path from 'node:path';\n".$contents;
    }

    private static function insertIntoExistingAlias(string $contents, string $entry): ?string
    {
        if (preg_match('/alias\s*:\s*\{/', $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $offset = $match[0][1] + strlen($match[0][0]);

        return substr($contents, 0, $offset)."\n".$entry.substr($contents, $offset);
    }

    private static function appendResolveBlock(string $contents, string $entry): ?string
    {
        $block = "    resolve: {\n        alias: {\n".$entry."\n        },\n    },";
        $position = strrpos($contents, '});');

        if ($position === false) {
            $position = strrpos($contents, '}');
        }

        if ($position === false) {
            return null;
        }

        return substr($contents, 0, $position).$block."\n".substr($contents, $position);
    }

    private static function contains(string $path, string $needle): bool
    {
        return is_file($path) && str_contains((string) file_get_contents($path), $needle);
    }
}
