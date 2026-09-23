<?php

namespace Workbench\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Signs each demo request in as one of the demo users — ?as=2 picks one, or
 * one is chosen at random — so the user filter and the Usage card have
 * people in them.
 */
class ActAsDemoUser
{
    public function handle(Request $request, Closure $next): mixed
    {
        $ids = DB::table('users')->pluck('id');

        if ($ids->isNotEmpty()) {
            $id = $request->integer('as') ?: $ids->random();

            Auth::onceUsingId($id);
        }

        return $next($request);
    }
}
