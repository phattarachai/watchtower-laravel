<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Phattarachai\WatchtowerLaravel\Server\Mail\IssueAlertMail;
use Phattarachai\WatchtowerLaravel\Server\Models\AlertRule;

use function Pest\Laravel\actingAs;

it('lists alert rules with their recipients and the type/level options', function (): void {
    $project = makeWatchtowerProject(['name' => 'Storefront']);
    makeWatchtowerRule($project, ['name' => 'Spike watch', 'type' => 'threshold', 'threshold_count' => 10, 'threshold_window_seconds' => 300]);

    actingAs(wtUser())
        ->get(route('watchtower.ui.alerts'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Watchtower')
            ->where('view', 'alerts')
            ->has('rules', 1)
            ->where('rules.0.name', 'Spike watch')
            ->where('rules.0.type', 'threshold')
            ->where('rules.0.threshold_count', 10)
            ->where('rules.0.emails', ['ops@example.test'])
            ->where('rules.0.project.name', 'Storefront')
            ->has('projects', 1)
            ->has('options.types', 4)
            ->where('options.levels', ['debug', 'info', 'warning', 'error', 'fatal'])
            ->has('endpoints.alertTest'));
});

it('creates, updates and deletes a rule', function (): void {
    $project = makeWatchtowerProject();

    $created = actingAs(wtUser())
        ->postJson(route('watchtower.ui.alerts.store'), [
            'project_id' => $project->getKey(),
            'name' => 'Fatal only',
            'type' => 'new_issue',
            'environment' => 'production',
            'min_level' => 'fatal',
            'cooldown_seconds' => 600,
            'emails' => ['a@example.test', 'b@example.test'],
            'is_active' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('rule.name', 'Fatal only')
        ->assertJsonPath('rule.emails', ['a@example.test', 'b@example.test']);

    $ruleId = $created->json('rule.id');

    expect(AlertRule::query()->find($ruleId)->min_level)->toBe('fatal');

    actingAs(wtUser())
        ->patchJson(route('watchtower.ui.alerts.update', ['rule' => $ruleId]), [
            'project_id' => $project->getKey(),
            'name' => 'Fatal only (paused)',
            'type' => 'new_issue',
            'min_level' => 'error',
            'cooldown_seconds' => 300,
            'emails' => ['a@example.test'],
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('rule.is_active', false)
        ->assertJsonPath('rule.min_level', 'error');

    actingAs(wtUser())
        ->deleteJson(route('watchtower.ui.alerts.destroy', ['rule' => $ruleId]))
        ->assertOk()
        ->assertJsonPath('deleted', true);

    expect(AlertRule::query()->find($ruleId))->toBeNull();
});

it('rejects an invalid rule payload', function (): void {
    $project = makeWatchtowerProject();

    actingAs(wtUser())
        ->postJson(route('watchtower.ui.alerts.store'), [
            'project_id' => $project->getKey(),
            'name' => '',
            'type' => 'not_a_type',
            'min_level' => 'shouting',
            'cooldown_seconds' => 900,
            'emails' => ['nope'],
            'is_active' => true,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'type', 'min_level', 'emails.0']);

    actingAs(wtUser())
        ->postJson(route('watchtower.ui.alerts.store'), [
            'project_id' => 99999,
            'name' => 'Orphan',
            'type' => 'new_issue',
            'min_level' => 'error',
            'cooldown_seconds' => 900,
            'emails' => ['a@example.test'],
            'is_active' => true,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['project_id']);
});

it('404s a rule update that names a different project', function (): void {
    $rule = makeWatchtowerRule(makeWatchtowerProject());
    $other = makeWatchtowerProject();

    actingAs(wtUser())
        ->patchJson(route('watchtower.ui.alerts.update', ['rule' => $rule->getKey()]), [
            'project_id' => $other->getKey(),
            'name' => 'Hijacked',
            'type' => 'new_issue',
            'min_level' => 'error',
            'cooldown_seconds' => 900,
            'emails' => ['a@example.test'],
            'is_active' => true,
        ])
        ->assertNotFound();
});

it('queues a test alert from the latest event of the rule project', function (): void {
    Mail::fake();

    $project = makeWatchtowerProject();
    $rule = makeWatchtowerRule($project);
    makeWatchtowerEvent(makeWatchtowerGroup($project));

    actingAs(wtUser())
        ->postJson(route('watchtower.ui.alerts.test', ['rule' => $rule->getKey()]))
        ->assertOk()
        ->assertJsonPath('queued', true)
        ->assertJsonPath('recipients', 1);

    Mail::assertQueued(IssueAlertMail::class, fn (IssueAlertMail $mail): bool => $mail->isTest);
});

it('answers 422 when the project has no events to build a test alert from', function (): void {
    Mail::fake();

    $rule = makeWatchtowerRule(makeWatchtowerProject());

    actingAs(wtUser())
        ->postJson(route('watchtower.ui.alerts.test', ['rule' => $rule->getKey()]))
        ->assertStatus(422)
        ->assertJsonStructure(['message']);

    Mail::assertNothingQueued();
});
