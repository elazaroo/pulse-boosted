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
     * When the application finished booting, for a request's bootstrap stage.
     */
    protected ?float $bootedAt = null;

    /**
     * The id of the execution most recently closed.
     */
    protected ?string $lastId = null;

    /**
     * When the most recent execution closed, as a microtime.
     */
    protected ?float $lastFinishedAt = null;

    /**
     * Called with each execution as it finishes, before it is kept or dropped.
     *
     * @var list<callable(Trace): void>
     */
    protected array $finishing = [];

    /**
     * Callbacks that decide an event is not worth keeping, by event type.
     *
     * @var array<string, list<callable(string, array<string, mixed>): bool>>
     */
    protected array $rejecting = [];

    /**
     * Callbacks that rewrite an event before it is kept, by event type.
     *
     * @var array<string, list<callable(string, array<string, mixed>): (string|array{0: string, 1: array<string, mixed>}|null)>>
     */
    protected array $redacting = [];

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
    public function start(string $type, string $name, array $meta = [], ?float $startedAt = null): void
    {
        if ($this->paused || ! $this->pulse->recording() || ! $this->enabled() || $this->current !== null) {
            return;
        }

        // The draw happens now, but the verdict waits: an execution that
        // lost it is still recorded, in memory, in case it turns out to be
        // one worth keeping. Only if nothing could make it so is there no
        // point recording at all.
        $sampled = $this->shouldSample($type);

        if (! $sampled && ! $this->keepsUnsampled()) {
            return;
        }

        if (function_exists('memory_reset_peak_usage') && $type !== 'request') {
            memory_reset_peak_usage();
        }

        if (is_scalar($deploy = $this->config->get('pulse-boosted.deployment')) && (string) $deploy !== '') {
            $meta['deploy'] = (string) $deploy;
        }

        $this->current = new Trace(
            id: (string) Str::uuid(),
            parentId: $this->inheritedParent,
            type: $type,
            name: $name,
            startedAt: $startedAt === null ? CarbonImmutable::now() : CarbonImmutable::createFromTimestampMs((int) round($startedAt * 1000)),
            meta: $meta,
            sampled: $sampled,
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
        // Pulse::ignore() means "this is the dashboard's own work"; a trace
        // full of Pulse's inserts is noise in someone else's timeline.
        if ($this->paused || $this->current === null || ! $this->pulse->recording()) {
            return;
        }

        foreach ([...($this->rejecting[$type] ?? []), ...($this->rejecting['*'] ?? [])] as $callback) {
            if ($this->ignore(fn () => $callback($label, $meta)) === true) {
                return;
            }
        }

        foreach ([...($this->redacting[$type] ?? []), ...($this->redacting['*'] ?? [])] as $callback) {
            $result = $this->ignore(fn () => $callback($label, $meta));

            if (is_string($result)) {
                $label = $result;
            } elseif (is_array($result) && isset($result[0]) && is_string($result[0])) {
                $label = $result[0];
                $meta = is_array($result[1] ?? null) ? $result[1] : $meta;
            }
        }

        if ($type === TraceEvent::EXCEPTION) {
            $this->current->keep('exception');
        }

        // Which stage it happened in, noted now rather than worked out later
        // from its offset: two things a fraction of a millisecond apart round
        // to the same millisecond, and could then land in the wrong stage.
        if (($stage = $this->current->currentStage()) !== null) {
            $meta['stage'] = $stage;
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
    public function finish(?string $status = null, array $meta = []): void
    {
        if ($this->current === null) {
            return;
        }

        // Who it ran for, as the application sees it at the end — after
        // authentication middleware has run. Asked without querying: only a
        // user already resolved, or one remembered at login, is used.
        if ($this->current->userId() === null) {
            $user = $this->pulse->rescue(fn () => $this->ignore(fn () => $this->pulse->resolveAuthenticatedUserId()));

            $this->current->setUser($user === null ? null : (string) $user);
        }

        // A request settles its status when the response goes out, and is
        // closed only after its terminating callbacks have run.
        [$settledStatus, $settledMeta] = $this->current->settled() ?? ['ok', []];

        $trace = $this->current->finish($status ?? $settledStatus, [...$settledMeta, ...$meta]);

        $this->current = null;
        $this->lastId = $trace->id;
        $this->lastFinishedAt = microtime(true);

        foreach ($this->finishing as $callback) {
            $this->pulse->rescue(fn () => $callback($trace));
        }

        if ($trace->worthKeeping($this->keepRules())) {
            $this->buffer[] = $trace;
        }
    }

    /**
     * Note that the current execution has moved into a lifecycle stage.
     */
    public function stage(string $stage, ?float $atMs = null): void
    {
        if ($this->paused) {
            return;
        }

        $this->current?->stage($stage, $atMs);
    }

    /**
     * The stage the current execution is in.
     */
    public function currentStage(): ?string
    {
        return $this->current?->currentStage();
    }

    /**
     * Record how the current execution ended, to be applied when it closes.
     *
     * @param  array<string, mixed>  $meta
     */
    public function settle(string $status, array $meta = []): void
    {
        $this->current?->settle($status, $meta);
    }

    /**
     * Note that the application has finished booting.
     */
    public function markBooted(?float $at = null): void
    {
        $this->bootedAt = $at ?? microtime(true);
    }

    /**
     * When the application finished booting, if it has.
     */
    public function bootedAt(): ?float
    {
        return $this->bootedAt;
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
     * Leave out events of a type the callback says are not worth keeping.
     *
     * @param  callable(string, array<string, mixed>): bool  $callback
     */
    public function reject(string $type, callable $callback): void
    {
        $this->rejecting[$type][] = $callback;
    }

    /**
     * Rewrite events of a type before they are kept — to take a secret out
     * of a query, or a token out of a URL.
     *
     * The callback gets the label and meta, and returns a new label, a
     * [label, meta] pair, or null to leave it as it was.
     *
     * @param  callable(string, array<string, mixed>): (string|array{0: string, 1: array<string, mixed>}|null)  $callback
     */
    public function redact(string $type, callable $callback): void
    {
        $this->redacting[$type][] = $callback;
    }

    /**
     * Draw again for the current execution at a different rate.
     */
    public function resample(float $rate): void
    {
        $this->current?->resample($this->draw($rate));
    }

    /**
     * Run something with each execution as it finishes, before the decision
     * to keep or drop it — so the callback can still ask for it to be kept.
     *
     * @param  callable(Trace): void  $callback
     */
    public function whenFinishing(callable $callback): void
    {
        $this->finishing[] = $callback;
    }

    /**
     * The id of the execution most recently closed, for anything that hears
     * about the end of an execution after the tracer has.
     */
    public function lastId(): ?string
    {
        return $this->lastId;
    }

    /**
     * The execution something belongs to: the open one, or one that closed a
     * moment ago.
     *
     * A worker reports a job's exception only after it has announced that
     * the job failed, which is what closes the job's trace; without looking
     * back this far, the exception would belong to nothing.
     */
    public function currentOrJustFinishedId(float $withinSeconds = 2.0): ?string
    {
        if ($this->current !== null) {
            return $this->current->id;
        }

        if ($this->lastFinishedAt !== null && microtime(true) - $this->lastFinishedAt <= $withinSeconds) {
            return $this->lastId;
        }

        return null;
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
     * Attach the application's own attributes to the current context.
     *
     * Does nothing when this execution was not sampled in, so application code
     * can call it unconditionally without checking first.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function context(array $attributes): void
    {
        if ($this->paused) {
            return;
        }

        $this->current?->context($attributes);
    }

    /**
     * What has been attached to the current context so far.
     *
     * @return array<string, scalar|null>
     */
    public function currentContext(): array
    {
        return $this->current?->currentContext() ?? [];
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
            // Not truncate: DDL commits implicitly on MySQL, ending any
            // transaction this is called inside.
            $this->connection()->table('pulse_boosted_trace_events')->delete();
            $this->connection()->table('pulse_boosted_traces')->delete();
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

        return $this->draw((float) $rate);
    }

    /**
     * One draw at a rate between 0 and 1.
     */
    protected function draw(float $rate): bool
    {
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
     * What makes an execution that lost the draw worth writing anyway.
     *
     * @return array{failed: bool, exceptions: bool, slower_than: int|float|null}
     */
    protected function keepRules(): array
    {
        $slowerThan = $this->config->get('pulse-boosted.traces.keep.slower_than', 1000);

        return [
            'failed' => (bool) $this->config->get('pulse-boosted.traces.keep.failed', true),
            'exceptions' => (bool) $this->config->get('pulse-boosted.traces.keep.exceptions', true),
            'slower_than' => $slowerThan === null || $slowerThan === '' ? null : (float) $slowerThan,
        ];
    }

    /**
     * Whether anything could make an unsampled execution worth keeping.
     */
    protected function keepsUnsampled(): bool
    {
        $rules = $this->keepRules();

        return $rules['failed'] || $rules['exceptions'] || $rules['slower_than'] !== null;
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
