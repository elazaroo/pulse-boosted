<?php

namespace Elazaroo\PulseBoosted\Queues;

use Elazaroo\PulseBoosted\Queues\Contracts\JobRepository;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * The things the dashboard can do to a queue, as opposed to read from it.
 *
 * These are gated separately from viewing. Somebody who can look at metrics
 * should not automatically be able to pause a production queue or delete jobs
 * from it, so `managePulseBoostedQueues` has to be defined explicitly and
 * denies by default.
 */
class QueueActions
{
    /**
     * The gate that guards everything in this class.
     */
    public const GATE = 'managePulseBoostedQueues';

    /**
     * Create a new actions instance.
     */
    public function __construct(
        protected Gate $gate,
        protected FailedJobProviderInterface $failer,
        protected JobRepository $jobs,
        protected QueueManager $queues,
    ) {
        //
    }

    /**
     * Whether the current user may change queue state.
     */
    public function allowed(): bool
    {
        return $this->gate->allows(self::GATE);
    }

    /**
     * Whether this Laravel can pause queues at all.
     *
     * Pausing landed in Laravel 13. On earlier versions the buttons are hidden
     * rather than shown doing nothing.
     */
    public function supportsPausing(): bool
    {
        return method_exists($this->queues, 'pause')
            && method_exists($this->queues, 'resume')
            && method_exists($this->queues, 'isPaused');
    }

    /**
     * Whether a queue is currently paused.
     */
    public function paused(string $connection, string $queue): bool
    {
        if (! $this->supportsPausing()) {
            return false;
        }

        try {
            return (bool) $this->queues->isPaused($connection, $queue);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Stop workers picking anything up from a queue.
     *
     * Jobs already running are left to finish; this only stops new ones being
     * reserved.
     */
    public function pause(string $connection, string $queue): void
    {
        $this->authorize();

        if ($this->supportsPausing()) {
            $this->queues->pause($connection, $queue);
        }
    }

    /**
     * Let workers pick up from a queue again.
     */
    public function resume(string $connection, string $queue): void
    {
        $this->authorize();

        if ($this->supportsPausing()) {
            $this->queues->resume($connection, $queue);
        }
    }

    /**
     * Tell every worker to finish its current job and exit.
     *
     * A process manager is expected to start them again, which is how a queue
     * restart picks up new code.
     */
    public function restartWorkers(): void
    {
        $this->authorize();

        Artisan::call('queue:restart');
    }

    /**
     * Put a failed job back onto its queue.
     */
    public function retry(string $uuid): void
    {
        $this->authorize();

        Artisan::call('queue:retry', ['id' => [$uuid]]);
    }

    /**
     * Forget a single failed job.
     */
    public function forget(string $uuid): void
    {
        $this->authorize();

        $this->failer->forget($uuid);
    }

    /**
     * Forget every failed job.
     */
    public function flush(?int $hours = null): void
    {
        $this->authorize();

        $this->failer->flush($hours);
    }

    /**
     * Retry every failed job.
     */
    public function retryAll(): void
    {
        $this->authorize();

        Artisan::call('queue:retry', ['id' => ['all']]);
    }

    /**
     * Whether a recorded job is one we can act on.
     *
     * Only failed jobs can be retried or forgotten: the failed job table is
     * the one place a finished job's payload still exists.
     */
    public function actionable(?object $job): bool
    {
        if ($job === null || ($job->status ?? null) !== JobStatus::Failed->value) {
            return false;
        }

        $uuid = $job->uuid ?? null;

        return is_string($uuid) && $this->failer->find($uuid) !== null;
    }

    /**
     * Throw unless the current user is allowed to act.
     */
    protected function authorize(): void
    {
        $this->gate->authorize(self::GATE);
    }
}
