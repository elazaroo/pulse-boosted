<?php

namespace Elazaroo\PulseBoosted\Queues;

enum JobStatus: string
{
    /**
     * The job has been pushed onto the queue and nobody has picked it up.
     */
    case Queued = 'queued';

    /**
     * A worker has reserved the job and is running it right now.
     */
    case Processing = 'processing';

    /**
     * The job finished without throwing.
     */
    case Processed = 'processed';

    /**
     * The job threw, but has attempts left and went back onto the queue.
     */
    case Released = 'released';

    /**
     * The job threw and has no attempts left.
     */
    case Failed = 'failed';

    /**
     * The worker killed the job for exceeding its timeout.
     */
    case TimedOut = 'timed_out';

    /**
     * Whether the job has reached the end of its life.
     */
    public function isFinished(): bool
    {
        return match ($this) {
            self::Processed, self::Failed, self::TimedOut => true,
            self::Queued, self::Processing, self::Released => false,
        };
    }

    /**
     * A human readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Processing => 'Processing',
            self::Processed => 'Processed',
            self::Released => 'Released',
            self::Failed => 'Failed',
            self::TimedOut => 'Timed out',
        };
    }
}
