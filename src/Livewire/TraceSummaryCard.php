<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Elazaroo\PulseBoosted\Traces\TraceRepository;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;

/**
 * Per-name totals for one kind of execution.
 *
 * Shows the 95th percentile next to the average, because an average hides the
 * slow tail: a command that usually takes 40ms and occasionally takes eight
 * seconds averages out to something that looks fine.
 *
 * @internal
 */
abstract class TraceSummaryCard extends Card
{
    /**
     * The kind of execution this card summarises.
     */
    abstract protected function executionType(): string;

    /**
     * What to call it.
     */
    abstract protected function title(): string;

    /**
     * Show the most recent trace for one of them.
     */
    public function showTrace(string $traceId): void
    {
        $this->dispatch('open-trace', traceId: $traceId);
    }

    /**
     * Render the component.
     */
    public function render(TraceRepository $traces): Renderable
    {
        return View::make('pulse-boosted::livewire.trace-summary', [
            'title' => $this->title(),
            'executionType' => $this->executionType(),
            'summary' => $traces->summaryByName($this->executionType()),
        ]);
    }
}
