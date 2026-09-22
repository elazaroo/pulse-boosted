<?php

namespace Elazaroo\PulseBoosted\Queues\Inspectors;

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\Queues\Capabilities;
use Elazaroo\PulseBoosted\Queues\Counts;
use Elazaroo\PulseBoosted\Queues\PendingJob;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reads the `jobs` table directly.
 *
 * This is the one driver where everything is visible, because the queue is
 * just rows. Counts are worked out here rather than deferred to the queue
 * contract so that they also work on Laravel 10 to 12.
 *
 * @internal
 */
class DatabaseInspector extends Inspector
{
    /**
     * What this inspector is able to report.
     */
    public function capabilities(): Capabilities
    {
        return Capabilities::all();
    }

    /**
     * The queue names that have rows in the jobs table.
     *
     * @return Collection<int, string>
     */
    public function queues(): Collection
    {
        try {
            $queues = $this->ignore(fn () => $this->table()->distinct()->orderBy('queue')->pluck('queue'));
        } catch (Throwable) {
            return parent::queues();
        }

        // A queue with nothing on it has no rows, so it would otherwise
        // disappear from the dashboard the moment it drained.
        return $queues
            ->push($this->defaultQueue())
            ->unique()
            ->values();
    }

    /**
     * How many jobs are waiting, by state.
     */
    public function counts(string $queue): Counts
    {
        try {
            $now = $this->now();

            return $this->ignore(fn () => new Counts(
                pending: (int) $this->table()->where('queue', $queue)->whereNull('reserved_at')->where('available_at', '<=', $now)->count(),
                delayed: (int) $this->table()->where('queue', $queue)->whereNull('reserved_at')->where('available_at', '>', $now)->count(),
                reserved: (int) $this->table()->where('queue', $queue)->whereNotNull('reserved_at')->count(),
            ));
        } catch (Throwable) {
            return new Counts;
        }
    }

    /**
     * @return Collection<int, PendingJob>
     */
    public function pending(string $queue, int $limit = 50, int $offset = 0): Collection
    {
        return $this->read(
            fn (Builder $query) => $query->whereNull('reserved_at')->where('available_at', '<=', $this->now()),
            $queue,
            $limit,
            $offset,
            'available_at',
        );
    }

    /**
     * @return Collection<int, PendingJob>
     */
    public function delayed(string $queue, int $limit = 50, int $offset = 0): Collection
    {
        return $this->read(
            fn (Builder $query) => $query->whereNull('reserved_at')->where('available_at', '>', $this->now()),
            $queue,
            $limit,
            $offset,
            'available_at',
        );
    }

    /**
     * @return Collection<int, PendingJob>
     */
    public function reserved(string $queue, int $limit = 50, int $offset = 0): Collection
    {
        return $this->read(
            fn (Builder $query) => $query->whereNotNull('reserved_at'),
            $queue,
            $limit,
            $offset,
            'reserved_at',
        );
    }

    /**
     * Run a listing query and turn the rows into jobs.
     *
     * @param  callable(Builder): Builder  $constrain
     * @return Collection<int, PendingJob>
     */
    protected function read(callable $constrain, string $queue, int $limit, int $offset, string $orderBy): Collection
    {
        try {
            $rows = $this->ignore(fn () => $constrain($this->table()->where('queue', $queue))
                ->orderBy($orderBy)
                ->orderBy('id')
                ->offset($offset)
                ->limit($limit)
                ->get());
        } catch (Throwable) {
            return collect();
        }

        return $rows->map(fn (object $row) => PendingJob::fromPayload(
            payload: PendingJob::decode($row->payload ?? null),
            id: (string) $row->id,
            attempts: (int) ($row->attempts ?? 0),
            availableAt: isset($row->available_at) ? (int) $row->available_at : null,
            reservedAt: isset($row->reserved_at) ? (int) $row->reserved_at : null,
        ));
    }

    /**
     * A query builder for the queue's jobs table.
     */
    protected function table(): Builder
    {
        return DB::connection(config("queue.connections.{$this->connection}.connection"))
            ->table(config("queue.connections.{$this->connection}.table", 'jobs'));
    }

    /**
     * The current time, as the queue driver stores it.
     *
     * This has to be the application's clock rather than the system one, so it
     * matches the `available_at` values DatabaseQueue writes.
     */
    protected function now(): int
    {
        return CarbonImmutable::now()->getTimestamp();
    }
}
