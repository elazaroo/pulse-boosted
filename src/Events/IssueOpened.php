<?php

namespace Elazaroo\PulseBoosted\Events;

use Illuminate\Foundation\Events\Dispatchable;
use stdClass;

/**
 * An exception nobody had seen before.
 *
 * Fired once per issue, when it is first recorded — not for every occurrence
 * after it — so it is safe to notify on.
 */
class IssueOpened
{
    use Dispatchable;

    /**
     * @param  stdClass  $issue  The row from pulse_boosted_issues.
     */
    public function __construct(public readonly stdClass $issue)
    {
        //
    }
}
