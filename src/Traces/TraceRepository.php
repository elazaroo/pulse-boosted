<?php

namespace Elazaroo\PulseBoosted\Traces;

use Elazaroo\PulseBoosted\Pulse;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Reads back what the tracer wrote.
 *
 * @phpstan-type TraceRow object{
 *     id: int,
 *     trace_id: string,
 *     parent_trace_id: ?string,
 *     type: string,
 *     name: string,
 *     started_at: int,
 *     duration_ms: ?int,
 *     status: string,
 *     user_id: ?string,
 *     meta: ?string
 * }
 * @phpstan-type TraceEventRow object{
 *     id: int,
 *     trace_id: string,
 *     type: string,
 *     label: string,
 *     offset_ms: int,
 *     duration_ms: ?int,
 *     level: ?string,
 *     meta: ?string
 * }
 *
 * @internal
 */
class TraceRepository
{
    /**
     * Create a new repository instance.
     */
    public function __construct(
        protected Pulse $pulse,
        protected DatabaseManager $db,
        protected Repository $config,
    ) {
        //
    }

    /**
     * Recent traces, slowest or newest first.
     *
     * @param  array<string, string|null>  $filters
     * @return Collection<int, TraceRow>
     */
    public function recent(array $filters = [], string $orderBy = 'latest', int $limit = 20, int $offset = 0): Collection
    {
        return $this->pulse->ignore(fn () => $this->filtered($filters)
            ->when(
                $orderBy === 'slowest',
                fn (Builder $query) => $query->orderByDesc('duration_ms'),
                fn (Builder $query) => $query->orderByDesc('id'),
            )
            ->offset($offset)
            ->limit($limit)
            ->get());
    }

    /**
     * How many traces match.
     *
     * @param  array<string, string|null>  $filters
     */
    public function count(array $filters = []): int
    {
        return $this->pulse->ignore(fn () => $this->filtered($filters)->count());
    }

    /**
     * One trace by id.
     *
     * @return TraceRow|null
     */
    public function find(string $traceId): ?object
    {
        return $this->pulse->ignore(fn () => $this->table()->where('trace_id', $traceId)->first());
    }

    /**
     * The trace a job belongs to, found through the uuid stored in its meta.
     *
     * @return TraceRow|null
     */
    public function forJob(string $uuid): ?object
    {
        return $this->pulse->ignore(fn () => $this->table()
            ->where('type', 'job')
            ->where('meta', 'like', '%"'.$uuid.'"%')
            ->orderByDesc('id')
            ->first());
    }

    /**
     * Everything that happened inside a trace, in order.
     *
     * @return Collection<int, TraceEventRow>
     */
    public function events(string $traceId, int $limit = 500): Collection
    {
        return $this->pulse->ignore(fn () => $this->connection()
            ->table('pulse_boosted_trace_events')
            ->where('trace_id', $traceId)
            ->orderBy('offset_ms')
            ->orderBy('id')
            ->limit($limit)
            ->get());
    }

    /**
     * Traces queued by this one.
     *
     * @return Collection<int, TraceRow>
     */
    public function children(string $traceId): Collection
    {
        return $this->pulse->ignore(fn () => $this->table()
            ->where('parent_trace_id', $traceId)
            ->orderBy('started_at')
            ->limit(50)
            ->get());
    }

    /**
     * How many of each type there are, for the tabs.
     *
     * @param  array<string, string|null>  $filters
     * @return array<string, int>
     */
    public function countsByType(array $filters = []): array
    {
        $counts = $this->pulse->ignore(fn () => $this->filtered(array_diff_key($filters, ['type' => null]))
            ->groupBy('type')
            ->selectRaw('type, count(*) as aggregate')
            ->pluck('aggregate', 'type')
            ->all());

        return collect(['request', 'job', 'command', 'schedule'])
            ->mapWithKeys(fn (string $type) => [$type => (int) ($counts[$type] ?? 0)])
            ->all();
    }

    /**
     * Events of one kind across every trace — log lines, sent mail, and so on.
     *
     * These are already captured as part of a trace, so a list of them costs
     * nothing extra to collect; it just asks the same rows a different way.
     *
     * @param  array<string, string|null>  $filters
     * @return Collection<int, object>
     */
    public function eventsOfType(string $type, array $filters = [], int $limit = 20, int $offset = 0): Collection
    {
        return $this->pulse->ignore(fn () => $this->eventsQuery($type, $filters)
            ->orderByDesc('pulse_boosted_trace_events.id')
            ->offset($offset)
            ->limit($limit)
            ->get());
    }

