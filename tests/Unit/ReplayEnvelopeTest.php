<?php

declare(strict_types=1);

use Phattarachai\WatchtowerLaravel\Support\ReplayEnvelope;
use Phattarachai\WatchtowerLaravel\Tests\Support\SentryEnvelope;

it('tells replay envelopes from every other kind', function (): void {
    $cases = [
        'session-mode segment' => [replayFixture('session-seg0'), null, true],
        'buffer-mode segment' => [replayFixture('buffer-seg0'), null, true],
        'gzipped replay segment' => [gzencode(replayFixture('session-seg0')), 'gzip', true],
        'browser error in buffer mode' => [replayFixture('error-in-buffer-mode'), null, false],
        'PHP error' => [SentryEnvelope::build(), null, false],
        'empty body' => ['', null, false],
        'not an envelope' => ['{"probe":true}', null, false],
    ];

    foreach ($cases as $name => [$body, $encoding, $expected]) {
        expect(ReplayEnvelope::isReplay($body, $encoding))->toBe($expected, $name);
    }
});
