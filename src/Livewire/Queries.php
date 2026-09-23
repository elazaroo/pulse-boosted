<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\Traces\TraceRepository;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;

/**
 * Every query, grouped by what it is, with where it was run from.
 *
 * The slow-queries card answers "what is slow"; this answers "what costs the
 * most", which is usually a fast query run far too often. Opening one shows
 * every place it is run from and the executions that ran it most, which is
 * where an N+1 gives itself away.
 *
 * @internal
 */
#[Lazy(isolate: false)]
class Queries extends Card
{
    /**
     * @var 'total'|'calls'|'avg'|'p95'
     */
    public string $orderBy = 'total';

    public string $search = '';

    /**
     * The query whose panel is open, by its key.
     */
    #[Url(as: 'query')]
    public ?string $selected = null;

    /**
     * Open the slowest execution that ran a query.
     */
    public function showTrace(string $traceId): void
    {
        $this->dispatch('open-trace', traceId: $traceId);
    }

    /**
     * Open a query.
     */
    public function select(string $key): void
    {
        $this->selected = $key;
    }

    /**
     * Close it.
     */
    public function deselect(): void
    {
        $this->selected = null;
    }

    public function render(TraceRepository $traces): Renderable
    {
        $detail = $this->selected === null ? null : ($traces->queryDetail(
            $this->selected,
            CarbonImmutable::now()->sub($this->periodAsInterval())->getTimestamp(),
        ) ?? ['missing' => true]);

        return View::make('pulse-boosted::livewire.queries', [
            'queries' => $traces->queryGroups(trim($this->search), $this->orderBy),
            'detail' => $detail,
        ]);
    }
}
