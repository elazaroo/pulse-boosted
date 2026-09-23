<?php

namespace Elazaroo\PulseBoosted\Traces;

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\Pulse;
use Elazaroo\PulseBoosted\Support\Location;
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
     * Every query the sampled executions ran, grouped by what it is.
     *
     * Pulse's own card lists the slow ones; this lists all of them, because
     * the query that costs the most is usually a fast one run ten thousand
     * times, not a slow one run once.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function queryGroups(string $search = '', string $orderBy = 'total', int $sample = 5000, int $limit = 100): Collection
    {
        $rows = $this->pulse->ignore(fn () => $this->connection()
            ->table('pulse_boosted_trace_events as e')
            ->join('pulse_boosted_traces as t', 't.trace_id', '=', 'e.trace_id')
            ->where('e.type', 'query')
            // Sampled executions only, so the counts are a fair sample rather
            // than one weighted towards the failures kept regardless.
            ->where('t.sampled', true)
            ->orderByDesc('e.id')
            ->limit($sample)
            ->get(['e.label', 'e.duration_ms', 'e.meta', 'e.trace_id']));

        $groups = [];

        foreach ($rows as $row) {
            $meta = json_decode((string) ($row->meta ?? ''), true);
            $meta = is_array($meta) ? $meta : [];

            $sql = QueryOrigin::normalize((string) $row->label);
            $connection = (string) ($meta['connection'] ?? '');
            $key = $connection.'|'.$sql;

            $groups[$key] ??= [
                'sql' => $sql,
                'connection' => $connection,
                'durations' => [],
                'locations' => [],
                'slowestMs' => -1,
                'slowestTrace' => null,
            ];

            $duration = (int) ($row->duration_ms ?? 0);

            $groups[$key]['durations'][] = $duration;

            if (isset($meta['file'])) {
                $location = $meta['file'].':'.($meta['line'] ?? '?');
                $groups[$key]['locations'][$location] = ($groups[$key]['locations'][$location] ?? 0) + 1;
            }

            if ($duration > $groups[$key]['slowestMs']) {
                $groups[$key]['slowestMs'] = $duration;
                $groups[$key]['slowestTrace'] = $row->trace_id;
            }
        }

        return collect($groups)
            ->when($search !== '', fn (Collection $groups) => $groups->filter(fn (array $group) => stripos($group['sql'], $search) !== false))
            ->map(function (array $group) {
                $durations = collect($group['durations'])->sort()->values();

                arsort($group['locations']);

                return [
                    'key' => self::queryKey($group['connection'], $group['sql']),
                    'sql' => $group['sql'],
                    'connection' => $group['connection'],
                    'calls' => $durations->count(),
                    'total' => (int) $durations->sum(),
                    'avg' => (int) round((float) $durations->avg()),
                    'p95' => $this->percentile($durations, 95),
                    'max' => (int) $durations->max(),
                    'location' => array_key_first($group['locations']),
                    'locations' => count($group['locations']),
                    'traceId' => $group['slowestTrace'],
                ];
            })
            ->sortByDesc(fn (array $group) => $group[in_array($orderBy, ['calls', 'avg', 'p95', 'total'], true) ? $orderBy : 'total'])
            ->take($limit)
            ->values();
    }

    /**
     * Requests grouped by route, with how they answered and how long they took.
     *
     * Grouped by the route's pattern rather than the URL, so /orders/41 and
     * /orders/42 are one row: the route is the thing that has a performance.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function routeGroups(string $search = '', string $orderBy = 'calls', int $sample = 5000): Collection
    {
        $rows = $this->pulse->ignore(fn () => $this->table()
            ->where('type', 'request')
            ->where('sampled', true)
            ->orderByDesc('id')
            ->limit($sample)
            ->get(['trace_id', 'name', 'duration_ms', 'status', 'meta']));

        return $rows
            ->groupBy('name')
            ->map(function (Collection $group, string $name) {
                $statuses = $group->map(function (object $row) {
                    $meta = json_decode((string) ($row->meta ?? ''), true);

                    return (int) (is_array($meta) ? ($meta['status'] ?? 0) : 0);
                });

                $durations = $group->pluck('duration_ms')->filter(fn ($value) => $value !== null)->map(fn ($value) => (int) $value)->sort()->values();

                [$method, $path] = array_pad(explode(' ', $name, 2), 2, '');

                $slowest = $group->sortByDesc(fn (object $row) => (int) ($row->duration_ms ?? 0))->first();

                return [
                    'name' => $name,
                    'method' => $method,
                    'path' => $path,
                    'calls' => $group->count(),
                    'ok' => $statuses->filter(fn (int $status) => $status > 0 && $status < 400)->count(),
                    'client' => $statuses->filter(fn (int $status) => $status >= 400 && $status < 500)->count(),
                    'server' => $statuses->filter(fn (int $status) => $status >= 500)->count(),
                    'avg' => $durations->isEmpty() ? null : (int) round((float) $durations->avg()),
                    'p95' => $this->percentile($durations, 95),
                    'traceId' => $slowest?->trace_id,
                ];
            })
            ->when($search !== '', fn (Collection $groups) => $groups->filter(fn (array $group) => stripos($group['name'], $search) !== false))
            ->sortByDesc(fn (array $group) => $group[in_array($orderBy, ['calls', 'avg', 'p95', 'server'], true) ? $orderBy : 'calls'] ?? 0)
            ->values();
    }

    /**
     * What identifies a group of queries: where it ran, and what it is with
     * the values taken out.
     */
    public static function queryKey(string $connection, string $sql): string
    {
        return md5($connection.'|'.$sql);
    }

    /**
     * Everything about one route: how it answered, how long it took, how
     * that changed over the window, and the requests worth opening.
     *
     * @return array<string, mixed>|null
     */
    public function routeDetail(string $name, int $since, int $buckets = 24, int $sample = 5000): ?array
    {
        $rows = $this->pulse->ignore(fn () => $this->table()
            ->where('type', 'request')
            ->where('name', $name)
            ->where('started_at', '>=', $since)
            ->orderByDesc('id')
            ->limit($sample)
            ->get(['trace_id', 'duration_ms', 'status', 'meta', 'started_at', 'user_id', 'sampled']));

        if ($rows->isEmpty()) {
            return null;
        }

        $rows = $rows->map(function (object $row) {
            $meta = json_decode((string) ($row->meta ?? ''), true);
            $row->code = (int) (is_array($meta) ? ($meta['status'] ?? 0) : 0);

            return $row;
        });

        // Figures from the fair sample only, like the list; the requests kept
        // for failing or being slow are still offered to open below.
        $sampled = $rows->filter(fn (\stdClass $row) => (bool) $row->sampled);
        $durations = $sampled->pluck('duration_ms')->filter(fn ($value) => $value !== null)->map(fn ($value) => (int) $value)->sort()->values();

        [$method, $path] = array_pad(explode(' ', $name, 2), 2, '');

        return [
            'name' => $name,
            'method' => $method,
            'path' => $path,
            'calls' => $sampled->count(),
            'avg' => $durations->isEmpty() ? null : (int) round((float) $durations->avg()),
            'p50' => $this->percentile($durations, 50),
            'p95' => $this->percentile($durations, 95),
            'p99' => $this->percentile($durations, 99),
            'max' => $durations->max(),
            'codes' => $sampled
                ->groupBy(fn (\stdClass $row) => $row->code === 0 ? 'unknown' : (string) $row->code)
                ->map(fn (Collection $group) => $group->count())
                ->sortKeys()
                ->all(),
            'users' => $sampled->pluck('user_id')->filter()->unique()->count(),
            'timeline' => $this->timeline($sampled, $since, $buckets, fn (\stdClass $row) => (int) $row->started_at, fn (Collection $group) => [
                'count' => $group->count(),
                'errors' => $group->filter(fn (\stdClass $row) => $row->code >= 500)->count(),
                'p95' => $this->percentile($group->pluck('duration_ms')->filter(fn ($v) => $v !== null)->map(fn ($v) => (int) $v)->sort()->values(), 95),
            ]),
            'slowest' => $rows->sortByDesc(fn (\stdClass $row) => (int) ($row->duration_ms ?? 0))->take(10)->values(),
            'failures' => $rows->filter(fn (\stdClass $row) => $row->code >= 500 || $row->status === 'failed')->take(10)->values(),
        ];
    }

    /**
     * Everything about one kind of query: how often and how long, where it is
     * run from, the executions that ran it most — an N+1 shows up as one
     * request running it forty times — and the slowest runs.
     *
     * @return array<string, mixed>|null
     */
    public function queryDetail(string $key, int $since, int $buckets = 24, int $sample = 20000): ?array
    {
        $rows = $this->pulse->ignore(fn () => $this->connection()
            ->table('pulse_boosted_trace_events as e')
            ->join('pulse_boosted_traces as t', 't.trace_id', '=', 'e.trace_id')
            ->where('e.type', 'query')
            ->where('t.sampled', true)
            ->where('t.started_at', '>=', $since)
            ->orderByDesc('e.id')
            ->limit($sample)
            ->get(['e.label', 'e.duration_ms', 'e.meta', 'e.offset_ms', 'e.trace_id', 't.name as execution', 't.type as execution_type', 't.started_at']));

        $matching = $rows
            ->map(function (object $row) {
                $meta = json_decode((string) ($row->meta ?? ''), true);
                $row->meta = is_array($meta) ? $meta : [];
                $row->sql = QueryOrigin::normalize((string) $row->label);
                $row->connection = (string) ($row->meta['connection'] ?? '');

                return $row;
            })
            ->filter(fn (\stdClass $row) => self::queryKey($row->connection, $row->sql) === $key)
            ->values();

        if ($matching->isEmpty()) {
            return null;
        }

        $durations = $matching->pluck('duration_ms')->map(fn ($value) => (int) ($value ?? 0))->sort()->values();

        return [
            'key' => $key,
            'sql' => $matching->first()->sql,
            'connection' => $matching->first()->connection,
            'calls' => $matching->count(),
            'total' => (int) $durations->sum(),
            'avg' => (int) round((float) $durations->avg()),
            'p95' => $this->percentile($durations, 95),
            'max' => (int) $durations->max(),
            'executions' => $matching->pluck('trace_id')->unique()->count(),
            'locations' => $matching
                ->filter(fn (\stdClass $row) => isset($row->meta['file']))
                ->groupBy(fn (\stdClass $row) => Location::relative($row->meta['file'], isset($row->meta['line']) ? (int) $row->meta['line'] : null) ?? $row->meta['file'])
                ->map(fn (Collection $group, mixed $location) => ['location' => is_scalar($location) ? (string) $location : '', 'calls' => $group->count(), 'total' => (int) $group->sum('duration_ms')])
                ->sortByDesc('calls')
                ->values()
                ->all(),
            'timeline' => $this->timeline($matching, $since, $buckets, fn (\stdClass $row) => (int) $row->started_at + intdiv((int) $row->offset_ms, 1000), fn (Collection $group) => [
                'count' => $group->count(),
                'errors' => 0,
                'p95' => $this->percentile($group->pluck('duration_ms')->map(fn ($v) => (int) ($v ?? 0))->sort()->values(), 95),
            ]),
            'perExecution' => $matching
                ->groupBy('trace_id')
                ->map(fn (Collection $group, string $traceId) => [
                    'traceId' => $traceId,
                    'execution' => $group->firstOrFail()->execution,
                    'type' => $group->firstOrFail()->execution_type,
                    'times' => $group->count(),
                    'total' => (int) $group->sum('duration_ms'),
                    'at' => (int) $group->firstOrFail()->started_at,
                ])
                ->sortByDesc('times')
                ->take(10)
                ->values()
                ->all(),
            'slowest' => $matching->sortByDesc(fn (\stdClass $row) => (int) ($row->duration_ms ?? 0))->take(10)->map(fn (\stdClass $row) => [
                'traceId' => $row->trace_id,
                'execution' => $row->execution,
                'label' => (string) $row->label,
                'durationMs' => (int) ($row->duration_ms ?? 0),
                'at' => (int) $row->started_at,
            ])->values()->all(),
        ];
    }

    /**
     * Rows split into equal slices of the window, oldest first.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @param  callable(\stdClass): int  $at
     * @param  callable(Collection<int, \stdClass>): array<string, mixed>  $summarise
     * @return list<array<string, mixed>>
     */
    protected function timeline(Collection $rows, int $since, int $buckets, callable $at, callable $summarise): array
    {
        $now = CarbonImmutable::now()->getTimestamp();
        $width = max(1, (int) ceil(($now - $since) / $buckets));

        $grouped = $rows->groupBy(fn (\stdClass $row) => min($buckets - 1, max(0, intdiv($at($row) - $since, $width))));

        $timeline = [];

        for ($i = 0; $i < $buckets; $i++) {
            $group = $grouped->get($i, collect());

            $timeline[] = [
                'start' => $since + $i * $width,
                ...$summarise($group),
            ];
        }

        return $timeline;
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

        foreach (['type', 'status', 'user' => 'user_id'] as $filter => $column) {
            $filter = is_int($filter) ? $column : $filter;

            if (($value = $filters[$filter] ?? null) !== null && $value !== '') {
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
