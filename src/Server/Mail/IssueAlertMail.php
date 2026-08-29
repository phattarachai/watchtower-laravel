<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Mail;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Phattarachai\WatchtowerCore\Alerts\AlertType;
use Phattarachai\WatchtowerCore\Alerts\EventSnapshot;
use Phattarachai\WatchtowerCore\Alerts\IssueSnapshot;
use Phattarachai\WatchtowerCore\Alerts\RuleSpec;
use Phattarachai\WatchtowerLaravel\Server\Models\Project;

class IssueAlertMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly RuleSpec $rule,
        public readonly IssueSnapshot $issue,
        public readonly EventSnapshot $event,
        public readonly Project $project,
        public readonly bool $isRegression = false,
        public readonly bool $isTest = false,
    ) {}

    public function envelope(): Envelope
    {
        $title = Str::limit($this->issue->title, 150);
        $testPrefix = $this->isTest ? '[TEST] ' : '';

        return new Envelope(
            subject: "{$testPrefix}{$this->subjectTag()} [{$this->project->name}] {$title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'watchtower::mail.alerts.issue',
            with: [
                'rule' => $this->rule,
                'issue' => $this->issue,
                'event' => $this->event,
                'project' => $this->project,
                'kind' => $this->rule->type,
                'headline' => $this->headline(),
                'topFrame' => $this->topFrame(),
                'firstSeenAt' => $this->toCarbon($this->issue->firstSeenAt),
                'lastSeenAt' => $this->toCarbon($this->issue->lastSeenAt),
                'isRegression' => $this->isRegression,
                'isTest' => $this->isTest,
                'issueUrl' => $this->issueUrl(),
            ],
        );
    }

    private function subjectTag(): string
    {
        return match ($this->rule->type) {
            AlertType::NewIssue => '[Watchtower:NEW]',
            AlertType::Regression => '[Watchtower:REGRESSION]',
            AlertType::Threshold => '[Watchtower:SPIKE]',
            AlertType::Milestone => '[Watchtower:'.$this->rule->thresholdCount.'x]',
        };
    }

    private function headline(): string
    {
        return match ($this->rule->type) {
            AlertType::NewIssue => 'A new issue was seen for the first time.',
            AlertType::Regression => 'This issue was resolved before and has started happening again.',
            AlertType::Threshold => sprintf(
                '%d events within %d minutes — %d events seen in total.',
                (int) $this->rule->thresholdCount,
                (int) round((int) $this->rule->thresholdWindowSeconds / 60),
                $this->issue->eventCount,
            ),
            AlertType::Milestone => sprintf(
                'This issue has now happened %d times (milestone of %d reached).',
                $this->issue->eventCount,
                (int) $this->rule->thresholdCount,
            ),
        };
    }

    /**
     * The deepest application frame — vendor frames are noise for triage.
     *
     * @return array{file: string, line: string, function: string}|null
     */
    private function topFrame(): ?array
    {
        $frames = $this->event->payload['exception']['values'][0]['stacktrace']['frames'] ?? null;

        if (! is_array($frames)) {
            return null;
        }

        foreach (array_reverse($frames) as $frame) {
            $file = (string) ($frame['filename'] ?? $frame['abs_path'] ?? '');

            if ($file === '' || str_contains($file, '/vendor/')) {
                continue;
            }

            return [
                'file' => $file,
                'line' => (string) ($frame['lineno'] ?? '?'),
                'function' => (string) ($frame['function'] ?? ''),
            ];
        }

        return null;
    }

    /**
     * The embedded UI mounts under the configured server path — there is no
     * named route to lean on because the host app owns routing.
     */
    private function issueUrl(): string
    {
        $prefix = trim((string) config('watchtower.server.path', 'watchtower'), '/');
        $path = $prefix === '' ? '' : $prefix.'/';

        return url($path.'issues/'.$this->issue->id);
    }

    private function toCarbon(?DateTimeInterface $date): ?Carbon
    {
        return $date === null ? null : Carbon::instance($date);
    }
}
