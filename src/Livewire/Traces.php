<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Elazaroo\PulseBoosted\Traces\Tracer;
use Elazaroo\PulseBoosted\Traces\TraceRepository;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * Executions, and what happened inside them.
 *
 * The rest of the dashboard answers "how many"; this answers "what led to it".
 * Opening one shows its timeline: every query, cache read, dispatched job,
 * outgoing call, exception and log line, in the order they happened and at the
 * offset they happened at.
 *
 * @phpstan-import-type TraceEventRow from TraceRepository
 *
 * @internal
 */
#[Lazy]
class Traces extends Card
{
    public const PER_PAGE = 20;

    #[Url(as: 'trace_type')]
    public string $type = '';

    #[Url(as: 'trace_status')]
    public string $status = '';

    #[Url(as: 'trace_sort')]
    public string $orderBy = 'latest';

    #[Url(as: 'trace')]
    public ?string $selected = null;

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
     * Open a trace.
     */
    public function select(string $traceId): void
    {
        $this->selected = $traceId;
    }

    /**
     * Close it.
     */
    public function deselect(): void
    {
        $this->selected = null;
    }

    /**
     * Open the trace a job belongs to, when asked from the jobs table.
     */
    #[On('trace-job')]
    public function openForJob(string $uuid, TraceRepository $traces): void
    {
        $trace = $traces->forJob($uuid);

        $this->selected = $trace?->trace_id;
    }

    /**
     * Render the component.
     */
    public function render(TraceRepository $traces, Tracer $tracer): Renderable
    {
        $filters = array_filter([
            'type' => $this->type ?: null,
            'status' => $this->status ?: null,
        ], fn ($value) => $value !== null);

        return View::make('pulse-boosted::livewire.traces', [
            'enabled' => $tracer->enabled(),
            'traces' => $traces->recent($filters, $this->orderBy, self::PER_PAGE, ($this->page - 1) * self::PER_PAGE),
            'total' => $traces->count($filters),
            'typeCounts' => $traces->countsByType($filters),
            'detail' => $this->detail($traces),
        ]);
    }

    /**
     * The trace whose timeline is open, if any.
     *
     * @return array<string, mixed>|null
     */
    protected function detail(TraceRepository $traces): ?array
    {
        if ($this->selected === null) {
            return null;
        }

        $trace = $traces->find($this->selected);

        if ($trace === null) {
            return ['missing' => true];
        }

        $events = $traces->events($trace->trace_id);

        return [
            'missing' => false,
            'trace' => $trace,
            'meta' => $this->decode($trace->meta),
            'events' => $this->withShares($events, (int) ($trace->duration_ms ?: 0)),
            'children' => $traces->children($trace->trace_id),
            'parent' => $trace->parent_trace_id === null ? null : $traces->find($trace->parent_trace_id),
            'summary' => $this->summarise($events),
        ];
    }

    /**
     * Work out where each event sits on the timeline, as percentages, so the
     * view can draw a bar without doing arithmetic in Blade.
     *
     * @param  Collection<int, TraceEventRow>  $events
     * @return Collection<int, array<string, mixed>>
     */
    protected function withShares(Collection $events, int $totalMs): Collection
    {
        $total = max($totalMs, 1);

        return $events->map(function ($event) use ($total) {
            $offset = (int) $event->offset_ms;
            $duration = (int) ($event->duration_ms ?? 0);

            return [
                'type' => $event->type,
                'label' => $event->label,
                'level' => $event->level,
                'offsetMs' => $offset,
                'durationMs' => $event->duration_ms === null ? null : $duration,
                'meta' => $this->decode($event->meta),
                // Clamped: an event can finish after the context it was
                // measured against, and a bar hanging off the end reads as a
                // bug rather than as rounding.
                'left' => min(100, max(0, $offset / $total * 100)),
                'width' => min(100, max(0.5, $duration / $total * 100)),
            ];
        });
    }

    /**
     * Totals per event type, so the panel can lead with "18 queries, 240ms".
     *
     * @param  Collection<int, TraceEventRow>  $events
     * @return array<string, array{count: int, ms: int}>
     */
    protected function summarise(Collection $events): array
    {
        $summary = [];

        foreach ($events as $event) {
            $summary[$event->type] ??= ['count' => 0, 'ms' => 0];
            $summary[$event->type]['count']++;
            $summary[$event->type]['ms'] += (int) ($event->duration_ms ?? 0);
        }

        return $summary;
    }

    /**
     * Decode a stored JSON column.
     *
     * @return array<string, mixed>
     */
    protected function decode(?string $json): array
    {
        if ($json === null) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
