<?php

use Elazaroo\PulseBoosted\Facades\Pulse as PulseBoosted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Workbench\App\Jobs\GenerateInvoice;
use Workbench\App\Jobs\SendWelcomeEmail;

/**
 * A route that does enough for a trace to be worth looking at: a few queries,
 * a cache hit and a miss, a log line, and a job dispatched so the request can
 * be followed into the worker that runs it.
 */
Route::get('/demo/checkout', function () {
    // What this request was about, so the trace is more than a URL.
    PulseBoosted::context([
        'tenant' => 'acme-corp',
        'order' => 4711,
        'plan' => 'pro',
    ]);

    DB::table('users')->count();
    DB::table('jobs')->where('queue', 'default')->count();

    Cache::put('demo:cart:42', ['items' => 3], 60);
    Cache::get('demo:cart:42');
    Cache::get('demo:cart:does-not-exist');

    Log::info('Checkout started', ['user' => 42]);

    SendWelcomeEmail::dispatch('buyer@example.com');
    GenerateInvoice::dispatch(9001)->onQueue('invoices');

    DB::table('users')->where('id', 1)->first();

    Log::warning('Payment provider was slow', ['ms' => 812]);

    return 'checked out';
});

/**
 * And one that fails, so there is a failed request trace to look at too.
 */
Route::get('/demo/broken', function () {
    PulseBoosted::context(['tenant' => 'acme-corp', 'order' => 4712]);

    DB::table('users')->count();

    Log::error('Could not reach the payment provider');

    throw new RuntimeException('Payment provider unreachable');
});
