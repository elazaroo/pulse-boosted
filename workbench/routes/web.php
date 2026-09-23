<?php

use Elazaroo\PulseBoosted\Facades\Pulse as PulseBoosted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Middleware\ActAsDemoUser;
use Workbench\App\Jobs\ChargeCard;
use Workbench\App\Jobs\GenerateInvoice;
use Workbench\App\Jobs\RebuildSearchIndex;
use Workbench\App\Jobs\SendWelcomeEmail;
use Workbench\App\Mail\Receipt;
use Workbench\App\Notifications\OrderShipped;

/*
 * Routes for the demo, each exercising something the dashboard shows. Every
 * request is signed in as one of the demo users, so the user filter has
 * people in it. `php artisan demo:traffic` calls all of them.
 */
Route::middleware(ActAsDemoUser::class)->prefix('demo')->group(function () {
    /**
     * A request that does enough for a trace to be worth looking at: queries,
     * cache reads and writes, log lines, mail, a notification, and jobs
     * dispatched so the request can be followed into the worker.
     */
    Route::get('/checkout', function () {
        // What this request was about, so the trace is more than a URL.
        PulseBoosted::context([
            'tenant' => 'acme-corp',
            'order' => $order = random_int(4000, 4999),
            'plan' => 'pro',
        ]);

        DB::table('users')->count();
        DB::table('jobs')->where('queue', 'default')->count();

        Cache::put('demo:cart:42', ['items' => 3], 60);
        Cache::get('demo:cart:42');
        Cache::get('demo:cart:does-not-exist');

        Log::info('Checkout started', ['order' => $order]);

        SendWelcomeEmail::dispatch('buyer@example.com');
        GenerateInvoice::dispatch($order)->onQueue('invoices');
        ChargeCard::dispatch($order);

        Mail::to(Auth::user()?->email ?? 'buyer@example.com')->send(new Receipt($order));
        Notification::route('mail', 'warehouse@example.com')->notify(new OrderShipped($order));

        DB::table('users')->where('id', 1)->first();

        Log::warning('Payment provider was slow', ['ms' => 812]);

        return 'checked out';
    });

    /**
     * An exception that escapes to the handler: unhandled.
     */
    Route::get('/broken', function () {
        PulseBoosted::context(['tenant' => 'acme-corp', 'order' => 4712]);

        DB::table('users')->count();

        Log::error('Could not reach the payment provider');

        throw new RuntimeException('Payment provider unreachable');
    });

    /**
     * One the application catches and reports itself: handled.
     */
    Route::get('/coupon', function () {
        try {
            throw new InvalidArgumentException('Coupon SPRING24 has expired');
        } catch (Throwable $e) {
            report($e);
        }

        return 'coupon rejected';
    });

    /**
     * A PHP Error rather than an Exception — usually a bug in the code.
     */
    Route::get('/profile', function () {
        $user = null;

        return $user->profile();
    });

    /**
     * Slower than its threshold, so it opens a performance issue.
     */
    Route::get('/report', function () {
        foreach (range(1, 8) as $i) {
            DB::table('users')->where('id', $i)->first();
        }

        usleep(900_000);

        return 'report built';
    });

    /**
     * One route, many URLs, a few of them missing: the Routes card groups
     * them by the pattern and counts the 404s.
     */
    Route::get('/orders/{order}', function (int $order) {
        abort_if($order % 5 === 0, 404);

        return DB::table('users')->whereIn('id', [1, 2, 3, $order % 7])->get();
    });

    /**
     * A server error on a request with a body, so its headers and body are
     * kept — with the password and card number taken out.
     */
    Route::post('/orders', function (Request $request) {
        Log::error('Order could not be created', ['sku' => $request->input('sku')]);

        abort(500, 'Could not create the order');
    });

    /**
     * Cache writes, reads and deletes on one key.
     */
    Route::get('/cart', function () {
        Cache::put('demo:cart:'.Auth::id(), ['items' => random_int(1, 5)], 300);
        Cache::get('demo:cart:'.Auth::id());
        Cache::forget('demo:cart:'.Auth::id());

        return 'cart refreshed';
    });

    /**
     * The same query run once per row: the Queries card shows it at the top
     * by number of calls, with the line it came from.
     */
    Route::get('/search', function () {
        foreach (DB::table('users')->pluck('id') as $id) {
            DB::table('users')->where('id', $id)->value('email');
        }

        return 'searched';
    });

    /**
     * A job slower than its threshold.
     */
    Route::get('/reindex', function () {
        RebuildSearchIndex::dispatch();

        return 'reindex queued';
    });
});
