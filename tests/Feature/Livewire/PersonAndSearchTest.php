<?php

use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Issues\IssueRepository;
use Elazaroo\PulseBoosted\Livewire\PersonViewer;
use Elazaroo\PulseBoosted\Livewire\Search;
use Elazaroo\PulseBoosted\Queues\Contracts\JobRepository;
use Elazaroo\PulseBoosted\Search\GlobalSearch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\User;

/**
 * One execution a user ran.
 */
function userTrace(string $userId, string $name, string $status = 'ok', string $type = 'request'): string
{
    $traceId = (string) Str::uuid();

    Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->insert([
        'trace_id' => $traceId,
        'type' => $type,
        'name' => $name,
        'started_at' => now()->getTimestamp(),
        'duration_ms' => 120,
        'status' => $status,
        'sampled' => true,
        'user_id' => $userId,
    ]));

    return $traceId;
}

function shippingFailed(): RuntimeException
{
    return new RuntimeException('Shipping label could not be printed');
}

it('shows what one user did and what went wrong for them', function () {
    $user = Pulse::ignore(fn () => User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.com']));
    $id = (string) $user->id;

    userTrace($id, 'GET /orders');
    $failed = userTrace($id, 'POST /checkout', 'failed');
    userTrace('999', 'GET /someone-else');

    $issues = app(IssueRepository::class);
    $issues->record(shippingFailed(), $failed, $id);
    $issues->flush();

    Livewire::test(PersonViewer::class)
        ->assertDontSee('Grace Hopper')
        ->dispatch('open-person', id: $id)
        ->assertSet('selected', $id)
        ->assertSee('Grace Hopper')
        ->assertSee('grace@example.com')
        ->assertSee('POST /checkout')
        ->assertSee('Shipping label could not be printed')
        ->assertSee('Where they went')
        ->assertDontSee('GET /someone-else')
        ->call('showTrace', $failed)
        ->assertDispatched('open-trace', traceId: $failed)
        ->call('deselect')
        ->assertDontSee('Grace Hopper');
});

it('is on every dashboard, apart from any card', function () {
    $this->get('/pulse-boosted')
        ->assertSeeLivewire(PersonViewer::class)
        ->assertSeeLivewire(Search::class);
});

it('finds issues, traces, jobs, routes and users from one box', function () {
    $user = Pulse::ignore(fn () => User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.com']));

    $traceId = userTrace((string) $user->id, 'GET /orders/{order}');

    app(IssueRepository::class)->record(shippingFailed(), null, null);
    app(IssueRepository::class)->flush();

    $uuid = (string) Str::uuid();

    app(JobRepository::class)->record($uuid, [
        'connection' => 'database',
        'queue' => 'default',
        'name' => 'App\Jobs\PrintShippingLabel',
        'status' => 'failed',
    ]);
    app(JobRepository::class)->flush();

    $search = app(GlobalSearch::class);

    $kinds = fn (string $query) => collect($search->search($query))->pluck('kind')->unique()->values()->all();

    expect($kinds('shipping'))->toBe(['Issue', 'Job']);
    expect($kinds('orders'))->toBe(['Trace', 'Route']);
    expect($kinds('grace@'))->toBe(['User']);
    expect($kinds(substr($traceId, 0, 8)))->toContain('Trace');
    expect(collect($search->search(substr($uuid, 0, 13)))->pluck('url')->first())->toContain('job='.$uuid);
    expect($search->search('x'))->toBe([]);

    $route = collect($search->search('orders'))->firstWhere('kind', 'Route');
    expect($route['url'])->toContain('route='.urlencode('GET /orders/{order}'))->toEndWith('#performance');

    Livewire::test(Search::class)
        ->assertSee('paste a trace or job id')
        ->set('query', 'grace')
        ->assertSee('Grace Hopper')
        ->assertSee('person='.$user->id, false)
        ->set('query', 'nothing-like-this')
        ->assertSee('Nothing matches');
});
