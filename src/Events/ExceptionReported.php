<?php

namespace Elazaroo\PulseBoosted\Events;

use Throwable;

class ExceptionReported
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public Throwable $exception,

        /**
         * Whether the application caught it and reported it itself, as
         * opposed to it escaping to the exception handler.
         */
        public bool $handled = true,
    ) {
        //
    }
}
