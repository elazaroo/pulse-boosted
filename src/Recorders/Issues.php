<?php

namespace Elazaroo\PulseBoosted\Recorders;

use Elazaroo\PulseBoosted\Events\ExceptionReported;
use Elazaroo\PulseBoosted\Issues\IssueRepository;
use Elazaroo\PulseBoosted\Pulse;
use Elazaroo\PulseBoosted\Traces\Tracer;

/**
 * Turns reported exceptions into issues.
 *
 * Deliberately not sampled with the traces: a trace is a convenience, but an
 * exception nobody recorded is a bug nobody knows about. Every one is counted,
 * and only the link back to a timeline depends on whether that execution
 * happened to be traced.
 *
 * @internal
 */
class Issues
{
    use Concerns\Ignores;

    /**
     * The events to listen for.
     *
     * @var class-string
     */
    public string $listen = ExceptionReported::class;

    /**
     * Create a new recorder instance.
     */
    public function __construct(
        protected IssueRepository $issues,
        protected Tracer $tracer,
        protected Pulse $pulse,
    ) {
        //
    }

    /**
     * Record the exception.
     */
    public function record(ExceptionReported $event): void
    {
        if ($this->shouldIgnore($event->exception::class)) {
            return;
        }

        $this->issues->record(
            $event->exception,
            $this->tracer->currentId(),
            $this->pulse->resolveAuthenticatedUserId(),
        );
    }
}
