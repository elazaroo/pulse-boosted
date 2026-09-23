<?php

use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Issues\IssueRepository;
use Elazaroo\PulseBoosted\Livewire\Logs;
use Elazaroo\PulseBoosted\Logging\LogStream;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * A traced execution with one log line in it.
 */
function logLine(string $message, string $level = 'info', int $ago = 0, string $execution = 'GET /checkout'): string
{
    $traceId = (string) Str::uuid();

    Pulse::ignore(function () use ($traceId, $message, $level, $ago, $execution) {
        DB::table('pulse_boosted_traces')->insert([
            'trace_id' => $traceId,
            'type' => 'request',
            'name' => $execution,
            'started_at' => now()->subSeconds($ago)->getTimestamp(),
            'duration_ms' => 50,
            'status' => 'ok',
        ]);

        DB::table('pulse_boosted_trace_events')->insert([
            'trace_id' => $traceId,
            'type' => 'log',
            'label' => $message,
            'offset_ms' => 0,
            'level' => $level,
        ]);
    });

    return $traceId;
}

/**
 * One occurrence of an exception, optionally inside a traced execution.
 */
function thrown(string $class, string $message, int $ago = 0, ?string $traceId = null): void
{
    Pulse::ignore(function () use ($class, $message, $ago, $traceId) {
        $fingerprint = md5($class.$message);

        DB::table('pulse_boosted_issues')->insertOrIgnore([
            'fingerprint' => $fingerprint,
            'class' => $class,
            'kind' => is_a($class, Error::class, true) ? 'error' : 'exception',
            'message' => $message,
            'file' => base_path('app/Thing.php'),
            'line' => 12,
            'status' => 'open',
            'first_seen_at' => now()->subSeconds($ago)->getTimestamp(),
            'last_seen_at' => now()->subSeconds($ago)->getTimestamp(),
            'occurrences' => 1,
        ]);

        DB::table('pulse_boosted_issue_occurrences')->insert([
            'fingerprint' => $fingerprint,
            'trace_id' => $traceId,
            'occurred_at' => now()->subSeconds($ago)->getTimestamp(),
        ]);
    });
}

it('shows log lines and exceptions in one stream, newest first', function () {
    logLine('Oldest line', ago: 300);
    thrown(RuntimeException::class, 'In between', ago: 200);
    logLine('Newest line', ago: 10);

    $entries = app(LogStream::class)->entries();

    expect($entries->pluck('message')->all())->toBe(['Newest line', 'In between', 'Oldest line']);
});

it('filters to exceptions only', function () {
    logLine('A log line', 'error');
    thrown(RuntimeException::class, 'Payment provider unreachable');

    Livewire::test(Logs::class, ['lazy' => false])
        ->set('logLevel', LogStream::EXCEPTIONS)
        ->assertSee('Payment provider unreachable')
        ->assertDontSee('A log line');
});

it('filters to one log level, leaving exceptions out', function () {
    logLine('Disk nearly full', 'warning');
    logLine('Checkout started', 'info');
    thrown(RuntimeException::class, 'Payment provider unreachable');

    Livewire::test(Logs::class, ['lazy' => false])
        ->set('logLevel', 'warning')
        ->assertSee('Disk nearly full')
        ->assertDontSee('Checkout started')
        ->assertDontSee('Payment provider unreachable');
});

it('searches log messages and exception classes alike', function () {
    logLine('Payment declined');
    logLine('Disk nearly full');
    thrown(InvalidArgumentException::class, 'Bad input');

    Livewire::test(Logs::class, ['lazy' => false])
        ->set('search', 'declined')
        ->assertSee('Payment declined')
        ->assertDontSee('Disk nearly full');

    Livewire::test(Logs::class, ['lazy' => false])
        ->set('search', 'InvalidArgument')
        ->assertSee('Bad input')
        ->assertDontSee('Payment declined');
});

it('does not let a search term act as a wildcard', function () {
    logLine('Disk nearly full');

    Livewire::test(Logs::class, ['lazy' => false])
        ->set('search', '%')
        ->assertDontSee('Disk nearly full');
});

