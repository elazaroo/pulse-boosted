<?php

namespace Elazaroo\PulseBoosted\Recorders;

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\Pulse;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;

/**
 * @internal
 */
class CacheInteractions
{
    use Concerns\Groups, Concerns\Ignores, Concerns\Sampling;

    /**
     * The events to listen for.
     *
     * @var list<class-string>
     */
    public array $listen = [
        CacheHit::class,
        CacheMissed::class,
    ];

    /**
     * Create a new recorder instance.
     */
    public function __construct(
        protected Pulse $pulse,
    ) {
        //
    }

    /**
     * Record the cache interaction.
     */
    public function record(CacheHit|CacheMissed $event): void
    {
        [$timestamp, $class, $key] = [
            CarbonImmutable::now()->getTimestamp(),
            $event::class,
            (string) $event->key,
        ];

        $this->pulse->lazy(function () use ($timestamp, $class, $key) {
            if (! $this->shouldSample() || $this->shouldIgnore($key)) {
                return;
            }

            $this->pulse->record(
                type: match ($class) { // @phpstan-ignore match.unhandled
                    CacheHit::class => 'cache_hit',
                    CacheMissed::class => 'cache_miss',
                },
                key: $this->group($key),
                timestamp: $timestamp,
            )->count();
        });
    }
}
