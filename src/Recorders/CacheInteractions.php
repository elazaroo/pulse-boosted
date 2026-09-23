<?php

namespace Elazaroo\PulseBoosted\Recorders;

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\Pulse;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgetFailed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Cache\Events\KeyWritten;

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

        // Writes, deletes and the ones that failed, so a key can be seen being
        // set and cleared, not only read. The failure events only exist on
        // newer versions of Laravel; listening for them elsewhere is harmless.
        KeyWritten::class,
        KeyForgotten::class,
        KeyWriteFailed::class,
        KeyForgetFailed::class,
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
    public function record(CacheHit|CacheMissed|KeyWritten|KeyForgotten|KeyWriteFailed|KeyForgetFailed $event): void
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
                    KeyWritten::class => 'cache_write',
                    KeyForgotten::class => 'cache_delete',
                    KeyWriteFailed::class, KeyForgetFailed::class => 'cache_failure',
                },
                key: $this->group($key),
                timestamp: $timestamp,
            )->count();
        });
    }
}
