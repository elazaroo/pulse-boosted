<?php

namespace Elazaroo\PulseBoosted\Queues\Contracts;

use Elazaroo\PulseBoosted\Queues\Capabilities;
use Elazaroo\PulseBoosted\Queues\Counts;
use Elazaroo\PulseBoosted\Queues\PendingJob;
use Illuminate\Support\Collection;

interface QueueInspector
{
    /**
     * What this inspector is able to report, so the dashboard can hide the
     * rest instead of showing an empty table.
     */
    public function capabilities(): Capabilities;

    /**
     * The queue names that exist on this connection.
     *
     * @return Collection<int, string>
     */
    public function queues(): Collection;

    /**
     * How many jobs are waiting, by state.
     */
    public function counts(string $queue): Counts;

    /**
     * The same, for every queue at once.
     *
     * The dashboard asks about every queue on every refresh, so a driver that
     * can answer in one round trip should do so here rather than being asked
     * one queue at a time.
     *
     * @param  iterable<int, string>  $queues
     * @return Collection<string, Counts>
     */
    public function allCounts(iterable $queues): Collection;

    /**
     * Jobs that are available to be worked right now.
     *
     * @return Collection<int, PendingJob>
     */
    public function pending(string $queue, int $limit = 50, int $offset = 0): Collection;

    /**
     * Jobs scheduled for later.
     *
     * @return Collection<int, PendingJob>
     */
    public function delayed(string $queue, int $limit = 50, int $offset = 0): Collection;

    /**
     * Jobs a worker has reserved and is running now.
     *
     * @return Collection<int, PendingJob>
     */
    public function reserved(string $queue, int $limit = 50, int $offset = 0): Collection;
}
