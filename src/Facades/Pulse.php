<?php

namespace Elazaroo\PulseBoosted\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Elazaroo\PulseBoosted\Pulse register(array $recorders)
 * @method static \Elazaroo\PulseBoosted\Entry record(\UnitEnum|string $type, \UnitEnum|string $key, int|null $value = null, \DateTimeInterface|int|null $timestamp = null)
 * @method static \Elazaroo\PulseBoosted\Value set(\UnitEnum|string $type, \UnitEnum|string $key, string $value, \DateTimeInterface|int|null $timestamp = null)
 * @method static \Elazaroo\PulseBoosted\Pulse lazy(callable $closure)
 * @method static \Elazaroo\PulseBoosted\Pulse report(\Throwable $e)
 * @method static \Elazaroo\PulseBoosted\Pulse startRecording()
 * @method static \Elazaroo\PulseBoosted\Pulse stopRecording()
 * @method static mixed ignore(callable $callback)
 * @method static bool recording()
 * @method static \Elazaroo\PulseBoosted\Pulse flush()
 * @method static \Elazaroo\PulseBoosted\Pulse filter(callable $filter)
 * @method static int ingest()
 * @method static int digest()
 * @method static bool wantsIngesting()
 * @method static \Illuminate\Support\Collection recorders()
 * @method static \Elazaroo\PulseBoosted\Contracts\ResolvesUsers resolveUsers(\Illuminate\Support\Collection $keys)
 * @method static \Elazaroo\PulseBoosted\Pulse user(callable $callback)
 * @method static callable authenticatedUserIdResolver()
 * @method static string|int|null resolveAuthenticatedUserId()
 * @method static \Elazaroo\PulseBoosted\Pulse rememberUser(\Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static \Elazaroo\PulseBoosted\Pulse|string css(string|\Illuminate\Contracts\Support\Htmlable|array|null $css = null)
 * @method static string js()
 * @method static array defaultVendorCacheKeys()
 * @method static bool registersRoutes()
 * @method static \Elazaroo\PulseBoosted\Pulse ignoreRoutes()
 * @method static \Elazaroo\PulseBoosted\Pulse handleExceptionsUsing(callable $callback)
 * @method static mixed|null rescue(callable $callback)
 * @method static \Elazaroo\PulseBoosted\Pulse setContainer(\Illuminate\Contracts\Foundation\Application $container)
 * @method static void afterResolving(\Illuminate\Contracts\Foundation\Application $app, string $class, \Closure $callback)
 * @method static void store(\Illuminate\Support\Collection $items)
 * @method static void trim()
 * @method static void purge(array $types = null)
 * @method static \Illuminate\Support\Collection values(string $type, array $keys = null)
 * @method static \Illuminate\Support\Collection graph(array $types, string $aggregate, \Carbon\CarbonInterval $interval)
 * @method static \Illuminate\Support\Collection aggregate(string $type, string|array $aggregates, \Carbon\CarbonInterval $interval, string|null $orderBy = null, string $direction = 'desc', int $limit = 101)
 * @method static \Illuminate\Support\Collection aggregateTypes(string|array $types, string $aggregate, \Carbon\CarbonInterval $interval, string|null $orderBy = null, string $direction = 'desc', int $limit = 101)
 * @method static float|\Illuminate\Support\Collection aggregateTotal(string|array $types, string $aggregate, \Carbon\CarbonInterval $interval)
 *
 * @see \Elazaroo\PulseBoosted\Pulse
 */
class Pulse extends Facade
{
    /**
     * Get the registered name of the component.
     */
    public static function getFacadeAccessor(): string
    {
        return \Elazaroo\PulseBoosted\Pulse::class;
    }
}