    /**
     * How many there are.
     *
     * @param  array<string, string|null>  $filters
     */
    public function countEventsOfType(string $type, array $filters = []): int
    {
        return $this->pulse->ignore(fn () => $this->eventsQuery($type, $filters)->count());
    }

    /**
     * Per-name totals for a kind of execution: how often, how long, and how
     * often it failed.
     *
     * The 95th percentile is worked out here rather than in SQL because the
     * function for it differs across every database this supports, and the
     * row counts involved are small — traces are sampled and kept a day.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function summaryByName(string $type, int $sample = 2000): Collection
    {
        // Sampled executions only, for the same reason as the alert metrics:
        // failures kept regardless of the draw would skew every figure.
        $rows = $this->pulse->ignore(fn () => $this->table()
            ->where('type', $type)
            ->where('sampled', true)
            ->orderByDesc('id')
            ->limit($sample)
            ->get(['trace_id', 'name', 'duration_ms', 'status', 'started_at']));

        return $rows
            ->groupBy('name')
            ->map(function (Collection $group, string $name) {
                $durations = $group
                    ->pluck('duration_ms')
                    ->filter(fn ($value) => $value !== null)
                    ->map(fn ($value) => (int) $value)
                    ->sort()
                    ->values();

                $average = $durations->avg();

                return [
                    'name' => $name,
                    // Rows come back newest first, so the head of the group is
                    // the most recent run — the one worth opening.
                    'traceId' => $group->first()?->trace_id,
                    'count' => $group->count(),
                    'failed' => $group->where('status', 'failed')->count(),
                    'avg' => $average === null ? null : (int) round($average),
                    'p95' => $this->percentile($durations, 95),
                    'max' => $durations->max(),
                    'lastAt' => $group->max('started_at'),
                ];
            })
            ->sortByDesc('count')
            ->values();
    }

    /**
     * The value below which the given share of a sorted set falls.
     *
     * @param  Collection<int, int>  $sorted
     */
    protected function percentile(Collection $sorted, int $percentile): ?int
    {
        if ($sorted->isEmpty()) {
            return null;
        }

        // Nearest-rank: with a handful of samples this is more honest than
        // interpolating between two of them.
        $index = (int) ceil($percentile / 100 * $sorted->count()) - 1;

        return (int) $sorted->get(max(0, $index));
    }

    /**
     * Events of one type, joined to the execution they belong to.
     *
     * @param  array<string, string|null>  $filters
     */
    protected function eventsQuery(string $type, array $filters): Builder
    {
        $query = $this->connection()
            ->table('pulse_boosted_trace_events')
            ->join('pulse_boosted_traces', 'pulse_boosted_traces.trace_id', '=', 'pulse_boosted_trace_events.trace_id')
            ->where('pulse_boosted_trace_events.type', $type)
            ->select([
                'pulse_boosted_trace_events.id',
                'pulse_boosted_trace_events.trace_id',
                'pulse_boosted_trace_events.label',
                'pulse_boosted_trace_events.level',
                'pulse_boosted_trace_events.meta',
                'pulse_boosted_traces.name as execution',
                'pulse_boosted_traces.type as execution_type',
                'pulse_boosted_traces.started_at',
            ]);

        if (($level = $filters['level'] ?? null) !== null && $level !== '') {
            $query->where('pulse_boosted_trace_events.level', $level);
        }

        if (($search = $filters['search'] ?? null) !== null && $search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search);

            $query->where('pulse_boosted_trace_events.label', 'like', "%{$escaped}%");
        }

        return $query;
    }

    /**
     * Apply the list's filters.
     *
     * @param  array<string, string|null>  $filters
     */
    protected function filtered(array $filters): Builder
    {
        $query = $this->table();

        foreach (['type', 'status'] as $column) {
            if (($value = $filters[$column] ?? null) !== null && $value !== '') {
                $query->where($column, $value);
            }
        }

        if (($search = $filters['search'] ?? null) !== null && $search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search);

            $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$escaped}%")
                // Attributes attached with PulseBoosted::context() live in the
                // meta column, and finding the one request that carried a
                // given order id is the whole point of having attached it.
                ->orWhere('meta', 'like', "%{$escaped}%"));
        }

        if (($slowerThan = $filters['slower_than'] ?? null) !== null && $slowerThan !== '') {
            $query->where('duration_ms', '>=', (int) $slowerThan);
        }

        return $query;
    }

    /**
     * A query builder for the traces table.
     */
    protected function table(): Builder
    {
        return $this->connection()->table('pulse_boosted_traces');
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
