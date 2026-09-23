<?php

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Livewire\Exceptions;
use Elazaroo\PulseBoosted\Livewire\Issues;
use Elazaroo\PulseBoosted\Livewire\Logs;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

it('is left off the default dashboard, where Issues and Logging cover it', function () {
    // Issues groups exceptions by where they were thrown and Logging lists
    // each one; this card is still available to published dashboards.
    $this
        ->get('/pulse-boosted')
        ->assertDontSeeLivewire(Exceptions::class)
        ->assertSeeLivewire(Issues::class)
        ->assertSeeLivewire(Logs::class);
});

it('renders exceptions', function () {
    $exception1 = json_encode(['RuntimeException', 'app/Foo.php:123']);
    $exception2 = json_encode(['RuntimeException', 'app/Bar.php:123']);

    // Add entries outside of the window.
    Carbon::setTestNow('2000-01-01 12:00:00');
    Pulse::record('exception', $exception1, now()->timestamp)->max()->count();
    Pulse::record('exception', $exception2, now()->timestamp)->max()->count();

    // Add entries to the "tail".
    Carbon::setTestNow('2000-01-01 12:00:01');
    Pulse::record('exception', $exception1, now()->timestamp)->max()->count();
    Pulse::record('exception', $exception1, now()->timestamp)->max()->count();
    Pulse::record('exception', $exception2, now()->timestamp)->max()->count();

    // Add entries to the current buckets.
    Carbon::setTestNow('2000-01-01 13:00:00');
    Pulse::record('exception', $exception1, now()->timestamp)->max()->count();
    Pulse::record('exception', $exception1, now()->timestamp)->max()->count();
    Pulse::record('exception', $exception2, now()->timestamp)->max()->count();

    Pulse::ingest();

    Livewire::test(Exceptions::class, ['lazy' => false])
        ->assertViewHas('exceptions', collect([
            (object) ['class' => 'RuntimeException', 'location' => 'app/Foo.php:123', 'count' => 4, 'latest' => CarbonImmutable::now(), 'isError' => false],
            (object) ['class' => 'RuntimeException', 'location' => 'app/Bar.php:123', 'count' => 2, 'latest' => CarbonImmutable::now(), 'isError' => false],
        ]));
});

it('separates PHP errors from exceptions', function () {
    Carbon::setTestNow('2000-01-01 13:00:00');

    Pulse::record('exception', json_encode(['RuntimeException', 'app/Foo.php:1']), now()->timestamp)->max()->count();
    Pulse::record('exception', json_encode(['TypeError', 'app/Bar.php:2']), now()->timestamp)->max()->count();
    Pulse::record('exception', json_encode(['DivisionByZeroError', 'app/Baz.php:3']), now()->timestamp)->max()->count();
    Pulse::ingest();

    $card = Livewire::test(Exceptions::class, ['lazy' => false]);

    // All three, then each half of the Throwable hierarchy on its own.
    $card->assertViewHas('exceptions', fn ($rows) => $rows->count() === 3);
    $card->assertViewHas('kindCounts', ['all' => 3, 'exceptions' => 1, 'errors' => 2]);

    $card->set('kind', 'errors')
        ->assertSee('TypeError')
        ->assertSee('DivisionByZeroError')
        ->assertDontSee('RuntimeException');

    $card->set('kind', 'exceptions')
        ->assertSee('RuntimeException')
        ->assertDontSee('TypeError');
});

it('counts a class the application no longer has as an exception', function () {
    Carbon::setTestNow('2000-01-01 13:00:00');

    Pulse::record('exception', json_encode(['Some\Removed\Package\Boom', 'app/Foo.php:1']), now()->timestamp)->max()->count();
    Pulse::ingest();

    Livewire::test(Exceptions::class, ['lazy' => false])
        ->assertViewHas('kindCounts', ['all' => 1, 'exceptions' => 1, 'errors' => 0]);
});
