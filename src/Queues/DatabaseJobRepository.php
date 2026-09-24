<?php

namespace Elazaroo\PulseBoosted\Queues;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Elazaroo\PulseBoosted\Pulse;
use Elazaroo\PulseBoosted\Queues\Contracts\JobRepository;
use Elazaroo\PulseBoosted\Recorders\Jobs;
use Elazaroo\PulseBoosted\Support\Like;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Stores one row per job in `pulse_boosted_jobs`.
 *
 * Pulse's own pipeline cannot hold this: an `Entry` is a timestamp, a type, a
 * key and a nullable integer, which has nowhere to put a payload, an exception
 * or a stack trace. So this keeps its own buffer and is flushed on the same
 * signals Pulse uses for its ingest.
 *
 * @internal
 */
class DatabaseJobRepository implements JobRepository
{
    /**
     * Pending writes, keyed by job UUID.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $buffer = [];

    /**
     * Pending attempts, in the order they finished.
     *
     * @var list<array<string, mixed>>
     */
    protected array $attemptBuffer = [];

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
     * Buffer a change to a job's record.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function record(string $uuid, array $attributes): void
    {
        $this->buffer[$uuid] = array_merge(
            $this->buffer[$uuid] ?? [],
            array_filter($attributes, fn ($value) => $value !== null),
        );
    }

    /**
     * Write everything buffered so far.
     */
    public function flush(): void
    {
        $this->flushAttempts();

        if ($this->buffer === []) {
            return;
        }

        $rows = $this->buffer;

        $this->buffer = [];

        // An upsert can only name one set of columns to update, so rows that
        // carry different sets are written in separate statements. In practice
        // there are very few distinct shapes: one per lifecycle stage.
        $this->pulse->ignore(fn () => collect($rows)
            ->map(function (array $attributes, string $uuid) {
                $row = ['uuid' => $uuid] + $attributes;

                // Normalise the key order so rows carrying the same columns
                // group together and every batched insert lines up.
                ksort($row);

                return $row;
            })
            ->groupBy(fn (array $row) => implode(',', array_keys($row)))
            ->each(function (Collection $group) {
                /** @var array<string, mixed> $first */
                $first = $group->first();

                $columns = array_values(array_diff(array_keys($first), ['uuid']));

                $this->table()->upsert($group->values()->all(), ['uuid'], $columns);
            }));
    }

    /**
     * Buffer one attempt at running a job.
     *
     * @param  array<string, mixed>  $attempt
     */
    public function recordAttempt(string $uuid, array $attempt): void
    {
        $this->attemptBuffer[] = ['uuid' => $uuid] + $attempt;
    }

    /**
     * Every recorded attempt at a job, first to last.
     *
     * @return Collection<int, object>
     */
    public function attempts(string $uuid): Collection
    {
        return $this->pulse->ignore(fn () => $this->attemptsTable()
            ->where('uuid', $uuid)
            ->orderBy('attempt')
            ->orderBy('id')
            ->limit(100)
            ->get());
    }

    /**
     * Write the buffered attempts.
     */
    protected function flushAttempts(): void
    {
        if ($this->attemptBuffer === []) {
            return;
        }

        $attempts = $this->attemptBuffer;

        $this->attemptBuffer = [];

        $columns = ['uuid', 'attempt', 'status', 'started_at', 'finished_at', 'duration_ms', 'exception_class', 'exception_message', 'trace_id'];

        $rows = array_map(fn (array $attempt) => array_combine(
            $columns,
            array_map(fn (string $column) => $attempt[$column] ?? null, $columns),
        ), $attempts);

        $this->pulse->ignore(function () use ($rows) {
            foreach (array_chunk($rows, 100) as $chunk) {
                $this->attemptsTable()->insert($chunk);
            }
        });
    }

    /**
     * Drop records older than the configured retention.
     */
    public function trim(): void
    {
        $keep = $this->config->get('pulse-boosted.recorders.'.Jobs::class.'.trim.keep') ?? '7 days';

        $before = CarbonImmutable::now()->subMilliseconds(
            (int) CarbonInterval::fromString($keep)->totalMilliseconds
        );

        $cutoff = $before->getTimestamp();

        $this->pulse->ignore(fn () => $this->table()
            ->where(fn (Builder $query) => $query
                // The common case, and the one that uses the index...
                ->where('queued_at', '<=', $cutoff)
                // ...and jobs that were already on the queue before recording
                // started, which have no queued_at to go on.
                ->orWhere(fn (Builder $query) => $query
                    ->whereNull('queued_at')
                    ->where('finished_at', '<=', $cutoff)))
            ->delete());

        $this->pulse->ignore(fn () => $this->attemptsTable()->where('finished_at', '<=', $cutoff)->delete());
    }

