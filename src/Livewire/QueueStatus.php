<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Elazaroo\PulseBoosted\Queues\InspectorManager;
use Elazaroo\PulseBoosted\Queues\QueueActions;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Lazy;

/**
 * What every queue is doing right now, on the dashboard.
 *
 * The Queues card next to this one plots throughput over a period; this one
 * answers the more immediate question of whether anything is piling up, and
 * lets you stop a queue or step into it.
 *
 * @internal
 */
#[Lazy]
class QueueStatus extends Card
{
    /**
     * The most rows to show, so a application with many connections does not
     * turn the dashboard into a queue scan.
     */
    public const MAX_QUEUES = 12;

    /**
     * Point the jobs table at a queue.
     */
    public function focus(string $connection, string $queue): void
    {
        $this->dispatch('queue-selected', connection: $connection, queue: $queue);
    }

    /**
     * Stop workers picking anything up from a queue.
     */
    public function pause(string $connection, string $queue, QueueActions $actions): void
    {
        $actions->pause($connection, $queue);
    }

    /**
     * Let workers pick up from a queue again.
     */
    public function resume(string $connection, string $queue, QueueActions $actions): void
    {
        $actions->resume($connection, $queue);
    }

    /**
     * Render the component.
     */
    public function render(InspectorManager $inspectors, QueueActions $actions): Renderable
    {
        return View::make('pulse-boosted::livewire.queue-status', [
            'queues' => $this->queues($inspectors, $actions),
            'canManage' => $actions->allowed(),
            'canPause' => $actions->allowed() && $actions->supportsPausing(),
            'enabled' => $inspectors->enabled(),
        ]);
    }

    /**
     * Every queue worth showing, with its live counts.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function queues(InspectorManager $inspectors, QueueActions $actions): Collection
    {
        if (! $inspectors->enabled()) {
            return collect();
        }

        return $inspectors->connections()
            ->flatMap(function (string $connection) use ($inspectors, $actions) {
                $inspector = $inspectors->for($connection);

                // A connection that can tell us nothing would only add empty
                // rows; the explorer explains why, this card just leaves it out.
                if (! $inspector->capabilities()->counts) {
                    return [];
                }

                $queues = $inspector->queues();

                // One round trip for the whole connection rather than three
                // counts per queue, which this card would otherwise run on
                // every refresh.
                $counts = $inspector->allCounts($queues);

                return $queues
                    ->map(fn (string $queue) => [
                        'connection' => $connection,
                        'queue' => $queue,
                        'counts' => $counts->get($queue) ?? $inspector->counts($queue),
                        'paused' => $actions->paused($connection, $queue),
                    ])
                    // A connection that is configured but not reachable — Redis
                    // with no server behind it, say — answers every count with
                    // a dash. A row of dashes is worse than no row.
                    ->filter(fn (array $row) => $row['counts']->known());
            })
            ->take(self::MAX_QUEUES)
            ->values();
    }
}
