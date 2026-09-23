<?php

use Elazaroo\PulseBoosted\Alerts\AlertManager;
use Elazaroo\PulseBoosted\Alerts\AlertRule;
use Elazaroo\PulseBoosted\Events\AlertResolved;
use Elazaroo\PulseBoosted\Events\AlertTriggered;
use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Livewire\Alerts;
use Elazaroo\PulseBoosted\Queues\Contracts\JobRepository;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Configure one rule and hand back the manager.
 *
 * @param  array<string, mixed>  $rule
 */
function withRule(array $rule): AlertManager
{
    Config::set('pulse-boosted.alerts.enabled', true);
    Config::set('pulse-boosted.alerts.rules', [array_merge([
        'name' => 'Too many exceptions',
        'metric' => 'exceptions',
        'threshold' => 2,
        'window' => '5 minutes',
    ], $rule)]);

    return app(AlertManager::class);
}

/**
 * Record one exception occurrence, which is what the exceptions metric counts.
 */
function occurrence(int $ago = 0): void
{
    Pulse::ignore(fn () => DB::table('pulse_boosted_issue_occurrences')->insert([
        'fingerprint' => md5('x'),
        'trace_id' => null,
        'user_id' => null,
        'occurred_at' => now()->subSeconds($ago)->getTimestamp(),
    ]));
}

/**
 * Record one finished execution.
 */
function execution(string $type = 'request', ?int $durationMs = 100, string $status = 'ok'): void
{
    Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->insert([
        'trace_id' => (string) Str::uuid(),
        'type' => $type,
        'name' => 'GET /checkout',
        'started_at' => now()->getTimestamp(),
        'duration_ms' => $durationMs,
        'status' => $status,
    ]));
}

it('does nothing while everything is within its threshold', function () {
    Event::fake([AlertTriggered::class]);

    $alerts = withRule([]);

    occurrence();
    occurrence();

    $alerts->evaluate();

    expect($alerts->active())->toHaveCount(0);
    Event::assertNotDispatched(AlertTriggered::class);
});

it('opens an episode when a reading breaches a rule', function () {
    Event::fake([AlertTriggered::class]);

    $alerts = withRule([]);

    occurrence();
    occurrence();
    occurrence();

    $alerts->evaluate();

    $active = $alerts->active();

    expect($active)->toHaveCount(1);
    expect($active[0]->rule)->toBe('Too many exceptions');
    expect((float) $active[0]->value)->toBe(3.0);
    expect($active[0]->resolved_at)->toBeNull();

    Event::assertDispatched(AlertTriggered::class, fn (AlertTriggered $event) => $event->value === 3.0);
});

it('does not open a second episode while the first is still open', function () {
    Event::fake([AlertTriggered::class]);

    $alerts = withRule([]);

    occurrence();
    occurrence();
    occurrence();

    $alerts->evaluate();
    $alerts->evaluate();
    $alerts->evaluate();

    expect($alerts->active())->toHaveCount(1);
    Event::assertDispatchedTimes(AlertTriggered::class, 1);
});

it('remembers the worst it got', function () {
    $alerts = withRule([]);

    occurrence();
    occurrence();
    occurrence();
    $alerts->evaluate();

    occurrence();
    occurrence();
    $alerts->evaluate();

    // Back down, but still breaching.
    Pulse::ignore(fn () => DB::table('pulse_boosted_issue_occurrences')->limit(2)->delete());
    $alerts->evaluate();

    $active = $alerts->active();

    expect((float) $active[0]->value)->toBe(3.0);
    expect((float) $active[0]->peak)->toBe(5.0);
});

it('closes the episode when the reading recovers', function () {
    Event::fake([AlertResolved::class]);

    $alerts = withRule([]);

    occurrence();
    occurrence();
    occurrence();
    $alerts->evaluate();

    Pulse::ignore(fn () => DB::table('pulse_boosted_issue_occurrences')->delete());
    $alerts->evaluate();

    expect($alerts->active())->toHaveCount(0);
    expect($alerts->history())->toHaveCount(1);
    expect($alerts->history()[0]->resolved_at)->not->toBeNull();

    Event::assertDispatched(AlertResolved::class, fn (AlertResolved $event) => $event->peak === 3.0);
});

it('opens a fresh episode when it happens again', function () {
    $alerts = withRule([]);

    occurrence();
    occurrence();
    occurrence();
    $alerts->evaluate();

    Pulse::ignore(fn () => DB::table('pulse_boosted_issue_occurrences')->delete());
    $alerts->evaluate();

    occurrence();
    occurrence();
    occurrence();
    $alerts->evaluate();

    expect($alerts->active())->toHaveCount(1);
    expect($alerts->history())->toHaveCount(1);
});

