<?php

namespace Elazaroo\PulseBoosted\Settings;

use Elazaroo\PulseBoosted\Alerts\Metrics;
use Elazaroo\PulseBoosted\Notify\Webhooks;
use Elazaroo\PulseBoosted\Recorders;
use Elazaroo\PulseBoosted\Recorders\Issues;

/**
 * What can be changed from the settings page, and how each one is edited.
 *
 * Each field names the config key it overrides, so everything that reads
 * config keeps working unchanged: the page only decides what config says.
 *
 * @phpstan-type Field array{
 *     key: string,
 *     group: string,
 *     label: string,
 *     help?: string,
 *     type: 'bool'|'emails'|'urls'|'ids'|'select'|'secret'|'secrets'|'rate'|'milliseconds'|'text'|'events'|'thresholds'|'rules',
 *     options?: array<string, string>,
 * }
 *
 * @internal
 */
class Schema
{
    /**
     * The groups, in the order the page shows them.
     */
    public const GROUPS = [
        'email' => ['Email', 'Who hears about issues, and which ones.'],
        'webhooks' => ['Webhooks', 'Slack, and anything else that takes a webhook.'],
        'issues' => ['Issues', 'What becomes an issue, and what happens to quiet ones.'],
        'thresholds' => ['Thresholds', 'How slow is too slow.'],
        'traces' => ['Traces', 'How much is recorded, and what is always kept.'],
        'alerts' => ['Alerts', 'Rules checked every minute.'],
        'schedule' => ['Scheduled tasks', 'Noticing tasks that did not run.'],
    ];

