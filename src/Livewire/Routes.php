<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Elazaroo\PulseBoosted\Traces\TraceRepository;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Lazy;

/**
 * Requests grouped by route: how often, how they answered, how long they took.
 *
 * @internal
 */
#[Lazy]
class Routes extends Card
{
    /**
     * @var 'calls'|'avg'|'p95'|'server'
     */
    public string $orderBy = 'calls';

    public string $search = '';

    /**
     * Open the slowest request to a route.
     */
    public function showTrace(string $traceId): void
    {
        $this->dispatch('open-trace', traceId: $traceId);
    }

    public function render(TraceRepository $traces): Renderable
    {
        return View::make('pulse-boosted::livewire.routes', [
            'routes' => $traces->routeGroups(trim($this->search), $this->orderBy),
        ]);
    }
}
