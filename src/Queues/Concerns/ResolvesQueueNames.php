<?php

namespace Elazaroo\PulseBoosted\Queues\Concerns;

use Illuminate\Events\CallQueuedListener;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Config;
use ReflectionClass;
use Throwable;

trait ResolvesQueueNames
{
    /**
     * Work out which queue a job was pushed onto.
     *
     * `JobQueued` reports a null queue whenever the caller did not name one,
     * which is most of the time, so the connection's default has to be filled
     * in here rather than left blank in the dashboard.
     */
    protected function queueForQueuedEvent(JobQueued $event): string
    {
        $queue = $event->queue ?? $this->queueFromJobObject($event);

        return $queue === null
            ? $this->defaultQueue($event->connectionName)
            : $this->normalizeSqsQueue($event->connectionName, $queue);
    }

    /**
     * Read the queue off the job object itself.
     */
    protected function queueFromJobObject(JobQueued $event): ?string
    {
        if (! is_object($event->job)) {
            return null;
        }

        if ($event->job instanceof CallQueuedListener) {
            return $this->queuedListenerQueue($event);
        }

        return $event->job->queue ?? null;
    }

    /**
     * Resolve the queue a queued listener will run on.
     */
    protected function queuedListenerQueue(JobQueued $event): ?string
    {
        try {
            $listener = (new ReflectionClass($event->job->class))->newInstanceWithoutConstructor();
        } catch (Throwable) {
            return null;
        }

        return method_exists($listener, 'viaQueue')
            ? $listener->viaQueue($event->job->data[0] ?? null)
            : ($listener->queue ?? null);
    }

    /**
     * The default queue configured for a connection.
     */
    protected function defaultQueue(string $connection): string
    {
        return Config::get('queue.connections.'.$connection.'.queue', 'default');
    }

    /**
     * Strip the prefix and suffix SQS puts around queue names, so the same
     * queue is not recorded under two different names.
     */
    protected function normalizeSqsQueue(string $connection, string $queue): string
    {
        $config = Config::get("queue.connections.{$connection}") ?? [];

        if (($config['driver'] ?? null) !== 'sqs') {
            return $queue;
        }

        if ($config['prefix'] ?? null) {
            $prefix = preg_quote($config['prefix'], '#');

            $queue = preg_replace("#^{$prefix}/#", '', $queue) ?? $queue;
        }

        if ($config['suffix'] ?? null) {
            $suffix = preg_quote($config['suffix'], '#');

            $queue = preg_replace("#{$suffix}$#", '', $queue) ?? $queue;
        }

        return $queue;
    }
}
