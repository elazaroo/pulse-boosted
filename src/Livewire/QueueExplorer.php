<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Elazaroo\PulseBoosted\Queues\Capabilities;
use Elazaroo\PulseBoosted\Queues\Contracts\JobRepository;
use Elazaroo\PulseBoosted\Queues\Counts;
use Elazaroo\PulseBoosted\Queues\InspectorManager;
use Elazaroo\PulseBoosted\Queues\JobStatus;
use Elazaroo\PulseBoosted\Queues\QueueActions;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The queue explorer.
 *
 * Reads from two places and is explicit about which: the live tabs come from
 * the queue backend itself, the recorded tabs from our own rows. The backend
 * cannot tell you about a job that already finished, and our rows cannot tell
 * you what is waiting right now, so neither alone is enough.
 *
 * @internal
 */
class QueueExplorer extends Component
{
    /**
     * Tabs that read the queue backend rather than recorded history.
     */
    public const LIVE_TABS = ['pending', 'delayed', 'running'];

    /**
     * How many rows a page holds.
     */
    public const PER_PAGE = 25;

    #[Url]
    public string $tab = 'pending';

    #[Url]
    public ?string $connection = null;

    #[Url]
    public ?string $queue = null;

    #[Url]
    public string $search = '';

    #[Url]
    public int $page = 1;

    /**
     * Reset paging whenever the view changes underneath it.
     */
    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->page = 1;
        }

        if (in_array($property, ['connection'], true)) {
            $this->queue = null;
        }
    }

    /**
     * Stop workers picking anything up from the queue being viewed.
     */
    public function pause(QueueActions $actions): void
    {
        $actions->pause((string) $this->connection, (string) $this->queue);
    }

    /**
     * Let workers pick up from it again.
     */
    public function resume(QueueActions $actions): void
    {
        $actions->resume((string) $this->connection, (string) $this->queue);
    }

    /**
     * Put a failed job back onto its queue.
     */
    public function retry(string $uuid, QueueActions $actions): void
    {
        $actions->retry($uuid);

        $this->dispatch('job-action', message: 'Job pushed back onto the queue.');
    }

    /**
     * Forget a failed job.
     */
    public function forget(string $uuid, QueueActions $actions): void
    {
        $actions->forget($uuid);

        $this->dispatch('job-action', message: 'Failed job deleted.');
    }

    /**
     * Retry every failed job.
     */
    public function retryAll(QueueActions $actions): void
    {
        $actions->retryAll();

        $this->dispatch('job-action', message: 'All failed jobs pushed back onto their queues.');
    }

    /**
     * Forget every failed job.
     */
    public function flush(QueueActions $actions): void
    {
        $actions->flush();

        $this->dispatch('job-action', message: 'Failed jobs cleared.');
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

        // Open on the connection the application actually uses, not whichever
        // one happens to be listed first in config/queue.php.
        $default = config('queue.default');

        $this->connection ??= $connections->contains($default)
            ? $default
            : ($connections->first() ?? $default);

        $connection = (string) $this->connection;

        $inspector = $inspectors->for($connection);
        $capabilities = $this->capabilities($inspector->capabilities(), $inspectors);

        $queues = $inspector->queues();
        $this->queue ??= $queues->first() ?? 'default';

        $counts = $capabilities->counts
            ? $inspector->counts($this->queue)
            : new Counts;

        if (in_array($this->tab, self::LIVE_TABS, true) && ! $capabilities->listing) {
            $this->tab = 'failed';
        }

        return View::make('pulse-boosted::livewire.queue-explorer', [
            'connections' => $connections,
            'queues' => $queues,
            'capabilities' => $capabilities,
            'counts' => $counts,
            'recordedCounts' => $repository->countsByStatus($this->recordedFilters()),
            'rows' => $this->rows($inspector, $repository),
            'total' => $this->total($repository),
            'live' => in_array($this->tab, self::LIVE_TABS, true),
            'canManage' => $actions->allowed(),
            'canPause' => $actions->allowed() && $actions->supportsPausing(),
            'paused' => $actions->paused($connection, (string) $this->queue),
            'tabs' => $this->tabs($capabilities),
        ]);
    }

    /**
     * The rows for the current tab.
     *
     * @return Collection<int, mixed>
     */
    protected function rows(mixed $inspector, JobRepository $repository): Collection
    {
        $offset = ($this->page - 1) * self::PER_PAGE;

        return match ($this->tab) {
            'pending' => $inspector->pending($this->queue, self::PER_PAGE, $offset),
            'delayed' => $inspector->delayed($this->queue, self::PER_PAGE, $offset),
            'running' => $inspector->reserved($this->queue, self::PER_PAGE, $offset),
            default => $repository->jobs($this->recordedFilters($this->tab), self::PER_PAGE, $offset),
        };
    }

    /**
     * How many rows the current tab has in total, where we can know.
     */
    protected function total(JobRepository $repository): ?int
    {
        return in_array($this->tab, self::LIVE_TABS, true)
            ? null
            : $repository->count($this->recordedFilters($this->tab));
    }

    /**
     * The filters applied to recorded rows.
     *
     * @return array<string, string|null>
     */
    protected function recordedFilters(?string $status = null): array
    {
        return array_filter([
            'connection' => $this->connection,
            'queue' => $this->queue,
            'status' => $status,
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
            ? [
                'pending' => 'Waiting',
                'delayed' => 'Delayed',
                'running' => 'Running',
            ]
            : [];

        return $live + [
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
            return Capabilities::none('Live queue inspection is disabled in the Pulse Boosted configuration.');
        }

        if (! $inspectors->listingEnabled()) {
            return new Capabilities(
                counts: $capabilities->counts,
                discovery: $capabilities->discovery,
                reason: 'Listing queue contents is disabled in the Pulse Boosted configuration.',
            );
        }

        return $capabilities;
    }
}
