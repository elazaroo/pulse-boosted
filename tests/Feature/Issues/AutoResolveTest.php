<?php

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\Events\IsolatedBeat;
use Elazaroo\PulseBoosted\Events\IssueRegressed;
use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Issues\IssueRepository;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;

function quiet(string $message): RuntimeException
{
    // Always thrown from here, so every call is the same issue.
    return new RuntimeException($message);
}

function issueSeen(string $message, int $daysAgo): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::now()->subDays($daysAgo));

    $repository = app(IssueRepository::class);
    $repository->record(quiet($message), null, null);
    $repository->flush();

    CarbonImmutable::setTestNow();
}

it('resolves open issues nobody has seen for the configured time', function () {
    issueSeen('Quiet', 10);

    $repository = app(IssueRepository::class);
    $repository->record(new LogicException('Still happening'), null, null);
    $repository->flush();

    expect($repository->resolveQuietFor('7 days'))->toBe(1);

    $statuses = $repository->issues([])->pluck('status', 'message')->all();

    expect($statuses)->toBe(['Still happening' => 'open', 'Quiet' => 'resolved']);
});

it('runs on the beat when switched on, and not when it is not', function () {
    issueSeen('Quiet', 10);

    event(new IsolatedBeat(CarbonImmutable::now()));

    expect(app(IssueRepository::class)->issues([])->first()->status)->toBe('open');

    Config::set('pulse-boosted.issues.auto_resolve_after', '7 days');

    event(new IsolatedBeat(CarbonImmutable::now()));

    expect(app(IssueRepository::class)->issues([])->first()->status)->toBe('resolved');

    // The throttle's own cache write, which the cache recorder queued.
    Pulse::flush();
});

it('reopens an auto-resolved issue that comes back, as a regression', function () {
    Event::fake([IssueRegressed::class]);

    issueSeen('Quiet', 10);

    $repository = app(IssueRepository::class);
    $repository->resolveQuietFor('7 days');

    $repository->record(quiet('Quiet'), null, null);
    $repository->flush();

    expect($repository->issues([])->first()->status)->toBe('open');
    Event::assertDispatched(IssueRegressed::class);
});
