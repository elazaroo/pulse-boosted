<?php

use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Livewire\Queries;
use Elazaroo\PulseBoosted\Livewire\Routes;
use Elazaroo\PulseBoosted\Recorders\Traces;
use Elazaroo\PulseBoosted\Traces\QueryOrigin;
use Elazaroo\PulseBoosted\Traces\TraceRepository;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Config::set('pulse-boosted.traces.sample_rate', 1.0);
    Config::set('pulse-boosted.traces.sample_rates', []);
});

/**
 * One request trace with the given queries in it.
 *
 * @param  list<array{0: string, 1: int}>  $queries
 */
function requestWith(string $name, int $status = 200, int $durationMs = 100, array $queries = [], bool $sampled = true): string
{
    $traceId = (string) Str::uuid();

    Pulse::ignore(function () use ($traceId, $name, $status, $durationMs, $queries, $sampled) {
        DB::table('pulse_boosted_traces')->insert([
            'trace_id' => $traceId,
            'type' => 'request',
            'name' => $name,
            'started_at' => now()->getTimestamp(),
            'duration_ms' => $durationMs,
            'status' => $status >= 500 ? 'failed' : 'ok',
            'sampled' => $sampled,
            'meta' => json_encode(['status' => $status]),
        ]);

        foreach ($queries as [$sql, $ms]) {
            DB::table('pulse_boosted_trace_events')->insert([
                'trace_id' => $traceId,
                'type' => 'query',
                'label' => $sql,
                'offset_ms' => 1,
                'duration_ms' => $ms,
                'meta' => json_encode(['connection' => 'sqlite', 'file' => 'app/Http/Controllers/OrderController.php', 'line' => 42]),
            ]);
        }
    });

    return $traceId;
}

it('folds lists and bulk inserts so the same query groups together', function () {
    expect(QueryOrigin::normalize('select * from users where id in (?, ?, ?)'))->toBe('select * from users where id in (...?)');
    expect(QueryOrigin::normalize('select * from users where id in (?)'))->toBe('select * from users where id in (...?)');
    expect(QueryOrigin::normalize('insert into logs (a, b) values (?, ?), (?, ?), (?, ?)'))->toBe('insert into logs (a, b) values (...)');
    expect(QueryOrigin::normalize('insert into logs (a) values (?)'))->toBe('insert into logs (a) values (?)');
});

it('groups every query with its calls, total, average and p95', function () {
    requestWith('GET /orders', queries: [
        ['select * from users where id in (?, ?)', 2],
        ['select * from users where id in (?, ?, ?)', 4],
        ['select * from orders where id = ?', 10],
    ]);

    $groups = app(TraceRepository::class)->queryGroups();

    $users = $groups->firstWhere('sql', 'select * from users where id in (...?)');

    expect($users['calls'])->toBe(2);
    expect($users['total'])->toBe(6);
    expect($users['avg'])->toBe(3);
    expect($users['location'])->toBe('app/Http/Controllers/OrderController.php:42');

    // Ordered by total time by default: the costliest first.
    expect($groups->first()['sql'])->toBe('select * from orders where id = ?');
    expect(app(TraceRepository::class)->queryGroups(orderBy: 'calls')->first()['sql'])->toBe('select * from users where id in (...?)');
});

it('reads queries from sampled executions only', function () {
    requestWith('GET /orders', queries: [['select 1', 1]]);
    requestWith('GET /broken', 500, sampled: false, queries: [['select 2', 1]]);

    expect(app(TraceRepository::class)->queryGroups()->pluck('sql')->all())->toBe(['select 1']);
});

it('notes where in the application a traced query was run from', function () {
    $line = __LINE__ + 1;
    Route::get('where', fn () => DB::table('users')->count())->middleware('web');

    $this->get('where')->assertOk();

    $meta = Pulse::ignore(fn () => DB::table('pulse_boosted_trace_events')
        ->where('type', 'query')
        ->where('label', 'like', '%count(*)%')
        ->value('meta'));

    $meta = json_decode($meta, true);

    // This test file is where it ran from — not the framework's query builder.
    expect($meta['file'])->toEndWith('QueriesAndRoutesTest.php');
    expect($meta['line'])->toBe($line);
});

it('shows the queries card and opens the slowest trace', function () {
    $slow = requestWith('GET /orders', queries: [['select * from orders', 90]]);
    requestWith('GET /orders', queries: [['select * from orders', 5]]);

    Livewire::test(Queries::class, ['lazy' => false])
        ->assertSee('select * from orders')
        ->assertSee('OrderController.php:42')
        ->call('showTrace', $slow)
        ->assertDispatched('open-trace', traceId: $slow);

    expect(app(TraceRepository::class)->queryGroups()->first()['traceId'])->toBe($slow);
});

