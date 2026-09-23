<?php

namespace Elazaroo\PulseBoosted\Alerts;

use Elazaroo\PulseBoosted\Events\IsolatedBeat;
use Elazaroo\PulseBoosted\Pulse;
use Elazaroo\PulseBoosted\Recorders\Concerns\Throttling;

/**
 * Runs the rules on a beat.
 *
 * IsolatedBeat is taken under a lock, so however many servers are running
 * pulse-boosted:check, the rules are evaluated once across the fleet rather
 * than once per machine — which matters, because an alert is a thing that
 * should fire once.
 *
 * @internal
 */
class EvaluateAlerts
{
    use Throttling;

    /**
     * Create a new listener.
     */
    public function __construct(
        protected Pulse $pulse,
        protected AlertManager $alerts,
    ) {
        //
    }

    /**
     * Handle the beat.
     */
    public function __invoke(IsolatedBeat $event): void
    {
        if (! $this->alerts->enabled()) {
            return;
        }

        $this->pulse->rescue(fn () => $this->throttle(
            $this->alerts->interval(),
            $event,
            fn () => $this->alerts->evaluate(),
        ));
    }
}
