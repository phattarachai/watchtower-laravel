<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Support;

final class BootstrapPatcher
{
    public function __construct(private readonly string $contents) {}

    public static function fromFile(string $path): self
    {
        return new self((string) file_get_contents($path));
    }

    public function alreadyWired(): bool
    {
        return (bool) preg_match('/Integration::handles\s*\(\s*\$exceptions/', $this->contents);
    }

    public function patch(): ?string
    {
        if ($this->alreadyWired()) {
            return $this->contents;
        }

        $withUse = $this->ensureUseStatement($this->contents);

        if ($withUse === null) {
            return null;
        }

        return $this->insertHandlesCall($withUse);
    }

    private function ensureUseStatement(string $source): ?string
    {
        if (preg_match('/^use\s+Sentry\\\\Laravel\\\\Integration\s*;/m', $source)) {
            return $source;
        }

        if (! preg_match_all('/^use\s+[^;]+;\s*$/m', $source, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $last           = end($matches[0]);
        $lastUseString  = $last[0];
        $lastUseOffset  = (int) $last[1];
        $insertPosition = $lastUseOffset + strlen($lastUseString);

        return substr($source, 0, $insertPosition)
            ."\nuse Sentry\\Laravel\\Integration;"
            .substr($source, $insertPosition);
    }

    private function insertHandlesCall(string $source): ?string
    {
        $pattern = '/->withExceptions\s*\(\s*function\s*\(\s*Exceptions\s+\$exceptions\s*\)\s*(?:use\s*\([^)]*\)\s*)?(?:\s*:\s*void)?\s*\{/m';

        if (! preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $matched        = $match[0][0];
        $matchOffset    = (int) $match[0][1];
        $insertPosition = $matchOffset + strlen($matched);

        return substr($source, 0, $insertPosition)
            ."\n        Integration::handles(\$exceptions);"
            .substr($source, $insertPosition);
    }
}
