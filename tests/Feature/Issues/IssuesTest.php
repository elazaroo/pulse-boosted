<?php

use Elazaroo\PulseBoosted\Events\ExceptionReported;
use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Issues\IssueRepository;
use Elazaroo\PulseBoosted\Livewire\Issues;
use Elazaroo\PulseBoosted\PulseServiceProvider;
use Elazaroo\PulseBoosted\Queues\QueueActions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/**
 * Throwing from a fixed place, so every call produces the same fingerprint.
 */
function throwFrom(string $message = 'Boom'): RuntimeException
{
    return new RuntimeException($message);
}

it('groups repeated exceptions into one issue', function () {
    $repository = app(IssueRepository::class);

    $exception = throwFrom();

    Pulse::report($exception);
    Pulse::report($exception);
    Pulse::report($exception);
    $repository->flush();

    $issues = $repository->issues([]);

    expect($issues)->toHaveCount(1);
    expect($issues[0]->class)->toBe(RuntimeException::class);
    expect((int) $issues[0]->occurrences)->toBe(3);
    expect($issues[0]->status)->toBe('open');

    Pulse::flush();
});

it('treats the same class thrown from different places as different issues', function () {
    $repository = app(IssueRepository::class);

    Pulse::report(new RuntimeException('one'));
    Pulse::report(throwFrom('two'));
    $repository->flush();

    expect($repository->count([]))->toBe(2);

    Pulse::flush();
});

it('treats different messages from one place as the same issue', function () {
    $repository = app(IssueRepository::class);

    // "User 41 not found" and "User 42 not found" are one bug.
    Pulse::report(throwFrom('User 41 not found'));
    Pulse::report(throwFrom('User 42 not found'));
    $repository->flush();

    expect($repository->count([]))->toBe(1);

    Pulse::flush();
});

it('records exceptions even when the execution was not traced', function () {
    // Traces are sampled; issues must not be.
    config(['pulse-boosted.traces.sample_rate' => 0.0]);

    $repository = app(IssueRepository::class);

    Pulse::report(throwFrom());
    $repository->flush();

    expect($repository->count([]))->toBe(1);

    Pulse::flush();
});

it('counts how many users an issue reached', function () {
    $repository = app(IssueRepository::class);

    $repository->record(throwFrom(), null, 'user-1');
    $repository->record(throwFrom(), null, 'user-2');
    $repository->record(throwFrom(), null, 'user-1');
    $repository->flush();

    $fingerprint = $repository->fingerprint(throwFrom());

    expect($repository->affectedUsers($fingerprint))->toBe(2);

    Pulse::flush();
});

it('reopens a resolved issue when it happens again', function () {
    $repository = app(IssueRepository::class);

    Pulse::report(throwFrom());
    $repository->flush();

    $fingerprint = $repository->fingerprint(throwFrom());
    $repository->setStatus($fingerprint, 'resolved');

    expect($repository->find($fingerprint)->status)->toBe('resolved');

    // A regression should announce itself rather than staying quietly closed.
    Pulse::report(throwFrom());
    $repository->flush();

    expect($repository->find($fingerprint)->status)->toBe('open');

    Pulse::flush();
});

it('leaves an ignored issue ignored when it happens again', function () {
    $repository = app(IssueRepository::class);

    Pulse::report(throwFrom());
    $repository->flush();

    $fingerprint = $repository->fingerprint(throwFrom());
    $repository->setStatus($fingerprint, 'ignored');

    Pulse::report(throwFrom());
    $repository->flush();

    expect($repository->find($fingerprint)->status)->toBe('ignored');

    Pulse::flush();
});

it('links an occurrence to the trace it happened in', function () {
    config(['pulse-boosted.traces.sample_rate' => 1.0]);
    config(['pulse-boosted.traces.sample_rates' => []]);

    Route::get('issue-trace', function () {
        Pulse::report(throwFrom('In a request'));

        return 'ok';
    })->middleware('web');

    $this->get('issue-trace')->assertOk();

    $repository = app(IssueRepository::class);
    $fingerprint = $repository->fingerprint(throwFrom());

    $occurrence = $repository->recentOccurrences($fingerprint)->first();

    expect($occurrence)->not->toBeNull();
    expect($occurrence->trace_id)->not->toBeNull();

    Pulse::flush();
});

it('shows issues on the dashboard', function () {
    $this->get('/pulse-boosted')->assertSeeLivewire(Issues::class);
});

