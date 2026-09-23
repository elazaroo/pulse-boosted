<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Elazaroo\PulseBoosted\Traces\TraceRepository;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Url;

/**
 * A list of one kind of thing that happened inside traces.
 *
 * Log lines, sent mail and delivered notifications are all recorded as part
 * of an execution already, so listing them costs nothing extra — it asks the
 * same rows a different way, and every row can be followed back to the
 * timeline it came from.
 *
 * @internal
 */
abstract class TraceEventCard extends Card
{
    public const PER_PAGE = 20;

    #[Url]
    public string $search = '';

    public int $page = 1;

    /**
     * The kind of event this card lists.
     */
    abstract protected function eventType(): string;

    /**
     * What to call it.
     */
    abstract protected function title(): string;

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
     * Show the trace an entry came from.
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
        $filters = $this->filters();

        return View::make('pulse-boosted::livewire.trace-events', [
            'title' => $this->title(),
            'eventType' => $this->eventType(),
            'events' => $traces->eventsOfType($this->eventType(), $filters, self::PER_PAGE, ($this->page - 1) * self::PER_PAGE),
            'total' => $traces->countEventsOfType($this->eventType(), $filters),
            'levels' => $this->levels($traces),
            'level' => $this->level(),
        ]);
    }

    /**
     * The filters this card applies.
     *
     * @return array<string, string|null>
     */
    protected function filters(): array
    {
        return array_filter([
            'search' => $this->search ?: null,
            'level' => $this->level() ?: null,
        ], fn ($value) => $value !== null);
    }

    /**
     * The level filter, for cards that have one.
     */
    protected function level(): string
    {
        return '';
    }

    /**
     * The levels to offer, for cards that have them.
     *
     * @return array<string, int>
     */
    protected function levels(TraceRepository $traces): array
    {
        return [];
    }
}
