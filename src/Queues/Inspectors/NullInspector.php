<?php

namespace Elazaroo\PulseBoosted\Queues\Inspectors;

use Elazaroo\PulseBoosted\Queues\Capabilities;
use Elazaroo\PulseBoosted\Queues\Counts;

/**
 * For connections where there is nothing to inspect.
 *
 * `sync` runs jobs immediately, so nothing is ever waiting; `null` discards
 * them. Both still show up in the explorer's recorded history — a synchronous
 * job has a duration and can fail — but there is no live queue behind them.
 *
 * @internal
 */
class NullInspector extends Inspector
{
    /**
     * What this inspector is able to report.
     */
    public function capabilities(): Capabilities
    {
        return Capabilities::none(
            'This connection runs jobs immediately, so nothing ever waits on a queue.'
        );
    }

    /**
     * How many jobs are waiting, by state.
     */
    public function counts(string $queue): Counts
    {
        return new Counts(pending: 0, delayed: 0, reserved: 0);
    }
}
