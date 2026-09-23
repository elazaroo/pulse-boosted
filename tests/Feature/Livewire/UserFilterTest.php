<?php

use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Issues\IssueRepository;
use Elazaroo\PulseBoosted\Livewire\Issues;
use Elazaroo\PulseBoosted\Livewire\Logs;
use Elazaroo\PulseBoosted\Livewire\Traces;
use Elazaroo\PulseBoosted\Livewire\UserFilter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * A request by one user, with a log line in it.
 */
function requestBy(?string $user, string $name, string $log): string
{
    $traceId = (string) Str::uuid();

    Pulse::ignore(function () use ($traceId, $user, $name, $log) {
        DB::table('pulse_boosted_traces')->insert([
            'trace_id' => $traceId,
            'type' => 'request',
            'name' => $name,
            'started_at' => now()->getTimestamp(),
            'duration_ms' => 50,
            'status' => 'ok',
            'user_id' => $user,
        ]);

        DB::table('pulse_boosted_trace_events')->insert([
            'trace_id' => $traceId,
            'type' => 'log',
            'label' => $log,
            'offset_ms' => 1,
            'level' => 'info',
        ]);
    });

    return $traceId;
}

it('narrows traces to one user', function () {
    requestBy('7', 'GET /mine', 'mine');
    requestBy('8', 'GET /theirs', 'theirs');

    Livewire::test(Traces::class, ['lazy' => false])
        ->call('filterByUser', '7')
        ->assertSee('GET /mine')
        ->assertDontSee('GET /theirs')
        ->call('filterByUser', '')
        ->assertSee('GET /theirs');
});

it('narrows logs and exceptions to one user', function () {
    requestBy('7', 'GET /mine', 'Seen by seven');
    requestBy('8', 'GET /theirs', 'Seen by eight');

    $repository = app(IssueRepository::class);
    $repository->record(new RuntimeException('Hit seven'), null, '7');
    $repository->record(new LogicException('Hit eight'), null, '8');
    $repository->flush();

    Livewire::test(Logs::class, ['lazy' => false])
        ->call('filterByUser', '7')
        ->assertSee('Seen by seven')
        ->assertSee('Hit seven')
        ->assertDontSee('Seen by eight')
        ->assertDontSee('Hit eight');
});

it('narrows issues to the ones a user ran into', function () {
    $repository = app(IssueRepository::class);
    $repository->record(new RuntimeException('Hit seven'), null, '7');
    $repository->record(new LogicException('Hit eight'), null, '8');
    $repository->flush();

    Livewire::test(Issues::class, ['lazy' => false])
        ->call('filterByUser', '7')
        ->assertSee('Hit seven')
        ->assertDontSee('Hit eight');
});

it('offers the users it has seen, and tells the cards when one is picked', function () {
    requestBy('7', 'GET /mine', 'mine');
    requestBy(null, 'GET /guest', 'guest');

    Livewire::test(UserFilter::class)
        ->assertViewHas('users', fn (array $users) => array_map('strval', array_keys($users)) === ['7'])
        ->set('user', '7')
        ->assertDispatched('user-filter', user: '7');
});

it('picks the filter up from the address, so a link can share it', function () {
    requestBy('7', 'GET /mine', 'mine');
    requestBy('8', 'GET /theirs', 'theirs');

    Livewire::withQueryParams(['user' => '8'])
        ->test(Traces::class, ['lazy' => false])
        ->assertSee('GET /theirs')
        ->assertDontSee('GET /mine');
});
