<?php

namespace Elazaroo\PulseBoosted\Events;

use Elazaroo\PulseBoosted\Alerts\AlertRule;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A rule that had tripped has recovered.
 *
 * Worth sending on as well: an alert nobody is told has ended is an alert
 * somebody is still worrying about.
 */
class AlertResolved
{
    use Dispatchable;

    public function __construct(
        public readonly AlertRule $rule,
        public readonly float $value,

        /**
         * The worst reading while it was open.
         */
        public readonly float $peak,

        /**
         * How long it was going on, in seconds.
         */
        public readonly int $durationSeconds,
    ) {
        //
    }
}
