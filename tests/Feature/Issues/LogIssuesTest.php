<?php

use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Issues\IssueMail;
use Elazaroo\PulseBoosted\Issues\IssueRepository;
use Elazaroo\PulseBoosted\Livewire\Issues;
use Elazaroo\PulseBoosted\Livewire\Logs;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

it('folds the parts of a message that change between writes', function () {
    expect(IssueRepository::normalizeMessage('Order 7 could not be charged'))->toBe('Order {n} could not be charged');
    expect(IssueRepository::normalizeMessage('Card declined for ada@example.com on order 12.50'))->toBe('Card declined for {email} on order {n}');
    expect(IssueRepository::normalizeMessage('Job 3f2b1c9e-8d4a-4e6f-9b1a-2c3d4e5f6a7b gave up after 3 tries'))->toBe('Job {uuid} gave up after {n} tries');
    expect(IssueRepository::normalizeMessage('Unknown coupon "SPRING24"'))->toBe('Unknown coupon "…"');
});

it('groups warnings that differ only in their details into one issue', function () {
    $repository = app(IssueRepository::class);

    foreach ([7, 9, 12] as $order) {
        Log::warning("Order {$order} could not be charged");
    }
    $repository->flush();

    $issues = $repository->issues(['kind' => 'log']);

    expect($issues)->toHaveCount(1);
    expect($issues[0]->class)->toBe('Order {n} could not be charged');
    expect($issues[0]->level)->toBe('warning');
    expect($issues[0]->message)->toBe('Order 12 could not be charged');
    expect((int) $issues[0]->occurrences)->toBe(3);
});

it('keeps the same message at different levels apart', function () {
    $repository = app(IssueRepository::class);

    foreach (['warning', 'error'] as $level) {
        Log::log($level, 'Payment gateway slow');
    }
    $repository->flush();

    expect($repository->issues(['kind' => 'log'])->pluck('level')->sort()->values()->all())->toBe(['error', 'warning']);
});

it('leaves lines below the configured level out', function () {
    $repository = app(IssueRepository::class);

    Log::info('Checkout started');
    Log::notice('Coupon applied');
    $repository->flush();

    expect($repository->count(['kind' => 'log']))->toBe(0);

    Config::set('pulse-boosted.issues.log_level', 'info');
    Log::info('Checkout started');
    $repository->flush();

    expect($repository->count(['kind' => 'log']))->toBe(1);

    Config::set('pulse-boosted.issues.log_level', 'none');
    Log::critical('Disk almost full');
    $repository->flush();

    expect($repository->count(['kind' => 'log']))->toBe(1);
});

it('does not count a reported exception a second time for the line that logs it', function () {
    $repository = app(IssueRepository::class);

    report(new RuntimeException('Card declined'));
    $repository->flush();

    expect($repository->count(['kind' => 'exception']))->toBe(1);
    expect($repository->count(['kind' => 'log']))->toBe(0);

    Pulse::flush();
});

it('reopens a logged issue that was resolved when it is written again', function () {
    $repository = app(IssueRepository::class);

    $write = fn () => Log::error('Stock sync failed for 4 products');

    $write();
    $repository->flush();
    $fingerprint = $repository->issues(['kind' => 'log'])->first()->fingerprint;
    $repository->setStatus($fingerprint, 'resolved');

    $write();
    $repository->flush();

    expect($repository->find($fingerprint)->status)->toBe('open');
});

it('emails logged errors but not warnings', function () {
    Mail::fake();
    Config::set('pulse-boosted.issues.notify.mail', 'oncall@example.com');

    $repository = app(IssueRepository::class);

    Log::warning('Retrying the gateway');
    $repository->flush();

    Mail::assertNothingSent();

    Log::error('Gateway gave up');
    $repository->flush();

    Mail::assertSent(IssueMail::class, fn (IssueMail $mail) => $mail->issue->level === 'error');
});

it('lists logged issues with their level and every entry on the same card', function () {
    $repository = app(IssueRepository::class);

    Log::warning('Order 7 could not be charged');
    $repository->flush();

    Livewire::test(Issues::class, ['lazy' => false])
        ->set('kind', 'log')
        ->assertSee('Order {n} could not be charged')
        ->assertSee('warning')
        ->set('view', 'stream')
        ->assertSee('Every entry')
        ->assertDontSee('Order {n} could not be charged');
});

it('replaces the Logging card on the default dashboard', function () {
    $this
        ->get('/pulse-boosted')
        ->assertSeeLivewire(Issues::class)
        ->assertDontSeeLivewire(Logs::class);
});
