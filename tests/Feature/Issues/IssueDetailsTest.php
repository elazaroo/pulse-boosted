<?php

use Elazaroo\PulseBoosted\Events\IssueOpened;
use Elazaroo\PulseBoosted\Events\IssueRegressed;
use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Issues\IssueMail;
use Elazaroo\PulseBoosted\Issues\IssueRepository;
use Elazaroo\PulseBoosted\Issues\StackTrace;
use Elazaroo\PulseBoosted\Livewire\Issues;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\View\ViewException;
use Livewire\Livewire;

/**
 * One exception, always thrown from this line.
 */
function brokenPayment(): RuntimeException
{
    return new RuntimeException('Payment provider unreachable');
}

it('marks an exception that escaped to the handler as unhandled', function () {
    Route::get('boom', fn () => throw brokenPayment())->middleware('web');

    $this->get('boom')->assertServerError();

    $issue = app(IssueRepository::class)->issues([])->first();

    expect((bool) $issue->handled)->toBeFalse();

    Pulse::flush();
});

it('marks an exception the application caught and reported as handled', function () {
    Route::get('caught', function () {
        try {
            throw new InvalidArgumentException('Bad coupon code');
        } catch (Throwable $e) {
            report($e);
        }

        return 'ok';
    })->middleware('web');

    $this->get('caught')->assertOk();

    $issue = app(IssueRepository::class)->issues([])->first();

    expect($issue->class)->toBe(InvalidArgumentException::class);
    expect((bool) $issue->handled)->toBeTrue();

    Pulse::flush();
});

it('counts rescue() as handled too, since it reports what it catches', function () {
    Route::get('rescued', fn () => rescue(fn () => throw new LogicException('Rescued')))->middleware('web');

    $this->get('rescued')->assertOk();

    expect((bool) app(IssueRepository::class)->issues([])->first()->handled)->toBeTrue();

    Pulse::flush();
});

it('filters issues by handled or not', function () {
    $repository = app(IssueRepository::class);

    $repository->record(new RuntimeException('Escaped'), null, null, handled: false);
    $repository->record(new LogicException('Caught'), null, null, handled: true);
    $repository->flush();

    expect($repository->issues(['handled' => 'unhandled'])->pluck('message')->all())->toBe(['Escaped']);
    expect($repository->issues(['handled' => 'handled'])->pluck('message')->all())->toBe(['Caught']);

    Livewire::test(Issues::class, ['lazy' => false])
        ->set('handled', 'handled')
        ->assertSee('Caught')
        ->assertDontSee('Escaped');
});

it('keeps the stack with the source around the application frames', function () {
    $repository = app(IssueRepository::class);

    $exception = brokenPayment();
    $repository->record($exception, null, null);
    $repository->flush();

    $frames = json_decode($repository->issues([])->first()->trace, true);

    expect($frames[0]['app'])->toBeTrue();
    expect($frames[0]['line'])->toBe($exception->getLine());

    // The line that threw is in the snippet, with context either side.
    expect($frames[0]['code'])->toHaveKey((string) $exception->getLine());
    expect($frames[0]['code'][$exception->getLine()])->toContain('Payment provider unreachable');
    expect(count($frames[0]['code']))->toBeGreaterThan(1)->toBeLessThanOrEqual(StackTrace::CONTEXT * 2 + 1);

    // Framework frames are listed, but their source is not read.
    $vendor = collect($frames)->firstWhere('app', false);

    expect($vendor)->not->toBeNull();
    expect($vendor['code'])->toBeNull();
});

it('reads source for only the first few application frames', function () {
    $frames = StackTrace::frames(brokenPayment());

    expect(collect($frames)->whereNotNull('code')->count())->toBeLessThanOrEqual(StackTrace::SOURCE_FRAMES);
    expect(count($frames))->toBeLessThanOrEqual(StackTrace::MAX_FRAMES);
});

it('groups an exception thrown while rendering a view by the one inside', function () {
    $repository = app(IssueRepository::class);

    $inner = brokenPayment();
    $repository->record(new ViewException('View [checkout] failed', 0, 1, __FILE__, 1, $inner), null, null);
    $repository->record($inner, null, null);
    $repository->flush();

    $issues = $repository->issues([]);

    expect($issues)->toHaveCount(1);
    expect($issues[0]->class)->toBe(RuntimeException::class);
    expect((int) $issues[0]->occurrences)->toBe(2);
});

