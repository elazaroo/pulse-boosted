<?php

namespace Elazaroo\PulseBoosted\Schedule;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;

/**
 * Feeds the scheduler's own events to the monitor, and has it look for
 * missed tasks each time the scheduler finishes a run.
 *
 * @internal
 */
class WatchSchedule
{
    public function __construct(
        protected ScheduleMonitor $monitor,
        protected Application $app,
    ) {
        //
    }

    public function __invoke(ScheduledTaskStarting|ScheduledTaskFinished|ScheduledTaskFailed|ScheduledTaskSkipped|CommandFinished $event): void
    {
        match (true) {
            $event instanceof ScheduledTaskStarting => $this->monitor->started($event->task),
            $event instanceof ScheduledTaskFinished => $this->monitor->finished($event->task, 'ok', (int) round($event->runtime * 1000)),
            // Dispatched after Finished when the exit code was not zero, so
            // this is the one that stands.
            $event instanceof ScheduledTaskFailed => $this->monitor->finished($event->task, 'failed'),
            $event instanceof ScheduledTaskSkipped => $this->monitor->skipped($event->task),
            $event instanceof CommandFinished => $this->afterRun($event),
        };
    }

    /**
     * Once the scheduler has started whatever was due, the schedule is
     * written down and anything that should have started and has not is
     * announced. Every run does it, so it needs no schedule of its own.
     */
    protected function afterRun(CommandFinished $event): void
    {
        if ($event->command !== 'schedule:run') {
            return;
        }

        rescue(function () {
            $this->monitor->snapshot($this->app->make(Schedule::class));
            $this->monitor->announceMissed();
        }, report: false);
    }
}
