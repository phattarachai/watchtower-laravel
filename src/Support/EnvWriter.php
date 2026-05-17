<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Support;

final class EnvWriter
{
    public function __construct(private readonly string $path) {}

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function has(string $key): bool
    {
        if (! $this->exists()) {
            return false;
        }

        $contents = (string) file_get_contents($this->path);
        $pattern  = '/^(\s*)'.preg_quote($key, '/').'\s*=.*$/m';

        return (bool) preg_match($pattern, $contents);
    }

    public function set(string $key, string $value): void
    {
        $contents = $this->exists() ? (string) file_get_contents($this->path) : '';
        $quoted   = $this->quote($value);
        $pattern  = '/^(\s*)'.preg_quote($key, '/').'\s*=.*$/m';

        if (preg_match($pattern, $contents)) {
            $contents = preg_replace($pattern, '${1}'.$key.'='.$this->escapeReplacement($quoted), $contents) ?? $contents;
        } else {
            if ($contents !== '' && ! str_ends_with($contents, "\n")) {
                $contents .= "\n";
            }

            $contents .= $key.'='.$quoted."\n";
        }

        file_put_contents($this->path, $contents);
    }

    private function quote(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/[\s#"\'\\\\$]/', $value)) {
            return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\"', '\\$'], $value).'"';
        }

        return $value;
    }

    private function escapeReplacement(string $value): string
    {
        return str_replace(['\\', '$'], ['\\\\', '\\$'], $value);
    }
}
