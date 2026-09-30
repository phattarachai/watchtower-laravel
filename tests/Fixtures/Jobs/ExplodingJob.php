<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/** One of the host app's own jobs — its failures must still be captured. */
class ExplodingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        throw new RuntimeException('the host job exploded');
    }
}
