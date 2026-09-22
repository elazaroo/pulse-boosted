<?php

namespace Elazaroo\PulseBoosted\Queues;

use Elazaroo\PulseBoosted\Queues\Contracts\JobRepository;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Artisan;

/**
 * The things the dashboard can do to a queue, as opposed to read from it.
 *
 * These are gated separately from viewing. Somebody who can look at metrics
 * should not automatically be able to retry or delete production jobs, so
 * `managePulseBoostedQueues` has to be defined explicitly and denies by
 * default.
 */
class JobActions
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
