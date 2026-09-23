<?php

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\Events\IssueAssigned;
use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Issues\IssueMail;
use Elazaroo\PulseBoosted\Issues\IssueRepository;
use Elazaroo\PulseBoosted\Livewire\Issues;
use Elazaroo\PulseBoosted\Queues\QueueActions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\User;

/**
 * Thrown from one place, so every call is the same issue.
 */
function cardDeclined(): RuntimeException
{
    return new RuntimeException('Card declined');
}

/**
 * One recorded issue.
 */
function recordedIssue(): string
{
    $repository = app(IssueRepository::class);

    $repository->record($exception = cardDeclined(), null, null);
    $repository->flush();

    return $repository->fingerprint($exception);
}

it('assigns an issue, says who did it, and announces it', function () {
    Event::fake([IssueAssigned::class]);

    $fingerprint = recordedIssue();
    $repository = app(IssueRepository::class);

    $repository->assign($fingerprint, '7', '3');

    expect($repository->find($fingerprint)->assignee)->toBe('7');
    expect($repository->activity($fingerprint)->map(fn ($entry) => [$entry->type, $entry->user_id, $entry->body])->all())
        ->toBe([['assigned', '3', '7']]);

    Event::assertDispatched(IssueAssigned::class, fn (IssueAssigned $event) => $event->assignee === '7' && $event->by === '3');

    // Assigning to the same person again changes nothing, so says nothing.
    $repository->assign($fingerprint, '7', '3');
    Event::assertDispatchedTimes(IssueAssigned::class, 1);

    $repository->assign($fingerprint, null, '3');

    expect($repository->find($fingerprint)->assignee)->toBeNull();
    expect($repository->activity($fingerprint)->last()->type)->toBe('unassigned');
});

it('keeps a history of what happened and who did it', function () {
    $fingerprint = recordedIssue();
    $repository = app(IssueRepository::class);

    $repository->comment($fingerprint, '  Looking at the gateway logs  ', '3');
    $repository->comment($fingerprint, '   ', '3');
    $repository->setStatus($fingerprint, 'resolved', '3');
    $repository->setStatus($fingerprint, 'resolved', '3');

    // It happens again.
    $repository->record(cardDeclined(), null, null);
    $repository->flush();

    expect($repository->activity($fingerprint)->map(fn ($entry) => [$entry->type, $entry->user_id, $entry->body])->all())->toBe([
        ['comment', '3', 'Looking at the gateway logs'],
        ['resolved', '3', null],
        ['regressed', null, null],
    ]);
});

it('says why an issue was resolved for being quiet', function () {
    $fingerprint = recordedIssue();
    $repository = app(IssueRepository::class);

    CarbonImmutable::setTestNow(now()->addDays(10));

    expect($repository->resolveQuietFor('7 days'))->toBe(1);
    expect($repository->activity($fingerprint)->last())
        ->type->toBe('resolved')
        ->user_id->toBeNull()
        ->body->toBe('Not seen for 7 days');

    CarbonImmutable::setTestNow();
});

it('filters issues by who they are assigned to', function () {
    $mine = recordedIssue();
    $repository = app(IssueRepository::class);
    $repository->record(new LogicException('Other'), null, null);
    $repository->flush();

    $repository->assign($mine, '5');

    expect($repository->issues(['assignee' => '5'])->pluck('fingerprint')->all())->toBe([$mine]);
    expect($repository->issues(['assignee' => 'none'])->pluck('class')->all())->toBe([LogicException::class]);
});

it('lets the person looking take an issue on and write about it', function () {
    Gate::define(QueueActions::GATE, fn ($user = null) => true);

    $user = User::factory()->create(['name' => 'Ada Lovelace']);
    $this->actingAs($user);

    $fingerprint = recordedIssue();

    Livewire::test(Issues::class, ['lazy' => false])
        ->call('select', $fingerprint)
        ->assertSee('Nobody')
        ->assertSee('Assign to me')
        ->call('assignToMe', $fingerprint)
        ->set('comment', 'On it')
        ->call('addComment', $fingerprint)
        ->assertSet('comment', '')
        ->assertSee('Ada Lovelace')
        ->assertSee('took it on')
        ->assertSee('On it')
        ->set('assignee', 'me')
        ->assertSee('RuntimeException')
        ->call('deselect')
        ->set('assignee', 'none')
        ->assertDontSee('Card declined');

    expect(app(IssueRepository::class)->find($fingerprint)->assignee)->toBe((string) $user->id);
});

it('does not let anyone assign issues without the gate', function () {
    $fingerprint = recordedIssue();

    Livewire::test(Issues::class, ['lazy' => false])
        ->call('assign', $fingerprint, '5')
        ->assertForbidden();

    expect(app(IssueRepository::class)->find($fingerprint)->assignee)->toBeNull();
});

it('emails whoever is responsible when their issue comes back', function () {
    Mail::fake();
    Config::set('pulse-boosted.issues.notify.mail', 'oncall@example.com');

    $user = User::factory()->create(['email' => 'ada@example.com']);

    $fingerprint = recordedIssue();
    $repository = app(IssueRepository::class);
    $repository->assign($fingerprint, (string) $user->id);
    $repository->setStatus($fingerprint, 'resolved');

    $repository->record(cardDeclined(), null, null);
    $repository->flush();

    Mail::assertSent(IssueMail::class, fn (IssueMail $mail) => $mail->regressed
        && $mail->hasTo('oncall@example.com')
        && $mail->hasTo('ada@example.com'));

    Pulse::flush();
});

it('drops the history of issues that are trimmed', function () {
    $fingerprint = recordedIssue();
    $repository = app(IssueRepository::class);

    $repository->comment($fingerprint, 'Fixed in 2.4.1');
    $repository->setStatus($fingerprint, 'resolved');

    CarbonImmutable::setTestNow(now()->addDays(40));
    $repository->trim();
    CarbonImmutable::setTestNow();

    expect($repository->find($fingerprint))->toBeNull();
    expect($repository->activity($fingerprint))->toBeEmpty();
});