it('lists open issues on the card', function () {
    $repository = app(IssueRepository::class);

    Pulse::report(throwFrom('Something broke'));
    $repository->flush();

    Livewire::test(Issues::class, ['lazy' => false])
        ->assertSee('RuntimeException')
        ->assertSee('Something broke');

    Pulse::flush();
});

it('refuses to change an issue without the manage gate', function () {
    $repository = app(IssueRepository::class);

    Pulse::report(throwFrom());
    $repository->flush();

    $fingerprint = $repository->fingerprint(throwFrom());

    Livewire::test(Issues::class, ['lazy' => false])
        ->call('resolve', $fingerprint)
        ->assertForbidden();

    expect($repository->find($fingerprint)->status)->toBe('open');

    Pulse::flush();
});

it('resolves an issue from the card when allowed', function () {
    Gate::define(QueueActions::GATE, fn ($user = null) => true);

    $repository = app(IssueRepository::class);

    Pulse::report(throwFrom());
    $repository->flush();

    $fingerprint = $repository->fingerprint(throwFrom());

    Livewire::test(Issues::class, ['lazy' => false])->call('resolve', $fingerprint);

    expect($repository->find($fingerprint)->status)->toBe('resolved');

    Pulse::flush();
});

it('keeps open issues when trimming but drops settled ones', function () {
    $repository = app(IssueRepository::class);

    Pulse::report(new RuntimeException('stays open'));
    Pulse::report(throwFrom('gets resolved'));
    $repository->flush();

    $resolved = $repository->fingerprint(throwFrom());
    $repository->setStatus($resolved, 'resolved');

    $this->travel(40)->days();
    $repository->trim();

    // An open issue is still a bug however old it is.
    expect($repository->count([]))->toBe(1);
    expect($repository->find($resolved))->toBeNull();

    Pulse::flush();
});

it('records exceptions the application throws, not only ones reported by hand', function () {
    // Regression: issues only listened for ExceptionReported, which nothing
    // dispatched for an exception that was simply thrown, so a real
    // application recorded no issues at all.
    Route::get('boom', fn () => throw new RuntimeException('Thrown, not reported'))->middleware('web');

    $this->get('boom')->assertServerError();

    $issues = app(IssueRepository::class)->issues([]);

    expect($issues)->toHaveCount(1);
    expect($issues[0]->message)->toBe('Thrown, not reported');

    Pulse::flush();
});

it('counts an exception the handler reports once, however many times the hook is registered', function () {
    // Regression: in the console, Collision wraps the exception handler and
    // the hook ended up registered twice on it, so every failed job was two
    // occurrences. Booting the provider again registers it a second time.
    app()->register(PulseServiceProvider::class, force: true);

    $dispatched = 0;
    Event::listen(ExceptionReported::class, function () use (&$dispatched) {
        $dispatched++;
    });

    report(new RuntimeException('Warehouse refused the sync'));

    expect($dispatched)->toBe(1);

    Pulse::flush();
});

it('tells PHP errors apart from exceptions', function () {
    $repository = app(IssueRepository::class);

    Pulse::report(new RuntimeException('Anticipated'));
    Pulse::report(new TypeError('A bug'));
    $repository->flush();

    expect($repository->countsByKind())->toBe(['' => 2, 'exception' => 1, 'error' => 1, 'log' => 0, 'performance' => 0]);
    expect($repository->issues(['kind' => 'error'])[0]->class)->toBe(TypeError::class);

    Livewire::test(Issues::class, ['lazy' => false])
        ->set('kind', 'exception')
        ->assertSee('Anticipated')
        ->assertDontSee('A bug');

    Pulse::flush();
});

it('sorts by how often an issue happens', function () {
    $repository = app(IssueRepository::class);

    $often = throwFrom('Often');
    Pulse::report(new LogicException('Rare'));
    Pulse::report($often);
    Pulse::report($often);
    $repository->flush();

    expect($repository->issues([], orderBy: 'count')[0]->class)->toBe(RuntimeException::class);
    expect($repository->issues([], orderBy: 'latest')[0]->class)->toBe(RuntimeException::class);

    Pulse::report(new LogicException('Rare'));
    $repository->flush();

    expect($repository->issues([], orderBy: 'count')[0]->class)->toBe(RuntimeException::class);

    Pulse::flush();
});
