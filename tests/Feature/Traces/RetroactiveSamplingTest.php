<?php

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\Alerts\AlertRule;
use Elazaroo\PulseBoosted\Alerts\Metrics;
use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Traces\Tracer;
use Elazaroo\PulseBoosted\Traces\TraceRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    // Every execution loses the draw, so anything written was kept for a reason.
    Config::set('pulse-boosted.traces.sample_rate', 0.0);
    Config::set('pulse-boosted.traces.sample_rates', []);
    Config::set('pulse-boosted.traces.keep', ['failed' => true, 'exceptions' => true, 'slower_than' => 1000]);
});

function traces(): Collection
{
    return Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->get());
}

it('writes nothing for an ordinary execution that lost the draw', function () {
    Route::get('fine', fn () => 'ok')->middleware('web');

    $this->get('fine')->assertOk();

    expect(traces())->toHaveCount(0);
});

it('keeps a failed request that lost the draw, with everything that led to it', function () {
    Route::get('broken', function () {
        DB::table('users')->count();
        Log::warning('About to fail');

        throw new RuntimeException('Boom');
    })->middleware('web');

    $this->get('broken')->assertServerError();

    $trace = traces()->first();

    expect($trace)->not->toBeNull();
    expect((bool) $trace->sampled)->toBeFalse();
    expect($trace->status)->toBe('failed');

    // Recorded from the start, not only from the moment it went wrong.
    $events = Pulse::ignore(fn () => DB::table('pulse_boosted_trace_events')->where('trace_id', $trace->trace_id)->pluck('type')->all());

    expect($events)->toContain('query', 'log', 'exception');

    Pulse::flush();
});

it('keeps a request that caught and reported an exception', function () {
    Route::get('caught', function () {
        report(new InvalidArgumentException('Bad coupon'));

        return 'ok';
    })->middleware('web');

    $this->get('caught')->assertOk();

    $trace = traces()->first();

    expect($trace->status)->toBe('ok');
    expect(json_decode($trace->meta, true)['kept_because'])->toBe('exception');

    Pulse::flush();
});

it('keeps a slow execution', function () {
    $tracer = app(Tracer::class);

    $tracer->start('command', 'reports:build');
    Carbon::setTestNow(now()->addSeconds(3));
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(3));
    $tracer->finish();
    $tracer->flush();

    CarbonImmutable::setTestNow();
    Carbon::setTestNow();

    expect(json_decode(traces()->first()->meta, true)['kept_because'])->toBe('slow');
});

it('does not keep what it was told not to', function () {
    Config::set('pulse-boosted.traces.keep', ['failed' => false, 'exceptions' => false, 'slower_than' => null]);

    Route::get('broken', fn () => throw new RuntimeException('Boom'))->middleware('web');

    $this->get('broken')->assertServerError();

    expect(traces())->toHaveCount(0);

    Pulse::flush();
});

it('still writes everything that won the draw, marked as sampled', function () {
    Config::set('pulse-boosted.traces.sample_rate', 1.0);

    Route::get('fine', fn () => 'ok')->middleware('web');

    $this->get('fine')->assertOk();

    expect((bool) traces()->first()->sampled)->toBeTrue();
    expect(json_decode(traces()->first()->meta ?? '{}', true))->not->toHaveKey('kept_because');
});

it('leaves kept failures out of the error rate, so it still tells the truth', function () {
    // Nine sampled successes and one sampled failure: a 10% error rate. Add
    // fifty failures that were only kept because they failed — counting them
    // would claim 85%.
    Pulse::ignore(function () {
        $row = fn (string $status, bool $sampled) => [
            'trace_id' => (string) Str::uuid(),
            'type' => 'request',
            'name' => 'GET /',
            'started_at' => now()->getTimestamp(),
            'duration_ms' => 100,
            'status' => $status,
            'sampled' => $sampled,
        ];

        DB::table('pulse_boosted_traces')->insert([
            ...array_map(fn () => $row('ok', true), range(1, 9)),
            $row('failed', true),
            ...array_map(fn () => $row('failed', false), range(1, 50)),
        ]);
    });

    $rate = app(Metrics::class)->read(new AlertRule(name: 'Error rate', metric: 'error_rate', threshold: 5));

    expect($rate)->toBe(10.0);

    // The list still shows every one of them.
    expect(app(TraceRepository::class)->count(['status' => 'failed']))->toBe(51);
});
