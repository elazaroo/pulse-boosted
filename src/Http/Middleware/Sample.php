<?php

namespace Elazaroo\PulseBoosted\Http\Middleware;

use Closure;
use Elazaroo\PulseBoosted\Traces\Tracer;
use Illuminate\Http\Request;

/**
 * Trace a route at its own rate.
 *
 * The checkout is worth tracing every time; a health check polled every ten
 * seconds is not worth tracing at all.
 *
 *     Route::post('/checkout', ...)->middleware(Sample::always());
 *     Route::get('/health', ...)->middleware(Sample::never());
 *     Route::get('/search', ...)->middleware(Sample::rate(0.01));
 *
 * An execution that loses its draw is still kept if it fails, throws or runs
 * slow, as with the global rate.
 */
class Sample
{
    public function __construct(protected Tracer $tracer)
    {
        //
    }

    public static function rate(float $rate): string
    {
        return self::class.':'.max(0.0, min(1.0, $rate));
    }

    public static function always(): string
    {
        return self::rate(1.0);
    }

    public static function never(): string
    {
        return self::rate(0.0);
    }

    public function handle(Request $request, Closure $next, string $rate = '1'): mixed
    {
        $this->tracer->resample((float) $rate);

        return $next($request);
    }
}