it('only counts what happened inside the window', function () {
    $alerts = withRule(['window' => '1 minute']);

    occurrence(ago: 3600);
    occurrence(ago: 3600);
    occurrence(ago: 3600);

    $alerts->evaluate();

    expect($alerts->active())->toHaveCount(0);
});

it('can watch for a reading falling below a threshold', function () {
    $alerts = withRule([
        'name' => 'Nothing is happening',
        'metric' => 'error_rate',
        'comparison' => 'below',
        'threshold' => 10,
    ]);

    execution(status: 'ok');
    execution(status: 'ok');

    $alerts->evaluate();

    expect($alerts->active())->toHaveCount(1);
});

it('never trips on a metric it cannot read', function () {
    // No traces at all, so there is no error rate to speak of. "We do not
    // know" is not the same as "it is fine", and it is not "it is bad" either.
    $alerts = withRule(['metric' => 'error_rate', 'threshold' => 5]);

    $readings = $alerts->evaluate();

    expect($readings[0]['value'])->toBeNull();
    expect($alerts->active())->toHaveCount(0);
});

it('reads the error rate as a percentage of traced executions', function () {
    $alerts = withRule(['metric' => 'error_rate', 'threshold' => 40]);

    execution(status: 'failed');
    execution(status: 'ok');
    execution(status: 'ok');
    execution(status: 'ok');

    $readings = $alerts->evaluate();

    expect($readings[0]['value'])->toBe(25.0);
    expect($alerts->active())->toHaveCount(0);
});

it('reads the 95th percentile of execution time', function () {
    $alerts = withRule(['metric' => 'p95_duration', 'threshold' => 500]);

    // Nine fast and one slow: ceil(0.95 * 10) puts the nearest rank on the
    // slow one, which is the point of looking at a percentile at all.
    foreach (range(1, 9) as $i) {
        execution(durationMs: 100);
    }
    execution(durationMs: 9000);

    $readings = $alerts->evaluate();

    expect($readings[0]['value'])->toBe(9000.0);
    expect($alerts->active())->toHaveCount(1);
});

it('can be narrowed to one kind of execution', function () {
    $alerts = withRule([
        'metric' => 'slow_executions',
        'threshold' => 0,
        'options' => ['type' => 'job', 'slower_than' => 1000],
    ]);

    execution('request', 5000);

    $alerts->evaluate();

    expect($alerts->active())->toHaveCount(0);

    execution('job', 5000);

    $alerts->evaluate();

    expect($alerts->active())->toHaveCount(1);
});

it('counts jobs that failed in the window', function () {
    $alerts = withRule(['metric' => 'failed_jobs', 'threshold' => 1]);

    // Through the repository, so it holds wherever the job history is kept.
    foreach (['A' => 'failed', 'B' => 'failed', 'C' => 'processed'] as $name => $status) {
        app(JobRepository::class)->record((string) Str::uuid(), ['connection' => 'database', 'queue' => 'default', 'name' => $name, 'status' => $status, 'finished_at' => now()->getTimestamp()]);
    }
    app(JobRepository::class)->flush();

    $readings = $alerts->evaluate();

    expect($readings[0]['value'])->toBe(2.0);
    expect($alerts->active())->toHaveCount(1);
});

it('reads how deep a queue is right now', function () {
    Config::set('queue.default', 'database');

    $alerts = withRule(['metric' => 'queue_size', 'threshold' => 1, 'options' => ['queue' => 'default']]);

    Pulse::ignore(fn () => DB::table('jobs')->insert([
        ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp()],
        ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp()],
    ]));

    $readings = $alerts->evaluate();

    expect($readings[0]['value'])->toBe(2.0);
    expect($alerts->active())->toHaveCount(1);
});

it('never trips on a queue the driver cannot count', function () {
    Config::set('pulse-boosted.queues.enabled', false);

    $alerts = withRule(['metric' => 'queue_size', 'threshold' => 0]);

    expect($alerts->evaluate()[0]['value'])->toBeNull();
    expect($alerts->active())->toHaveCount(0);
});

it('skips a rule the configuration got wrong rather than breaking the page', function () {
    Config::set('pulse-boosted.alerts.rules', [
        ['name' => 'Fine', 'metric' => 'exceptions', 'threshold' => 1],
        ['name' => 'Missing a threshold', 'metric' => 'exceptions'],
        ['name' => 'Bad comparison', 'metric' => 'exceptions', 'threshold' => 1, 'comparison' => 'sideways'],
        'not even an array',
    ]);

    expect(app(AlertManager::class)->rules())->toHaveCount(1);
});

