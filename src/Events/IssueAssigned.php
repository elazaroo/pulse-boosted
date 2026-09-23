<?php

namespace Elazaroo\PulseBoosted\Events;

use Illuminate\Foundation\Events\Dispatchable;
use stdClass;

/**
 * Somebody was made responsible for an issue, or it was left with nobody.
 */
class IssueAssigned
{
    use Dispatchable;

    /**
     * @param  stdClass  $issue  The row from pulse_boosted_issues, already updated.
     * @param  string|null  $assignee  The user now responsible, or null for nobody.
     * @param  string|null  $by  The user who did it, when there was one.
     */
    public function __construct(
        public readonly stdClass $issue,
        public readonly ?string $assignee,
        public readonly ?string $by = null,
    ) {
        //
    }
}
