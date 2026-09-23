<?php

namespace Elazaroo\PulseBoosted\Traces;

use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;
use Stringable;
use UnitEnum;

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

    /**
     * How many of each kind of event happened, and how long they took,
     * counted as they arrive — including any past the cap, so the totals are
     * right even when the timeline is not complete.
     *
     * @var array<string, array{count: int, ms: float}>
     */
    protected array $counts = [];

    protected ?float $durationMs = null;

    protected string $status = 'ok';

    protected ?string $userId = null;

    /**
     * Why this execution should be written even though it lost the sampling
     * draw, once something has made it worth keeping.
     */
    protected ?string $keepBecause = null;

    /**
     * Where each lifecycle stage began, in milliseconds from the start.
     *
     * @var list<array{0: string, 1: float}>
     */
    protected array $stages = [];

    /**
     * How it ended, when that is known before it is closed — a request's
     * status is known when the response is sent, but it goes on running
     * terminating callbacks after.
     *
     * @var array{0: string, 1: array<string, mixed>}|null
     */
    protected ?array $settled = null;

    /**
     * Whatever the application chose to attach to this execution.
     *
     * @var array<string, scalar|null>
     */
    protected array $context = [];

    /**
     * The most keys one execution may attach. A loop calling context() with a
     * new key each time would otherwise write an unbounded row.
     */
    public const MAX_CONTEXT = 25;

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
        protected bool $sampled = true,
    ) {
        //
    }

    /**
     * Milliseconds since this context began.
     */
    public function elapsedMs(): float
    {
        // getPreciseTimestamp(3) is already milliseconds, so the difference
        // is too; dividing again turned every duration and offset into zero.
        return CarbonImmutable::now()->getPreciseTimestamp(3) - $this->startedAt->getPreciseTimestamp(3);
    }

    /**
     * Add a child event, up to the cap.
     */
    public function add(TraceEvent $event, int $max): void
    {
        $this->counts[$event->type] ??= ['count' => 0, 'ms' => 0.0];
        $this->counts[$event->type]['count']++;
        $this->counts[$event->type]['ms'] += (float) ($event->durationMs ?? 0);

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

        if ($this->counts !== []) {
            $this->meta['counts'] = array_map(
                fn (array $count) => ['count' => $count['count'], 'ms' => (int) round($count['ms'])],
                $this->counts,
            );
        }

        // Peak for the process. The tracer resets it when an execution starts
        // where PHP allows, so a worker's tenth job is not blamed for the
        // memory its first one used.
        $this->meta['peak_memory'] = memory_get_peak_usage(true);

        if ($this->stages !== []) {
            $this->meta['stages'] = $this->stageDurations();
        }

        return $this;
    }

    /**
     * Note that a lifecycle stage has begun.
     */
    public function stage(string $name, ?float $atMs = null): void
    {
        $last = $this->stages[array_key_last($this->stages) ?? -1] ?? null;

        if ($last !== null && $last[0] === $name) {
            return;
        }

        $this->stages[] = [$name, $atMs ?? $this->elapsedMs()];
    }

    /**
     * The stage it is in now.
     */
    public function currentStage(): ?string
    {
        return $this->stages === [] ? null : $this->stages[array_key_last($this->stages)][0];
    }

    /**
     * Record how it ended, for when it is closed later.
     *
     * @param  array<string, mixed>  $meta
     */
    public function settle(string $status, array $meta = []): void
    {
        $this->settled = [$status, $meta];
    }

    /**
     * The outcome recorded by settle(), if any.
     *
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    public function settled(): ?array
    {
        return $this->settled;
    }

    /**
     * Whether it won the sampling draw.
     */
    public function sampled(): bool
    {
        return $this->sampled;
    }

    /**
     * Draw again, with a different rate — for a route that asked for its own.
     */
    public function resample(bool $sampled): void
    {
        $this->sampled = $sampled;
    }

    /**
     * How long it took, once finished.
     */
    public function durationMs(): ?float
    {
        return $this->durationMs;
    }

    /**
     * How it ended, once finished.
     */
    public function status(): string
    {
        return $this->status;
    }

    /**
     * Keep this execution whatever the sampling draw said.
     */
    public function keep(string $because): void
    {
        $this->keepBecause ??= $because;
    }

    /**
     * Whether it should be written, and if it was not sampled, why.
     *
     * @param  array{failed?: bool, exceptions?: bool, slower_than?: int|float|null}  $rules
     */
    public function worthKeeping(array $rules): bool
    {
        if ($this->sampled) {
            return true;
        }

        if (($rules['failed'] ?? true) && $this->status === 'failed') {
            $this->keep('failed');
        }

        if (($rules['slower_than'] ?? null) !== null && $this->durationMs !== null && $this->durationMs >= (float) $rules['slower_than']) {
            $this->keep('slow');
        }

        if ($this->keepBecause === 'exception' && ! ($rules['exceptions'] ?? true)) {
            $this->keepBecause = null;
        }

        return $this->keepBecause !== null;
    }

    /**
     * Each stage with when it started and how long it lasted.
     *
     * @return list<array{name: string, start: int, duration: int}>
     */
    protected function stageDurations(): array
    {
        $end = (float) $this->durationMs;
        $stages = [];

        foreach ($this->stages as $i => [$name, $start]) {
            $until = $this->stages[$i + 1][1] ?? $end;

            $stages[] = [
                'name' => $name,
                'start' => (int) round($start),
                'duration' => (int) round(max(0, $until - $start)),
            ];
        }

        return $stages;
    }

    /**
     * Attach the authenticated user.
     */
    public function setUser(?string $id): void
    {
        $this->userId = $id;
    }

    /**
     * Attach the application's own attributes to this execution.
     *
     * A trace says what the application did; this says what it was doing it
     * about — which tenant, which order, which feature flag was on. Without it
     * a slow request is one of a thousand slow requests.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function context(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $key = (string) $key;

            if (count($this->context) >= self::MAX_CONTEXT && ! array_key_exists($key, $this->context)) {
                continue;
            }

            $this->context[$key] = $this->scalar($value);
        }
    }

    /**
     * What has been attached so far.
     *
     * @return array<string, scalar|null>
     */
    public function currentContext(): array
    {
        return $this->context;
    }

    /**
     * Reduce a value to something that survives a round trip through JSON and
     * fits in a table cell.
     */
    protected function scalar(mixed $value): string|int|float|bool|null
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value), is_float($value) => $value,
            is_string($value) => Str::limit($value, 500),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof Stringable, is_object($value) && method_exists($value, '__toString') => Str::limit((string) $value, 500),
            is_array($value) => Str::limit(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '', 500),
            is_object($value) => $value::class,
            default => null,
        };
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
        $meta = $this->meta;

        if (! $this->sampled && $this->keepBecause !== null) {
            $meta['kept_because'] = $this->keepBecause;
        }

        if ($this->context !== []) {
            $meta['context'] = $this->context;
        }

        return [
            'trace_id' => $this->id,
            'parent_trace_id' => $this->parentId,
            'type' => $this->type,
            'name' => $this->name,
            'started_at' => $this->startedAt->getTimestamp(),
            'duration_ms' => $this->durationMs === null ? null : (int) round($this->durationMs),
            'status' => $this->status,
            'sampled' => $this->sampled,
            'user_id' => $this->userId,
            'meta' => $meta === [] ? null : json_encode($meta, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR),
        ];
    }
}
