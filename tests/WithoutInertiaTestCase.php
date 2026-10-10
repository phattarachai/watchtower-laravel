<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Tests;

use Phattarachai\WatchtowerLaravel\Tests\Support\HidesInertia;

/** Standalone mode on a host that never installed inertiajs/inertia-laravel. */
abstract class WithoutInertiaTestCase extends ServerTestCase
{
    use HidesInertia;
}
