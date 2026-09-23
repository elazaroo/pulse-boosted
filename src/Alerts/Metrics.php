<?php

namespace Elazaroo\PulseBoosted\Alerts;

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\Pulse;
use Elazaroo\PulseBoosted\Queues\InspectorManager;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/**
 * Reads the numbers the alert rules are about.
 *
 * Everything here is answered from what is already being recorded, so adding a
 * rule costs one query a minute and nothing at request time.
 *
 * @internal
 */
class Metrics
{
    /**
     * The metrics a rule may name, and what each one means.
     *
     * @var array<string, string>
     */
    public const AVAILABLE = [
        'exceptions' => 'Exceptions thrown in the window',
        'new_issues' => 'Issues seen for the first time in the window',
        'failed_jobs' => 'Jobs that failed in the window',
        'queue_size' => 'Jobs waiting on a queue right now',
        'error_rate' => 'Percentage of traced executions that failed',
        'p95_duration' => 'Milliseconds the slowest 5% of executions exceeded',
        'slow_executions' => 'Executions slower than the given milliseconds',
    ];

    /**
     * Create a new reader.
     */
    public function __construct(
        protected Pulse $pulse,
        protected DatabaseManager $db,
        protected Repository $config,
        protected InspectorManager $inspectors,
    ) {
        //
    }

    /**
     * Read the metric a rule is about, or null when it cannot be read.
     */
    public function read(AlertRule $rule): ?float
    {
        if (! array_key_exists($rule->metric, self::AVAILABLE)) {
            throw new InvalidArgumentException("Alert [{$rule->name}] names an unknown metric [{$rule->metric}].");
        }

        $since = CarbonImmutable::now()->sub($rule->interval())->getTimestamp();

        return $this->pulse->ignore(fn () => match ($rule->metric) {
            'exceptions' => $this->exceptions($since),
            'new_issues' => $this->newIssues($since),
            'failed_jobs' => $this->failedJobs($since, $rule),
            'queue_size' => $this->queueSize($rule),
            'error_rate' => $this->errorRate($since, $rule),
            'p95_duration' => $this->p95Duration($since, $rule),
            'slow_executions' => $this->slowExecutions($since, $rule),
        });
    }

    /**
     * Exceptions are never sampled, so this is a true count rather than an
     * estimate scaled up from a sample.
     */
    protected function exceptions(int $since): float
    {
        return (float) $this->connection()
            ->table('pulse_boosted_issue_occurrences')
            ->where('occurred_at', '>=', $since)
            ->count();
    }

    /**
     * Bugs nobody had seen before. A spike here is a deploy that went wrong.
     */
    protected function newIssues(int $since): float
    {
        return (float) $this->connection()
            ->table('pulse_boosted_issues')
            ->where('first_seen_at', '>=', $since)
            ->count();
    }

    protected function failedJobs(int $since, AlertRule $rule): float
    {
        $query = $this->connection()
            ->table('pulse_boosted_jobs')
            ->where('status', 'failed')
            ->where('finished_at', '>=', $since);

        if (($queue = $rule->option('queue')) !== null) {
            $query->where('queue', $queue);
        }

        return (float) $query->count();
    }

    /**
     * How deep a queue is right now, straight from the driver.
     *
     * One connection only — the default unless the rule names another. Adding
     * every configured connection together would double count, because some
     * of them are the same backend under a second name: a failover or deferred
     * connection wrapping the default one counts the same jobs twice. A second
     * connection worth watching is worth a rule of its own.
     *
     * With no 'queue' this is every queue on that connection added together.
     */
    protected function queueSize(AlertRule $rule): ?float
    {
        if (! $this->inspectors->enabled()) {
            return null;
        }

        $connection = (string) ($rule->option('connection') ?? $this->config->get('queue.default'));

        $inspector = $this->inspectors->for($connection);

        if (! $inspector->capabilities()->counts) {
            return null;
        }

        $queues = ($wanted = $rule->option('queue')) === null
            ? $inspector->queues()
            : collect([(string) $wanted]);

        // One round trip for the connection rather than three counts per
        // queue, the same as the Queue Status card.
        $counts = $inspector->allCounts($queues);

        $totals = collect();

        foreach ($queues as $queue) {
            $total = ($counts->get($queue) ?? $inspector->counts($queue))->total();

            if ($total !== null) {
                $totals->push($total);
            }
        }

        // A driver that cannot count its own queue is an unknown, not a zero,
        // and a rule never trips on an unknown.
        return $totals->isEmpty() ? null : (float) $totals->sum();
    }

    /**
     * The share of traced executions that failed, as a percentage.
     *
     * Traces are sampled, but a ratio of two numbers from the same sample is
     * still the right ratio.
     */
    protected function errorRate(int $since, AlertRule $rule): ?float
    {
        $rows = $this->executions($since, $rule)->get(['status']);

        if ($rows->isEmpty()) {
            return null;
        }

        return $rows->where('status', 'failed')->count() / $rows->count() * 100;
    }

    /**
     * What the slowest 5% of executions exceeded.
     */
    protected function p95Duration(int $since, AlertRule $rule): ?float
    {
        $durations = $this->executions($since, $rule)
            ->whereNotNull('duration_ms')
            ->orderBy('duration_ms')
            ->pluck('duration_ms')
            ->map(fn ($value) => (int) $value);

        if ($durations->isEmpty()) {
            return null;
        }

        // Nearest-rank, the same as the summary cards, so a rule set from
        // what a card shows means what it looked like it meant.
        $index = (int) ceil(0.95 * $durations->count()) - 1;

        return (float) $durations->get(max(0, $index));
    }

    protected function slowExecutions(int $since, AlertRule $rule): float
    {
        return (float) $this->executions($since, $rule)
            ->where('duration_ms', '>=', (int) $rule->option('slower_than', 1000))
            ->count();
    }

    /**
     * The traces a rule is about, narrowed to one kind if it says so.
     */
    protected function executions(int $since, AlertRule $rule): Builder
    {
        $query = $this->connection()
            ->table('pulse_boosted_traces')
            ->where('started_at', '>=', $since);

        if (($type = $rule->option('type')) !== null) {
            $query->where('type', $type);
        }

        return $query;
    }

    /**
     * The connection the rest of Pulse Boosted stores on.
     */
    protected function connection(): Connection
    {
        return $this->db->connection(
            $this->config->get('pulse-boosted.storage.database.connection')
        );
    }
}
