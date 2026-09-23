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

    protected ?float $durationMs = null;

    protected string $status = 'ok';

    protected ?string $userId = null;

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
            'user_id' => $this->userId,
            'meta' => $meta === [] ? null : json_encode($meta, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR),
        ];
    }
}