it('groups requests by route with how they answered', function () {
    requestWith('GET /orders/{order}', 200, 100);
    requestWith('GET /orders/{order}', 200, 300);
    requestWith('GET /orders/{order}', 404, 50);
    requestWith('GET /orders/{order}', 500, 900);
    requestWith('POST /checkout', 200, 20);

    $routes = app(TraceRepository::class)->routeGroups();

    $orders = $routes->firstWhere('name', 'GET /orders/{order}');

    expect($orders['method'])->toBe('GET');
    expect($orders['path'])->toBe('/orders/{order}');
    expect($orders['calls'])->toBe(4);
    expect($orders['ok'])->toBe(2);
    expect($orders['client'])->toBe(1);
    expect($orders['server'])->toBe(1);
    expect($orders['p95'])->toBe(900);

    expect(app(TraceRepository::class)->routeGroups(orderBy: 'server')->first()['name'])->toBe('GET /orders/{order}');
});

it('leaves requests kept outside the sample out of the route figures', function () {
    requestWith('GET /orders', 200);
    requestWith('GET /orders', 500, sampled: false);

    $orders = app(TraceRepository::class)->routeGroups()->first();

    expect($orders['calls'])->toBe(1);
    expect($orders['server'])->toBe(0);
});

it('shows the routes card', function () {
    requestWith('GET /orders/{order}', 500, 900);

    Livewire::test(Routes::class, ['lazy' => false])
        ->assertSee('/orders/{order}')
        ->assertSee('900ms')
        ->set('search', 'nothing-matches')
        ->assertDontSee('/orders/{order}');
});

it('names a Livewire request by its components rather than the update route', function () {
    Config::set('pulse-boosted.recorders.'.Traces::class.'.ignore', []);

    $snapshot = fn (string $name) => ['snapshot' => json_encode(['memo' => ['name' => $name]]), 'updates' => [], 'calls' => []];

    // Livewire 3 serves it at /livewire/update, Livewire 4 behind a hash.
    $uri = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($route) => preg_match('#(^|/)livewire[^/]*/update$#', $route->uri()))
        ->uri();

    // The checksum is fake, so Livewire refuses these — but by then the route
    // has matched, which is all the naming needs.
    $this->postJson($uri, ['components' => [$snapshot('checkout-form')]]);

    $this->postJson($uri, ['components' => [$snapshot('pulse-boosted.jobs')]]);

    $names = Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->pluck('name')->all());

    expect($names)->toContain('LIVEWIRE checkout-form');

    // The dashboard's own polling is not the application's traffic.
    expect(collect($names)->filter(fn ($name) => str_contains($name, 'pulse-boosted')))->toBeEmpty();
});

it('opens a route with its percentiles, responses, timeline and slowest requests', function () {
    requestWith('GET /orders/{order}', 200, 100);
    requestWith('GET /orders/{order}', 200, 300);
    $failed = requestWith('GET /orders/{order}', 500, 900);
    // Kept for failing, so not part of the figures, but still worth opening.
    $kept = requestWith('GET /orders/{order}', 500, 1500, sampled: false);

    $detail = app(TraceRepository::class)->routeDetail('GET /orders/{order}', now()->subHour()->getTimestamp());

    expect($detail)
        ->calls->toBe(3)
        ->p95->toBe(900)
        ->codes->toBe(['200' => 2, '500' => 1]);
    expect($detail['timeline'])->toHaveCount(24);
    expect(array_sum(array_column($detail['timeline'], 'count')))->toBe(3);
    expect(array_sum(array_column($detail['timeline'], 'errors')))->toBe(1);
    expect($detail['slowest']->first()->trace_id)->toBe($kept);
    expect($detail['failures']->pluck('trace_id')->all())->toContain($failed, $kept);

    Livewire::test(Routes::class, ['lazy' => false])
        ->call('select', 'GET /orders/{order}')
        ->assertSet('selected', 'GET /orders/{order}')
        ->assertSee('Slowest requests')
        ->assertSee('Recent failures')
        ->assertSee('900ms')
        ->call('showTrace', $kept)
        ->assertDispatched('open-trace', traceId: $kept)
        ->call('deselect')
        ->assertDontSee('Recent failures');
});

it('opens a query with where it runs from and the executions that ran it most', function () {
    $loop = requestWith('GET /orders', queries: array_fill(0, 12, ['select * from users where id = ?', 2]));
    requestWith('GET /orders/{order}', queries: [['select * from users where id = ?', 30]]);

    $key = app(TraceRepository::class)->queryGroups()->first()['key'];
    $detail = app(TraceRepository::class)->queryDetail($key, now()->subHour()->getTimestamp());

    expect($detail)
        ->sql->toBe('select * from users where id = ?')
        ->calls->toBe(13)
        ->executions->toBe(2)
        ->max->toBe(30);
    expect($detail['perExecution'][0])->traceId->toBe($loop)->times->toBe(12);
    expect($detail['locations'][0])->location->toBe('app/Http/Controllers/OrderController.php:42')->calls->toBe(13);

    Livewire::test(Queries::class, ['lazy' => false])
        ->call('select', $key)
        ->assertSee('Executions that ran it most')
        ->assertSee('N+1')
        ->assertSee('12 &times;', false);
});

it('says so when an opened route or query has nothing in the period', function () {
    Livewire::test(Routes::class, ['lazy' => false])
        ->call('select', 'GET /gone')
        ->assertSee('No requests to this route in the selected period');

    Livewire::test(Queries::class, ['lazy' => false])
        ->call('select', md5('nothing'))
        ->assertSee('Not run in the selected period');
});
