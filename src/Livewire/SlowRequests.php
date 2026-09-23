<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Elazaroo\PulseBoosted\Recorders\Concerns\Thresholds;
use Elazaroo\PulseBoosted\Recorders\SlowRequests as SlowRequestsRecorder;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;

/**
 * @internal
 */
#[Lazy(isolate: false)]
class SlowRequests extends Card
{
    use Thresholds;

    /**
     * Ordering.
     *
     * @var 'slowest'|'count'
     */
    #[Url(as: 'slow-requests')]
    public string $orderBy = 'slowest';

    /**
     * Render the component.
     */
    public function render(): Renderable
    {
        [$slowRequests, $time, $runAt] = $this->remember(
            fn () => $this->aggregate(
                'slow_request',
                ['max', 'count'],
                match ($this->orderBy) {
                    'count' => 'count',
                    default => 'max',
                },
            )->map(function ($row) {
                [$method, $uri, $action] = json_decode($row->key, flags: JSON_THROW_ON_ERROR);

                return (object) [
                    'uri' => $uri,
                    'method' => $method,
                    'action' => $action,
                    'count' => $row->count,
                    'slowest' => $row->max,
                    'threshold' => $this->threshold($uri, SlowRequestsRecorder::class),
                ];
            }),
            $this->orderBy,
        );

        return View::make('pulse-boosted::livewire.slow-requests', [
            'time' => $time,
            'runAt' => $runAt,
            'slowRequests' => $slowRequests,
            'config' => [
                'threshold' => Config::get('pulse-boosted.recorders.'.SlowRequestsRecorder::class.'.threshold'),
                'sample_rate' => Config::get('pulse-boosted.recorders.'.SlowRequestsRecorder::class.'.sample_rate'),
            ],
        ]);
    }
}
