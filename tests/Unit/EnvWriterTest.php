<?php

declare(strict_types=1);

use Phattarachai\WatchtowerLaravel\Support\EnvWriter;

beforeEach(function (): void {
    $this->path = tempnam(sys_get_temp_dir(), 'wt-env-');
});

afterEach(function (): void {
    if (is_file($this->path)) {
        unlink($this->path);
    }
});

it('appends a new key when missing', function (): void {
    file_put_contents($this->path, "FOO=bar\n");

    $writer = new EnvWriter($this->path);
    $writer->set('BAR', 'baz');

    $contents = file_get_contents($this->path);

    expect($contents)->toContain("FOO=bar\n")
        ->and($contents)->toContain("BAR=baz\n");
});

it('updates an existing key in place', function (): void {
    file_put_contents($this->path, "# top comment\nFOO=old\nBAR=keep\n");

    $writer = new EnvWriter($this->path);
    $writer->set('FOO', 'new');

    $contents = file_get_contents($this->path);

    expect($contents)->toContain("FOO=new")
        ->and($contents)->toContain("BAR=keep")
        ->and($contents)->toContain('# top comment')
        ->and(substr_count((string) $contents, 'FOO='))->toBe(1);
});

it('quotes values containing whitespace', function (): void {
    file_put_contents($this->path, '');

    $writer = new EnvWriter($this->path);
    $writer->set('NAME', 'hello world');

    $contents = file_get_contents($this->path);

    expect($contents)->toContain('NAME="hello world"');
});

it('quotes values containing special characters', function (): void {
    file_put_contents($this->path, '');

    $writer = new EnvWriter($this->path);
    $writer->set('SECRET', 'a#b$c');

    $contents = file_get_contents($this->path);

    expect($contents)->toContain('SECRET="a#b\\$c"');
});

it('reports key presence accurately', function (): void {
    file_put_contents($this->path, "PRESENT=yes\n");

    $writer = new EnvWriter($this->path);

    expect($writer->has('PRESENT'))->toBeTrue()
        ->and($writer->has('MISSING'))->toBeFalse();
});

it('creates the file when setting on a non-existent path', function (): void {
    unlink($this->path);

    $writer = new EnvWriter($this->path);
    $writer->set('FRESH', 'value');

    expect(file_get_contents($this->path))->toContain("FRESH=value\n");
});

it('setIfAbsent writes only when the key is missing', function (): void {
    file_put_contents($this->path, "PRESENT=already-here\n");

    $writer = new EnvWriter($this->path);

    expect($writer->setIfAbsent('PRESENT', 'overwritten'))->toBeFalse()
        ->and($writer->setIfAbsent('FRESH', 'value'))->toBeTrue();

    $contents = (string) file_get_contents($this->path);

    expect($contents)->toContain('PRESENT=already-here')
        ->and($contents)->not->toContain('overwritten')
        ->and($contents)->toContain("FRESH=value\n");
});
