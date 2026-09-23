<?php

use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Livewire\Logs;
use Elazaroo\PulseBoosted\Livewire\Mail;
use Elazaroo\PulseBoosted\Livewire\Notifications;
use Elazaroo\PulseBoosted\Traces\TraceEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Put one execution, and one thing that happened inside it, in the tables.
 */
function traceWithEvent(string $type, string $label, ?string $level = null, string $execution = 'GET /checkout'): string
{
    $traceId = (string) Str::uuid();

    Pulse::ignore(function () use ($traceId, $type, $label, $level, $execution) {
        DB::table('pulse_boosted_traces')->insert([
            'trace_id' => $traceId,
            'type' => 'request',
            'name' => $execution,
            'started_at' => now()->getTimestamp(),
            'duration_ms' => 120,
            'status' => 'ok',
        ]);

        DB::table('pulse_boosted_trace_events')->insert([
            'trace_id' => $traceId,
            'type' => $type,
            'label' => $label,
            'offset_ms' => 10,
            'level' => $level,
        ]);
    });

    return $traceId;
}

it('lists log lines with the execution they came from', function () {
    traceWithEvent(TraceEvent::LOG, 'Payment declined', 'error', 'POST /pay');

    Livewire::test(Logs::class, ['lazy' => false])
        ->assertSee('Payment declined')
        ->assertSee('POST /pay');
});

it('filters logs by level', function () {
    traceWithEvent(TraceEvent::LOG, 'Disk nearly full', 'warning');
    traceWithEvent(TraceEvent::LOG, 'Payment declined', 'error');

    Livewire::test(Logs::class, ['lazy' => false])
        ->set('logLevel', 'error')
        ->assertSee('Payment declined')
        ->assertDontSee('Disk nearly full');
});

it('searches logs by message', function () {
    traceWithEvent(TraceEvent::LOG, 'Disk nearly full', 'warning');
    traceWithEvent(TraceEvent::LOG, 'Payment declined', 'error');

    Livewire::test(Logs::class, ['lazy' => false])
        ->set('search', 'declined')
        ->assertSee('Payment declined')
        ->assertDontSee('Disk nearly full');
});

it('does not let a search term act as a wildcard', function () {
    traceWithEvent(TraceEvent::LOG, 'Disk nearly full', 'warning');

    Livewire::test(Logs::class, ['lazy' => false])
        ->set('search', '%')
        ->assertDontSee('Disk nearly full');
});

it('resets to the first page when the filters change', function () {
    traceWithEvent(TraceEvent::LOG, 'Something', 'info');

    Livewire::test(Logs::class, ['lazy' => false])
        ->set('page', 3)
        ->set('search', 'some')
        ->assertSet('page', 1);
});

it('lists sent mail', function () {
    traceWithEvent(TraceEvent::MAIL, 'Your invoice is ready');

    Livewire::test(Mail::class, ['lazy' => false])
        ->assertSee('Your invoice is ready')
        ->assertSee('Mail');
});

it('lists delivered notifications', function () {
    traceWithEvent(TraceEvent::NOTIFICATION, 'InvoicePaid via mail');

    Livewire::test(Notifications::class, ['lazy' => false])
        ->assertSee('InvoicePaid via mail');
});

it('keeps each card to its own kind of event', function () {
    traceWithEvent(TraceEvent::LOG, 'A log line', 'info');
    traceWithEvent(TraceEvent::MAIL, 'An email');

    Livewire::test(Mail::class, ['lazy' => false])
        ->assertSee('An email')
        ->assertDontSee('A log line');
});

it('says so when there is nothing recorded', function () {
    Livewire::test(Notifications::class, ['lazy' => false])->assertSee('Nothing recorded');
});

it('can open the trace a row came from', function () {
    $traceId = traceWithEvent(TraceEvent::LOG, 'Payment declined', 'error');

    Livewire::test(Logs::class, ['lazy' => false])
        ->call('showTrace', $traceId)
        ->assertDispatched('open-trace', traceId: $traceId);
});
