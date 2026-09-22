<?php

namespace Elazaroo\PulseBoosted\Recorders;

use Elazaroo\PulseBoosted\Events\ExceptionReported;
use Elazaroo\PulseBoosted\Traces\TraceEvent;
use Elazaroo\PulseBoosted\Traces\Tracer;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Str;
use Throwable;

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
     * The events to listen for.
     *
     * @var list<class-string>
     */
    public array $listen = [
        // Contexts that open and close a trace.
        RouteMatched::class,
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
        JobQueued::class,
        MessageLogged::class,
        ExceptionReported::class,
        ResponseReceived::class,
        ConnectionFailed::class,
        MessageSent::class,
        NotificationSent::class,
    ];

    /**
     * Create a new recorder instance.
     */
    public function __construct(
        protected Tracer $tracer,
        protected Repository $config,
    ) {
        //
    }

    /**
     * Record the event against the current trace.
     */
    public function record(object $event): void
    {
        match (true) {
            // Opening and closing contexts.
            $event instanceof RouteMatched => $this->startRequest($event),
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

        if ($this->shouldIgnore($name)) {
            return;
        }

        $this->tracer->start('request', $event->request->method().' /'.ltrim($name, '/'), [
            'method' => $event->request->method(),
            'uri' => $event->request->path(),
            'route' => $event->route->getName(),
        ]);
    }

    /**
     * So does an Artisan command.
     */
    protected function startCommand(CommandStarting $event): void
    {
        if ($event->command === '' || $this->shouldIgnore($event->command)) {
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
        $this->tracer->event(TraceEvent::QUERY, $event->sql, $event->time, [
            'connection' => $event->connectionName,
        ]);
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
            'queue' => $event->queue,
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
        $this->tracer->event(
            TraceEvent::EXCEPTION,
            $event->exception::class.': '.Str::limit($event->exception->getMessage(), 200),
            meta: [
                'class' => $event->exception::class,
                'file' => $event->exception->getFile(),
                'line' => $event->exception->getLine(),
            ],
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
