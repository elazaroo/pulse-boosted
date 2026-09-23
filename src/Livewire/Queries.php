<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Elazaroo\PulseBoosted\Traces\TraceRepository;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Lazy;

/**
 * Every query, grouped by what it is, with where it was run from.
 *
 * The slow-queries card answers "what is slow"; this answers "what costs the
 * most", which is usually a fast query run far too often.
 *
 * @internal
 */
#[Lazy]
class Queries extends Card
{
    /**
     * @var 'total'|'calls'|'avg'|'p95'
     */
    public string $orderBy = 'total';

    public string $search = '';

    /**
     * Open the slowest execution that ran a query.
     */
    public function showTrace(string $traceId): void
    {
        $this->dispatch('open-trace', traceId: $traceId);
    }

    public function render(TraceRepository $traces): Renderable
    {
        return View::make('pulse-boosted::livewire.queries', [
            'queries' => $traces->queryGroups(trim($this->search), $this->orderBy),
        ]);
    }
}
