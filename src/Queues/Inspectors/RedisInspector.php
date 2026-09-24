<?php

namespace Elazaroo\PulseBoosted\Queues\Inspectors;

use Elazaroo\PulseBoosted\Queues\Capabilities;
use Elazaroo\PulseBoosted\Queues\Counts;
use Elazaroo\PulseBoosted\Queues\PendingJob;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads the Redis keys a queue is made of.
 *
 * A Redis queue is a list of waiting jobs at `queues:{name}`, plus two sorted
 * sets: `queues:{name}:delayed` and `queues:{name}:reserved`, both scored by
 * the timestamp at which the job becomes available again. Everything here is
 * read with LRANGE and ZRANGE, which never pop, so inspecting a queue cannot
 * lose a job.
 *
 * @internal
 */
class RedisInspector extends Inspector
{
    /**
     * What this inspector is able to report.
     */
    public function capabilities(): Capabilities
    {
        if ($this->isCluster()) {
            // Cluster keys carry hash tags that the public API does not expose,
            // so listing would silently read the wrong keys. Counts go through
            // the driver itself and stay correct.
            return new Capabilities(
                counts: $this->hasNativeCounts(),
                reason: 'Listing queue contents is not supported on Redis Cluster.',
            );
        }

        return Capabilities::all();
    }

    /**
     * The queue names that have keys in Redis.
     *
     * @return Collection<int, string>
     */
    public function queues(): Collection
    {
        try {
            $keys = $this->ignore(fn () => $this->connection()->keys('queues:*'));
        } catch (Throwable) {
            return parent::queues();
        }

        return collect(is_array($keys) ? $keys : [])
            ->map(fn ($key) => trim(Str::between((string) $key, 'queues:', ':'), '{}'))
            ->filter()
            ->push($this->defaultQueue())
            ->unique()
            ->values();
    }

    /**
     * How many jobs are waiting, by state.
     *
     * Worked out here rather than through the queue contract so the numbers
     * are also available on Laravel 11 and 12.
     */
    public function counts(string $queue): Counts
    {
        try {
            $key = $this->key($queue);

            return $this->ignore(fn () => new Counts(
                pending: (int) $this->connection()->llen($key),
                delayed: (int) $this->connection()->zcard($key.':delayed'),
                reserved: (int) $this->connection()->zcard($key.':reserved'),
            ));
        } catch (Throwable) {
            return parent::counts($queue);
        }
    }

    /**
     * @return Collection<int, PendingJob>
     */
    public function pending(string $queue, int $limit = 50, int $offset = 0): Collection
    {
        return $this->jobs(
            fn (string $key) => $this->connection()->lrange($key, $offset, $offset + $limit - 1),
            $this->key($queue),
        );
    }

    /**
     * @return Collection<int, PendingJob>
     */
    public function delayed(string $queue, int $limit = 50, int $offset = 0): Collection
    {
        return $this->sortedSet($this->key($queue).':delayed', $limit, $offset, 'availableAt');
    }

    /**
     * @return Collection<int, PendingJob>
     */
    public function reserved(string $queue, int $limit = 50, int $offset = 0): Collection
    {
        return $this->sortedSet($this->key($queue).':reserved', $limit, $offset, 'reservedAt');
    }

    /**
     * Read a page of a sorted set, keeping each member's score, which is when
     * the job becomes available again.
     *
     * @return Collection<int, PendingJob>
     */
    protected function sortedSet(string $key, int $limit, int $offset, string $scoreMeans): Collection
    {
        try {
            $members = $this->ignore(fn () => $this->connection()->zrange($key, $offset, $offset + $limit - 1, true));
        } catch (Throwable) {
            return collect();
        }

        return collect(is_array($members) ? $members : [])->map(function ($score, $raw) use ($scoreMeans) {
            $payload = PendingJob::decode(is_string($raw) ? $raw : null);

            return PendingJob::fromPayload(
                payload: $payload,
                attempts: (int) ($payload['attempts'] ?? 0),
                availableAt: $scoreMeans === 'availableAt' ? (int) $score : null,
                reservedAt: $scoreMeans === 'reservedAt' ? (int) $score : null,
            );
        })->values();
    }

    /**
     * Read a page of a list.
     *
     * @param  callable(string): mixed  $read
     * @return Collection<int, PendingJob>
     */
    protected function jobs(callable $read, string $key): Collection
    {
        try {
            $raw = $this->ignore(fn () => $read($key));
        } catch (Throwable) {
            return collect();
        }

        return collect(is_array($raw) ? $raw : [])->map(function ($item) {
            $payload = PendingJob::decode(is_string($item) ? $item : null);

            return PendingJob::fromPayload(
                payload: $payload,
                attempts: (int) ($payload['attempts'] ?? 0),
            );
        })->values();
    }

    /**
     * The base Redis key for a queue.
     */
    protected function key(string $queue): string
    {
        return 'queues:'.$queue;
    }

    /**
     * The Redis connection the queue uses.
     */
    protected function connection(): mixed
    {
        return $this->queue instanceof RedisQueue
            ? $this->queue->getConnection()
            : throw new \RuntimeException('Not a Redis queue.');
    }

    /**
     * Whether the queue is backed by a Redis cluster.
     */
    protected function isCluster(): bool
    {
        try {
            return str_contains(strtolower($this->connection()::class), 'cluster');
        } catch (Throwable) {
            return false;
        }
    }
}
