<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Elazaroo\PulseBoosted\Recorders\CacheInteractions as CacheInteractionsRecorder;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Lazy;

/**
 * @internal
 */
#[Lazy]
class Cache extends Card
{
    use Concerns\HasPeriod, Concerns\RemembersQueries;

    /**
     * Render the component.
     */
    public function render(): Renderable
    {
        [$cacheInteractions, $allTime, $allRunAt] = $this->remember(
            fn () => with(
                $this->aggregateTotal(['cache_hit', 'cache_miss', 'cache_write', 'cache_delete', 'cache_failure'], 'count'),
                fn ($results) => (object) [
                    'hits' => $results['cache_hit'] ?? 0,
                    'misses' => $results['cache_miss'] ?? 0,
                    'writes' => $results['cache_write'] ?? 0,
                    'deletes' => $results['cache_delete'] ?? 0,
                    'failures' => $results['cache_failure'] ?? 0,
                ]
            ),
            'all'
        );

        [$cacheKeyInteractions, $keyTime, $keyRunAt] = $this->remember(
            fn () => $this->aggregateTypes(['cache_hit', 'cache_miss', 'cache_write', 'cache_delete', 'cache_failure'], 'count')
                ->map(function ($row) {
                    return (object) [
                        'key' => $row->key,
                        'hits' => $row->cache_hit ?? 0,
                        'misses' => $row->cache_miss ?? 0,
                        'writes' => $row->cache_write ?? 0,
                        'deletes' => $row->cache_delete ?? 0,
                        'failures' => $row->cache_failure ?? 0,
                    ];
                }),
            'keys'
        );

        return View::make('pulse-boosted::livewire.cache', [
            'allTime' => $allTime,
            'allRunAt' => $allRunAt,
            'allCacheInteractions' => $cacheInteractions,
            'keyTime' => $keyTime,
            'keyRunAt' => $keyRunAt,
            'cacheKeyInteractions' => $cacheKeyInteractions,
            'config' => Config::get('pulse-boosted.recorders.'.CacheInteractionsRecorder::class),
        ]);
    }
}
