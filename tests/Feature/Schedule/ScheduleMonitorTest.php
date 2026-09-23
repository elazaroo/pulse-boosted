<?php

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\Events\ScheduledTaskMissed;
use Elazaroo\PulseBoosted\Livewire\ScheduledTasks;
use Elazaroo\PulseBoosted\Schedule\ScheduleMonitor;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-09-23 10:00:30');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * A schedule with one hourly task and one daily one.
 */
function demoSchedule(): Schedule
{
    $schedule = new Schedule('UTC');

    $schedule->command('reports:send')->hourly();
    $schedule->call(fn () => null)->dailyAt('03:00')->name('backups:run');

    return $schedule;
}

function taskNamed(string $name): object
{
    return app(ScheduleMonitor::class)->tasks()->firstWhere('name', $name);
}

it('writes the schedule down each time the scheduler runs', function () {
    app(ScheduleMonitor::class)->snapshot(demoSchedule());

    $tasks = app(ScheduleMonitor::class)->tasks();

    expect($tasks->pluck('name')->sort()->values()->all())->toBe(['backups:run', 'reports:send']);
    expect(taskNamed('reports:send'))
        ->expression->toBe('0 * * * *')
        ->state->toBe('waiting')
        ->next_due->toBe(CarbonImmutable::parse('2026-09-23 11:00:00')->getTimestamp());
});

it('calls a task missed once its due time passes without it starting', function () {
    $monitor = app(ScheduleMonitor::class);

    // Known since yesterday, so it was being watched when it came due.
    CarbonImmutable::setTestNow('2026-09-22 09:00:00');
    $monitor->snapshot($schedule = demoSchedule());

    // Due at 10:00, and it is 10:00:30: still within the grace.
    CarbonImmutable::setTestNow('2026-09-23 10:00:30');
    expect(taskNamed('reports:send')->state)->not->toBe('missed');

    CarbonImmutable::setTestNow('2026-09-23 10:05:00');
    expect(taskNamed('reports:send')->state)->toBe('missed');

    // Once it has started for that due time, it is not missed any more.
    $monitor->started($schedule->events()[0]);
    expect(taskNamed('reports:send')->state)->toBe('running');

    $monitor->finished($schedule->events()[0], 'ok', 120);
    expect(taskNamed('reports:send'))->state->toBe('ok')->last_duration_ms->toBe(120);
});

it('does not call a task missed that was added after it was due', function () {
    CarbonImmutable::setTestNow('2026-09-23 10:10:00');
    app(ScheduleMonitor::class)->snapshot(demoSchedule());

    expect(taskNamed('reports:send')->state)->toBe('waiting');
});

it('counts a task held back by its own conditions as looked at', function () {
    $monitor = app(ScheduleMonitor::class);

    CarbonImmutable::setTestNow('2026-09-22 09:00:00');
    $monitor->snapshot($schedule = demoSchedule());

    CarbonImmutable::setTestNow('2026-09-23 10:00:01');
    $monitor->skipped($schedule->events()[0]);

    CarbonImmutable::setTestNow('2026-09-23 10:05:00');
    expect(taskNamed('reports:send')->state)->toBe('skipped');
});

it('announces each missed due time once', function () {
    Event::fake([ScheduledTaskMissed::class]);

    $monitor = app(ScheduleMonitor::class);

    CarbonImmutable::setTestNow('2026-09-22 09:00:00');
    $monitor->snapshot(demoSchedule());

    CarbonImmutable::setTestNow('2026-09-23 10:05:00');
    expect($monitor->announceMissed())->toBe(2);
    expect($monitor->announceMissed())->toBe(0);

    Event::assertDispatched(ScheduledTaskMissed::class, fn (ScheduledTaskMissed $event) => $event->task->name === 'reports:send'
        && $event->dueAt === CarbonImmutable::parse('2026-09-23 10:00:00')->getTimestamp());

    // The next hour it is due, and missed, again.
    CarbonImmutable::setTestNow('2026-09-23 11:05:00');
    expect($monitor->announceMissed())->toBe(1);
});

it('leaves out tasks limited to other environments', function () {
    $schedule = new Schedule('UTC');
    $schedule->command('reports:send')->hourly()->environments(['production']);
    $schedule->command('cache:prune')->hourly();

    app(ScheduleMonitor::class)->snapshot($schedule);

    expect(app(ScheduleMonitor::class)->tasks()->pluck('name')->all())->toBe(['cache:prune']);
});

it('notices the scheduler itself has stopped', function () {
    $monitor = app(ScheduleMonitor::class);

    expect($monitor->schedulerStopped())->toBeFalse();

    $monitor->snapshot(demoSchedule());
    expect($monitor->schedulerStopped())->toBeFalse();

    CarbonImmutable::setTestNow(now()->addMinutes(10));
    expect($monitor->schedulerStopped())->toBeTrue();
});

it('follows the scheduler through its own events', function () {
    $schedule = demoSchedule();
    $this->app->instance(Schedule::class, $schedule);
    $task = $schedule->events()[0];

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFinished($task, 0.25));

    expect(taskNamed('reports:send'))->last_status->toBe('ok')->last_duration_ms->toBe(250);

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFinished($task, 0.1));
    event(new ScheduledTaskFailed($task, new RuntimeException('Exit code 1')));

    expect(taskNamed('reports:send'))->last_status->toBe('failed')->state->toBe('failed');

    event(new ScheduledTaskSkipped($schedule->events()[1]));
    expect(taskNamed('backups:run')->last_skipped_at)->not->toBeNull();

    // The end of schedule:run writes down the whole schedule.
    event(new CommandFinished('schedule:run', new ArrayInput([]), new NullOutput, 0));
    expect(app(ScheduleMonitor::class)->tasks())->toHaveCount(2);
});

it('shows each task with how it is doing on the card', function () {
    $monitor = app(ScheduleMonitor::class);

    CarbonImmutable::setTestNow('2026-09-22 09:00:00');
    $monitor->snapshot(demoSchedule());

    CarbonImmutable::setTestNow('2026-09-23 10:05:00');

    Livewire::test(ScheduledTasks::class, ['lazy' => false])
        ->assertSee('reports:send')
        ->assertSee('0 * * * *')
        ->assertSee('missed')
        ->assertSee('2 missed')
        ->assertSee('The scheduler has stopped');
});

it('names artisan commands without the php binary in front', function () {
    $schedule = new Schedule('UTC');
    $event = $schedule->command('inspire')->everyMinute();

    expect(ScheduleMonitor::name($event))->toBe('inspire');
});
