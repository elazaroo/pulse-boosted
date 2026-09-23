<?php

namespace Elazaroo\PulseBoosted\Recorders;

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\Events\ExceptionReported;
use Elazaroo\PulseBoosted\Traces\MarksControllerStage;
use Elazaroo\PulseBoosted\Traces\QueryOrigin;
use Elazaroo\PulseBoosted\Traces\RequestSnapshot;
use Elazaroo\PulseBoosted\Traces\Stage;
use Elazaroo\PulseBoosted\Traces\TraceEvent;
use Elazaroo\PulseBoosted\Traces\Tracer;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgetFailed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Events\Terminating;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Routing\Events\PreparingResponse;
use Illuminate\Routing\Events\ResponsePrepared;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Throwable;
use WeakMap;

/**
 * Joins everything that happens in one execution onto a single trace.
 *
 * Counting exceptions tells you something broke; a trace tells you what the
 * application was doing at the time. This listens for the events that open and
 * close an execution context, and for the ones that belong inside it.
 *
 * @internal
 */
class Traces
{
    use Concerns\Ignores;

    /**
     * The key a queued job carries so it can be joined to whatever queued it.
     */
    public const PAYLOAD_KEY = 'pulse_boosted_trace';

    /**
     * Commands that host other executions rather than being one.
     *
     * A worker runs for days and works thousands of jobs; a scheduler runs
     * the tasks that are due. Tracing the command itself would open one
     * execution that never closes, and every job or task inside it would
     * find the tracer busy and go unrecorded.
     *
     * @var list<string>
     */
    public const HOST_COMMANDS = [
        'queue:work',
        'queue:listen',
        'horizon',
        'horizon:work',
        'horizon:supervisor',
        'schedule:run',
        'schedule:work',
        'octane:start',
        'octane:frankenphp',
        'octane:roadrunner',
        'octane:swoole',
        'reverb:start',
        'pulse-boosted:work',
        'pulse-boosted:check',
    ];

    /**
     * Commands that run constantly and say nothing worth a trace.
     *
     * @var list<string>
     */
    public const NOISE_COMMANDS = [
        'horizon:snapshot',
        'horizon:status',
        'queue:monitor',
        'schedule:list',
        'schedule:finish',
        'inertia:start-ssr',
        'invoke-serialized-closure',
        'octane:status',
        'config:cache',
        'package:discover',
        // A whole interactive session as one execution says nothing.
        'tinker',
    ];

    /**
     * The events to listen for.
     *
     * @var list<class-string>
     */
    public array $listen = [
        // Contexts that open and close a trace.
        RouteMatched::class,
        RequestHandled::class,

        // The stages of a request. Listening for an event this version of
        // Laravel does not have is harmless: it never fires.
        PreparingResponse::class,
        ResponsePrepared::class,
        Terminating::class,

        CommandStarting::class,
        CommandFinished::class,
        ScheduledTaskStarting::class,
        ScheduledTaskFinished::class,
        ScheduledTaskFailed::class,
        JobProcessing::class,
        JobProcessed::class,
        JobFailed::class,

        // Things that happen inside one.
        QueryExecuted::class,
        CacheHit::class,
        CacheMissed::class,
        KeyWritten::class,
        KeyForgotten::class,
        KeyWriteFailed::class,
        KeyForgetFailed::class,
        JobQueued::class,
        MessageLogged::class,
        ExceptionReported::class,
        ResponseReceived::class,
        ConnectionFailed::class,
        MessageSent::class,
        NotificationSent::class,
    ];

    /**
     * Exceptions already on the timeline, so Laravel logging the same one a
     * moment later does not put it there twice.
     *
     * @var WeakMap<Throwable, true>
     */
    protected WeakMap $reported;

    /**
     * Create a new recorder instance.
     */
    public function __construct(
        protected Tracer $tracer,
        protected Repository $config,
        protected RequestSnapshot $snapshot,
    ) {
        $this->reported = new WeakMap;
    }

