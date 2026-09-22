<?php

namespace Elazaroo\PulseBoosted\Queues;

/**
 * What an inspector can actually tell you about a queue.
 *
 * Drivers differ enormously here: the database driver can show you every
 * waiting job, SQS can only give you approximate counts, and `sync` has no
 * queue at all. The dashboard reads these flags and hides what it cannot
 * show, rather than presenting an empty table as though the queue were empty.
 */
class Capabilities
{
    public function __construct(
        /**
         * Whether pending, delayed and reserved counts can be read.
         */
        public bool $counts = false,

        /**
         * Whether the jobs waiting on the queue can be listed, not just counted.
         */
        public bool $listing = false,

        /**
         * Whether the queue names can be discovered, rather than having to be
         * configured by hand.
         */
        public bool $discovery = false,

        /**
         * Why the inspector is limited, shown to the user when something is
         * unavailable.
         */
        public ?string $reason = null,
    ) {
        //
    }

    /**
     * An inspector that can do nothing.
     */
    public static function none(?string $reason = null): self
    {
        return new self(reason: $reason);
    }

    /**
     * An inspector that can do everything.
     */
    public static function all(): self
    {
        return new self(counts: true, listing: true, discovery: true);
    }
}
