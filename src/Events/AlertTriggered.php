<?php

namespace Elazaroo\PulseBoosted\Events;

use Elazaroo\PulseBoosted\Alerts\AlertRule;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A rule has gone from fine to not fine.
 *
 * Pulse Boosted does not send this anywhere: where an alert should go is the
 * application's business, not the dashboard's. Listen for this and notify
 * however you already notify.
 */
class AlertTriggered
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $context  Whatever the metric could say about the reading.
     */
    public function __construct(
        public readonly AlertRule $rule,
        public readonly float $value,
        public readonly array $context = [],
    ) {
        //
    }
}
