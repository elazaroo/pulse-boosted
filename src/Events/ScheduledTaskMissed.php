<?php

namespace Elazaroo\PulseBoosted\Events;

use Illuminate\Foundation\Events\Dispatchable;
use stdClass;

/**
 * A scheduled task that should have started by now has not.
 *
 * Fired once for each time it was due, by the scheduler itself, so it is
 * safe to notify on. When the scheduler has stopped altogether there is
 * nothing left to fire it: the dashboard says so instead.
 */
class ScheduledTaskMissed
{
    use Dispatchable;

    /**
     * @param  stdClass  $task  The row from pulse_boosted_scheduled_tasks.
     * @param  int  $dueAt  When it should have started, as a timestamp.
     */
    public function __construct(
        public readonly stdClass $task,
        public readonly int $dueAt,
    ) {
        //
    }
}
