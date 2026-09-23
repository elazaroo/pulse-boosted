<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\Support\People;
use Elazaroo\PulseBoosted\Traces\TraceRepository;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;

/**
 * Requests grouped by route: how often, how they answered, how long they took.
 * Opening one shows how that changed over the period, and the requests worth
 * looking at.
 *
 * @internal
 */
#[Lazy(isolate: false)]
class Routes extends Card
{
    /**
     * @var 'calls'|'avg'|'p95'|'server'
     */
    public string $orderBy = 'calls';

    public string $search = '';

    /**
     * The route whose panel is open, by its name: "GET /orders/{order}".
     */
    #[Url(as: 'route')]
    public ?string $selected = null;

    /**
     * Open the slowest request to a route.
     */
    public function showTrace(string $traceId): void
    {
        $this->dispatch('open-trace', traceId: $traceId);
    }

    /**
     * Open a route.
     */
    public function select(string $name): void
    {
        $this->selected = $name;
    }

    /**
     * Close it.
     */
    public function deselect(): void
    {
        $this->selected = null;
    }

    public function render(TraceRepository $traces, People $people): Renderable
    {
        $detail = null;

        if ($this->selected !== null) {
            $detail = $traces->routeDetail(
                $this->selected,
                CarbonImmutable::now()->sub($this->periodAsInterval())->getTimestamp(),
            ) ?? ['missing' => true, 'name' => $this->selected];

            $detail['people'] = isset($detail['slowest'])
                ? $people->resolve([...$detail['slowest']->pluck('user_id'), ...$detail['failures']->pluck('user_id')])
                : [];
        }

        return View::make('pulse-boosted::livewire.routes', [
            'routes' => $traces->routeGroups(trim($this->search), $this->orderBy),
            'detail' => $detail,
        ]);
    }
}
