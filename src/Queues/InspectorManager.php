<?php

namespace Elazaroo\PulseBoosted\Queues;

use Closure;
use Elazaroo\PulseBoosted\Queues\Contracts\QueueInspector;
use Elazaroo\PulseBoosted\Queues\Inspectors\CountingInspector;
use Elazaroo\PulseBoosted\Queues\Inspectors\DatabaseInspector;
use Elazaroo\PulseBoosted\Queues\Inspectors\NullInspector;
use Elazaroo\PulseBoosted\Queues\Inspectors\RedisInspector;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Queue\NullQueue;
use Illuminate\Queue\QueueManager as LaravelQueueManager;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Resolves the right inspector for a queue connection.
 *
 * Third-party drivers can register their own with `extend()`; anything
 * unrecognised falls back to counting through the queue contract, which every
 * driver on Laravel 13 implements.
 */
class InspectorManager
{
    /**
     * Custom inspector resolvers, keyed by queue driver.
     *
     * @var array<string, Closure(string): QueueInspector>
     */
    protected array $custom = [];

    /**
     * Resolved inspectors, keyed by connection name.
     *
     * @var array<string, QueueInspector>
     */
    protected array $inspectors = [];

    /**
     * Create a new manager instance.
     */
    public function __construct(
        protected LaravelQueueManager $queues,
        protected Repository $config,
    ) {
        //
    }

    /**
     * Register an inspector for a queue driver.
     *
     * @param  Closure(string): QueueInspector  $resolver
     */
    public function extend(string $driver, Closure $resolver): self
    {
        $this->custom[$driver] = $resolver;

        return $this;
    }

    /**
     * The queue connections the dashboard should offer.
     *
     * @return Collection<int, string>
     */
    public function connections(): Collection
    {
        $configured = $this->config->get('pulse-boosted.queues.connections');

        if (is_array($configured)) {
            return collect($configured)->values();
        }

        return collect(array_keys($this->config->get('queue.connections', [])))->values();
    }

    /**
     * Whether live inspection is switched on at all.
     */
    public function enabled(): bool
    {
        return (bool) $this->config->get('pulse-boosted.queues.enabled', true);
    }

    /**
     * Whether listing queue contents is allowed, on top of counting them.
     */
    public function listingEnabled(): bool
    {
        return (bool) $this->config->get('pulse-boosted.queues.listing', true);
    }

    /**
     * Get the inspector for a connection.
     */
    public function for(string $connection): QueueInspector
    {
        return $this->inspectors[$connection] ??= $this->resolve($connection);
    }

    /**
     * Build the inspector for a connection.
     */
    protected function resolve(string $connection): QueueInspector
    {
        $driver = $this->config->get("queue.connections.{$connection}.driver");

        try {
            $queue = $this->queues->connection($connection);
        } catch (Throwable) {
            // A connection that is configured but not usable here — a missing
            // extension, or credentials this environment does not have.
            return new NullInspector(new NullQueue, $connection);
        }

        if (isset($this->custom[$driver])) {
            return ($this->custom[$driver])($connection);
        }

        return match ($driver) {
            'database' => new DatabaseInspector($queue, $connection),
            'redis' => new RedisInspector($queue, $connection),
            'sync', 'null' => new NullInspector($queue, $connection),
            default => new CountingInspector($queue, $connection),
        };
    }
}
