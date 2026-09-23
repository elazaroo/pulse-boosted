<?php

use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Http\Middleware\Sample;
use Elazaroo\PulseBoosted\Traces\Tracer;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

beforeEach(function () {
    Config::set('pulse-boosted.traces.sample_rate', 1.0);
    Config::set('pulse-boosted.traces.sample_rates', []);
    Config::set('queue.default', 'database');
});

function traceNames(): array
{
    return Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->orderBy('id')->pluck('name')->all());
}

function eventLabels(string $type): array
{
    return Pulse::ignore(fn () => DB::table('pulse_boosted_trace_events')->where('type', $type)->pluck('label')->all());
}

it('traces each job a worker runs, not the worker', function () {
    // What `php artisan queue:work` does: announce the command, then work.
    // Artisan::call alone does not announce it, which is how this hid.
    event(new CommandStarting('queue:work', new ArrayInput([]), new NullOutput));

    Bus::dispatchToQueue(new FilteringTestJob);
    Artisan::call('queue:work', ['--max-jobs' => 1, '--stop-when-empty' => true, '--sleep' => 0]);

    event(new CommandFinished('queue:work', new ArrayInput([]), new NullOutput, 0));
    app(Tracer::class)->flush();

    expect(traceNames())->toContain(FilteringTestJob::class)->not->toContain('queue:work');
});

it('traces each scheduled task, not the scheduler running them', function () {
    event(new CommandStarting('schedule:run', new ArrayInput([]), new NullOutput));

    $tracer = app(Tracer::class);
    $tracer->start('schedule', 'reports:daily');
    $tracer->finish();

    event(new CommandFinished('schedule:run', new ArrayInput([]), new NullOutput, 0));
    $tracer->flush();

    expect(traceNames())->toBe(['reports:daily']);
});

it('leaves out commands that run constantly and say nothing', function () {
    event(new CommandStarting('horizon:snapshot', new ArrayInput([]), new NullOutput));
    event(new CommandFinished('horizon:snapshot', new ArrayInput([]), new NullOutput, 0));
    app(Tracer::class)->flush();

    expect(traceNames())->toBe([]);

    Config::set('pulse-boosted.traces.capture_vendor_commands', true);

    event(new CommandStarting('horizon:snapshot', new ArrayInput([]), new NullOutput));
    event(new CommandFinished('horizon:snapshot', new ArrayInput([]), new NullOutput, 0));
    app(Tracer::class)->flush();

    expect(traceNames())->toBe(['horizon:snapshot']);
});

it('leaves out queries the application rejects', function () {
    Pulse::rejectQueries(fn (string $sql) => str_contains($sql, 'password_reset'));

    Route::get('q', function () {
        DB::table('users')->count();
        DB::table('password_reset_tokens')->count();

        return 'ok';
    })->middleware('web');

    $this->get('q')->assertOk();

    $labels = eventLabels('query');

    expect(collect($labels)->filter(fn ($sql) => str_contains($sql, 'users')))->not->toBeEmpty();
    expect(collect($labels)->filter(fn ($sql) => str_contains($sql, 'password_reset')))->toBeEmpty();
});

it('leaves out cache keys matching a pattern', function () {
    Pulse::rejectCacheKeys(['/^rate-limit:/']);

    Route::get('c', function () {
        Cache::get('rate-limit:127.0.0.1');
        Cache::get('cart:42');

        return 'ok';
    })->middleware('web');

    $this->get('c')->assertOk();

    expect(eventLabels('cache'))->toContain('cart:42')->not->toContain('rate-limit:127.0.0.1');
});

it('rewrites events before keeping them', function () {
    Pulse::redactTraceEvents('log', fn (string $message) => preg_replace('/\d{4}-\d{4}-\d{4}-\d{4}/', '****', $message));

    Route::get('l', function () {
        logger()->info('Charged card 4242-4242-4242-4242');

        return 'ok';
    })->middleware('web');

    $this->get('l')->assertOk();

    expect(eventLabels('log'))->toBe(['Charged card ****']);
});

it('traces a route at its own rate', function () {
    Config::set('pulse-boosted.traces.sample_rate', 0.0);

    Route::get('always', fn () => 'ok')->middleware(['web', Sample::always()]);
    Route::get('never', fn () => 'ok')->middleware(['web', Sample::never()]);

    $this->get('always')->assertOk();
    $this->get('never')->assertOk();

    expect(traceNames())->toBe(['GET /always']);
});

it('still keeps a failure on a route that is never sampled', function () {
    Route::get('never', fn () => throw new RuntimeException('Boom'))->middleware(['web', Sample::never()]);

    $this->get('never')->assertServerError();

    expect(traceNames())->toBe(['GET /never']);

    Pulse::flush();
});

class FilteringTestJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        //
    }
}
