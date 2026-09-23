<?php

namespace Elazaroo\PulseBoosted\Queues\Inspectors;

use Elazaroo\PulseBoosted\Queues\Capabilities;

/**
 * For Laravel's `failover` connection, which has no queue of its own.
 *
 * It hands each job to the first of its connections that will take it.
 * Counting through it would count those connections' jobs a second time,
 * so it reports nothing and leaves them to be shown under their own names.
 *
 * @internal
 */
class FailoverInspector extends NullInspector
{
    /**
     * What this inspector is able to report.
     */
    public function capabilities(): Capabilities
    {
        return Capabilities::none(
            'This connection hands jobs to the connections it lists, which are shown on their own.'
        );
    }
}