    /**
     * Record the event against the current trace.
     */
    public function record(object $event): void
    {
        match (true) {
            // Opening and closing contexts.
            $event instanceof RouteMatched => $this->startRequest($event),
            $event instanceof RequestHandled => $this->finishRequest($event),
            $event instanceof PreparingResponse => $this->stageAfter(Stage::CONTROLLER, Stage::RENDER),
            $event instanceof ResponsePrepared => $this->stageAfter(Stage::RENDER, Stage::AFTER_MIDDLEWARE),
            $event instanceof Terminating => $this->tracer->stage(Stage::TERMINATING),
            $event instanceof CommandStarting => $this->startCommand($event),
            $event instanceof CommandFinished => $this->tracer->finish($event->exitCode === 0 ? 'ok' : 'failed', ['exit_code' => $event->exitCode]),
            $event instanceof ScheduledTaskStarting => $this->tracer->start('schedule', $this->taskName($event->task)),
            $event instanceof ScheduledTaskFinished => $this->tracer->finish('ok'),
            $event instanceof ScheduledTaskFailed => $this->tracer->finish('failed'),
            $event instanceof JobProcessing => $this->startJob($event),
            $event instanceof JobProcessed => $this->tracer->finish('ok'),
            $event instanceof JobFailed => $this->finishFailedJob($event),

            // Children.
            $event instanceof QueryExecuted => $this->query($event),
            $event instanceof CacheHit => $this->tracer->event(TraceEvent::CACHE, $event->key, meta: ['result' => 'hit']),
            $event instanceof CacheMissed => $this->tracer->event(TraceEvent::CACHE, $event->key, meta: ['result' => 'miss']),
            $event instanceof KeyWritten => $this->tracer->event(TraceEvent::CACHE, $event->key, meta: ['result' => 'write']),
            $event instanceof KeyForgotten => $this->tracer->event(TraceEvent::CACHE, $event->key, meta: ['result' => 'delete']),
            $event instanceof KeyWriteFailed, $event instanceof KeyForgetFailed => $this->tracer->event(TraceEvent::CACHE, $event->key, meta: ['result' => 'failure'], level: 'warning'),
            $event instanceof JobQueued => $this->jobQueued($event),
            $event instanceof MessageLogged => $this->log($event),
            $event instanceof ExceptionReported => $this->exception($event),
            $event instanceof ResponseReceived => $this->httpResponse($event),
            $event instanceof ConnectionFailed => $this->tracer->event(TraceEvent::HTTP, (string) $event->request->url(), meta: ['failed' => true]),
            $event instanceof MessageSent => $this->mail($event),
            $event instanceof NotificationSent => $this->notification($event),
            default => null,
        };
    }

    /**
     * An HTTP request becomes the context for everything it does.
     */
    protected function startRequest(RouteMatched $event): void
    {
        $name = $event->route->uri();

        if ($this->isOwnTraffic($name) || $this->shouldIgnore($name)) {
            return;
        }

        $startedAt = $this->requestStartedAt($event->request);

        $label = $event->request->method().' /'.ltrim($name, '/');

        // Every Livewire interaction goes to the same update route, which
        // would make them one row. Named by the components instead, each
        // interaction is its own thing — and the dashboard's own polling can
        // be recognised and left out.
        if (($components = $this->livewireComponents($event->request, $name)) !== null) {
            if ($components !== [] && collect($components)->every(fn (string $component) => str_starts_with($component, 'pulse-boosted.'))) {
                return;
            }

            if ($components !== []) {
                $label = 'LIVEWIRE '.implode(', ', $components);
            }
        }

        $this->tracer->start('request', $label, [
            'method' => $event->request->method(),
            'uri' => $event->request->path(),
            'route' => $event->route->getName(),
            'action' => $event->route->getActionName(),
        ], $startedAt);

        if (! $this->tracer->recording()) {
            return;
        }

        // Everything before the route matched: booting the framework, then
        // the global middleware. Measured from when PHP received the request,
        // so the timeline starts where the user's wait did.
        $bootedAt = $this->tracer->bootedAt();

        if ($startedAt !== null && $bootedAt !== null && $bootedAt >= $startedAt) {
            $this->tracer->stage(Stage::BOOTSTRAP, 0);
            $this->tracer->stage(Stage::MIDDLEWARE, ($bootedAt - $startedAt) * 1000);
        } else {
            $this->tracer->stage(Stage::MIDDLEWARE, 0);
        }

        $this->markControllerStart($event->route);
    }

