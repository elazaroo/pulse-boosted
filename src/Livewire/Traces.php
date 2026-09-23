<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Elazaroo\PulseBoosted\Traces\Tracer;
use Elazaroo\PulseBoosted\Traces\TraceRepository;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;

/**
 * Executions, and what happened inside them.
 *
 * The rest of the dashboard answers "how many"; this answers "what led to it".
 * Opening one shows its timeline: every query, cache read, dispatched job,
 * outgoing call, exception and log line, in the order they happened and at the
 * offset they happened at.
 *
 * @internal
 */
#[Lazy(isolate: false)]
class Traces extends Card
{
    use Concerns\FiltersByUser;

    public const PER_PAGE = 20;

    #[Url(as: 'trace_type')]
    public string $type = '';

    #[Url(as: 'trace_status')]
    public string $status = '';

    #[Url(as: 'trace_sort')]
    public string $orderBy = 'latest';

    public int $page = 1;

    /**
     * Reset paging when the view changes underneath it.
     */
    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->page = 1;
        }
    }

    /**
     * Open a trace. Kept for anything that still calls it on this card; the
     * timeline itself is drawn by the viewer every page has.
     */
    public function select(string $traceId): void
    {
        $this->dispatch('open-trace', traceId: $traceId);
    }

    /**
     * Render the component.
     */
    public function render(TraceRepository $traces, Tracer $tracer): Renderable
    {
        $filters = array_filter([
            'type' => $this->type ?: null,
            'status' => $this->status ?: null,
            'user' => $this->user ?: null,
        ], fn ($value) => $value !== null);

        return View::make('pulse-boosted::livewire.traces', [
            'enabled' => $tracer->enabled(),
            'traces' => $traces->recent($filters, $this->orderBy, self::PER_PAGE, ($this->page - 1) * self::PER_PAGE),
            'total' => $traces->count($filters),
            'typeCounts' => $traces->countsByType($filters),
        ]);
    }
}
