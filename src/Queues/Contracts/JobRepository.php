<?php

namespace Elazaroo\PulseBoosted\Queues\Contracts;

use Illuminate\Support\Collection;

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
     */
    public function find(string $uuid): ?object;

    /**
     * Retrieve recorded jobs, newest first.
     *
     * @param  array{status?: string, connection?: string, queue?: string, class?: string, search?: string, batch_id?: string}  $filters
     * @return Collection<int, object>
     */
    public function jobs(array $filters = [], int $limit = 50, int $offset = 0): Collection;

    /**
     * Count recorded jobs matching the filters.
     *
     * @param  array{status?: string, connection?: string, queue?: string, class?: string, search?: string, batch_id?: string}  $filters
     */
    public function count(array $filters = []): int;

    /**
     * Count recorded jobs per status, for the explorer's tabs.
     *
     * @param  array{connection?: string, queue?: string, class?: string, search?: string}  $filters
     * @return array<string, int>
     */
    public function countsByStatus(array $filters = []): array;

    /**
     * The distinct connection and queue pairs that have been recorded.
     *
     * @return Collection<int, object{connection: string, queue: string}>
     */
    public function recordedQueues(): Collection;
}
