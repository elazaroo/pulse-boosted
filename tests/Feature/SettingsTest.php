<?php

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\Alerts\AlertManager;
use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Issues\PerformanceThresholds;
use Elazaroo\PulseBoosted\Livewire\Settings as SettingsForm;
use Elazaroo\PulseBoosted\Queues\QueueActions;
use Elazaroo\PulseBoosted\Recorders\SlowRequests;
use Elazaroo\PulseBoosted\Settings\Schema;
use Elazaroo\PulseBoosted\Settings\Settings;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/**
 * The form field for a config key.
 */
function field(string $key): string
{
    return 'values.'.Schema::id($key);
}

/**
 * The settings as a fresh request would see them: read again and laid over
 * config from scratch.
 */
function freshSettings(): Settings
{
    app()->forgetInstance(Settings::class);

    $settings = app(Settings::class);
    $settings->apply();

    return $settings;
}

beforeEach(function () {
    Gate::define(QueueActions::GATE, fn ($user = null) => true);
});

it('lays what is saved over config, and goes back to config when asked', function () {
    Config::set('pulse-boosted.issues.notify.mail', 'from-env@example.com');

    $settings = freshSettings();
    $settings->set('issues.notify.mail', ['oncall@example.com'], '3');

    expect(config('pulse-boosted.issues.notify.mail'))->toBe(['oncall@example.com']);

    // Another request, reading it back.
    Config::set('pulse-boosted.issues.notify.mail', 'from-env@example.com');
    $settings = freshSettings();

    expect(config('pulse-boosted.issues.notify.mail'))->toBe(['oncall@example.com']);
    expect($settings->change('issues.notify.mail'))->updated_by->toBe('3');

    $settings->forget('issues.notify.mail');

    expect(config('pulse-boosted.issues.notify.mail'))->toBe('from-env@example.com');
    expect(Pulse::ignore(fn () => DB::table('pulse_boosted_settings')->count()))->toBe(0);
});

it('checks what it is given', function () {
    Livewire::test(SettingsForm::class, ['group' => 'email'])
        ->set(field('issues.notify.mail'), "oncall@example.com\nnot-an-email")
        ->call('save')
        ->assertHasErrors(field('issues.notify.mail'));

    Livewire::test(SettingsForm::class, ['group' => 'traces'])
        ->set(field('traces.sample_rate'), '1.5')
        ->call('save')
        ->assertHasErrors(field('traces.sample_rate'));

    expect(Pulse::ignore(fn () => DB::table('pulse_boosted_settings')->count()))->toBe(0);
});

it('saves only what differs from config', function () {
    Config::set('pulse-boosted.issues.notify.regressions', true);
    freshSettings();

    Livewire::test(SettingsForm::class, ['group' => 'email'])
        ->set(field('issues.notify.regressions'), false)
        ->call('save')
        ->assertSee('Set here');

    expect(Pulse::ignore(fn () => DB::table('pulse_boosted_settings')->pluck('key')->all()))->toBe(['issues.notify.regressions']);
    expect(config('pulse-boosted.issues.notify.regressions'))->toBeFalse();

    Livewire::test(SettingsForm::class, ['group' => 'email'])
        ->set(field('issues.notify.regressions'), true)
        ->call('save');

    expect(Pulse::ignore(fn () => DB::table('pulse_boosted_settings')->count()))->toBe(0);
});

it('sets the thresholds that open performance issues and fill the slow cards', function () {
    Livewire::test(SettingsForm::class, ['group' => 'thresholds'])
        ->call('addRow', 'issues.thresholds')
        ->set(field('issues.thresholds').'.0.pattern', 'GET /checkout')
        ->set(field('issues.thresholds').'.0.ms', 800)
        ->call('addRow', 'issues.thresholds')
        ->set(field('issues.thresholds').'.1.type', 'job')
        ->set(field('issues.thresholds').'.1.pattern', '*')
        ->set(field('issues.thresholds').'.1.ms', 5000)
        ->set(field('recorders.'.SlowRequests::class.'.threshold'), 750)
        ->call('save')
        ->assertHasNoErrors();

    freshSettings();

    expect(config('pulse-boosted.issues.thresholds'))->toBe([
        'request' => ['GET /checkout' => 800],
        'job' => ['*' => 5000],
        'command' => [],
        'schedule' => [],
    ]);
    expect(app(PerformanceThresholds::class)->threshold('request', 'GET /checkout'))->toBe(800);
    expect(app(PerformanceThresholds::class)->threshold('job', 'App\Jobs\Anything'))->toBe(5000);
    expect(config('pulse-boosted.recorders.'.SlowRequests::class.'.threshold'))->toBe(750);
});

