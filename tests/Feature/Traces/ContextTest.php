<?php

use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Livewire\TraceViewer as TracesCard;
use Elazaroo\PulseBoosted\Traces\Trace;
use Elazaroo\PulseBoosted\Traces\Tracer;
use Elazaroo\PulseBoosted\Traces\TraceRepository;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

beforeEach(function () {
    Config::set('pulse-boosted.traces.sample_rate', 1.0);
    Config::set('pulse-boosted.traces.sample_rates', []);
});

/**
 * The meta column of the only trace recorded.
 *
 * @return array<string, mixed>
 */
function recordedMeta(): array
{
    $trace = Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->first());

    return json_decode($trace?->meta ?? '{}', true) ?: [];
}

it('attaches the application\'s own attributes to a trace', function () {
    Route::get('checkout', function () {
        Pulse::context(['order' => 4711, 'tenant' => 'acme']);

        return 'ok';
    })->middleware('web');

    $this->get('checkout')->assertOk();

    expect(recordedMeta()['context'])->toBe(['order' => 4711, 'tenant' => 'acme']);
});

it('merges repeated calls', function () {
    $tracer = app(Tracer::class);

    $tracer->start('command', 'demo');
    $tracer->context(['a' => 1]);
    $tracer->context(['b' => 2, 'a' => 3]);
    $tracer->finish();
    $tracer->flush();

    expect(recordedMeta()['context'])->toBe(['a' => 3, 'b' => 2]);
});

it('does nothing when the execution was not sampled in', function () {
    Config::set('pulse-boosted.traces.sample_rate', 0.0);

    Route::get('checkout', function () {
        // The application should not have to ask whether it is being traced.
        Pulse::context(['order' => 4711]);

        return 'ok';
    })->middleware('web');

    $this->get('checkout')->assertOk();

    expect(Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->count()))->toBe(0);
});

it('does nothing when tracing is switched off', function () {
    Config::set('pulse-boosted.traces.enabled', false);

    Pulse::context(['order' => 4711]);

    expect(Pulse::currentContext())->toBe([]);
});

it('reduces values to something a column can hold', function () {
    $tracer = app(Tracer::class);

    $tracer->start('command', 'demo');
    $tracer->context([
        'int' => 7,
        'float' => 1.5,
        'true' => true,
        'null' => null,
        'enum' => ContextSuit::Hearts,
        'date' => new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')),
        'object' => new ContextThing,
        'array' => ['a' => 1],
        'long' => str_repeat('x', 2000),
    ]);
    $tracer->finish();
    $tracer->flush();

    $context = recordedMeta()['context'];

    expect($context['int'])->toBe(7);
    expect($context['float'])->toBe(1.5);
    expect($context['true'])->toBeTrue();
    expect($context['null'])->toBeNull();
    expect($context['enum'])->toBe('hearts');
    expect($context['date'])->toBe('2026-01-01T00:00:00+00:00');
    expect($context['object'])->toBe(ContextThing::class);
    expect($context['array'])->toBe('{"a":1}');
    expect(strlen($context['long']))->toBeLessThanOrEqual(503); // 500 plus the ellipsis
});

it('caps how many attributes one execution may attach', function () {
    $tracer = app(Tracer::class);

    $tracer->start('command', 'demo');

    // A loop attaching a new key each time would otherwise write a row that
    // grows without limit.
    foreach (range(1, 100) as $i) {
        $tracer->context(["key{$i}" => $i]);
    }

    $tracer->finish();
    $tracer->flush();

    expect(recordedMeta()['context'])->toHaveCount(Trace::MAX_CONTEXT);
});

it('still lets an existing key be updated once the cap is reached', function () {
    $tracer = app(Tracer::class);

    $tracer->start('command', 'demo');

    foreach (range(1, Trace::MAX_CONTEXT) as $i) {
        $tracer->context(["key{$i}" => $i]);
    }

    $tracer->context(['key1' => 'changed', 'overflow' => 'dropped']);
    $tracer->finish();
    $tracer->flush();

    $context = recordedMeta()['context'];

    expect($context['key1'])->toBe('changed');
    expect($context)->not->toHaveKey('overflow');
});

it('finds a trace by what was attached to it', function () {
    $tracer = app(Tracer::class);

    $tracer->start('command', 'demo:one');
    $tracer->context(['order' => 4711]);
    $tracer->finish();

    $tracer->start('command', 'demo:two');
    $tracer->context(['order' => 9999]);
    $tracer->finish();

    $tracer->flush();

    $found = app(TraceRepository::class)->recent(['search' => '4711']);

    expect($found)->toHaveCount(1);
    expect($found[0]->name)->toBe('demo:one');
});

it('shows what was attached in the timeline panel', function () {
    $tracer = app(Tracer::class);

    $tracer->start('command', 'demo');
    $tracer->context(['tenant' => 'acme-corp']);
    $tracer->finish();
    $tracer->flush();

    $traceId = Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->value('trace_id'));

    Livewire::test(TracesCard::class, ['lazy' => false])
        ->call('select', $traceId)
        ->assertSee('Context')
        ->assertSee('tenant')
        ->assertSee('acme-corp');
});

enum ContextSuit: string
{
    case Hearts = 'hearts';
}

class ContextThing
{
    //
}
