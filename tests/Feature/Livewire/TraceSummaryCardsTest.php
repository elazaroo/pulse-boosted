<?php

use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Livewire\Commands;
use Elazaroo\PulseBoosted\Livewire\ScheduledTasks;
use Elazaroo\PulseBoosted\Traces\TraceRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Record one finished execution.
 */
function executionTrace(string $type, string $name, ?int $durationMs = 100, string $status = 'ok'): string
{
    $traceId = (string) Str::uuid();

    Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->insert([
        'trace_id' => $traceId,
        'type' => $type,
        'name' => $name,
        'started_at' => now()->getTimestamp(),
        'duration_ms' => $durationMs,
        'status' => $status,
    ]));

    return $traceId;
}

it('totals commands by name', function () {
    executionTrace('command', 'invoices:send', 100);
    executionTrace('command', 'invoices:send', 300);
    executionTrace('command', 'cache:clear', 20);

    $summary = app(TraceRepository::class)->summaryByName('command');

    expect($summary)->toHaveCount(2);
    expect($summary[0]['name'])->toBe('invoices:send');
    expect($summary[0]['count'])->toBe(2);
    expect($summary[0]['avg'])->toBe(200);
    expect($summary[0]['max'])->toBe(300);
});

it('reports the 95th percentile rather than hiding the slow tail in an average', function () {
    // Ninety-nine fast runs and one that took eight seconds. The average says
    // everything is fine; p95 is what tells you it is not.
    foreach (range(1, 99) as $i) {
        executionTrace('command', 'reports:build', 40);
    }
    executionTrace('command', 'reports:build', 8000);

    $summary = app(TraceRepository::class)->summaryByName('command');

    expect($summary[0]['avg'])->toBe(120);
    expect($summary[0]['p95'])->toBe(40);
    expect($summary[0]['max'])->toBe(8000);
});

it('picks the p95 by nearest rank', function () {
    foreach ([10, 20, 30, 40, 50, 60, 70, 80, 90, 100] as $duration) {
        executionTrace('command', 'ranked', $duration);
    }

    // ceil(0.95 * 10) = 10, so the tenth smallest.
    expect(app(TraceRepository::class)->summaryByName('command')[0]['p95'])->toBe(100);
});

it('counts how often something failed', function () {
    executionTrace('command', 'flaky', 10, 'failed');
    executionTrace('command', 'flaky', 10, 'ok');
    executionTrace('command', 'steady', 10, 'ok');

    $summary = app(TraceRepository::class)->summaryByName('command');

    expect($summary->firstWhere('name', 'flaky')['failed'])->toBe(1);
    expect($summary->firstWhere('name', 'steady')['failed'])->toBe(0);
});

it('copes with executions that never finished', function () {
    executionTrace('command', 'hung', null);

    $summary = app(TraceRepository::class)->summaryByName('command');

    expect($summary[0]['avg'])->toBeNull();
    expect($summary[0]['p95'])->toBeNull();
    expect($summary[0]['count'])->toBe(1);
});

it('keeps commands and scheduled tasks apart', function () {
    executionTrace('command', 'invoices:send');
    executionTrace('schedule', 'App\Console\Commands\PruneMedia');

    Livewire::test(Commands::class, ['lazy' => false])
        ->assertSee('invoices:send')
        ->assertDontSee('PruneMedia');

    Livewire::test(ScheduledTasks::class, ['lazy' => false])
        ->assertSee('PruneMedia')
        ->assertDontSee('invoices:send');
});

it('shows the numbers on the card', function () {
    executionTrace('command', 'invoices:send', 100, 'failed');

    Livewire::test(Commands::class, ['lazy' => false])
        ->assertSee('invoices:send')
        ->assertSee('100ms')
        ->assertSee('Commands');
});

it('says so when nothing has run', function () {
    Livewire::test(ScheduledTasks::class, ['lazy' => false])
        ->assertSee('Nothing recorded')
        ->assertSee('the scheduler is not running');
});

it('opens the most recent run of a name', function () {
    executionTrace('command', 'invoices:send', 100);
    $latest = executionTrace('command', 'invoices:send', 200);

    expect(app(TraceRepository::class)->summaryByName('command')[0]['traceId'])->toBe($latest);

    Livewire::test(Commands::class, ['lazy' => false])
        ->call('showTrace', $latest)
        ->assertDispatched('open-trace', traceId: $latest);
});
