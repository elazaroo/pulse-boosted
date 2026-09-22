<?php

namespace Elazaroo\PulseBoosted\Events;

use Carbon\CarbonImmutable;

class SharedBeat
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public CarbonImmutable $time,
        public string $instance,
    ) {
        //
    }
}
