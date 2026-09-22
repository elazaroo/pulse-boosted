<?php

namespace Elazaroo\PulseBoosted\Events;

use Carbon\CarbonImmutable;

class IsolatedBeat
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public CarbonImmutable $time,
    ) {
        //
    }
}