    /**
     * Drop every record.
     */
    public function purge(): void
    {
        // Not truncate: that is DDL, and MySQL commits implicitly on DDL,
        // which would end any transaction this is called inside.
        $this->pulse->ignore(function () {
            $this->attemptsTable()->delete();
            $this->table()->delete();
        });
    }

    /**
     * Retrieve a single job by its UUID.
     */
    public function find(string $uuid): ?object
    {
        /** @var object|null $row */
        $row = $this->pulse->ignore(fn () => $this->table()->where('uuid', $uuid)->first());

        return $row; // @phpstan-ignore return.type
    }

    /**
     * Retrieve recorded jobs, newest first.
     *
     * @param  array<string, string|null>  $filters
     * @return Collection<int, object>
     *
     * @phpstan-return Collection<int, mixed>
     */
    public function jobs(array $filters = [], int $limit = 50, int $offset = 0): Collection
    {
        return $this->pulse->ignore(fn () => $this->filtered($filters)
            ->orderByDesc('id')
            ->offset($offset)
            ->limit($limit)
            ->get());
    }

    /**
     * Count recorded jobs matching the filters.
     *
     * @param  array<string, string|null>  $filters
     */
    public function count(array $filters = []): int
    {
        return $this->pulse->ignore(fn () => $this->filtered($filters)->count());
    }

    /**
     * Count recorded jobs per status.
     *
     * @param  array<string, string|null>  $filters
     * @return array<string, int>
     */
    public function countsByStatus(array $filters = []): array
    {
        $counts = $this->pulse->ignore(fn () => $this->filtered(array_diff_key($filters, ['status' => null]))
            ->groupBy('status')
            ->selectRaw('status, count(*) as aggregate')
            ->pluck('aggregate', 'status')
            ->all());

        return collect(JobStatus::cases())
            ->mapWithKeys(fn (JobStatus $status) => [
                $status->value => (int) ($counts[$status->value] ?? 0),
            ])
            ->all();
    }

    /**
     * The distinct connection and queue pairs that have been recorded.
     *
     * @return Collection<int, object>
     */
    public function recordedQueues(): Collection
    {
        return $this->pulse->ignore(fn () => $this->table()
            ->distinct()
            ->select('connection', 'queue')
            ->orderBy('connection')
            ->orderBy('queue')
            ->get());
    }

    /**
     * Apply the explorer's filters to a query.
     *
     * @param  array<string, string|null>  $filters
     */
    protected function filtered(array $filters): Builder
    {
        $query = $this->table();

        foreach (['status', 'connection', 'queue', 'class', 'batch_id'] as $column) {
            if (($value = $filters[$column] ?? null) !== null && $value !== '') {
                $query->where($column, $value);
            }
        }

        if (($after = $filters['finished_after'] ?? null) !== null && $after !== '') {
            $query->where('finished_at', '>=', (int) $after);
        }

        if (($search = $filters['search'] ?? null) !== null && $search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search);

            $query->where(fn (Builder $query) => $query
                ->where('name', Like::operator($query), "%{$escaped}%")
                ->orWhere('uuid', Like::operator($query), "%{$escaped}%")
                ->orWhere('exception_class', Like::operator($query), "%{$escaped}%"));
        }

        return $query;
    }

    /**
     * A query builder for the jobs table.
     */
    /**
     * The attempts table.
     */
    protected function attemptsTable(): Builder
    {
        return $this->connection()->table('pulse_boosted_job_attempts');
    }

    protected function table(): Builder
    {
        return $this->connection()->table('pulse_boosted_jobs');
    }

    /**
     * Resolve the database connection, which is the one Pulse Boosted already
     * stores its aggregates on.
     */
    protected function connection(): Connection
    {
        return $this->db->connection(
            $this->config->get('pulse-boosted.storage.database.connection')
        );
    }
}
