<?php

namespace Elazaroo\PulseBoosted\Queues\Inspectors;

use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Queues\Contracts\QueueInspector;
use Elazaroo\PulseBoosted\Queues\Counts;
use Elazaroo\PulseBoosted\Queues\PendingJob;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Shared behaviour for every inspector.
 *
 * Laravel 13 put `pendingSize()`, `delayedSize()` and `reservedSize()` on the
 * queue contract and implemented them in every driver, so on that version the
 * counts come for free and are correct even for SQS and Beanstalkd. On older
 * versions those methods are absent and each subclass has to decide whether it
 * can work the numbers out itself.
 *
 * @internal
 */
abstract class Inspector implements QueueInspector
{
    /**
     * Create a new inspector instance.
     */
    public function __construct(
        protected Queue $queue,
        protected string $connection,
    ) {
        //
    }

    /**
     * How many jobs are waiting, by state.
     */
    public function counts(string $queue): Counts
    {
        if (! $this->hasNativeCounts()) {
            return new Counts;
        }

        try {
            return $this->ignore(fn () => new Counts(
                pending: (int) $this->queue->pendingSize($queue),
                delayed: (int) $this->queue->delayedSize($queue),
                reserved: (int) $this->queue->reservedSize($queue),
            ));
        } catch (Throwable) {
            // A queue that does not exist yet, or a backend we cannot reach.
            return new Counts;
        }
    }

    /**
     * The same, for every queue at once.
     *
     * @param  iterable<int, string>  $queues
     * @return Collection<string, Counts>
     */
    public function allCounts(iterable $queues): Collection
    {
        $counts = [];

        foreach ($queues as $queue) {
            $counts[$queue] = $this->counts($queue);
        }

        return collect($counts);
    }

    /**
     * The queue names that exist on this connection.
     *
     * @return Collection<int, string>
     */
    public function queues(): Collection
    {
        return collect([$this->defaultQueue()]);
    }

    /**
     * @return Collection<int, PendingJob>
     */
    public function pending(string $queue, int $limit = 50, int $offset = 0): Collection
    {
        return collect();
    }

    /**
     * @return Collection<int, PendingJob>
     */
    public function delayed(string $queue, int $limit = 50, int $offset = 0): Collection
    {
        return collect();
    }

    /**
     * @return Collection<int, PendingJob>
     */
    public function reserved(string $queue, int $limit = 50, int $offset = 0): Collection
    {
        return collect();
    }

    /**
     * Run a query without the recorders seeing it.
     *
     * Inspecting a queue means querying the application's own database or
     * Redis. Left alone, those queries would be recorded as application
     * queries and the dashboard would show itself working.
     */
    protected function ignore(callable $callback): mixed
    {
        return Pulse::ignore($callback);
    }

    /**
     * Whether the installed Laravel exposes per-state sizes on the queue.
     */
    protected function hasNativeCounts(): bool
    {
        return method_exists($this->queue, 'pendingSize')
            && method_exists($this->queue, 'delayedSize')
            && method_exists($this->queue, 'reservedSize');
    }

    /**
     * The queue this connection uses when none is named.
     */
    protected function defaultQueue(): string
    {
        return config("queue.connections.{$this->connection}.queue", 'default');
    }

    /**
     * The reason counts are unavailable, when they are.
     */
    protected function noNativeCountsReason(): ?string
    {
        return $this->hasNativeCounts()
            ? null
            : 'Live queue sizes need Laravel 13 or newer for this driver.';
    }
}
