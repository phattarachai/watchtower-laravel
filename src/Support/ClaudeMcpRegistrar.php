<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Support;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Wraps the `claude mcp add` shell-out so tests can fake it.
 *
 * @phpstan-type RegistrationResult array{success: bool, output: string}
 */
class ClaudeMcpRegistrar
{
    public function find(): ?string
    {
        return (new ExecutableFinder)->find('claude');
    }

    public function isAvailable(): bool
    {
        return $this->find() !== null;
    }

    /**
     * @return RegistrationResult
     */
    public function register(string $binary, string $name, string $url, string $bearer, string $workingDir): array
    {
        $process = new Process(
            [$binary, 'mcp', 'add', '--transport', 'http', $name, $url, '--header', sprintf('Authorization: Bearer %s', $bearer)],
            $workingDir
        );

        $process->run();

        return [
            'success' => $process->isSuccessful(),
            'output'  => trim($process->getErrorOutput() ?: $process->getOutput()),
        ];
    }
}
