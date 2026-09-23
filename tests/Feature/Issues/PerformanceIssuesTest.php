<?php

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\Events\IssueOpened;
use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Issues\IssueRepository;
use Elazaroo\PulseBoosted\Issues\PerformanceThresholds;
use Elazaroo\PulseBoosted\Livewire\Issues;
use Elazaroo\PulseBoosted\Traces\Tracer;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(function () {
    Config::set('pulse-boosted.traces.sample_rate', 1.0);
    Config::set('pulse-boosted.traces.sample_rates', []);
});

/**
 * Run an execution that takes the given time.
 */
function execute(string $type, string $name, int $ms): void
{
    $tracer = app(Tracer::class);

    $tracer->start($type, $name);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMilliseconds($ms));
    $tracer->finish();
    CarbonImmutable::setTestNow();

    $tracer->flush();
    app(IssueRepository::class)->flush();
}

it('opens an issue for an execution slower than its threshold', function () {
    Config::set('pulse-boosted.issues.thresholds.command', ['reports:build' => 500]);

    execute('command', 'reports:build', 2000);

    $issue = app(IssueRepository::class)->issues([])->first();

    expect($issue->kind)->toBe('performance');
    expect($issue->class)->toBe('Slow command: reports:build');
    expect($issue->message)->toContain('over its 500ms threshold');

    // The occurrence points at the trace that proved it.
    $traceId = Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->value('trace_id'));

    expect(app(IssueRepository::class)->recentOccurrences($issue->fingerprint)->first()->trace_id)->toBe($traceId);
});

it('leaves executions within their threshold alone', function () {
    Config::set('pulse-boosted.issues.thresholds.command', ['reports:build' => 5000]);

    execute('command', 'reports:build', 200);

    expect(app(IssueRepository::class)->count([]))->toBe(0);
});

it('groups every slow run of one thing into one issue', function () {
    Config::set('pulse-boosted.issues.thresholds.command', ['*' => 100]);

    execute('command', 'reports:build', 300);
    execute('command', 'reports:build', 400);
    execute('command', 'cache:warm', 300);

    $issues = app(IssueRepository::class)->issues([]);

    expect($issues)->toHaveCount(2);
    expect((int) $issues->firstWhere('class', 'Slow command: reports:build')->occurrences)->toBe(2);
});

it('picks the exact name first, then a pattern, then everything', function () {
    Config::set('pulse-boosted.issues.thresholds.request', [
        'GET /checkout' => 800,
        '#^GET /api/#' => 300,
        '*' => 2000,
    ]);

    $thresholds = app(PerformanceThresholds::class);

    expect($thresholds->threshold('request', 'GET /checkout'))->toBe(800);
    expect($thresholds->threshold('request', 'GET /api/orders'))->toBe(300);
    expect($thresholds->threshold('request', 'GET /about'))->toBe(2000);
    expect($thresholds->threshold('job', 'App\Jobs\Anything'))->toBeNull();
});

it('keeps the trace of a slow execution even when it lost the draw', function () {
    Config::set('pulse-boosted.traces.sample_rate', 0.0);
    Config::set('pulse-boosted.traces.keep', ['failed' => true, 'exceptions' => true, 'slower_than' => null]);
    Config::set('pulse-boosted.issues.thresholds.command', ['reports:build' => 100]);

    execute('command', 'reports:build', 500);

    expect(Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->count()))->toBe(1);
});

it('announces a new performance issue like any other', function () {
    Event::fake([IssueOpened::class]);
    Config::set('pulse-boosted.issues.thresholds.command', ['*' => 100]);

    execute('command', 'reports:build', 500);

    Event::assertDispatched(IssueOpened::class, fn ($event) => $event->issue->kind === 'performance');
});

it('can be filtered to on the issues card', function () {
    Config::set('pulse-boosted.issues.thresholds.command', ['*' => 100]);

    execute('command', 'reports:build', 500);

    app(IssueRepository::class)->record(new RuntimeException('Boom'), null, null);
    app(IssueRepository::class)->flush();

    Livewire::test(Issues::class, ['lazy' => false])
        ->set('kind', 'performance')
        ->assertSee('Slow command: reports:build')
        ->assertDontSee('Boom');
});
