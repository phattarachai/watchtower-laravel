<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Phattarachai\WatchtowerLaravel\Server\Models\AlertRule;
use Phattarachai\WatchtowerLaravel\Server\Models\Event;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;
use Phattarachai\WatchtowerLaravel\Server\Models\Project;
use Phattarachai\WatchtowerLaravel\Tests\Fixtures\WtUser;
use Phattarachai\WatchtowerLaravel\Tests\ServerTestCase;
use Phattarachai\WatchtowerLaravel\Tests\Support\SentryEnvelope;
use Phattarachai\WatchtowerLaravel\Tests\TestCase;
use Phattarachai\WatchtowerLaravel\Tests\UiTestCase;

uses(TestCase::class)->in('Feature', 'Unit');
uses(ServerTestCase::class, RefreshDatabase::class)->in('Server');
uses(UiTestCase::class, RefreshDatabase::class)->in('Ui');

/**
 * @param  array<string, mixed>  $attributes
 */
function makeWatchtowerProject(array $attributes = []): Project
{
    return Project::create(array_replace([
        'name' => 'Embedded App',
        'slug' => 'embedded-app-'.Str::random(6),
        'platform' => 'laravel',
        'public_key' => Project::newPublicKey(),
        'is_active' => true,
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeWatchtowerGroup(Project $project, array $attributes = []): IssueGroup
{
    return IssueGroup::create(array_replace([
        'project_id' => $project->getKey(),
        'fingerprint' => substr(md5(Str::random(12)), 0, 32),
        'title' => 'RuntimeException: Something exploded',
        'platform' => 'php',
        'level' => 'error',
        'status' => IssueGroup::STATUS_UNRESOLVED,
        'first_seen_at' => now()->subDay(),
        'last_seen_at' => now(),
        'event_count' => 1,
        'user_count' => 1,
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 * @param  array<string, mixed>  $payloadOverrides
 */
function makeWatchtowerEvent(IssueGroup $group, array $attributes = [], array $payloadOverrides = []): Event
{
    return Event::create(array_replace([
        'group_id' => $group->getKey(),
        'project_id' => $group->project_id,
        'environment' => 'production',
        'release' => '1.0.0',
        'received_at' => now(),
        'level' => 'error',
        'sdk_name' => 'sentry.php.laravel',
        'payload' => SentryEnvelope::eventPayload($payloadOverrides),
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeWatchtowerRule(Project $project, array $attributes = []): AlertRule
{
    return AlertRule::create(array_replace([
        'project_id' => $project->getKey(),
        'name' => 'All new issues',
        'type' => 'new_issue',
        'environment' => null,
        'min_level' => 'error',
        'cooldown_seconds' => 900,
        'targets' => ['emails' => ['ops@example.test']],
        'is_active' => true,
    ], $attributes));
}

/** A signed-in user the UI gate accepts. */
function wtUser(): WtUser
{
    return WtUser::query()->firstOrCreate(
        ['email' => 'ops@example.test'],
        ['name' => 'Ops User', 'password' => bcrypt('secret')],
    );
}
