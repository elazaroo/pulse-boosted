<?php

namespace Elazaroo\PulseBoosted\Recorders\Concerns;

use Carbon\CarbonImmutable;
use DateInterval;
use Elazaroo\PulseBoosted\Events\IsolatedBeat;
use Elazaroo\PulseBoosted\Events\SharedBeat;
use Elazaroo\PulseBoosted\Support\CacheStoreResolver;
use Illuminate\Support\Facades\App;
use Illuminate\Support\InteractsWithTime;

trait Throttling
{
    use InteractsWithTime;

    /**
     * Determine if the recorder is ready to record another snapshot.
     */
    protected function throttle(DateInterval|int $interval, SharedBeat|IsolatedBeat $event, callable $callback, ?string $key = null): void
    {
        $key ??= static::class;

        if ($event instanceof SharedBeat) {
            $key = $event->instance.":{$key}";
        }

        $cache = App::make(CacheStoreResolver::class);

        $key = 'elazaroo:pulse-boosted:throttle:'.$key;

        $lastRunAt = $cache->store()->get($key);

        if ($lastRunAt !== null && CarbonImmutable::createFromTimestamp($lastRunAt)->addSeconds($this->secondsUntil($interval))->isFuture()) {
            return;
        }

        $callback($event);

        $cache->store()->put($key, $event->time->getTimestamp(), $interval);
    }
}
