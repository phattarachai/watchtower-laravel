<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Phattarachai\WatchtowerCore\Alerts\AlertType;
use Phattarachai\WatchtowerLaravel\Server\Alerts\AlertDispatcher;
use Phattarachai\WatchtowerLaravel\Server\Mail\IssueAlertMail;
use Phattarachai\WatchtowerLaravel\Server\Models\AlertRule;
use Phattarachai\WatchtowerLaravel\Server\Models\Event;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;
use Phattarachai\WatchtowerLaravel\Server\Models\NotificationSent;
use Phattarachai\WatchtowerLaravel\Server\Models\Project;
use Phattarachai\WatchtowerLaravel\Tests\Support\SentryEnvelope;

/**
 * @param  array<string, mixed>  $attributes
 */
function makeAlertGroup(Project $project, array $attributes = []): IssueGroup
{
    return IssueGroup::create(array_replace([
        'project_id' => $project->id,
        'fingerprint' => str_pad(uniqid('', false), 32, 'a'),
        'title' => 'TestException: boom',
        'platform' => 'php',
        'level' => 'error',
        'status' => IssueGroup::STATUS_UNRESOLVED,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'event_count' => 1,
        'user_count' => 0,
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeAlertEvent(IssueGroup $group, array $attributes = []): Event
{
    return Event::create(array_replace([
        'group_id' => $group->id,
        'project_id' => $group->project_id,
        'environment' => 'production',
        'received_at' => now(),
        'level' => 'error',
        'sdk_name' => 'sentry.php.laravel',
        'payload' => ['exception' => ['values' => [['type' => 'TestException', 'value' => 'boom']]]],
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeAlertRule(Project $project, AlertType $type, array $attributes = []): AlertRule
{
    return AlertRule::create(array_replace([
        'project_id' => $project->id,
        'name' => 'Test rule',
        'type' => $type,
        'min_level' => 'error',
        'targets' => ['emails' => ['ops@example.com']],
        'cooldown_seconds' => 0,
        'is_active' => true,
    ], $attributes));
}

function dispatchAlerts(IssueGroup $group, Event $event, bool $isNewGroup = false, bool $isRegression = false): void
{
    app(AlertDispatcher::class)->dispatch($group, $event, $isNewGroup, $isRegression);
}

beforeEach(function (): void {
    Mail::fake();
    $this->project = makeWatchtowerProject();
});

it('fires a new_issue alert exactly once on the first event', function (): void {
    $group = makeAlertGroup($this->project);
    $event = makeAlertEvent($group);
    makeAlertRule($this->project, AlertType::NewIssue);

    dispatchAlerts($group, $event, isNewGroup: true);

    Mail::assertQueued(IssueAlertMail::class, 1);
    expect(NotificationSent::count())->toBe(1);
});

it('does not fire new_issue on subsequent events', function (): void {
    $group = makeAlertGroup($this->project);
    $event = makeAlertEvent($group);
    makeAlertRule($this->project, AlertType::NewIssue);

    dispatchAlerts($group, $event);

    Mail::assertNothingQueued();
    expect(NotificationSent::count())->toBe(0);
});

it('does not fire a second new_issue alert within the cooldown window', function (): void {
    $group = makeAlertGroup($this->project);
    $event = makeAlertEvent($group);
    makeAlertRule($this->project, AlertType::NewIssue, ['cooldown_seconds' => 600]);

    dispatchAlerts($group, $event, isNewGroup: true);
    dispatchAlerts($group, $event, isNewGroup: true);

    Mail::assertQueued(IssueAlertMail::class, 1);
    expect(NotificationSent::count())->toBe(1);
});

it('fires a regression alert when a resolved group reopens', function (): void {
    $group = makeAlertGroup($this->project);
    $event = makeAlertEvent($group);
    makeAlertRule($this->project, AlertType::Regression);

    dispatchAlerts($group, $event, isRegression: true);

    Mail::assertQueued(IssueAlertMail::class, 1);
});

it('fires a threshold alert once N events land inside the window', function (): void {
    $group = makeAlertGroup($this->project);

    foreach (range(1, 4) as $ignored) {
        makeAlertEvent($group, ['received_at' => now()->subSeconds(30)]);
    }

    $event = makeAlertEvent($group);
    makeAlertRule($this->project, AlertType::Threshold, [
        'threshold_count' => 5,
        'threshold_window_seconds' => 60,
    ]);

    dispatchAlerts($group, $event);

    Mail::assertQueued(IssueAlertMail::class, 1);
});

it('skips a threshold alert while the count is below the cap', function (): void {
    $group = makeAlertGroup($this->project);
    $event = makeAlertEvent($group);
    makeAlertRule($this->project, AlertType::Threshold, [
        'threshold_count' => 5,
        'threshold_window_seconds' => 60,
    ]);

    dispatchAlerts($group, $event);

    Mail::assertNothingQueued();
});

it('ignores events below the rule min_level', function (): void {
    $group = makeAlertGroup($this->project);
    $event = makeAlertEvent($group, ['level' => 'info']);
    makeAlertRule($this->project, AlertType::NewIssue, ['min_level' => 'error']);

    dispatchAlerts($group, $event, isNewGroup: true);

    Mail::assertNothingQueued();
});

it('skips a rule scoped to another environment', function (): void {
    $group = makeAlertGroup($this->project);
    $event = makeAlertEvent($group, ['environment' => 'staging']);
    makeAlertRule($this->project, AlertType::NewIssue, ['environment' => 'production']);

    dispatchAlerts($group, $event, isNewGroup: true);

    Mail::assertNothingQueued();
});

it('skips a muted rule', function (): void {
    $group = makeAlertGroup($this->project);
    $event = makeAlertEvent($group);
    makeAlertRule($this->project, AlertType::NewIssue, ['muted_until' => now()->addHour()]);

    dispatchAlerts($group, $event, isNewGroup: true);

    Mail::assertNothingQueued();
});

it('skips an inactive rule', function (): void {
    $group = makeAlertGroup($this->project);
    $event = makeAlertEvent($group);
    makeAlertRule($this->project, AlertType::NewIssue, ['is_active' => false]);

    dispatchAlerts($group, $event, isNewGroup: true);

    Mail::assertNothingQueued();
});

it('stays quiet while the issue is snoozed into the future', function (): void {
    $group = makeAlertGroup($this->project, [
        'status' => IssueGroup::STATUS_SNOOZED,
        'snoozed_until' => now()->addHour(),
    ]);
    $event = makeAlertEvent($group);
    makeAlertRule($this->project, AlertType::NewIssue);

    dispatchAlerts($group, $event, isNewGroup: true);

    Mail::assertNothingQueued();
});

it('fires a milestone alert only once per rule and group', function (): void {
    $group = makeAlertGroup($this->project, ['event_count' => 10]);
    $event = makeAlertEvent($group);
    makeAlertRule($this->project, AlertType::Milestone, ['threshold_count' => 10]);

    dispatchAlerts($group, $event);
    $group->update(['event_count' => 50]);
    dispatchAlerts($group->fresh(), $event);

    Mail::assertQueued(IssueAlertMail::class, 1);
});

it('sends nothing and records nothing when the rule carries no email targets', function (): void {
    $group = makeAlertGroup($this->project);
    $event = makeAlertEvent($group);
    makeAlertRule($this->project, AlertType::NewIssue, ['targets' => ['emails' => []]]);

    dispatchAlerts($group, $event, isNewGroup: true);

    Mail::assertNothingQueued();
    expect(NotificationSent::count())->toBe(0);
});

it('ignores user_id targets and malformed addresses', function (): void {
    $group = makeAlertGroup($this->project);
    $event = makeAlertEvent($group);
    makeAlertRule($this->project, AlertType::NewIssue, [
        'targets' => ['emails' => ['not-an-email', ''], 'user_ids' => [1, 2]],
    ]);

    dispatchAlerts($group, $event, isNewGroup: true);

    Mail::assertNothingQueued();
    expect(NotificationSent::count())->toBe(0);
});

it('lowercases and dedupes the recipient list', function (): void {
    $group = makeAlertGroup($this->project);
    $event = makeAlertEvent($group);
    makeAlertRule($this->project, AlertType::NewIssue, [
        'targets' => ['emails' => ['Ops@Example.com', 'ops@example.com', 'dev@example.com']],
    ]);

    dispatchAlerts($group, $event, isNewGroup: true);

    Mail::assertQueued(
        IssueAlertMail::class,
        fn (IssueAlertMail $mail): bool => $mail->hasTo('ops@example.com') && $mail->hasTo('dev@example.com'),
    );

    expect(NotificationSent::sole()->recipient_count)->toBe(2);
});

it('records the notification row shape', function (): void {
    $group = makeAlertGroup($this->project);
    $event = makeAlertEvent($group);
    $rule = makeAlertRule($this->project, AlertType::NewIssue);

    dispatchAlerts($group, $event, isNewGroup: true);

    $sent = NotificationSent::sole();

    expect($sent->rule_id)->toBe($rule->id)
        ->and($sent->group_id)->toBe($group->id)
        ->and($sent->kind)->toBe('new_issue')
        ->and($sent->recipient_count)->toBe(1)
        ->and($sent->sent_at)->not->toBeNull();
});

it('renders the alert mail with the issue link and top application frame', function (): void {
    $group = makeAlertGroup($this->project);
    $event = makeAlertEvent($group, [
        'payload' => [
            'exception' => [
                'values' => [[
                    'type' => 'TestException',
                    'value' => 'boom',
                    'stacktrace' => [
                        'frames' => [
                            ['filename' => '/app/vendor/laravel/framework/src/Bootstrap.php', 'lineno' => 10, 'function' => 'boot'],
                            ['filename' => '/app/app/Services/Billing.php', 'lineno' => 42, 'function' => 'charge'],
                            ['filename' => '/app/vendor/pest/src/Runner.php', 'lineno' => 7, 'function' => 'run'],
                        ],
                    ],
                ]],
            ],
        ],
    ]);
    makeAlertRule($this->project, AlertType::NewIssue);

    dispatchAlerts($group, $event, isNewGroup: true);

    Mail::assertQueued(IssueAlertMail::class, function (IssueAlertMail $mail) use ($group): bool {
        $rendered = $mail->render();

        expect($mail->envelope()->subject)->toContain('[Watchtower:NEW]')
            ->and($mail->envelope()->subject)->toContain($this->project->name);

        return str_contains($rendered, '/app/app/Services/Billing.php:42')
            && str_contains($rendered, url('watchtower/issues/'.$group->id))
            && str_contains($rendered, 'A new issue was seen for the first time.');
    });
});

it('queues an alert mail end to end through the ingest route', function (): void {
    $project = makeWatchtowerProject();
    makeAlertRule($project, AlertType::NewIssue);

    $this->call('POST', "/watchtower/api/{$project->id}/envelope", server: [
        'CONTENT_TYPE' => 'application/x-sentry-envelope',
        'HTTP_X_SENTRY_AUTH' => "Sentry sentry_version=7, sentry_key={$project->public_key}",
    ], content: SentryEnvelope::build())->assertSuccessful();

    Mail::assertQueued(IssueAlertMail::class, 1);
    expect(NotificationSent::count())->toBe(1);
});

it('sends nothing through ingest when the project has no active rules', function (): void {
    $project = makeWatchtowerProject();

    $this->call('POST', "/watchtower/api/{$project->id}/envelope", server: [
        'CONTENT_TYPE' => 'application/x-sentry-envelope',
        'HTTP_X_SENTRY_AUTH' => "Sentry sentry_version=7, sentry_key={$project->public_key}",
    ], content: SentryEnvelope::build())->assertSuccessful();

    Mail::assertNothingQueued();
});
