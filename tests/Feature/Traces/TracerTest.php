<?php

use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Recorders\Traces as TracesRecorder;
use Elazaroo\PulseBoosted\Traces\Tracer;
use Elazaroo\PulseBoosted\Traces\TraceRepository;
use Illuminate\Auth\GenericUser;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

beforeEach(function () {
    Config::set('queue.default', 'database');

    // Record everything, so the tests are not at the mercy of a dice roll.
    Config::set('pulse-boosted.traces.sample_rate', 1.0);
    Config::set('pulse-boosted.traces.sample_rates', []);
});

it('records a request and everything that happened inside it', function () {
    Route::get('traced', function () {
        DB::table('users')->count();
        Cache::get('something');
        Log::warning('Careful');
        SidecarJob::dispatch();

        return 'ok';
    })->middleware('web');

    $this->get('traced')->assertOk();

    $trace = Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->first());

    expect($trace)->not->toBeNull();
    expect($trace->type)->toBe('request');
    expect($trace->name)->toContain('traced');
    expect($trace->status)->toBe('ok');
    expect((int) $trace->duration_ms)->toBeGreaterThanOrEqual(0);

    $types = Pulse::ignore(fn () => DB::table('pulse_boosted_trace_events')
        ->where('trace_id', $trace->trace_id)
        ->pluck('type')
        ->unique()
        ->sort()
        ->values()
        ->all());

    expect($types)->toContain('query', 'cache', 'log', 'job');

    Pulse::flush();
});

it('joins a job back to the request that queued it', function () {
    Route::get('queues-work', function () {
        SidecarJob::dispatch();

        return 'ok';
    })->middleware('web');

    $this->get('queues-work')->assertOk();

    $request = Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->where('type', 'request')->first());
    expect($request)->not->toBeNull();

    Artisan::call('queue:work', ['--max-jobs' => 1, '--stop-when-empty' => true, '--sleep' => 0]);

    $job = Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->where('type', 'job')->first());

    expect($job)->not->toBeNull();
    expect($job->parent_trace_id)->toBe($request->trace_id);

    Pulse::flush();
});

it('records a command as its own context', function () {
    // Artisan::call() does not fire the console events under testbench the
    // way `php artisan` does, so the recorder is driven directly.
    $recorder = app(TracesRecorder::class);

    $recorder->record(new CommandStarting('traced:thing', new ArrayInput([]), new NullOutput));
    app(Tracer::class)->event('query', 'select 1');
    $recorder->record(new CommandFinished('traced:thing', new ArrayInput([]), new NullOutput, 0));

    app(Tracer::class)->flush();

    $trace = Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->where('type', 'command')->first());

    expect($trace)->not->toBeNull();
    expect($trace->name)->toBe('traced:thing');
    expect($trace->status)->toBe('ok');

    Pulse::flush();
});

it('marks a command that exited non-zero as failed', function () {
    $recorder = app(TracesRecorder::class);

    $recorder->record(new CommandStarting('broken:thing', new ArrayInput([]), new NullOutput));
    $recorder->record(new CommandFinished('broken:thing', new ArrayInput([]), new NullOutput, 1));

    app(Tracer::class)->flush();

    $trace = Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->where('type', 'command')->first());

    expect($trace->status)->toBe('failed');
    expect(json_decode($trace->meta, true)['exit_code'])->toBe(1);

    Pulse::flush();
});

it('marks a failed job trace as failed', function () {
    Bus::dispatchToQueue(new ExplodingSidecarJob);
    Artisan::call('queue:work', ['--max-jobs' => 1, '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1]);

    $trace = Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->where('type', 'job')->first());

    expect($trace)->not->toBeNull();
    expect($trace->status)->toBe('failed');

    $exception = Pulse::ignore(fn () => DB::table('pulse_boosted_trace_events')
        ->where('trace_id', $trace->trace_id)
        ->where('type', 'exception')
        ->first());

    expect($exception)->not->toBeNull();
    expect($exception->label)->toContain('Sidecar exploded');

    Pulse::flush();
});

it('records nothing when sampled out', function () {
    Config::set('pulse-boosted.traces.sample_rate', 0.0);

    Route::get('not-traced', fn () => 'ok')->middleware('web');
    $this->get('not-traced')->assertOk();

    expect(Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->count()))->toBe(0);

    Pulse::flush();
});

it('records nothing when tracing is switched off', function () {
    Config::set('pulse-boosted.traces.enabled', false);

    Route::get('disabled', fn () => 'ok')->middleware('web');
    $this->get('disabled')->assertOk();

    expect(Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->count()))->toBe(0);

    Pulse::flush();
});

it('does not record what it is told to ignore', function () {
    $tracer = app(Tracer::class);
    $tracer->start('command', 'manual');

    $tracer->event('query', 'select 1');
    $tracer->ignore(fn () => $tracer->event('query', 'select 2'));
    $tracer->event('query', 'select 3');

    $tracer->finish();
    $tracer->flush();

    $labels = Pulse::ignore(fn () => DB::table('pulse_boosted_trace_events')->pluck('label')->all());

    expect($labels)->toBe(['select 1', 'select 3']);

    Pulse::flush();
});

