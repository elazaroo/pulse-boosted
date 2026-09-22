<?php

namespace Elazaroo\PulseBoosted\Traces;

use Carbon\CarbonImmutable;

/**
 * One execution context, while it is still being recorded.
 *
 * @internal
 */
class Trace
{
    /**
     * @var list<TraceEvent>
     */
    protected array $events = [];

    /**
     * How many events were dropped after the cap was reached, so the timeline
     * can say so rather than quietly ending early.
     */
    protected int $dropped = 0;

    protected ?float $durationMs = null;

    protected string $status = 'ok';

    protected ?string $userId = null;

    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $parentId,
        public readonly string $type,
        public readonly string $name,
        public readonly CarbonImmutable $startedAt,
        protected array $meta = [],
    ) {
        //
    }

    /**
     * Milliseconds since this context began.
     */
    public function elapsedMs(): float
    {
        return (CarbonImmutable::now()->getPreciseTimestamp(3) - $this->startedAt->getPreciseTimestamp(3)) / 1000;
    }

    /**
     * Add a child event, up to the cap.
     */
    public function add(TraceEvent $event, int $max): void
    {
        if (count($this->events) >= $max) {
            $this->dropped++;

            return;
        }

        $this->events[] = $event;
    }

    /**
     * Close the context.
     *
     * @param  array<string, mixed>  $meta
     */
    public function finish(string $status, array $meta = []): self
    {
        $this->status = $status;
        $this->durationMs = $this->elapsedMs();
        $this->meta = array_merge($this->meta, $meta);

        if ($this->dropped > 0) {
            $this->meta['dropped_events'] = $this->dropped;
        }

        return $this;
    }

    /**
     * Attach the authenticated user.
     */
    public function setUser(?string $id): void
    {
        $this->userId = $id;
    }

    /**
     * @return list<TraceEvent>
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * The row to persist.
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'trace_id' => $this->id,
            'parent_trace_id' => $this->parentId,
            'type' => $this->type,
            'name' => $this->name,
            'started_at' => $this->startedAt->getTimestamp(),
            'duration_ms' => $this->durationMs === null ? null : (int) round($this->durationMs),
            'status' => $this->status,
            'user_id' => $this->userId,
            'meta' => $this->meta === [] ? null : json_encode($this->meta, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR),
        ];
    }
}
