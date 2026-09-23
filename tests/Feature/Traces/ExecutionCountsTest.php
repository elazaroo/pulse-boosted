<?php

use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Livewire\Traces as TracesCard;
use Elazaroo\PulseBoosted\Traces\Tracer;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    Config::set('pulse-boosted.traces.sample_rate', 1.0);
    Config::set('pulse-boosted.traces.sample_rates', []);
});

it('counts every event by kind, even the ones past the cap', function () {
    Config::set('pulse-boosted.traces.max_events', 3);

    $tracer = app(Tracer::class);

    $tracer->start('command', 'import');

    foreach (range(1, 10) as $i) {
        $tracer->event('query', "select {$i}", 2.0);
    }

    $tracer->event('log', 'done');
    $tracer->finish();
    $tracer->flush();

    $meta = json_decode(Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->value('meta')), true);

    // Three kept on the timeline, but ten happened, and the totals say so.
    expect($meta['dropped_events'])->toBe(8);
    expect($meta['counts']['query'])->toBe(['count' => 10, 'ms' => 20]);
    expect($meta['counts']['log']['count'])->toBe(1);
});

it('records the peak memory of the execution', function () {
    $tracer = app(Tracer::class);

    $tracer->start('command', 'import');
    $tracer->finish();
    $tracer->flush();

    $meta = json_decode(Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->value('meta')), true);

    expect($meta['peak_memory'])->toBeInt()->toBeGreaterThan(0);
});

it('shows the counts and peak memory in the panel rather than the raw metadata', function () {
    $tracer = app(Tracer::class);

    $tracer->start('command', 'import');
    $tracer->event('query', 'select 1', 5.0);
    $tracer->finish();
    $tracer->flush();

    $traceId = Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->value('trace_id'));

    Livewire::test(TracesCard::class, ['lazy' => false])
        ->call('select', $traceId)
        ->assertSee('Peak memory')
        ->assertSee('MB')
        ->assertViewHas('detail', fn (array $detail) => $detail['summary']['query']['count'] === 1
            && ! array_key_exists('counts', $detail['meta'])
            && ! array_key_exists('peak_memory', $detail['meta']));
});
