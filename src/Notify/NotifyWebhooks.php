<?php

namespace Elazaroo\PulseBoosted\Notify;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Elazaroo\PulseBoosted\Events\AlertResolved;
use Elazaroo\PulseBoosted\Events\AlertTriggered;
use Elazaroo\PulseBoosted\Events\IssueAssigned;
use Elazaroo\PulseBoosted\Events\IssueOpened;
use Elazaroo\PulseBoosted\Events\IssueRegressed;
use Elazaroo\PulseBoosted\Events\ScheduledTaskMissed;
use Elazaroo\PulseBoosted\Issues\SendIssueMail;
use Elazaroo\PulseBoosted\Support\DashboardUrl;
use Elazaroo\PulseBoosted\Support\Location;
use Elazaroo\PulseBoosted\Support\People;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use stdClass;

/**
 * Turns the dashboard's events into webhooks.
 *
 * The same rules as the issue email apply to issues — regressions can be
 * turned off, and issues made from log lines are only sent at the notify
 * level or above — so the two never disagree about what is worth telling.
 *
 * @internal
 */
class NotifyWebhooks
{
    public function __construct(
        protected Webhooks $webhooks,
        protected Repository $config,
        protected People $people,
    ) {
        //
    }

    public function __invoke(IssueOpened|IssueRegressed|IssueAssigned|AlertTriggered|AlertResolved|ScheduledTaskMissed $event): void
    {
        match (true) {
            $event instanceof IssueOpened => $this->issue('issue.opened', 'New issue', $event->issue),
            $event instanceof IssueRegressed => $this->issue('issue.regressed', 'Regressed', $event->issue),
            $event instanceof IssueAssigned => $this->assigned($event),
            $event instanceof AlertTriggered => $this->alert('alert.triggered', $event),
            $event instanceof AlertResolved => $this->alert('alert.resolved', $event),
            $event instanceof ScheduledTaskMissed => $this->missed($event),
        };
    }

    protected function issue(string $name, string $label, stdClass $issue): void
    {
        if ($name === 'issue.regressed' && ! $this->config->get('pulse-boosted.issues.notify.regressions', true)) {
            return;
        }

        if (($issue->kind ?? null) === 'log' && ! SendIssueMail::severeEnough((string) ($issue->level ?? ''), $this->config)) {
            return;
        }

        $this->webhooks->send(
            $name,
            "{$label}: ".self::title($issue).($issue->message ? ' — '.Str::limit((string) $issue->message, 150) : ''),
            $this->issueData($issue),
            DashboardUrl::to(['issue' => $issue->fingerprint], 'errors'),
        );
    }

    protected function assigned(IssueAssigned $event): void
    {
        $people = $this->people->resolve([$event->assignee, $event->by]);

        $to = $event->assignee === null ? null : $people[$event->assignee]->name ?? null;
        $by = $event->by === null ? null : $people[$event->by]->name ?? null;

        $this->webhooks->send(
            'issue.assigned',
            ($to === null ? 'Unassigned' : "Assigned to {$to}").': '.self::title($event->issue).($by ? " (by {$by})" : ''),
            [
                ...$this->issueData($event->issue),
                'assignee' => $event->assignee === null ? null : ['id' => $event->assignee, 'name' => $to, 'email' => $people[$event->assignee]->email ?? null],
                'by' => $event->by === null ? null : ['id' => $event->by, 'name' => $by],
            ],
            DashboardUrl::to(['issue' => $event->issue->fingerprint], 'errors'),
        );
    }

    protected function alert(string $name, AlertTriggered|AlertResolved $event): void
    {
        $rule = $event->rule;
        $value = round($event->value, 2);

        $this->webhooks->send(
            $name,
            $name === 'alert.triggered'
                ? "Alert: {$rule->name} is {$value}, {$rule->comparison} {$rule->threshold}"
                : "Recovered: {$rule->name} is back at {$value}",
            [
                'rule' => [
                    'name' => $rule->name,
                    'metric' => $rule->metric,
                    'threshold' => $rule->threshold,
                    'comparison' => $rule->comparison,
                    'window' => $rule->window,
                ],
                'value' => $event->value,
                ...($event instanceof AlertResolved ? ['peak' => $event->peak, 'duration_seconds' => $event->durationSeconds] : []),
                'fields' => [
                    'Metric' => $rule->metric,
                    'Threshold' => "{$rule->comparison} {$rule->threshold}",
                    'Window' => $rule->window,
                    ...($event instanceof AlertResolved ? ['Lasted' => CarbonImmutable::now()->subSeconds($event->durationSeconds)->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE, parts: 2)] : []),
                ],
            ],
            DashboardUrl::to([], 'overview'),
        );
    }

    protected function missed(ScheduledTaskMissed $event): void
    {
        $task = $event->task;
        $due = CarbonImmutable::createFromTimestamp($event->dueAt);

        $this->webhooks->send(
            'schedule.missed',
            "Missed: {$task->name} was due at {$due->toDateTimeString()} and has not started",
            [
                'task' => [
                    'name' => $task->name,
                    'expression' => $task->expression,
                    'timezone' => $task->timezone,
                    'last_started_at' => $task->last_started_at === null ? null : CarbonImmutable::createFromTimestamp((int) $task->last_started_at)->toIso8601String(),
                ],
                'due_at' => $due->toIso8601String(),
                'fields' => [
                    'Schedule' => $task->expression,
                    'Last started' => $task->last_started_at === null ? 'never' : CarbonImmutable::createFromTimestamp((int) $task->last_started_at)->diffForHumans(),
                ],
            ],
            DashboardUrl::to([], 'traces'),
        );
    }

    /**
     * What to call an issue in one line: the class for something thrown,
     * the name itself for a logged line or a slow execution.
     */
    protected static function title(stdClass $issue): string
    {
        return in_array($issue->kind ?? 'exception', ['exception', 'error'], true)
            ? class_basename((string) $issue->class)
            : (string) $issue->class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function issueData(stdClass $issue): array
    {
        $location = Location::relative($issue->file, $issue->line);

        return [
            'issue' => [
                'fingerprint' => $issue->fingerprint,
                'class' => $issue->class,
                'message' => $issue->message,
                'kind' => $issue->kind ?? null,
                'level' => $issue->level ?? null,
                'handled' => (bool) ($issue->handled ?? false),
                'location' => $location,
                'occurrences' => (int) $issue->occurrences,
                'first_seen_at' => CarbonImmutable::createFromTimestamp((int) $issue->first_seen_at)->toIso8601String(),
                'last_seen_at' => CarbonImmutable::createFromTimestamp((int) $issue->last_seen_at)->toIso8601String(),
            ],
            'fields' => [
                'Where' => $location,
                'Occurrences' => number_format((int) $issue->occurrences),
                'Kind' => ($issue->kind ?? null) === 'log' ? 'logged '.$issue->level : ($issue->kind ?? null),
            ],
        ];
    }
}
