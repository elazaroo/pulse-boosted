<?php

namespace Elazaroo\PulseBoosted\Recorders;

use Elazaroo\PulseBoosted\Events\ExceptionReported;
use Elazaroo\PulseBoosted\Issues\IssueRepository;
use Elazaroo\PulseBoosted\Pulse;
use Elazaroo\PulseBoosted\Traces\QueryOrigin;
use Elazaroo\PulseBoosted\Traces\Tracer;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Log\Events\MessageLogged;
use Throwable;
use WeakMap;

/**
 * Turns reported exceptions, and log lines at warning or above, into issues.
 *
 * Deliberately not sampled with the traces: a trace is a convenience, but an
 * exception nobody recorded is a bug nobody knows about — and a warning the
 * application writes a thousand times an hour is a problem somebody should
 * be able to mark dealt with. Every one is counted, and only the link back
 * to a timeline depends on whether that execution happened to be traced.
 *
 * @internal
 */
class Issues
{
    use Concerns\Ignores;

    /**
     * Log levels from most to least severe.
     */
    public const LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    /**
     * The events to listen for.
     *
     * @var list<class-string>
     */
    public array $listen = [
        ExceptionReported::class,
        MessageLogged::class,
    ];

    /**
     * Exceptions already made issues, so the log line Laravel writes for each
     * one straight after reporting it does not become a second issue.
     *
     * @var WeakMap<Throwable, true>
     */
    protected WeakMap $reported;

    /**
     * Create a new recorder instance.
     */
    public function __construct(
        protected IssueRepository $issues,
        protected Tracer $tracer,
        protected Pulse $pulse,
        protected Repository $config,
    ) {
        $this->reported = new WeakMap;
    }

    /**
     * Record the exception or log line.
     */
    public function record(ExceptionReported|MessageLogged $event): void
    {
        if ($event instanceof MessageLogged) {
            $this->recordLog($event);

            return;
        }

        $this->reported[$event->exception] = true;

        if ($this->shouldIgnore($event->exception::class)) {
            return;
        }

        $this->issues->record(
            $event->exception,
            $this->tracer->currentOrJustFinishedId(),
            $this->pulse->resolveAuthenticatedUserId(),
            $event->handled,
        );
    }

    /**
     * A log line severe enough to be an issue of its own.
     */
    protected function recordLog(MessageLogged $event): void
    {
        $level = strtolower((string) $event->level);

        if (! $this->severeEnough($level)) {
            return;
        }

        $exception = $event->context['exception'] ?? null;

        // Laravel logs every exception it reports; that one is an issue as
        // itself already.
        if ($exception instanceof Throwable && isset($this->reported[$exception])) {
            return;
        }

        $message = (string) $event->message;

        if ($message === '' || $this->shouldIgnore($message)) {
            return;
        }

        $this->issues->recordLog(
            level: $level,
            message: $message,
            origin: QueryOrigin::find(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40)),
            traceId: $this->tracer->currentOrJustFinishedId(),
            userId: $this->pulse->resolveAuthenticatedUserId(),
        );
    }

    /**
     * Whether a level is at or above the one configured for issues.
     */
    protected function severeEnough(string $level): bool
    {
        $threshold = strtolower((string) $this->config->get('pulse-boosted.issues.log_level', 'warning'));

        if ($threshold === '' || $threshold === 'none') {
            return false;
        }

        $at = array_search($level, self::LEVELS, true);
        $limit = array_search($threshold, self::LEVELS, true);

        return $at !== false && $limit !== false && $at <= $limit;
    }
}
