<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\Queues\Capabilities;
use Elazaroo\PulseBoosted\Queues\Contracts\JobRepository;
use Elazaroo\PulseBoosted\Queues\InspectorManager;
use Elazaroo\PulseBoosted\Queues\JobStatus;
use Elazaroo\PulseBoosted\Queues\PendingJob;
use Elazaroo\PulseBoosted\Queues\QueueActions;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * Every job, on the dashboard, with its detail in the same page.
 *
 * Reads from two places and says which: the live tabs come off the queue
 * backend, the rest from our own rows. Opening a job does not navigate — the
 * panel is rendered from data this component already has, so inspecting a job
 * costs one round trip rather than a page load.
 *
 * @internal
 */
#[Lazy]
class Jobs extends Card
{
    /**
     * Tabs that read the queue backend rather than recorded history.
     */
    public const LIVE_TABS = ['waiting', 'delayed', 'running'];

    /**
     * How many rows a page holds.
     */
    public const PER_PAGE = 20;

    /**
     * Which tab is open. Everything, by default: the question is usually
     * "what happened", not "what is queued right now".
     */
    #[Url(as: 'tab')]
    public string $tab = 'all';

    #[Url(as: 'conn')]
    public ?string $connection = null;

    #[Url(as: 'queue')]
    public ?string $queue = null;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'job')]
    public ?string $selected = null;

    public int $page = 1;

    /**
     * Reset paging whenever the view changes underneath it.
     */
    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->page = 1;
        }

        if ($property === 'connection') {
            $this->queue = null;
        }
    }

    /**
     * Focus a queue picked from another card on the page.
     */
    #[On('queue-selected')]
    public function focusQueue(string $connection, string $queue): void
    {
        $this->connection = $connection;
        $this->queue = $queue;
        $this->page = 1;
        $this->selected = null;
    }

    /**
     * Open a job's detail panel.
     */
    public function select(string $uuid): void
    {
        $this->selected = $uuid;
    }

    /**
     * Close it.
     */
    public function deselect(): void
    {
        $this->selected = null;
    }

    /**
     * Put a failed job back onto its queue.
     */
    public function retry(string $uuid, QueueActions $actions): void
    {
        $actions->retry($uuid);
    }

    /**
     * Forget a failed job.
     */
    public function forget(string $uuid, QueueActions $actions): void
    {
        $actions->forget($uuid);

        if ($this->selected === $uuid) {
            $this->selected = null;
        }
    }

    /**
     * Retry every failed job.
     */
    public function retryAll(QueueActions $actions): void
    {
        $actions->retryAll();
    }

    /**
     * Forget every failed job.
     */
    public function flush(QueueActions $actions): void
    {
        $actions->flush();
    }

    /**
     * Render the component.
     */
    public function render(
        InspectorManager $inspectors,
        JobRepository $repository,
        QueueActions $actions,
    ): Renderable {
        $connections = $inspectors->connections();

        $this->connection ??= $connections->contains($default = config('queue.default'))
            ? $default
            : ($connections->first() ?? $default);

        $connection = (string) $this->connection;

        $inspector = $inspectors->for($connection);
        $capabilities = $this->capabilities($inspector->capabilities(), $inspectors);

        $queues = $inspector->queues();

        if ($this->queue !== null && ! $queues->contains($this->queue)) {
            $this->queue = null;
        }

        if (in_array($this->tab, self::LIVE_TABS, true) && ! $capabilities->listing) {
            $this->tab = 'all';
        }

        $live = in_array($this->tab, self::LIVE_TABS, true);

        return View::make('pulse-boosted::livewire.jobs', [
            'connections' => $connections,
            'queues' => $queues,
            'counts' => $repository->countsByStatus($this->filters()),
            'jobs' => $this->jobRows($inspector, $repository),
            'total' => $live ? null : $repository->count($this->filters($this->tab)),
            'live' => $live,
            'tabs' => $this->tabs($capabilities),
            'detail' => $this->detail($repository, $inspector, $actions),
            'canManage' => $actions->allowed(),
        ]);
    }

    /**
     * The rows for the current tab.
     *
     * @return Collection<int, mixed>
     */
    protected function jobRows(mixed $inspector, JobRepository $repository): Collection
    {
        $offset = ($this->page - 1) * self::PER_PAGE;
        $queue = $this->queue;

        return match ($this->tab) {
            'waiting' => $this->fromBackend(fn () => $inspector->pending($queue ?? '', self::PER_PAGE, $offset), $queue, $inspector),
            'delayed' => $this->fromBackend(fn () => $inspector->delayed($queue ?? '', self::PER_PAGE, $offset), $queue, $inspector),
            'running' => $this->fromBackend(fn () => $inspector->reserved($queue ?? '', self::PER_PAGE, $offset), $queue, $inspector),
            default => $repository->jobs($this->filters($this->tab), self::PER_PAGE, $offset),
        };
    }

    /**
     * Read a live tab, across every queue when none is picked.
     *
     * @param  callable(): Collection<int, PendingJob>  $read
     * @return Collection<int, PendingJob>
     */
    protected function fromBackend(callable $read, ?string $queue, mixed $inspector): Collection
    {
        if ($queue !== null) {
            return $read();
        }

        $method = match ($this->tab) {
            'delayed' => 'delayed',
            'running' => 'reserved',
            default => 'pending',
        };

        return $inspector->queues()
            ->flatMap(fn (string $name) => $inspector->{$method}($name, self::PER_PAGE, 0))
            ->take(self::PER_PAGE)
            ->values();
    }

    /**
     * The job whose panel is open, if any.
     *
     * @return array<string, mixed>|null
     */
    protected function detail(JobRepository $repository, mixed $inspector, QueueActions $actions): ?array
    {
        if ($this->selected === null) {
            return null;
        }

        $job = $repository->find($this->selected);

        if ($job === null) {
            // Queued before recording started, or already trimmed. The backend
            // may still have it, which is better than an empty panel.
            return $this->detailFromBackend($inspector);
        }

        $arguments = $job->payload === null ? null : json_decode($job->payload, true);

        return [
            'source' => 'recorded',
            'uuid' => $job->uuid,
            'name' => $job->name,
            'class' => $job->class,
            'status' => JobStatus::tryFrom($job->status),
            'statusLabel' => JobStatus::tryFrom($job->status)?->label() ?? $job->status,
            'connection' => $job->connection,
            'queue' => $job->queue,
            'attempts' => $job->attempts,
            'maxTries' => $job->max_tries,
            'timeout' => $job->timeout,
            'durationMs' => $job->duration_ms,
            'batchId' => $job->batch_id,
            'jobId' => $job->job_id,
            'exception' => $job->exception,
            'exceptionClass' => $job->exception_class,
            'arguments' => is_array($arguments) ? $arguments : null,
            'timeline' => $this->timeline($job),
            'canAct' => $actions->allowed() && $actions->actionable($job),
        ];
    }

    /**
     * A job we never recorded, described from whatever is on the queue.
     *
     * @return array<string, mixed>|null
     */
    protected function detailFromBackend(mixed $inspector): ?array
    {
        foreach ($inspector->queues() as $queue) {
            foreach (['pending', 'delayed', 'reserved'] as $state) {
                $match = $inspector->{$state}($queue, 200, 0)
                    ->first(fn (PendingJob $job) => $job->uuid === $this->selected);

                if ($match === null) {
                    continue;
                }

                return [
                    'source' => 'backend',
                    'uuid' => $match->uuid,
                    'name' => $match->name,
                    'class' => $match->class,
                    'status' => null,
                    'statusLabel' => match ($state) {
                        'delayed' => 'Delayed',
                        'reserved' => 'Running',
                        default => 'Waiting',
                    },
                    'connection' => $this->connection,
                    'queue' => $queue,
                    'attempts' => $match->attempts,
                    'maxTries' => $match->maxTries,
                    'timeout' => null,
                    'durationMs' => null,
                    'batchId' => $match->batchId,
                    'jobId' => $match->id,
                    'exception' => null,
                    'exceptionClass' => null,
                    'arguments' => null,
                    'timeline' => array_filter([
                        $match->createdAt ? ['label' => 'Queued', 'at' => $match->createdAt->getTimestamp()] : null,
                        $match->availableAt ? ['label' => 'Available', 'at' => $match->availableAt->getTimestamp()] : null,
                        $match->reservedAt ? ['label' => 'Reserved', 'at' => $match->reservedAt->getTimestamp()] : null,
                    ]),
                    'canAct' => false,
                ];
            }
        }

        return ['source' => 'missing', 'uuid' => $this->selected];
    }

    /**
     * The points in a recorded job's life that we know about.
     *
     * @return list<array{label: string, at: int}>
     */
    protected function timeline(object $job): array
    {
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

    /**
     * The filters applied to recorded rows.
     *
     * @return array<string, string|null>
     */
    protected function filters(?string $status = null): array
    {
        return array_filter([
            'connection' => $this->connection,
            'queue' => $this->queue,
            'status' => $status === 'all' ? null : $status,
            'search' => $this->search ?: null,
        ], fn ($value) => $value !== null);
    }

    /**
     * The tabs to show, given what the driver can do.
     *
     * @return array<string, string>
     */
    protected function tabs(Capabilities $capabilities): array
    {
        $live = $capabilities->listing
            ? ['waiting' => 'Waiting', 'delayed' => 'Delayed', 'running' => 'Running']
            : [];

        return ['all' => 'All'] + $live + [
            JobStatus::Processed->value => 'Completed',
            JobStatus::Failed->value => 'Failed',
        ];
    }

    /**
     * The driver's capabilities, narrowed by configuration.
     */
    protected function capabilities(Capabilities $capabilities, InspectorManager $inspectors): Capabilities
    {
        if (! $inspectors->enabled()) {
            return Capabilities::none('Live queue inspection is disabled in the configuration.');
        }

        if (! $inspectors->listingEnabled()) {
            return new Capabilities(
                counts: $capabilities->counts,
                discovery: $capabilities->discovery,
                reason: 'Listing queue contents is disabled in the configuration.',
            );
        }

        return $capabilities;
    }

    /**
     * A timestamp as a readable moment.
     */
    public function moment(?int $timestamp): string
    {
        return $timestamp === null
            ? '—'
            : CarbonImmutable::createFromTimestamp($timestamp)->diffForHumans();
    }
}
