<?php

namespace Elazaroo\PulseBoosted\Recorders;

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\Queues\Concerns\ResolvesQueueNames;
use Elazaroo\PulseBoosted\Queues\Contracts\JobRepository;
use Elazaroo\PulseBoosted\Queues\JobStatus;
use Elazaroo\PulseBoosted\Queues\PayloadCapture;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records one row per job, which is what the queue explorer and the job detail
 * view read.
 *
 * This is deliberately separate from the `Queues` recorder: that one keeps
 * bucketed counters for the throughput graph, this one keeps individual jobs.
 * Unlike `Queues`, it does not skip the `sync` connection — a synchronous job
 * still has a class, a duration and an exception worth seeing, and event
 * recording is the only way to observe one at all.
 *
 * @internal
 */
class Jobs
{
    use Concerns\Ignores, Concerns\Sampling, ResolvesQueueNames;

    /**
     * The longest exception string that will be stored.
     */
    protected const MAX_EXCEPTION_LENGTH = 10_000;

    /**
     * When each in-flight job started, keyed by UUID, so a duration can be
     * worked out when it finishes.
     *
     * @var array<string, int>
     */
    protected array $startedAt = [];

    /**
     * The events to listen for.
     *
     * @var list<class-string>
     */
    public array $listen = [
        JobQueued::class,
        JobProcessing::class,
        JobProcessed::class,
        JobReleasedAfterException::class,
        JobFailed::class,
        JobTimedOut::class,
    ];

    /**
     * Create a new recorder instance.
     */
    public function __construct(
        protected JobRepository $jobs,
        protected PayloadCapture $payload,
    ) {
        //
    }

    /**
     * Record the job.
     */
    public function record(JobQueued|JobProcessing|JobProcessed|JobReleasedAfterException|JobFailed|JobTimedOut $event): void
    {
        $now = CarbonImmutable::now();

        if ($event instanceof JobQueued) {
            $this->recordQueued($event, $now);

            return;
        }

        $this->recordLifecycle($event, $now);
    }

    /**
     * Record a job being pushed onto the queue.
     *
     * This is the only point at which the job object is still alive, so it is
     * also the only safe point at which its arguments can be read.
     */
    protected function recordQueued(JobQueued $event, CarbonImmutable $now): void
    {
        $payload = $this->decodePayload($event);

        $uuid = $payload['uuid'] ?? null;

        if (! is_string($uuid)) {
            return;
        }

        $name = $this->nameForQueuedEvent($event);

        if (! $this->shouldRecord($uuid, $name)) {
            return;
        }

        $this->jobs->record($uuid, [
            'job_id' => $event->id === null ? null : (string) $event->id,
            'connection' => $event->connectionName,
            'queue' => $this->queueForQueuedEvent($event),
            'name' => $name,
            'class' => $payload['data']['commandName'] ?? null,
            'status' => JobStatus::Queued->value,
            'queued_at' => $now->getTimestamp(),
            'max_tries' => $this->intOrNull($payload['maxTries'] ?? null),
            'timeout' => $this->intOrNull($payload['timeout'] ?? null),
            'batch_id' => $payload['data']['batchId'] ?? null,
            'payload' => $this->payload->capture($event->job),
        ]);
    }

    /**
     * Record a job moving through the worker.
     */
    protected function recordLifecycle(JobProcessing|JobProcessed|JobReleasedAfterException|JobFailed|JobTimedOut $event, CarbonImmutable $now): void
    {
        $job = $event->job;

        if (! $job instanceof JobContract) {
            return;
        }

        $uuid = $job->uuid();
        $name = $job->resolveName();

        if (! is_string($uuid) || ! $this->shouldRecord($uuid, $name)) {
            return;
        }

        // Every stage re-states the job's identity. A worker may be the first
        // thing that ever sees a job — it could have been queued before this
        // package was installed — and the row has to be complete either way.
        $attributes = [
            'job_id' => $job->getJobId() === null ? null : (string) $job->getJobId(),
            'connection' => $job->getConnectionName(),
            'queue' => $job->getQueue(),
            'name' => $name,
            'attempts' => $job->attempts(),
            'max_tries' => $this->intOrNull($job->maxTries()),
            'timeout' => $this->intOrNull($job->timeout()),
        ];

        $this->jobs->record($uuid, match (true) {
            $event instanceof JobProcessing => $attributes + $this->processing($uuid, $now),
            $event instanceof JobProcessed => $attributes + $this->finished($uuid, $now, JobStatus::Processed),
            $event instanceof JobReleasedAfterException => $attributes + $this->released($uuid),
            $event instanceof JobFailed => $attributes + $this->finished($uuid, $now, JobStatus::Failed) + $this->exception($event->exception),
            $event instanceof JobTimedOut => $attributes + $this->finished($uuid, $now, JobStatus::TimedOut),
        });
    }

    /**
     * Attributes for a job that has just been picked up.
     *
     * @return array<string, mixed>
     */
    protected function processing(string $uuid, CarbonImmutable $now): array
    {
        $this->startedAt[$uuid] = $now->getTimestampMs();

        return [
            'status' => JobStatus::Processing->value,
            'started_at' => $now->getTimestamp(),
        ];
    }

    /**
     * Attributes for a job that has reached the end of its life.
     *
     * @return array<string, mixed>
     */
    protected function finished(string $uuid, CarbonImmutable $now, JobStatus $status): array
    {
        return [
            'status' => $status->value,
            'finished_at' => $now->getTimestamp(),
            'duration_ms' => $this->duration($uuid, $now),
        ];
    }

    /**
     * Attributes for a job that threw but will be tried again.
     *
     * @return array<string, mixed>
     */
    protected function released(string $uuid): array
    {
        unset($this->startedAt[$uuid]);

        return ['status' => JobStatus::Released->value];
    }

    /**
     * Attributes describing a throwable.
     *
     * @return array<string, mixed>
     */
    protected function exception(?Throwable $exception): array
    {
        if ($exception === null) {
            return [];
        }

        return [
            'exception_class' => $exception::class,
            'exception' => Str::limit((string) $exception, self::MAX_EXCEPTION_LENGTH),
        ];
    }

    /**
     * How long the job ran for, when we saw it start.
     */
    protected function duration(string $uuid, CarbonImmutable $now): ?int
    {
        if (! isset($this->startedAt[$uuid])) {
            return null;
        }

        $started = $this->startedAt[$uuid];

        unset($this->startedAt[$uuid]);

        return $now->getTimestampMs() - $started;
    }

    /**
     * Whether this job should be recorded at all.
     *
     * Sampling is seeded with the UUID so that a sampled-out job stays sampled
     * out for its whole lifecycle, rather than appearing halfway through.
     */
    protected function shouldRecord(string $uuid, string $name): bool
    {
        return $this->shouldSampleDeterministically($uuid) && ! $this->shouldIgnore($name);
    }

    /**
     * The display name for a job being queued.
     */
    protected function nameForQueuedEvent(JobQueued $event): string
    {
        if (is_string($event->job)) {
            return $event->job;
        }

        if (method_exists($event->job, 'displayName')) {
            return $event->job->displayName();
        }

        return $event->job::class;
    }

    /**
     * Decode the payload of a queued job, tolerating malformed JSON.
     *
     * @return array<string, mixed>
     */
    protected function decodePayload(JobQueued $event): array
    {
        try {
            $payload = $event->payload();
        } catch (Throwable) {
            return [];
        }

        return is_array($payload) ? $payload : [];
    }

    /**
     * Cast a payload value to an integer, leaving nulls alone.
     */
    protected function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