it('records the Laravel and PHP versions it happened on', function () {
    $repository = app(IssueRepository::class);

    $repository->record(brokenPayment(), null, null);
    $repository->flush();

    $issue = $repository->issues([])->first();

    expect($issue->php_version)->toBe(PHP_VERSION);
    expect($issue->laravel_version)->toBe(app()->version());
});

it('announces an issue once, when it is first seen', function () {
    Event::fake([IssueOpened::class, IssueRegressed::class]);

    $repository = app(IssueRepository::class);

    $repository->record(brokenPayment(), null, null);
    $repository->flush();
    $repository->record(brokenPayment(), null, null);
    $repository->flush();

    Event::assertDispatchedTimes(IssueOpened::class, 1);
    Event::assertNotDispatched(IssueRegressed::class);
});

it('announces a resolved issue that happens again as a regression', function () {
    Event::fake([IssueOpened::class, IssueRegressed::class]);

    $repository = app(IssueRepository::class);

    $repository->record(brokenPayment(), null, null);
    $repository->flush();

    $fingerprint = $repository->issues([])->first()->fingerprint;
    $repository->setStatus($fingerprint, 'resolved');

    $repository->record(brokenPayment(), null, null);
    $repository->flush();

    Event::assertDispatched(IssueRegressed::class, fn ($event) => $event->issue->status === 'open');
});

it('does not announce an ignored issue happening again', function () {
    Event::fake([IssueRegressed::class]);

    $repository = app(IssueRepository::class);

    $repository->record(brokenPayment(), null, null);
    $repository->flush();
    $repository->setStatus($repository->issues([])->first()->fingerprint, 'ignored');

    $repository->record(brokenPayment(), null, null);
    $repository->flush();

    Event::assertNotDispatched(IssueRegressed::class);
});

it('emails the configured addresses when an issue opens', function () {
    Mail::fake();
    Config::set('pulse-boosted.issues.notify.mail', 'oncall@example.com, lead@example.com');

    $repository = app(IssueRepository::class);
    $repository->record(brokenPayment(), null, null);
    $repository->flush();

    Mail::assertSent(IssueMail::class, function (IssueMail $mail) {
        return $mail->hasTo('oncall@example.com')
            && $mail->hasTo('lead@example.com')
            && ! $mail->regressed;
    });
});

it('emails again when a resolved issue regresses, unless told not to', function () {
    Mail::fake();
    Config::set('pulse-boosted.issues.notify.mail', ['oncall@example.com']);

    $repository = app(IssueRepository::class);
    $repository->record(brokenPayment(), null, null);
    $repository->flush();
    $repository->setStatus($repository->issues([])->first()->fingerprint, 'resolved');
    $repository->record(brokenPayment(), null, null);
    $repository->flush();

    Mail::assertSent(IssueMail::class, fn (IssueMail $mail) => $mail->regressed);

    Mail::fake();
    Config::set('pulse-boosted.issues.notify.regressions', false);

    $repository->setStatus($repository->issues([])->first()->fingerprint, 'resolved');
    $repository->record(brokenPayment(), null, null);
    $repository->flush();

    Mail::assertNothingSent();
});

it('sends nothing when nobody is configured', function () {
    Mail::fake();
    Config::set('pulse-boosted.issues.notify.mail', '');

    $repository = app(IssueRepository::class);
    $repository->record(brokenPayment(), null, null);
    $repository->flush();

    Mail::assertNothingSent();
});

it('writes the email with the issue details and a link back to it', function () {
    $repository = app(IssueRepository::class);
    $repository->record(brokenPayment(), null, null, handled: false);
    $repository->flush();

    $issue = $repository->issues([])->first();

    $mail = new IssueMail($issue);

    $mail->assertHasSubject('RuntimeException: Payment provider unreachable');
    $mail->assertSeeInHtml('Unhandled');
    $mail->assertSeeInHtml('Payment provider unreachable');
    $mail->assertSeeInHtml('?issue='.$issue->fingerprint);
    $mail->assertSeeInHtml('PHP');

    expect((new IssueMail($issue, regressed: true))->envelope()->subject)->toStartWith('[Regressed]');
});

it('shows the stack, versions and a copy button in the issue panel', function () {
    $repository = app(IssueRepository::class);
    $repository->record(brokenPayment(), null, null);
    $repository->flush();

    Livewire::test(Issues::class, ['lazy' => false])
        ->call('select', $repository->issues([])->first()->fingerprint)
        ->assertSee('Stack trace')
        ->assertSee('Unhandled')
        ->assertSee('Laravel '.app()->version())
        ->assertSee('Copy as Markdown')
        ->assertSee('Payment provider unreachable');
});
