<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Elazaroo\PulseBoosted\Schedule\ScheduleMonitor;
use Elazaroo\PulseBoosted\Traces\TraceRepository;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Lazy;

/**
 * Scheduled tasks: whether the scheduler is running them, how long they take,
 * and whether they finish — and which should have run and did not.
 *
 * The timings come from traces; what is due comes from the schedule itself,
 * written down each time the scheduler runs, because a task that never
 * started leaves no trace to count.
 *
 * @internal
 */
#[Lazy(isolate: false)]
class ScheduledTasks extends TraceSummaryCard
{
    protected function executionType(): string
    {
        return 'schedule';
    }

    protected function title(): string
    {
        return 'Scheduled Tasks';
    }

    public function render(TraceRepository $traces): Renderable
    {
        $monitor = app(ScheduleMonitor::class);

        $summary = $traces->summaryByName('schedule')->keyBy('name');
        $tasks = rescue(fn () => $monitor->tasks(), collect(), report: false);

        // Everything the schedule lists, then anything only traces know of:
        // a task since removed, or the monitor turned off.
        $rows = $tasks
            ->map(fn (object $task) => ['task' => $task, 'stats' => $summary->get($task->name)])
            ->concat($summary->except($tasks->pluck('name')->all())->map(fn (array $stats) => ['task' => null, 'stats' => $stats])->values())
            ->sortBy(fn (array $row) => sprintf(
                '%d|%s',
                ['missed' => 0, 'failed' => 1, 'running' => 2][$row['task']->state ?? ''] ?? 3,
                $row['task']->name ?? ($row['stats']['name'] ?? ''),
            ))
            ->values();

        return View::make('pulse-boosted::livewire.scheduled-tasks', [
            'tasks' => $rows,
            'missed' => $tasks->where('state', 'missed')->count(),
            'lastRun' => rescue(fn () => $monitor->lastRun(), null, report: false),
            'stopped' => rescue(fn () => $monitor->schedulerStopped(), false, report: false),
        ]);
    }
}
