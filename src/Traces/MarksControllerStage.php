<?php

namespace Elazaroo\PulseBoosted\Traces;

use Closure;
use Illuminate\Http\Request;

/**
 * Marks the moment a request's own code starts running.
 *
 * Laravel says when a route is matched, but that is before the route's
 * middleware has run — and middleware is often where the time goes. This is
 * appended as the route's innermost middleware, so the controller stage
 * starts exactly when the controller does.
 *
 * @internal
 */
class MarksControllerStage
{
    public function __construct(protected Tracer $tracer)
    {
        //
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $this->tracer->stage(Stage::CONTROLLER);

        return $next($request);
    }
}
