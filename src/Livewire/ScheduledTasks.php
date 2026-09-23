<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Livewire\Attributes\Lazy;

/**
 * Scheduled tasks: whether the scheduler is running them, how long they take,
 * and whether they finish.
 *
 * @internal
 */
#[Lazy]
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
}
