<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Tests\Support;

use Phattarachai\WatchtowerLaravel\Server\Ui\UiPage;

/**
 * Stands in for a host without inertiajs/inertia-laravel. It has to run before
 * the application boots, because the provider decides whether to mount the UI
 * routes while booting.
 */
trait HidesInertia
{
    private const string MISSING_INERTIA_CLASS = 'Inertia\NotInstalled';

    private string $realInertiaClass = '';

    protected function setUp(): void
    {
        $this->realInertiaClass = UiPage::$inertiaClass;
        UiPage::$inertiaClass = self::MISSING_INERTIA_CLASS;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        UiPage::$inertiaClass = $this->realInertiaClass;
    }
}