it('caps how many events one trace may hold', function () {
    Config::set('pulse-boosted.traces.max_events', 3);

    $tracer = app(Tracer::class);
    $tracer->start('command', 'busy');

    foreach (range(1, 10) as $i) {
        $tracer->event('query', "select {$i}");
    }

    $tracer->finish();
    $tracer->flush();

    $trace = Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->first());

    expect(Pulse::ignore(fn () => DB::table('pulse_boosted_trace_events')->count()))->toBe(3);
    // And it says so, rather than pretending the execution just stopped.
    expect(json_decode($trace->meta, true)['dropped_events'])->toBe(7);

    Pulse::flush();
});

it('respects the configured log level', function () {
    Config::set('pulse-boosted.traces.log_level', 'error');

    Route::get('logging', function () {
        Log::debug('Ignore me');
        Log::error('Keep me');

        return 'ok';
    })->middleware('web');

    $this->get('logging')->assertOk();

    $logs = Pulse::ignore(fn () => DB::table('pulse_boosted_trace_events')->where('type', 'log')->pluck('label')->all());

    expect($logs)->toBe(['Keep me']);

    Pulse::flush();
});

it('trims traces and their events together', function () {
    Config::set('pulse-boosted.traces.trim.keep', '1 hour');

    $tracer = app(Tracer::class);
    $tracer->start('command', 'old');
    $tracer->event('query', 'select 1');
    $tracer->finish();
    $tracer->flush();

    expect(Pulse::ignore(fn () => DB::table('pulse_boosted_trace_events')->count()))->toBe(1);

    $this->travel(2)->hours();
    $tracer->trim();

    expect(Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->count()))->toBe(0);
    expect(Pulse::ignore(fn () => DB::table('pulse_boosted_trace_events')->count()))->toBe(0);

    Pulse::flush();
});

it('finds the trace belonging to a job', function () {
    Bus::dispatchToQueue(new SidecarJob);
    Artisan::call('queue:work', ['--max-jobs' => 1, '--stop-when-empty' => true, '--sleep' => 0]);

    $trace = Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->where('type', 'job')->first());
    $uuid = json_decode($trace->meta, true)['uuid'];

    expect(app(TraceRepository::class)->forJob($uuid)?->trace_id)->toBe($trace->trace_id);

    Pulse::flush();
});

it('carries the trace id on the queue payload', function () {
    $tracer = app(Tracer::class);
    $tracer->start('command', 'dispatcher');

    Bus::dispatchToQueue(new SidecarJob);

    $payload = json_decode(Pulse::ignore(fn () => DB::table('jobs')->first()->payload), true);

    expect($payload[TracesRecorder::PAYLOAD_KEY])->toBe($tracer->currentId());

    $tracer->finish();
    Pulse::flush();
});

class SidecarJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        //
    }
}

class ExplodingSidecarJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        throw new RuntimeException('Sidecar exploded');
    }
}

it('measures elapsed time in milliseconds', function () {
    $tracer = app(Tracer::class);
    $tracer->start('command', 'timed');

    // Two events either side of a real pause, so the offsets have to differ.
    $tracer->event('query', 'first');
    usleep(60_000);
    $tracer->event('query', 'second');

    $tracer->finish();
    $tracer->flush();

    $events = Pulse::ignore(fn () => DB::table('pulse_boosted_trace_events')->orderBy('id')->get());
    $trace = Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->first());

    expect((int) $events[0]->offset_ms)->toBeLessThan(50);
    expect((int) $events[1]->offset_ms)->toBeGreaterThanOrEqual(50);
    expect((int) $trace->duration_ms)->toBeGreaterThanOrEqual(50);

    Pulse::flush();
});

it('marks a request that returned a server error as failed', function () {
    Route::get('exploding', fn () => throw new RuntimeException('Nope'))->middleware('web');

    $this->get('exploding')->assertStatus(500);

    $trace = Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->where('type', 'request')->first());

    expect($trace->status)->toBe('failed');
    expect(json_decode($trace->meta, true)['status'])->toBe(500);

    Pulse::flush();
});

it('records who a request ran for', function () {
    // Regression: nothing ever attached the user, so no trace had one and
    // the user filter had nobody to offer.
    Route::get('mine', function () {
        Auth::setUser(new GenericUser(['id' => 42]));

        return 'ok';
    })->middleware('web');

    $this->get('mine')->assertOk();

    expect(Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->value('user_id')))->toBe('42');
});

it('leaves the user empty when nobody is signed in', function () {
    Route::get('anonymous', fn () => 'ok')->middleware('web');

    $this->get('anonymous')->assertOk();

    expect(Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->value('user_id')))->toBeNull();
});
