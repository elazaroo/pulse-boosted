<?php

namespace Elazaroo\PulseBoosted\Queues\Contracts;

use Illuminate\Support\Collection;

/**
 * @phpstan-type RecordedJob object{
 *     id: int,
 *     uuid: string,
 *     job_id: ?string,
 *     connection: string,
 *     queue: string,
 *     name: string,
 *     class: ?string,
 *     status: string,
 *     queued_at: ?int,
 *     started_at: ?int,
 *     finished_at: ?int,
 *     duration_ms: ?int,
 *     attempts: int,
 *     max_tries: ?int,
 *     timeout: ?int,
 *     payload: ?string,
 *     tags: ?string,
 *     exception: ?string,
 *     exception_class: ?string,
 *     batch_id: ?string,
 *     worker: ?string
 * }
 * @phpstan-type JobFilters array<string, string|null>
 *
 * Filters: status, connection, queue, class, batch_id, search, and
 * finished_after — a timestamp.
 */
interface JobRepository
{
    /**
     * Buffer a change to a job's record.
     *
     * Calls accumulate per UUID and are written on flush, so a job that is
     * queued and run inside one process costs a single write.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function record(string $uuid, array $attributes): void;

    /**
     * Write everything buffered so far.
     */
    public function flush(): void;

    /**
     * Drop records older than the configured retention.
     */
    public function trim(): void;

    /**
     * Drop every record.
     */
    public function purge(): void;

    /**
     * Retrieve a single job by its UUID.
     *
     * @return RecordedJob|null
     */
    public function find(string $uuid): ?object;

    /**
     * Retrieve recorded jobs, newest first.
     *
     * @param  JobFilters  $filters
     * @return Collection<int, RecordedJob>
     */
    public function jobs(array $filters = [], int $limit = 50, int $offset = 0): Collection;

    /**
     * Count recorded jobs matching the filters.
     *
     * @param  JobFilters  $filters
     */
    public function count(array $filters = []): int;

    /**
     * Count recorded jobs per status, for the explorer's tabs.
     *
     * @param  JobFilters  $filters
     * @return array<string, int>
     */
    public function countsByStatus(array $filters = []): array;

    /**
     * Buffer one attempt at running a job.
     *
     * @param  array<string, mixed>  $attempt
     */
    public function recordAttempt(string $uuid, array $attempt): void;

    /**
     * Every recorded attempt at a job, first to last.
     *
     * @return Collection<int, object>
     */
    public function attempts(string $uuid): Collection;

    /**
     * The distinct connection and queue pairs that have been recorded.
     *
     * @return Collection<int, object>
     */
    public function recordedQueues(): Collection;
}
