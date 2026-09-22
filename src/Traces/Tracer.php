<?php

namespace Elazaroo\PulseBoosted\Traces;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Elazaroo\PulseBoosted\Pulse;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Throwable;

/**
 * Holds the execution context everything else hangs off.
 *
 * Pulse counts things: how many exceptions this hour, how many slow queries.
 * That tells you something is wrong but not what led to it. A trace is the
 * other half — one request, command, scheduled task or job, with every query,
 * cache read, dispatched job, outgoing call, exception and log line that
 * happened inside it, in order.
 *
 * The cost is a row per event, so this samples at the entry point: a trace is
 * either recorded whole or not at all. Half a trace would be worse than none,
 * because the gaps would look like time the application spent doing nothing.
 */
class Tracer
{
    /**
     * The trace being recorded, if this execution was sampled in.
     */
    protected ?Trace $current = null;

    /**
     * Finished traces waiting to be written.
     *
     * @var list<Trace>
     */
    protected array $buffer = [];

    /**
     * A trace id inherited from whatever queued the job we are running.
     */
    protected ?string $inheritedParent = null;

    /**
     * Whether recording is suspended.
     *
     * Writing a trace runs queries of its own, and the recorder listens for
     * queries, so without this the tracer would record itself recording.
     */
    protected bool $paused = false;

    /**
     * Create a new tracer.
     */
    public function __construct(
        protected Pulse $pulse,
        protected DatabaseManager $db,
        protected Repository $config,
    ) {
        //
    }

    /**
     * Whether tracing is switched on at all.
     */
    public function enabled(): bool
    {
        return (bool) $this->config->get('pulse-boosted.traces.enabled', true);
    }

    /**
     * Begin an execution context.
     *
     * @param  array<string, mixed>  $meta
     */
    public function start(string $type, string $name, array $meta = []): void
    {
        if ($this->paused || ! $this->enabled() || $this->current !== null) {
            return;
        }

        if (! $this->shouldSample($type)) {
            return;
        }

        $this->current = new Trace(
            id: (string) Str::uuid(),
            parentId: $this->inheritedParent,
            type: $type,
            name: $name,
            startedAt: CarbonImmutable::now(),
            meta: $meta,
        );

        $this->inheritedParent = null;
    }

    /**
     * Record something that happened inside the current context.
     *
     * @param  array<string, mixed>  $meta
     */
    public function event(string $type, string $label, ?float $durationMs = null, array $meta = [], ?string $level = null): void
    {
        if ($this->paused || $this->current === null) {
            return;
        }

        $this->current->add(new TraceEvent(
            type: $type,
            label: Str::limit($label, 1_000),
            offsetMs: $this->current->elapsedMs(),
            durationMs: $durationMs,
            level: $level,
            meta: $meta,
        ), $this->maxEvents());
    }

    /**
     * Close the current context.
     *
     * @param  array<string, mixed>  $meta
     */
    public function finish(string $status = 'ok', array $meta = []): void
    {
        if ($this->current === null) {
            return;
        }

        $this->buffer[] = $this->current->finish($status, $meta);

        $this->current = null;
    }

    /**
     * The id of the context being recorded, for anything that needs to point
     * back at it later — a queued job, most usefully.
     */
    public function currentId(): ?string
    {
        return $this->current?->id;
    }

    /**
     * Whether a context is open.
     */
    public function recording(): bool
    {
        return $this->current !== null;
    }

    /**
     * Note the trace that queued the job we are about to run, so the two can
     * be joined up even though they happened in different processes.
     */
    public function inheritParent(?string $traceId): void
    {
        $this->inheritedParent = $traceId;
    }

    /**
     * Attach the authenticated user to the current context.
     */
    public function setUser(string|int|null $id): void
    {
        $this->current?->setUser($id === null ? null : (string) $id);
    }

    /**
     * Run something without recording any of it.
     *
     * The equivalent of wrapping a block you do not want in the timeline —
     * a health check that queries in a loop, say.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function ignore(callable $callback): mixed
    {
        $cached = $this->paused;

        try {
            $this->paused = true;

            return $callback();
        } finally {
            $this->paused = $cached;
        }
    }

    /**
     * Suspend recording until resumed.
     */
    public function pause(): void
    {
        $this->paused = true;
    }

    /**
     * Resume recording.
     */
    public function resume(): void
    {
        $this->paused = false;
    }

    /**
     * Write everything finished so far.
     */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $traces = $this->buffer;

        $this->buffer = [];

        $this->ignore(fn () => $this->pulse->ignore(function () use ($traces) {
            $rows = [];
            $events = [];

            foreach ($traces as $trace) {
                $rows[] = $trace->attributes();

                foreach ($trace->events() as $event) {
                    $events[] = $event->attributes($trace->id);
                }
            }

            $this->connection()->table('pulse_boosted_traces')->insert($rows);

            // Chunked because a busy request can carry hundreds of queries,
            // and SQL Server caps the parameters in one statement.
            foreach (array_chunk($events, 200) as $chunk) {
                $this->connection()->table('pulse_boosted_trace_events')->insert($chunk);
            }
        }));
    }

    /**
     * Drop traces past the configured retention.
     */
    public function trim(): void
    {
        $keep = $this->config->get('pulse-boosted.traces.trim.keep') ?? '24 hours';

        $before = CarbonImmutable::now()->sub(CarbonInterval::fromString($keep))->getTimestamp();

        $this->ignore(fn () => $this->pulse->ignore(function () use ($before) {
            $expired = $this->connection()
                ->table('pulse_boosted_traces')
                ->where('started_at', '<=', $before)
                ->pluck('trace_id');

            if ($expired->isEmpty()) {
                return;
            }

            foreach ($expired->chunk(500) as $chunk) {
                $this->connection()
                    ->table('pulse_boosted_trace_events')
                    ->whereIn('trace_id', $chunk->all())
                    ->delete();
            }

            $this->connection()
                ->table('pulse_boosted_traces')
                ->where('started_at', '<=', $before)
                ->delete();
        }));
    }

    /**
     * Drop every trace.
     */
    public function purge(): void
    {
        $this->ignore(fn () => $this->pulse->ignore(function () {
            $this->connection()->table('pulse_boosted_trace_events')->truncate();
            $this->connection()->table('pulse_boosted_traces')->truncate();
        }));
    }

    /**
     * Whether this execution should be recorded.
     *
     * Sampling happens here, at the entry point, so a trace is whole or absent.
     */
    protected function shouldSample(string $type): bool
    {
        $rate = $this->config->get("pulse-boosted.traces.sample_rates.{$type}");

        $rate ??= $this->config->get('pulse-boosted.traces.sample_rate', 0.1);

        if ($rate >= 1) {
            return true;
        }

        if ($rate <= 0) {
            return false;
        }

        try {
            return random_int(1, 1_000_000) <= (int) round($rate * 1_000_000);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The most events one trace may carry, so a runaway loop cannot write a
     * million rows.
     */
    protected function maxEvents(): int
    {
        return (int) $this->config->get('pulse-boosted.traces.max_events', 500);
    }

    /**
     * The connection the rest of Pulse Boosted stores on.
     */
    protected function connection(): Connection
    {
        return $this->db->connection(
            $this->config->get('pulse-boosted.storage.database.connection')
        );
    }
}
