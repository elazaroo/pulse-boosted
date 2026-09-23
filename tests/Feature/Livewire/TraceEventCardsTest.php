<?php

use Elazaroo\PulseBoosted\Facades\Pulse;
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

it('lists sent mail with the execution it came from', function () {
    traceWithEvent(TraceEvent::MAIL, 'Your invoice is ready', execution: 'POST /pay');

    Livewire::test(Mail::class, ['lazy' => false])
        ->assertSee('Your invoice is ready')
        ->assertSee('POST /pay');
});

it('searches by subject', function () {
    traceWithEvent(TraceEvent::MAIL, 'Your invoice is ready');
    traceWithEvent(TraceEvent::MAIL, 'Welcome aboard');

    Livewire::test(Mail::class, ['lazy' => false])
        ->set('search', 'invoice')
        ->assertSee('Your invoice is ready')
        ->assertDontSee('Welcome aboard');
});

it('does not let a search term act as a wildcard', function () {
    traceWithEvent(TraceEvent::MAIL, 'Welcome aboard');

    Livewire::test(Mail::class, ['lazy' => false])
        ->set('search', '%')
        ->assertDontSee('Welcome aboard');
});

it('resets to the first page when the filters change', function () {
    traceWithEvent(TraceEvent::MAIL, 'Something');

    Livewire::test(Mail::class, ['lazy' => false])
        ->set('page', 3)
        ->set('search', 'some')
        ->assertSet('page', 1);
});

it('lists delivered notifications', function () {
    traceWithEvent(TraceEvent::NOTIFICATION, 'InvoicePaid via mail');

    Livewire::test(Notifications::class, ['lazy' => false])
        ->assertSee('InvoicePaid via mail');
});

it('keeps each card to its own kind of event', function () {
    traceWithEvent(TraceEvent::NOTIFICATION, 'A notification');
    traceWithEvent(TraceEvent::MAIL, 'An email');

    Livewire::test(Mail::class, ['lazy' => false])
        ->assertSee('An email')
        ->assertDontSee('A notification');
});

it('says so when there is nothing recorded', function () {
    Livewire::test(Notifications::class, ['lazy' => false])->assertSee('Nothing recorded');
});

it('can open the trace a row came from', function () {
    $traceId = traceWithEvent(TraceEvent::MAIL, 'Your invoice is ready');

    Livewire::test(Mail::class, ['lazy' => false])
        ->call('showTrace', $traceId)
        ->assertDispatched('open-trace', traceId: $traceId);
});
