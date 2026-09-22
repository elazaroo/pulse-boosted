<?php

namespace Elazaroo\PulseBoosted\Recorders;

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\Pulse;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;

/**
 * Tracks the workers that are running.
 *
 * Laravel has no notion of a worker identity, so one is made here from the
 * host and process id. Each worker writes a heartbeat into `pulse_boosted_values`
 * as it loops; the card treats anything that has not been heard from recently
 * as gone, which is the best that can be done without a process manager.
 *
 * Heartbeats are throttled in memory rather than through the cache, because
 * this object lives for as long as the worker process does.
 *
 * @internal
 */
class Workers
{
    /**
     * How often a worker writes its heartbeat.
     */
    public const HEARTBEAT_SECONDS = 5;

    /**
     * The type these records are stored under.
     */
    public const TYPE = 'worker';

    /**
     * When this worker last wrote a heartbeat.
     */
    protected ?int $lastHeartbeatAt = null;

    /**
     * When the worker process started.
     */
    protected ?int $startedAt = null;

    /**
     * How many jobs this worker has finished.
     */
    protected int $processed = 0;

    /**
     * How many of those failed.
     */
    protected int $failed = 0;

    /**
     * The job being run right now, if any.
     */
    protected ?string $currentJob = null;

    /**
     * The connection the worker is serving, remembered because only some of
     * the events carry it.
     */
    protected ?string $connection = null;

    /**
     * The queues the worker is serving.
     */
    protected ?string $queue = null;

    /**
     * The events to listen for.
     *
     * @var list<class-string>
     */
    public array $listen = [
        Looping::class,
        JobProcessing::class,
        JobProcessed::class,
        JobFailed::class,
        WorkerStopping::class,
    ];

    /**
     * Create a new recorder instance.
     */
    public function __construct(
        protected Pulse $pulse,
    ) {
        //
    }

    /**
     * Record the worker's state.
     */
    public function record(Looping|JobProcessing|JobProcessed|JobFailed|WorkerStopping $event): void
    {
        $now = CarbonImmutable::now();

        $this->startedAt ??= $now->getTimestamp();

        match (true) {
            $event instanceof JobProcessing => $this->startedJob($event, $now),
            $event instanceof JobProcessed => $this->finishedJob($now),
            $event instanceof JobFailed => $this->failedJob($now),
            $event instanceof WorkerStopping => $this->stopping($event, $now),
            default => $this->looping($event, $now),
        };
    }

    /**
     * The worker is between jobs.
     */
    protected function looping(Looping $event, CarbonImmutable $now): void
    {
        $this->write($now, 'idle', $event->connectionName, $event->queue);
    }

    /**
     * The worker picked a job up.
     */
    protected function startedJob(JobProcessing $event, CarbonImmutable $now): void
    {
        $this->currentJob = $event->job->resolveName();

        $this->write($now, 'busy', $event->connectionName, $event->job->getQueue(), force: true);
    }

    /**
     * The worker finished a job.
     */
    protected function finishedJob(CarbonImmutable $now): void
    {
        $this->processed++;
        $this->currentJob = null;

        $this->write($now, 'idle', force: true);
    }

    /**
     * The worker's job threw.
     */
    protected function failedJob(CarbonImmutable $now): void
    {
        $this->processed++;
        $this->failed++;
        $this->currentJob = null;

        $this->write($now, 'idle', force: true);
    }

    /**
     * The worker is shutting down, so stop claiming it is there.
     */
    protected function stopping(WorkerStopping $event, CarbonImmutable $now): void
    {
        $this->currentJob = null;

        $this->write($now, 'stopped', force: true);
    }

    /**
     * Write the worker's current state, at most once per heartbeat interval
     * unless the state actually changed.
     */
    protected function write(
        CarbonImmutable $now,
        string $status,
        ?string $connection = null,
        ?string $queue = null,
        bool $force = false,
    ): void {
        $this->connection = $connection ?? $this->connection;
        $this->queue = $queue ?? $this->queue;

        if (! $force && $this->lastHeartbeatAt !== null
            && $now->getTimestamp() - $this->lastHeartbeatAt < self::HEARTBEAT_SECONDS) {
            return;
        }

        $this->lastHeartbeatAt = $now->getTimestamp();

        $this->pulse->set(self::TYPE, $this->key(), json_encode([
            'host' => $this->host(),
            'pid' => getmypid(),
            'status' => $status,
            'connection' => $this->connection,
            'queue' => $this->queue,
            'current_job' => $this->currentJob,
            'processed' => $this->processed,
            'failed' => $this->failed,
            'started_at' => $this->startedAt,
            'last_seen' => $now->getTimestamp(),
            'memory' => (int) round(memory_get_usage(true) / 1024 / 1024),
        ]) ?: '{}', $now->getTimestamp());
    }

    /**
     * This worker's identity.
     */
    protected function key(): string
    {
        return $this->host().':'.getmypid();
    }

    /**
     * The machine the worker is on.
     */
    protected function host(): string
    {
        return gethostname() ?: 'unknown';
    }
}
