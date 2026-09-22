<?php

namespace Elazaroo\PulseBoosted\Events;

use Throwable;

class ExceptionReported
{
    /**
     * Create a new event instance.
     */
    public function __construct(public Throwable $exception)
    {
        //
    }
}
