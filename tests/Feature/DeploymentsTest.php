<?php

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\Deployments\Deployments;
use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Issues\IssueRepository;
use Elazaroo\PulseBoosted\Livewire\Issues;
use Elazaroo\PulseBoosted\Livewire\Overview;
use Elazaroo\PulseBoosted\Traces\Tracer;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    Config::set('pulse-boosted.traces.sample_rate', 1.0);
    Config::set('pulse-boosted.traces.sample_rates', []);
});

it('tags traces with the version that ran them', function () {
    Config::set('pulse-boosted.deployment', 'v1.4.2');

    $tracer = app(Tracer::class);
    $tracer->start('command', 'import');
    $tracer->finish();
    $tracer->flush();

    $meta = json_decode(Pulse::ignore(fn () => DB::table('pulse_boosted_traces')->value('meta')), true);

    expect($meta['deploy'])->toBe('v1.4.2');
});

it('notes the first time a version reports in, and only the first time', function () {
    $deployments = app(Deployments::class);

    $deployments->note('v1', 1_000);
    $deployments->note('v1', 2_000);
    $deployments->note('v2', 3_000);

    expect($deployments->recent()->pluck('version')->all())->toBe(['v2', 'v1']);
    expect((int) $deployments->recent()->last()->deployed_at)->toBe(1_000);
});

it('marks a deployment from the command line', function () {
    $this->artisan('pulse-boosted:deploy', ['version' => 'abc123'])->assertSuccessful();

    expect(app(Deployments::class)->latest()->version)->toBe('abc123');
});

it('refuses to mark a deployment without a version', function () {
    Config::set('pulse-boosted.deployment', null);

    $this->artisan('pulse-boosted:deploy')->assertFailed();
});

it('records which deploy an issue first appeared in, and was last seen in', function () {
    $repository = app(IssueRepository::class);
    $exception = new RuntimeException('Boom');

    Config::set('pulse-boosted.deployment', 'v1');
    $repository->record($exception, null, null);
    $repository->flush();

    Config::set('pulse-boosted.deployment', 'v2');
    $repository->record($exception, null, null);
    $repository->flush();

    $issue = $repository->issues([])->first();

    expect($issue->first_seen_deploy)->toBe('v1');
    expect($issue->last_seen_deploy)->toBe('v2');
});

it('marks issues that are new in the latest deploy', function () {
    $deployments = app(Deployments::class);
    $repository = app(IssueRepository::class);

    // The old issue appeared a day ago, under the previous deploy.
    CarbonImmutable::setTestNow(CarbonImmutable::now()->subDay());
    $deployments->note('v1', CarbonImmutable::now()->getTimestamp());
    Config::set('pulse-boosted.deployment', 'v1');
    $repository->record(new LogicException('Old'), null, null);
    $repository->flush();
    CarbonImmutable::setTestNow();

    $deployments->note('v2', now()->getTimestamp());
    Config::set('pulse-boosted.deployment', 'v2');
    $repository->record(new RuntimeException('Fresh'), null, null);
    $repository->flush();

    Livewire::test(Issues::class, ['lazy' => false])
        ->assertViewHas('latestDeploy', 'v2')
        ->assertSeeInOrder(['RuntimeException', 'New'])
        ->assertSeeHtmlInOrder(['title="First seen in the latest deploy, v2"'])
        ->assertViewHas('issues', fn ($issues) => $issues->where('first_seen_deploy', 'v2')->pluck('class')->all() === [RuntimeException::class]);

    Livewire::test(Overview::class, ['lazy' => false])
        ->assertSee('Deployed')
        ->assertSee('v2')
        ->assertSee('1 new issue since');
});

it('does not call everything new when there has only ever been one deploy', function () {
    app(Deployments::class)->note('v1');
    Config::set('pulse-boosted.deployment', 'v1');

    $repository = app(IssueRepository::class);
    $repository->record(new RuntimeException('Boom'), null, null);
    $repository->flush();

    Livewire::test(Issues::class, ['lazy' => false])->assertViewHas('latestDeploy', null);
});