    /**
     * The components a Livewire update request is for, or null if it is not
     * one.
     *
     * @return list<string>|null
     */
    protected function livewireComponents(Request $request, string $uri): ?array
    {
        if (! preg_match('#(^|/)livewire[^/]*/update$#', $uri)) {
            return null;
        }

        $names = [];

        foreach ((array) $request->input('components', []) as $component) {
            $snapshot = is_array($component) ? ($component['snapshot'] ?? null) : null;
            $snapshot = is_string($snapshot) ? json_decode($snapshot, true) : null;
            $name = is_array($snapshot) ? ($snapshot['memo']['name'] ?? null) : null;

            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * When PHP started handling this request, if that can be trusted.
     *
     * Under Octane the process outlives the request, so LARAVEL_START is long
     * past; a start more than a minute ago is not this request's.
     */
    protected function requestStartedAt(Request $request): ?float
    {
        $start = $request->server('REQUEST_TIME_FLOAT') ?? (defined('LARAVEL_START') ? LARAVEL_START : null);

        if (! is_numeric($start)) {
            return null;
        }

        $now = CarbonImmutable::now()->getPreciseTimestamp(3) / 1000;
        $start = (float) $start;

        return $start <= $now && $now - $start < 60 ? $start : null;
    }

    /**
     * Put the controller-stage marker innermost in the route's middleware.
     */
    protected function markControllerStart(Route $route): void
    {
        $middleware = $route->middleware();

        if (in_array(MarksControllerStage::class, $middleware, true)) {
            return;
        }

        $route->action['middleware'] = [...$middleware, MarksControllerStage::class];

        // The route may already have worked out its middleware — it keeps it
        // between requests under Octane — so make it work it out again.
        $route->computedMiddleware = null;
    }

    /**
     * Move to the next stage, but only from the one it should follow.
     *
     * Laravel prepares a response more than once on the way out; only the
     * first time, straight after the controller, is the render.
     */
    protected function stageAfter(string $from, string $to): void
    {
        if ($this->tracer->currentStage() === $from) {
            $this->tracer->stage($to);
        }
    }

    /**
     * Whether this request is the dashboard watching itself.
     *
     * The dashboard polls every few seconds, and each poll queries the very
     * tables it is displaying. Left alone it becomes the busiest thing in the
     * application and buries the traces you came to look at.
     */
    protected function isOwnTraffic(string $uri): bool
    {
        $path = trim((string) $this->config->get('pulse-boosted.path', 'pulse-boosted'), '/');
        $uri = trim($uri, '/');

        return $uri === $path || str_starts_with($uri, $path.'/');
    }

    /**
     * Close a request's trace with what actually came back.
     *
     * Without this every request reads as successful, because nothing else
     * tells the tracer the response was a 500.
     */
    protected function finishRequest(RequestHandled $event): void
    {
        $status = $event->response->getStatusCode();

        // The response is on its way, but terminating callbacks have yet to
        // run, and they are part of the request too. The trace is closed once
        // they have.
        $meta = ['status' => $status];

        // What it carried, for the failures only: that is when it is needed,
        // and keeping it for every request would multiply the rows' size.
        if ($status >= 500 && $this->tracer->recording()) {
            $meta['request'] = $this->snapshot->capture($event->request);
        }

        $this->tracer->settle($status >= 500 ? 'failed' : 'ok', $meta);
        $this->tracer->stage(Stage::SENDING);
    }

    /**
     * So does an Artisan command.
     */
    protected function startCommand(CommandStarting $event): void
    {
        if ($event->command === '' || $this->shouldIgnore($event->command)) {
            return;
        }

        if (in_array($event->command, self::HOST_COMMANDS, true)) {
            return;
        }

        if (in_array($event->command, self::NOISE_COMMANDS, true) && ! $this->config->get('pulse-boosted.traces.capture_vendor_commands', false)) {
            return;
        }

        $this->tracer->start('command', $event->command);
    }

    /**
     * And a job being worked, which carries a line back to whatever queued it.
     */
    protected function startJob(JobProcessing $event): void
    {
        $name = $event->job->resolveName();

        if ($this->shouldIgnore($name)) {
            return;
        }

        $this->tracer->inheritParent($this->parentFromPayload($event->job->payload()));

        $this->tracer->start('job', $name, [
            'connection' => $event->connectionName,
            'queue' => $event->job->getQueue(),
            'uuid' => $event->job->uuid(),
            'attempt' => $event->job->attempts(),
        ]);
    }

    /**
     * A failed job closes its trace with the exception that killed it.
     */
    protected function finishFailedJob(JobFailed $event): void
    {
        $this->tracer->event(
            TraceEvent::EXCEPTION,
            $event->exception::class.': '.Str::limit($event->exception->getMessage(), 200),
            meta: ['class' => $event->exception::class],
        );

        $this->tracer->finish('failed');
    }

    /**
     * A query, with the time it took.
     */
    protected function query(QueryExecuted $event): void
    {
        if (! $this->tracer->recording()) {
            return;
        }

        $meta = ['connection' => $event->connectionName];

        // Where in the application it was run from — the line to go and
        // change. Only worked out for executions being recorded, since a
        // backtrace per query is not free.
        if (($origin = QueryOrigin::find(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40))) !== null) {
            $meta['file'] = $origin[0];
            $meta['line'] = $origin[1];
        }

        $this->tracer->event(TraceEvent::QUERY, $event->sql, $event->time, $meta);
    }

    /**
     * A job being pushed onto a queue from inside this context.
     */
    protected function jobQueued(JobQueued $event): void
    {
        $name = match (true) {
            is_string($event->job) => $event->job,
            method_exists($event->job, 'displayName') => $event->job->displayName(),
            default => $event->job::class,
        };

        $this->tracer->event(TraceEvent::JOB, $name, meta: [
            'connection' => $event->connectionName,
            // JobQueued only gained a queue property in Laravel 11.
            'queue' => $event->queue ?? null,
        ]);
    }

    /**
     * A log line, kept at or above the configured level.
     */
    protected function log(MessageLogged $event): void
    {
        if (! $this->passesLogLevel((string) $event->level)) {
            return;
        }

        // Laravel's handler logs every exception it reports, straight after
        // reporting it. That exception is already on the timeline as itself,
        // with its class and location, so the log line would only repeat it.
        // An exception logged by hand without being reported still counts.
        $exception = $event->context['exception'] ?? null;

        if ($exception instanceof Throwable && isset($this->reported[$exception])) {
            return;
        }

        $this->tracer->event(
            TraceEvent::LOG,
            (string) $event->message,
            level: (string) $event->level,
            meta: $event->context === [] ? [] : ['context' => $this->readableContext($event->context)],
        );
    }

    /**
     * An exception reported through Pulse.
     */
    protected function exception(ExceptionReported $event): void
    {
        $this->reported[$event->exception] = true;

        $this->tracer->event(
            TraceEvent::EXCEPTION,
            $event->exception::class.': '.Str::limit($event->exception->getMessage(), 200),
            meta: [
                'class' => $event->exception::class,
                'file' => $event->exception->getFile(),
                'line' => $event->exception->getLine(),
                'handled' => $event->handled,
            ],
            level: $event->handled ? 'handled' : 'unhandled',
        );
    }

    /**
     * An outgoing HTTP call.
     */
    protected function httpResponse(ResponseReceived $event): void
    {
        $this->tracer->event(
            TraceEvent::HTTP,
            $event->request->method().' '.$event->request->url(),
            meta: ['status' => $event->response->status()],
        );
    }

    /**
     * A sent mail.
     */
    protected function mail(MessageSent $event): void
    {
        try {
            $subject = $event->message->getSubject() ?? 'No subject';
        } catch (Throwable) {
            $subject = 'Unknown';
        }

        $this->tracer->event(TraceEvent::MAIL, (string) $subject);
    }

    /**
     * A sent notification.
     */
    protected function notification(NotificationSent $event): void
    {
        $this->tracer->event(TraceEvent::NOTIFICATION, $event->notification::class, meta: [
            'channel' => $event->channel,
        ]);
    }

    /**
     * The trace that queued a job, if it carried one.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function parentFromPayload(array $payload): ?string
    {
        $parent = $payload[self::PAYLOAD_KEY] ?? null;

        return is_string($parent) ? $parent : null;
    }

    /**
     * Whether a log line is at or above the configured level.
     */
    protected function passesLogLevel(string $level): bool
    {
        $order = ['debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3, 'error' => 4, 'critical' => 5, 'alert' => 6, 'emergency' => 7];

        $minimum = (string) $this->config->get('pulse-boosted.traces.log_level', 'debug');

        return ($order[$level] ?? 0) >= ($order[$minimum] ?? 0);
    }

    /**
     * Reduce log context to something JSON can hold.
     *
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>
     */
    protected function readableContext(array $context): array
    {
        return collect($context)
            ->map(fn ($value) => match (true) {
                is_scalar($value), $value === null => $value,
                is_array($value) => '[array]',
                is_object($value) => '['.$value::class.']',
                default => '['.gettype($value).']',
            })
            ->take(20)
            ->all();
    }

    /**
     * A readable name for a scheduled task.
     */
    protected function taskName(object $task): string
    {
        $description = $task->description ?? null;

        if (is_string($description) && $description !== '') {
            return $description;
        }

        $command = $task->command ?? null;

        return is_string($command) && $command !== '' ? $command : 'Closure';
    }
}
