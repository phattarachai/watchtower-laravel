<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Ui;

use Inertia\Inertia;
use Inertia\Response;
use Phattarachai\WatchtowerLaravel\Watchtower;

/**
 * Every UI route renders the same Inertia component; the `view` prop is what
 * the React root switches on. Endpoint URLs are handed over as props rather
 * than resolved in JS, so the module carries no Laravel coupling.
 */
final class UiPage
{
    public const string COMPONENT = 'Watchtower';

    /** Stand-in the JS swaps for a real id — see `withId()` in the module. */
    public const string ID_PLACEHOLDER = '__ID__';

    /**
     * @param  array<string, mixed>  $props
     */
    public static function render(string $view, array $props): Response
    {
        return Inertia::render(self::COMPONENT, [
            'view' => $view,
            'endpoints' => self::endpoints(),
            'brand' => self::brand(),
            ...$props,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public static function endpoints(): array
    {
        $id = self::ID_PLACEHOLDER;

        return [
            'issues' => route('watchtower.ui.issues'),
            'issue' => route('watchtower.ui.issue', ['group' => $id]),
            'issueStatus' => route('watchtower.ui.issues.status', ['group' => $id]),
            'issueBulkStatus' => route('watchtower.ui.issues.bulk-status'),
            'issueBulkDestroy' => route('watchtower.ui.issues.bulk-destroy'),
            'alerts' => route('watchtower.ui.alerts'),
            'alertStore' => route('watchtower.ui.alerts.store'),
            'alertUpdate' => route('watchtower.ui.alerts.update', ['rule' => $id]),
            'alertDestroy' => route('watchtower.ui.alerts.destroy', ['rule' => $id]),
            'alertTest' => route('watchtower.ui.alerts.test', ['rule' => $id]),
            'settings' => route('watchtower.ui.settings'),
            'projectStore' => route('watchtower.ui.projects.store'),
            'projectUpdate' => route('watchtower.ui.projects.update', ['project' => $id]),
            'projectRotateKey' => route('watchtower.ui.projects.rotate', ['project' => $id]),
        ];
    }

    /**
     * @return array{name: string, version: string}
     */
    public static function brand(): array
    {
        return [
            'name' => (string) config('app.name', 'Laravel'),
            'version' => Watchtower::VERSION,
        ];
    }
}
