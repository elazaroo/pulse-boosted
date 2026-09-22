<?php

use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Livewire\Workers;
use Elazaroo\PulseBoosted\Recorders\Workers as WorkersRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

beforeEach(function () {
    Config::set('queue.default', 'database');
});

it('is on the dashboard', function () {
    $this->get('/pulse-boosted')->assertSeeLivewire(Workers::class);
});

it('says so when no worker has reported in', function () {
    Livewire::test(Workers::class, ['lazy' => false])
        ->assertSee('No workers have reported in');
});

it('records a worker as it runs jobs', function () {
    Bus::dispatchToQueue(new WorkedJob);
    Artisan::call('queue:work', ['--max-jobs' => 1, '--stop-when-empty' => true, '--sleep' => 0]);

    $workers = Pulse::ignore(fn () => Pulse::values(WorkersRecorder::TYPE));

    expect($workers)->toHaveCount(1);

    $worker = json_decode($workers->first()->value, true);

    expect($worker['host'])->not->toBeEmpty();
    expect($worker['pid'])->toBe(getmypid());
    expect($worker['processed'])->toBe(1);
    expect($worker['connection'])->toBe('database');

    Pulse::flush();
});

it('shows the worker on the card', function () {
    Bus::dispatchToQueue(new WorkedJob);
    Artisan::call('queue:work', ['--max-jobs' => 1, '--stop-when-empty' => true, '--sleep' => 0]);

    Livewire::test(Workers::class, ['lazy' => false])
        ->assertSee(gethostname())
        ->assertDontSee('No workers have reported in');

    Pulse::flush();
});

it('reports a worker that shut down cleanly as stopped', function () {
    Bus::dispatchToQueue(new WorkedJob);
    Artisan::call('queue:work', ['--max-jobs' => 1, '--stop-when-empty' => true, '--sleep' => 0]);

    Livewire::test(Workers::class, ['lazy' => false])->assertSee('stopped');

    Pulse::flush();
});

it('treats a worker that died without saying so as stale', function () {
    Carbon::setTestNow('2000-01-02 03:04:05');

    // A worker killed outright never gets to report 'stopped'; all we have is
    // a heartbeat that stopped arriving.
    Pulse::set(WorkersRecorder::TYPE, 'ghost:999', json_encode([
        'host' => 'ghost',
        'pid' => 999,
        'status' => 'idle',
        'connection' => 'database',
        'queue' => 'default',
        'processed' => 3,
        'failed' => 0,
        'started_at' => now()->timestamp,
        'last_seen' => now()->timestamp,
    ]));
    Pulse::ingest();

    Carbon::setTestNow('2000-01-02 03:10:05');

    Livewire::test(Workers::class, ['lazy' => false])->assertSee('stale');

    Pulse::flush();
});

class WorkedJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        //
    }
}
