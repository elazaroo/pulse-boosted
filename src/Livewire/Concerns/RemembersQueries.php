<?php

namespace Elazaroo\PulseBoosted\Livewire\Concerns;

use Carbon\CarbonImmutable;
use Closure;
use DateInterval;
use DateTimeInterface;
use Elazaroo\PulseBoosted\Support\CacheStoreResolver;
use Illuminate\Support\Benchmark;
use Illuminate\Support\Facades\App;

trait RemembersQueries
{
    /**
     * Remember the query for the current period.
     *
     * @return array{0: mixed, 1: float, 2: string}
     */
    protected function remember(callable $query, string $key = '', DateTimeInterface|DateInterval|Closure|int|null $ttl = 5): array
    {
        return App::make(CacheStoreResolver::class)->store()->remember('elazaroo:pulse-boosted:'.static::class.':'.$key.':'.$this->period, $ttl, function () use ($query) {
            $start = CarbonImmutable::now('UTC')->toDateTimeString();

            [$value, $duration] = Benchmark::value(fn () => $query($this->periodAsInterval()));

            return [$value, $duration, $start];
        });
    }
}
