<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\People\PersonReport;
use Elazaroo\PulseBoosted\Support\People;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One user: what they did, what went wrong for them, and when.
 *
 * Part of the page rather than of any card, like the trace timeline, so a
 * user can be opened from wherever their name appears.
 *
 * @internal
 */
class PersonViewer extends Component
{
    use Concerns\HasPeriod;

    #[Url(as: 'person')]
    public ?string $selected = null;

    /**
     * Open a user.
     */
    #[On('open-person')]
    public function open(string|int $id): void
    {
        $this->selected = (string) $id;
    }

    /**
     * Close it.
     */
    public function deselect(): void
    {
        $this->selected = null;
    }

    /**
     * Show one of their executions.
     */
    public function showTrace(string $traceId): void
    {
        $this->dispatch('open-trace', traceId: $traceId);
    }

    public function render(PersonReport $report, People $people): Renderable
    {
        return View::make('pulse-boosted::livewire.person-viewer', [
            'person' => $this->selected === null ? null : $people->one($this->selected),
            'report' => $this->selected === null ? null : $report->for(
                $this->selected,
                CarbonImmutable::now()->sub($this->periodAsInterval())->getTimestamp(),
            ),
            'periodLabel' => $this->periodForHumans(),
        ]);
    }
}
