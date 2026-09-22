<?php

namespace Elazaroo\PulseBoosted\Queues;

/**
 * How many jobs are sitting on a queue right now, by state.
 *
 * A null means "this driver cannot tell us", which is different from zero and
 * is rendered differently.
 */
class Counts
{
    public function __construct(
        /**
         * Available to be picked up immediately.
         */
        public ?int $pending = null,

        /**
         * Scheduled for later.
         */
        public ?int $delayed = null,

        /**
         * Reserved by a worker and running now.
         */
        public ?int $reserved = null,
    ) {
        //
    }

    /**
     * Everything the driver could tell us about, added together.
     */
    public function total(): ?int
    {
        if ($this->pending === null && $this->delayed === null && $this->reserved === null) {
            return null;
        }

        return (int) $this->pending + (int) $this->delayed + (int) $this->reserved;
    }

    /**
     * Whether the driver told us anything at all.
     */
    public function known(): bool
    {
        return $this->total() !== null;
    }
}
