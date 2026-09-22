<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Elazaroo\PulseBoosted\Queues\Contracts\JobRepository;
use Elazaroo\PulseBoosted\Queues\JobStatus;
use Elazaroo\PulseBoosted\Queues\QueueActions;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Livewire\Component;

/**
 * A single job: its timeline, its arguments, and the exception that killed it.
 *
 * @internal
 */
class JobDetail extends Component
{
    public string $uuid;

    /**
     * Put the job back onto its queue.
     */
    public function retry(QueueActions $actions): void
    {
        $actions->retry($this->uuid);

        $this->dispatch('job-action', message: 'Job pushed back onto the queue.');
    }

    /**
     * Forget the failed job.
     */
    public function forget(QueueActions $actions): void
    {
        $actions->forget($this->uuid);

        $this->redirectRoute('pulse-boosted.queues', navigate: true);
    }

    /**
     * Render the component.
     */
    public function render(JobRepository $repository, QueueActions $actions): Renderable
    {
        $job = $repository->find($this->uuid);

        return View::make('pulse-boosted::livewire.job-detail', [
            'job' => $job,
            'status' => $job === null ? null : JobStatus::tryFrom($job->status),
            'arguments' => $this->arguments($job),
            'timeline' => $this->timeline($job),
            'canManage' => $actions->allowed(),
            'canAct' => $actions->allowed() && $actions->actionable($job),
        ]);
    }

    /**
     * The captured arguments, decoded.
     *
     * This is plain JSON produced when the job was queued. The serialized
     * command that the queue itself stores is never touched.
     *
     * @return array<string, mixed>|null
     */
    protected function arguments(?object $job): ?array
    {
        if ($job === null || ($job->payload ?? null) === null) {
            return null;
        }

        $decoded = json_decode($job->payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The points in the job's life that we know about.
     *
     * @return list<array{label: string, at: int}>
     */
    protected function timeline(?object $job): array
    {
        if ($job === null) {
            return [];
        }

        $timeline = [];

        foreach ([
            'Queued' => $job->queued_at ?? null,
            'Started' => $job->started_at ?? null,
            'Finished' => $job->finished_at ?? null,
        ] as $label => $at) {
            if ($at !== null) {
                $timeline[] = ['label' => $label, 'at' => (int) $at];
            }
        }

        return $timeline;
    }
}