it('marks PHP errors apart from exceptions', function () {
    thrown(TypeError::class, 'Wrong type');

    $entry = app(LogStream::class)->entries()->first();

    expect($entry->isError)->toBeTrue();
});

it('shows where an exception was thrown, relative to the application', function () {
    thrown(RuntimeException::class, 'Boom');

    expect(app(LogStream::class)->entries()->first()->location)
        ->toBe('app/Thing.php:12');
});

it('links an exception to its trace only when there is one to open', function () {
    $traceId = logLine('Something', ago: 5);
    thrown(RuntimeException::class, 'Traced', traceId: $traceId);
    thrown(LogicException::class, 'Untraced');

    $entries = app(LogStream::class)->entries(LogStream::EXCEPTIONS);

    expect($entries->firstWhere('message', 'Traced')->traceId)->toBe($traceId);
    expect($entries->firstWhere('message', 'Untraced')->execution)->toBeNull();
});

it('counts each filter', function () {
    logLine('One', 'warning');
    logLine('Two', 'warning');
    thrown(RuntimeException::class, 'Boom');

    expect(app(LogStream::class)->counts())->toBe(['exception' => 1, 'warning' => 2]);
});

it('pages through the merged stream', function () {
    foreach (range(1, 15) as $i) {
        logLine("Line {$i}", ago: $i * 10);
        thrown(RuntimeException::class, "Exception {$i}", ago: $i * 10 + 5);
    }

    $first = app(LogStream::class)->entries(limit: 20);
    $second = app(LogStream::class)->entries(limit: 20, offset: 20);

    expect($first)->toHaveCount(20);
    expect($second)->toHaveCount(10);
    expect($first->pluck('key')->intersect($second->pluck('key')))->toBeEmpty();
});

it('resets to the first page when the filters change', function () {
    Livewire::test(Logs::class, ['lazy' => false])
        ->set('page', 3)
        ->set('logLevel', 'error')
        ->assertSet('page', 1);
});

it('can open the trace an entry came from', function () {
    $traceId = logLine('Payment declined', 'error');

    Livewire::test(Logs::class, ['lazy' => false])
        ->call('showTrace', $traceId)
        ->assertDispatched('open-trace', traceId: $traceId);
});

it('says so when nothing has been logged', function () {
    Livewire::test(Logs::class, ['lazy' => false])->assertSee('Nothing logged');
});

it('does not put a reported exception on the timeline twice', function () {
    // Laravel's handler logs every exception straight after reporting it.
    // The exception is already on the timeline as itself; the log line that
    // repeats it is left out.
    Config::set('pulse-boosted.traces.sample_rate', 1.0);
    Config::set('pulse-boosted.traces.sample_rates', []);

    Route::get('boom', function () {
        Log::warning('Before it broke');

        throw new RuntimeException('Payment provider unreachable');
    })->middleware('web');

    $this->get('boom')->assertServerError();

    $events = Pulse::ignore(fn () => DB::table('pulse_boosted_trace_events')->orderBy('id')->get(['type', 'label']));

    expect($events->where('type', 'exception'))->toHaveCount(1);
    expect($events->where('type', 'log')->pluck('label')->all())->toBe(['Before it broke']);

    Pulse::flush();
});

it('still records an exception that was logged by hand without being reported', function () {
    Config::set('pulse-boosted.traces.sample_rate', 1.0);
    Config::set('pulse-boosted.traces.sample_rates', []);

    Route::get('caught', function () {
        Log::error('Handled it', ['exception' => new RuntimeException('Caught and logged')]);

        return 'ok';
    })->middleware('web');

    $this->get('caught')->assertOk();

    $labels = Pulse::ignore(fn () => DB::table('pulse_boosted_trace_events')->where('type', 'log')->pluck('label')->all());

    expect($labels)->toBe(['Handled it']);
    expect(app(IssueRepository::class)->count([]))->toBe(0);
});
