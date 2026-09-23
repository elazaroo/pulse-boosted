<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Livewire\Attributes\Lazy;

/**
 * Artisan commands: how often they run, how long they take, how often they
 * exit non-zero.
 *
 * @internal
 */
#[Lazy(isolate: false)]
class Commands extends TraceSummaryCard
{
    protected function executionType(): string
    {
        return 'command';
    }

    protected function title(): string
    {
        return 'Commands';
    }
}
