<?php

use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Livewire\Traces as TracesCard;
use Illuminate\Foundation\Events\Terminating;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

beforeEach(function () {
    Config::set('pulse-boosted.traces.sample_rate', 1.0);
    Config::set('pulse-boosted.traces.sample_rates', []);
});

/**
 * @return array{0: object, 1: list<array<string, mixed>>}
 */
function lastRequestTrace(): array
{
    $trace = Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->where('type', 'request')->orderByDesc('id')->first());

    return [$trace, json_decode($trace->meta, true)['stages'] ?? []];
}

it('splits a request into the stages of its lifecycle, in order', function () {
    Route::get('staged', fn () => 'ok')->middleware('web');

    $this->get('staged')->assertOk();

    [, $stages] = lastRequestTrace();

    $names = array_column($stages, 'name');

    $expected = ['middleware', 'controller', 'render', 'after_middleware', 'sending'];

    if (class_exists(Terminating::class)) {
        $expected[] = 'terminating';
    }

    // Bootstrap is only there when the request's start time can be trusted,
    // which it cannot inside a test; everything after it always is.
    expect(array_values(array_diff($names, ['bootstrap'])))->toBe($expected);
});

it('makes the stages add up to the whole request', function () {
    Route::get('staged', fn () => 'ok')->middleware('web');

    $this->get('staged')->assertOk();

    [$trace, $stages] = lastRequestTrace();

    $sum = array_sum(array_column($stages, 'duration'));

    // Rounded to the millisecond, one per stage at most.
    expect(abs($sum - (int) $trace->duration_ms))->toBeLessThanOrEqual(count($stages));

    foreach ($stages as $i => $stage) {
        if ($i > 0) {
            expect($stage['start'])->toBeGreaterThanOrEqual($stages[$i - 1]['start']);
        }
    }
});

it('puts each query under the stage it ran in', function () {
    Route::get('staged', function () {
        DB::table('users')->where('id', 2)->count();

        app()->terminating(fn () => DB::table('users')->where('id', 3)->count());

        return 'ok';
    })->middleware(['web', StagesTestMiddleware::class]);

    $this->get('staged')->assertOk();

    [$trace] = lastRequestTrace();

    Livewire::test(TracesCard::class, ['lazy' => false])
        ->call('select', $trace->trace_id)
        ->assertViewHas('detail', function (array $detail) {
            $byStage = collect($detail['stages'])->mapWithKeys(fn ($stage) => [
                $stage['name'] => collect($stage['events'])->pluck('label')->implode(' | '),
            ]);

            expect($byStage['middleware'])->toContain('select count(*)');
            expect($byStage['controller'])->toContain('select count(*)');
            expect($byStage['render'])->toBe('');

            if (class_exists(Terminating::class)) {
                expect($byStage['terminating'])->toContain('select count(*)');
            }

            return true;
        })
        ->assertSee('Controller')
        ->assertSee('Middleware');
});

it('keeps the status the response went out with, after terminating callbacks run', function () {
    Route::get('broken', fn () => response('nope', 503))->middleware('web');

    $this->get('broken')->assertStatus(503);

    [$trace] = lastRequestTrace();

    expect($trace->status)->toBe('failed');
    expect(json_decode($trace->meta, true)['status'])->toBe(503);
});

it('does not stage commands, which have no request lifecycle', function () {
    $this->artisan('list')->assertOk();

    $trace = Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->where('type', 'command')->first());

    expect(json_decode($trace->meta ?? '{}', true))->not->toHaveKey('stages');
});

class StagesTestMiddleware
{
    public function handle($request, Closure $next)
    {
        DB::table('users')->where('id', 1)->count();

        return $next($request);
    }
}
