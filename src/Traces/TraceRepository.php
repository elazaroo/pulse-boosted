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

            $query->where('name', 'like', "%{$escaped}%");
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
