<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\Queues\QueueActions;
use Elazaroo\PulseBoosted\Recorders\Workers as WorkersRecorder;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Lazy;

/**
 * The queue workers that are running.
 *
 * Laravel does not track workers, so this reads the heartbeats each one writes
 * as it loops. A worker killed outright cannot say goodbye, so anything not
 * heard from for a while is shown as stale rather than being trusted.
 *
 * @internal
 */
#[Lazy]
class Workers extends Card
{
    /**
     * How long a worker may go unheard from before it is treated as gone.
     */
    public const STALE_AFTER_SECONDS = 30;

    /**
     * How long to keep showing a worker that shut down cleanly.
     *
     * Long enough to notice a restart, short enough that a machine which has
     * been restarting workers all week is not a wall of dead rows.
     */
    public const FORGET_STOPPED_AFTER_SECONDS = 300;

    /**
     * Tell every worker to finish its current job and exit.
     */
    public function restart(QueueActions $actions): void
    {
        $actions->restartWorkers();

        $this->dispatch('workers-restarting');
    }

    /**
     * Render the component.
     */
    public function render(QueueActions $actions): Renderable
    {
        return View::make('pulse-boosted::livewire.workers', [
            'workers' => $this->workers(),
            'canManage' => $actions->allowed(),
        ]);
    }

    /**
     * Every worker we have heard from, newest heartbeat first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function workers(): Collection
    {
        $now = CarbonImmutable::now()->getTimestamp();

        $workers = [];

        foreach ($this->values(WorkersRecorder::TYPE) as $record) {
            $worker = json_decode($record->value, true);

            if (! is_array($worker)) {
                continue;
            }

            $lastSeen = (int) ($worker['last_seen'] ?? $record->timestamp);
            $silentFor = $now - $lastSeen;
            $status = $this->status((string) ($worker['status'] ?? 'idle'), $silentFor);

            if ($status === 'stopped' && $silentFor > self::FORGET_STOPPED_AFTER_SECONDS) {
                continue;
            }

            $workers[] = [
                'key' => (string) $record->key,
                'host' => (string) ($worker['host'] ?? 'unknown'),
                'pid' => $worker['pid'] ?? null,
                'connection' => $worker['connection'] ?? null,
                'queue' => $worker['queue'] ?? null,
                'currentJob' => $worker['current_job'] ?? null,
                'processed' => (int) ($worker['processed'] ?? 0),
                'failed' => (int) ($worker['failed'] ?? 0),
                'memory' => $worker['memory'] ?? null,
                'startedAt' => $worker['started_at'] ?? null,
                'silentFor' => $silentFor,
                'status' => $status,
            ];
        }

        return collect($workers)->sortBy('silentFor')->values();
    }

    /**
     * What to call a worker, given what it last said and how long ago.
     */
    protected function status(string $reported, int $silentFor): string
    {
        if ($reported === 'stopped') {
            return 'stopped';
        }

        return $silentFor > self::STALE_AFTER_SECONDS ? 'stale' : $reported;
    }
}