it('rejects a threshold pattern that is not a valid expression', function () {
    Livewire::test(SettingsForm::class, ['group' => 'thresholds'])
        ->call('addRow', 'issues.thresholds')
        ->set(field('issues.thresholds').'.0.pattern', '#^GET (#')
        ->set(field('issues.thresholds').'.0.ms', 800)
        ->call('save')
        ->assertHasErrors(field('issues.thresholds').'.0.pattern');
});

it('writes alert rules the alert manager reads', function () {
    Livewire::test(SettingsForm::class, ['group' => 'alerts'])
        ->call('addRow', 'alerts.rules')
        ->set(field('alerts.rules').'.0.name', 'Checkout failing')
        ->set(field('alerts.rules').'.0.metric', 'error_rate')
        ->set(field('alerts.rules').'.0.threshold', '5')
        ->set(field('alerts.rules').'.0.window', '10 minutes')
        ->set(field('alerts.rules').'.0.options', 'type=request')
        ->call('save')
        ->assertHasNoErrors();

    freshSettings();

    $rule = app(AlertManager::class)->rules()->first();

    expect($rule)
        ->name->toBe('Checkout failing')
        ->metric->toBe('error_rate')
        ->threshold->toBe(5.0)
        ->window->toBe('10 minutes')
        ->options->toBe(['type' => 'request']);

    Livewire::test(SettingsForm::class, ['group' => 'alerts'])
        ->call('addRow', 'alerts.rules')
        ->set(field('alerts.rules').'.1.name', 'Bad window')
        ->set(field('alerts.rules').'.1.threshold', '1')
        ->set(field('alerts.rules').'.1.window', 'whenever')
        ->call('save')
        ->assertHasErrors(field('alerts.rules').'.1.window');
});

it('lets anyone who can see the dashboard read the settings, and only the gate change them', function () {
    Gate::define(QueueActions::GATE, fn ($user = null) => false);

    Livewire::test(SettingsForm::class, ['group' => 'email'])
        ->assertSee('changing them needs the')
        ->call('save')
        ->assertForbidden();

    Livewire::test(SettingsForm::class, ['group' => 'email'])
        ->call('useConfig', 'issues.notify.mail')
        ->assertForbidden();
});

it('has a page of its own, linked from the header', function () {
    $this->get('/pulse-boosted/settings')
        ->assertOk()
        ->assertSeeLivewire(SettingsForm::class)
        ->assertSee('Webhooks')
        ->assertSee('Thresholds')
        ->assertSee('Dashboard');

    $this->get('/pulse-boosted')->assertSee('/pulse-boosted/settings', false);
});

it('reaches processes that outlive a request within a minute', function () {
    $settings = freshSettings();

    expect(config('pulse-boosted.issues.log_level'))->toBe('warning');

    // Changed from the dashboard, by another process.
    Pulse::ignore(fn () => DB::table('pulse_boosted_settings')->insert([
        'key' => 'issues.log_level',
        'value' => json_encode('error'),
        'updated_at' => now()->getTimestamp(),
    ]));
    Pulse::ignore(fn () => cache()->store(config('pulse-boosted.cache'))->forget(Settings::CACHE_KEY));

    $settings->refreshIfStale();
    expect(config('pulse-boosted.issues.log_level'))->toBe('warning');

    CarbonImmutable::setTestNow(now()->addMinutes(2));
    $settings->refreshIfStale();
    CarbonImmutable::setTestNow();

    expect(config('pulse-boosted.issues.log_level'))->toBe('error');
});

it('ignores what it cannot read rather than breaking the application', function () {
    Pulse::ignore(fn () => DB::table('pulse_boosted_settings')->insert([
        ['key' => 'not.a.setting', 'value' => '"x"', 'updated_at' => 0],
    ]));

    expect(freshSettings()->stored())->toBe([]);
});
