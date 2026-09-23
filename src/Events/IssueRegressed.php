<?php

namespace Elazaroo\PulseBoosted\Events;

use Illuminate\Foundation\Events\Dispatchable;
use stdClass;

/**
 * An issue somebody had marked resolved has happened again.
 *
 * Resolving an issue is a claim that it is fixed; this is the evidence that
 * it is not, which is exactly when somebody should hear about it.
 */
class IssueRegressed
{
    use Dispatchable;

    /**
     * @param  stdClass  $issue  The row from pulse_boosted_issues, reopened.
     */
    public function __construct(public readonly stdClass $issue)
    {
        //
    }
}
