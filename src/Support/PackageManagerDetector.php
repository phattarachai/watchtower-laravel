<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Support;

/**
 * Detects the consumer's JS package manager from lockfiles and reports
 * whether a dependency is already declared in `package.json`. Used by the
 * install command to print a tailored "install @sentry/browser" hint that
 * matches the consumer's toolchain — npm / pnpm / yarn / bun — instead of
 * dumping a one-size-fits-all `npm install`.
 *
 * Lockfile precedence follows the de-facto JS-tooling convention: bun >
 * pnpm > yarn > npm. Bun and pnpm are checked first because their presence
 * is intentional (a developer who runs `bun install` made a choice); npm
 * is the fallback when only `package-lock.json` (or no lockfile) is found.
 */
final class PackageManagerDetector
{
    public function __construct(private readonly string $basePath) {}

    /**
     * Returns the detected package manager name, or null when no
     * `package.json` exists at the project root (in which case the
     * consumer doesn't have a JS toolchain at all).
     */
    public function detect(): ?string
    {
        if (! is_file($this->path('package.json'))) {
            return null;
        }

        return match (true) {
            is_file($this->path('bun.lockb')), is_file($this->path('bun.lock')) => 'bun',
            is_file($this->path('pnpm-lock.yaml'))                              => 'pnpm',
            is_file($this->path('yarn.lock'))                                   => 'yarn',
            default                                                             => 'npm',
        };
    }

    /**
     * Returns the install command for a given dependency in the consumer's
     * package manager, e.g. `pnpm add @sentry/browser`. Falls back to npm
     * when no `package.json` exists so the printed hint is always usable.
     */
    public function installCommand(string $package): string
    {
        $manager = $this->detect() ?? 'npm';

        return match ($manager) {
            'bun'   => 'bun add '.$package,
            'pnpm'  => 'pnpm add '.$package,
            'yarn'  => 'yarn add '.$package,
            default => 'npm install --save '.$package,
        };
    }

    /**
     * True when `$package` is already declared under `dependencies` or
     * `devDependencies` in the consumer's `package.json`. Used to suppress
     * the install hint when the dep is already present.
     */
    public function hasDependency(string $package): bool
    {
        $path = $this->path('package.json');

        if (! is_file($path)) {
            return false;
        }

        $contents = (string) file_get_contents($path);

        $decoded = json_decode($contents, associative: true);

        if (! is_array($decoded)) {
            return false;
        }

        foreach (['dependencies', 'devDependencies'] as $bucket) {
            if (is_array($decoded[$bucket] ?? null) && array_key_exists($package, $decoded[$bucket])) {
                return true;
            }
        }

        return false;
    }

    private function path(string $relative): string
    {
        return $this->basePath.DIRECTORY_SEPARATOR.$relative;
    }
}