it('reports a metric that blows up without dropping the rest', function () {
    Config::set('pulse-boosted.alerts.rules', [
        ['name' => 'Unknown metric', 'metric' => 'nonsense', 'threshold' => 1],
        ['name' => 'Fine', 'metric' => 'exceptions', 'threshold' => 0],
    ]);

    occurrence();

    $readings = app(AlertManager::class)->evaluate();

    expect($readings)->toHaveCount(2);
    expect($readings[0]['value'])->toBeNull();
    expect($readings[1]['value'])->toBe(1.0);

    // The broken rule was reported as an exception of its own.
    Pulse::flush();
});

it('does nothing at all when alerting is switched off', function () {
    $alerts = withRule([]);

    Config::set('pulse-boosted.alerts.enabled', false);

    occurrence();
    occurrence();
    occurrence();

    expect($alerts->evaluate())->toHaveCount(0);
    expect($alerts->active())->toHaveCount(0);
});

it('keeps open episodes when it trims, however old', function () {
    Config::set('pulse-boosted.alerts.trim.keep', '1 second');

    $alerts = withRule([]);

    occurrence();
    occurrence();
    occurrence();
    $alerts->evaluate();

    // One that has been settled for a while.
    Pulse::ignore(fn () => DB::table('pulse_boosted_alerts')->insert([
        'rule' => 'Old news',
        'metric' => 'exceptions',
        'value' => 9,
        'threshold' => 2,
        'comparison' => 'above',
        'triggered_at' => now()->subDay()->getTimestamp(),
        'resolved_at' => now()->subDay()->getTimestamp(),
        'peak' => 9,
        'last_checked_at' => now()->subDay()->getTimestamp(),
    ]));

    $alerts->trim();

    expect($alerts->active())->toHaveCount(1);
    expect($alerts->history())->toHaveCount(0);
});

it('shows what is wrong on the card', function () {
    $alerts = withRule(['description' => 'Something is throwing a lot.']);

    occurrence();
    occurrence();
    occurrence();
    $alerts->evaluate();

    Livewire::test(Alerts::class, ['lazy' => false])
        ->assertSee('Too many exceptions')
        ->assertSee('Something is throwing a lot.');
});

it('says so on the card when nothing is breaching', function () {
    withRule([]);

    Livewire::test(Alerts::class, ['lazy' => false])
        ->assertSee('Nothing is breaching a rule')
        // The quiet rules are still listed, so it is clear what is watched.
        ->assertSee('Too many exceptions');
});

it('says so on the card when nothing is configured', function () {
    Config::set('pulse-boosted.alerts.rules', []);

    Livewire::test(Alerts::class, ['lazy' => false])
        ->assertSee('No alert rules');
});

it('shows settled episodes under history', function () {
    $alerts = withRule([]);

    occurrence();
    occurrence();
    occurrence();
    $alerts->evaluate();

    Pulse::ignore(fn () => DB::table('pulse_boosted_issue_occurrences')->delete());
    $alerts->evaluate();

    Livewire::test(Alerts::class, ['lazy' => false])
        ->set('tab', 'history')
        ->assertSee('Too many exceptions');
});

it('checks the rules from the command line', function () {
    $alerts = withRule([]);

    occurrence();
    occurrence();
    occurrence();

    $this->artisan('pulse-boosted:alerts')
        ->expectsOutputToContain('Too many exceptions')
        ->assertFailed();

    expect($alerts->active())->toHaveCount(1);
});

it('leaves the state alone on a dry run', function () {
    $alerts = withRule([]);

    occurrence();
    occurrence();
    occurrence();

    $this->artisan('pulse-boosted:alerts', ['--dry-run' => true])->assertFailed();

    expect($alerts->active())->toHaveCount(0);
});

it('rejects a comparison it does not understand', function () {
    expect(fn () => new AlertRule(name: 'x', metric: 'exceptions', threshold: 1, comparison: 'sideways'))
        ->toThrow(InvalidArgumentException::class);
});

it('renders a single root element whether or not rules are configured', function () {
    // Livewire marks an @if with comments. At the top level of the view they
    // sat outside the root, and the morph that swaps the lazy placeholder
    // for the card then lost the component's snapshot.
    foreach ([[], [['name' => 'Rule', 'metric' => 'exceptions', 'threshold' => 1]]] as $rules) {
        Config::set('pulse-boosted.alerts.rules', $rules);

        $html = trim(Livewire::test(Alerts::class, ['lazy' => false])->html());

        expect($html)->toStartWith('<div');
        expect(substr_count($html, 'wire:snapshot'))->toBe(1);
    }
});
