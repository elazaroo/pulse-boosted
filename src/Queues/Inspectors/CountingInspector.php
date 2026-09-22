<?php

namespace Elazaroo\PulseBoosted\Queues\Inspectors;

use Elazaroo\PulseBoosted\Queues\Capabilities;

/**
 * For drivers that can be counted but not read.
 *
 * SQS is the reason this exists: you cannot look at its queue without
 * receiving messages, which would make them invisible to your real workers for
 * the duration of the visibility timeout. Its counts are approximate by
 * design. Beanstalkd is similar — `statsTube` gives totals, and peeking only
 * ever returns one job.
 *
 * @internal
 */
class CountingInspector extends Inspector
{
    /**
     * What this inspector is able to report.
     */
    public function capabilities(): Capabilities
    {
        return new Capabilities(
            counts: $this->hasNativeCounts(),
            reason: $this->noNativeCountsReason()
                ?? 'This driver reports queue sizes but cannot list the jobs waiting on them.',
        );
    }
}
