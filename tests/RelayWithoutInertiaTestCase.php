<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Tests;

use Phattarachai\WatchtowerLaravel\Tests\Support\HidesInertia;

/** Relay mode on a Livewire, Filament or Blade host with no Inertia at all. */
abstract class RelayWithoutInertiaTestCase extends TestCase
{
    use HidesInertia;
}