    /**
     * Every field, in the order the page shows them.
     *
     * @return list<Field>
     */
    public static function fields(): array
    {
        $levels = array_combine(Issues::LEVELS, Issues::LEVELS);

        return [
            ['key' => 'issues.notify.mail', 'group' => 'email', 'type' => 'emails', 'label' => 'Recipients', 'help' => 'Emailed when an issue first happens, and when a resolved one comes back.'],
            ['key' => 'issues.notify.regressions', 'group' => 'email', 'type' => 'bool', 'label' => 'Email regressions', 'help' => 'An issue marked resolved that happens again.'],
            ['key' => 'issues.notify.assignee', 'group' => 'email', 'type' => 'bool', 'label' => 'Email the assignee', 'help' => 'Whoever an issue is assigned to hears about it coming back, on top of the recipients.'],
            ['key' => 'issues.notify.log_level', 'group' => 'email', 'type' => 'select', 'options' => $levels, 'label' => 'Least severe logged issue to send', 'help' => 'Issues made from log lines are only emailed, and sent as webhooks, at this level or above.'],

            ['key' => 'webhooks.slack', 'group' => 'webhooks', 'type' => 'secrets', 'label' => 'Slack incoming webhooks', 'help' => 'One per line. The URL is the credential, so it is stored encrypted and never shown again in full.'],
            ['key' => 'webhooks.urls', 'group' => 'webhooks', 'type' => 'urls', 'label' => 'Other webhooks', 'help' => 'One per line. Each gets the event as JSON.'],
            ['key' => 'webhooks.secret', 'group' => 'webhooks', 'type' => 'secret', 'label' => 'Signing secret', 'help' => 'Webhooks are signed with it in X-Pulse-Boosted-Signature, as sha256= and the HMAC of the body.'],
            ['key' => 'webhooks.events', 'group' => 'webhooks', 'type' => 'events', 'label' => 'Events to send'],
            ['key' => 'webhooks.queue', 'group' => 'webhooks', 'type' => 'text', 'label' => 'Queue connection', 'help' => 'Empty sends straight away. A connection name sends from a worker, retried when the receiver is down.'],

            ['key' => 'issues.log_level', 'group' => 'issues', 'type' => 'select', 'options' => ['none' => 'none — log lines never become issues'] + $levels, 'label' => 'Least severe log line that opens an issue'],
            ['key' => 'issues.auto_resolve_after', 'group' => 'issues', 'type' => 'select', 'options' => ['' => 'Never', '1 day' => 'After 1 day', '3 days' => 'After 3 days', '7 days' => 'After 7 days', '14 days' => 'After 14 days', '30 days' => 'After 30 days'], 'label' => 'Resolve issues nobody has seen', 'help' => 'One that was not really fixed reopens the next time it happens.'],
            ['key' => 'issues.assignees', 'group' => 'issues', 'type' => 'ids', 'label' => 'People to assign issues to', 'help' => 'User ids, one per line, offered when assigning.'],

            ['key' => 'issues.thresholds', 'group' => 'thresholds', 'type' => 'thresholds', 'label' => 'Slow enough to be an issue', 'help' => 'An execution slower than its threshold opens an issue and its trace is kept. A name is exact, * is everything of that kind, and a pattern like #^GET /api/# is a regular expression. The first that matches wins.'],
            ['key' => 'recorders.'.Recorders\SlowRequests::class.'.threshold', 'group' => 'thresholds', 'type' => 'milliseconds', 'label' => 'Slow request', 'help' => 'For the Slow Requests card.'],
            ['key' => 'recorders.'.Recorders\SlowQueries::class.'.threshold', 'group' => 'thresholds', 'type' => 'milliseconds', 'label' => 'Slow query', 'help' => 'For the Slow Queries card.'],
            ['key' => 'recorders.'.Recorders\SlowJobs::class.'.threshold', 'group' => 'thresholds', 'type' => 'milliseconds', 'label' => 'Slow job', 'help' => 'For the Slow Jobs card.'],
            ['key' => 'recorders.'.Recorders\SlowOutgoingRequests::class.'.threshold', 'group' => 'thresholds', 'type' => 'milliseconds', 'label' => 'Slow outgoing request', 'help' => 'For the Slow Outgoing Requests card.'],
            ['key' => 'traces.keep.slower_than', 'group' => 'thresholds', 'type' => 'milliseconds', 'label' => 'Keep traces slower than', 'help' => 'Kept whatever the sampling draw said. Empty keeps none for being slow.'],

            ['key' => 'traces.sample_rate', 'group' => 'traces', 'type' => 'rate', 'label' => 'Sample rate', 'help' => 'The share of executions recorded when a kind has no rate of its own.'],
            ['key' => 'traces.sample_rates.request', 'group' => 'traces', 'type' => 'rate', 'label' => 'Requests'],
            ['key' => 'traces.sample_rates.job', 'group' => 'traces', 'type' => 'rate', 'label' => 'Jobs'],
            ['key' => 'traces.sample_rates.command', 'group' => 'traces', 'type' => 'rate', 'label' => 'Commands'],
            ['key' => 'traces.sample_rates.schedule', 'group' => 'traces', 'type' => 'rate', 'label' => 'Scheduled tasks'],
            ['key' => 'traces.keep.failed', 'group' => 'traces', 'type' => 'bool', 'label' => 'Always keep failures'],
            ['key' => 'traces.keep.exceptions', 'group' => 'traces', 'type' => 'bool', 'label' => 'Always keep executions that threw'],
            ['key' => 'traces.request.capture_payload', 'group' => 'traces', 'type' => 'bool', 'label' => 'Capture the body of failed requests', 'help' => 'Only for requests that ended in a 5xx, with passwords and the like redacted.'],
            ['key' => 'traces.log_level', 'group' => 'traces', 'type' => 'select', 'options' => $levels, 'label' => 'Least severe log line recorded'],

            ['key' => 'alerts.enabled', 'group' => 'alerts', 'type' => 'bool', 'label' => 'Check alert rules'],
            ['key' => 'alerts.rules', 'group' => 'alerts', 'type' => 'rules', 'label' => 'Rules', 'help' => 'Each is a metric, a threshold and a window. Options narrow a metric down: type=request, queue=default, ms=2000.'],

            ['key' => 'schedule.monitor', 'group' => 'schedule', 'type' => 'bool', 'label' => 'Watch the schedule', 'help' => 'Each schedule:run writes the schedule down, so a task that should have started and did not is shown as missed.'],
        ];
    }

    /**
     * The fields in one group.
     *
     * @return list<Field>
     */
    public static function group(string $group): array
    {
        return array_values(array_filter(self::fields(), fn (array $field) => $field['group'] === $group));
    }

    /**
     * @return Field|null
     */
    public static function field(string $key): ?array
    {
        foreach (self::fields() as $field) {
            if ($field['key'] === $key) {
                return $field;
            }
        }

        return null;
    }

    /**
     * A key safe to use as a form field name: config keys have dots, and
     * recorder keys backslashes.
     */
    public static function id(string $key): string
    {
        return 'f'.substr(md5($key), 0, 10);
    }

    /**
     * Kinds of execution a threshold can be set for.
     *
     * @return array<string, string>
     */
    public static function thresholdTypes(): array
    {
        return ['request' => 'Request', 'job' => 'Job', 'command' => 'Command', 'schedule' => 'Scheduled task'];
    }

    /**
     * @return array<string, string>
     */
    public static function metrics(): array
    {
        return Metrics::AVAILABLE;
    }

    /**
     * @return list<string>
     */
    public static function events(): array
    {
        return Webhooks::EVENTS;
    }

    /**
     * Whether a field's value is kept encrypted.
     *
     * @param  Field  $field
     */
    public static function secret(array $field): bool
    {
        return in_array($field['type'], ['secret', 'secrets'], true);
    }
}
