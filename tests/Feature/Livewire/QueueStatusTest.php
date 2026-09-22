<?php

use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Livewire\QueueStatus;
use Elazaroo\PulseBoosted\Queues\QueueActions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function () {
    Config::set('queue.default', 'database');
});

it('is on the dashboard', function () {
    $this->get('/pulse-boosted')->assertSeeLivewire(QueueStatus::class);
});

it('shows live counts for each queue', function () {
    Bus::dispatchToQueue(new WatchedJob);
    Bus::dispatchToQueue(new WatchedJob);
    Bus::dispatchToQueue((new WatchedJob)->onQueue('emails'));

    Livewire::test(QueueStatus::class, ['lazy' => false])
        ->assertSee('default')
        ->assertSee('emails')
        ->assertSee('Queue Status');

    Pulse::flush();
});

it('leaves out connections that cannot be counted', function () {
    // sync has no queue behind it, so a row for it would say nothing.
    Livewire::test(QueueStatus::class, ['lazy' => false])->assertDontSee('sync');

    Pulse::flush();
});

it('hides pause controls without the manage gate', function () {
    Bus::dispatchToQueue(new WatchedJob);

    Livewire::test(QueueStatus::class, ['lazy' => false])->assertDontSee('Pause');

    Pulse::flush();
});

it('pauses and resumes a queue', function () {
    Gate::define(QueueActions::GATE, fn ($user = null) => true);

    Bus::dispatchToQueue(new WatchedJob);

    $actions = app(QueueActions::class);

    expect($actions->supportsPausing())->toBeTrue();
    expect($actions->paused('database', 'default'))->toBeFalse();

    Livewire::test(QueueStatus::class, ['lazy' => false])
        ->assertSee('Pause')
        ->call('pause', 'database', 'default');

    expect($actions->paused('database', 'default'))->toBeTrue();

    Livewire::test(QueueStatus::class, ['lazy' => false])
        ->assertSee('Paused')
        ->call('resume', 'database', 'default');

    expect($actions->paused('database', 'default'))->toBeFalse();

    Pulse::flush();
});

it('refuses to pause without the manage gate', function () {
    Bus::dispatchToQueue(new WatchedJob);

    Livewire::test(QueueStatus::class, ['lazy' => false])
        ->call('pause', 'database', 'default')
        ->assertForbidden();

    expect(app(QueueActions::class)->paused('database', 'default'))->toBeFalse();

    Pulse::flush();
});

class WatchedJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        //
    }
}
