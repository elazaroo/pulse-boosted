<?php

use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Queues\InspectorManager;
use Elazaroo\PulseBoosted\Queues\Inspectors\CountingInspector;
use Elazaroo\PulseBoosted\Queues\Inspectors\DatabaseInspector;
use Elazaroo\PulseBoosted\Queues\Inspectors\NullInspector;
use Elazaroo\PulseBoosted\Queues\PendingJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Connectors\NullConnector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Config::set('queue.default', 'database');
});

it('picks an inspector from the connection driver', function () {
    $manager = app(InspectorManager::class);

    expect($manager->for('database'))->toBeInstanceOf(DatabaseInspector::class);
    expect($manager->for('sync'))->toBeInstanceOf(NullInspector::class);
});

it('falls back to counting for drivers it does not know', function () {
    Queue::extend('unknown', fn () => new NullConnector);
    Config::set('queue.connections.unknown', ['driver' => 'unknown', 'queue' => 'default']);

    expect(app(InspectorManager::class)->for('unknown'))->toBeInstanceOf(CountingInspector::class);
});

it('degrades to nothing when a connection cannot be built at all', function () {
    // Beanstalkd without pheanstalk installed: the driver is configured, but
    // constructing it throws, so there is nothing to inspect.
    Config::set('queue.connections.beanstalkd', ['driver' => 'beanstalkd', 'queue' => 'default']);

    $inspector = app(InspectorManager::class)->for('beanstalkd');

    expect($inspector)->toBeInstanceOf(NullInspector::class);
});

it('lets a third party register its own inspector', function () {
    Config::set('queue.connections.custom', ['driver' => 'custom', 'queue' => 'default']);

    $manager = app(InspectorManager::class);
    $manager->extend('custom', fn (string $connection) => new NullInspector(app('queue')->connection('sync'), $connection));

    expect($manager->for('custom'))->toBeInstanceOf(NullInspector::class);
});

it('reports what the sync driver cannot do', function () {
    $capabilities = app(InspectorManager::class)->for('sync')->capabilities();

    expect($capabilities->counts)->toBeFalse();
    expect($capabilities->listing)->toBeFalse();
    expect($capabilities->reason)->toContain('runs jobs immediately');
});

it('counts pending, delayed and reserved jobs on the database driver', function () {
    Carbon::setTestNow('2000-01-02 03:04:05');

    Bus::dispatchToQueue(new InspectedJob);
    Bus::dispatchToQueue(new InspectedJob);
    Bus::dispatchToQueue((new InspectedJob)->delay(now()->addHour()));

    $counts = app(InspectorManager::class)->for('database')->counts('default');

    expect($counts->pending)->toBe(2);
    expect($counts->delayed)->toBe(1);
    expect($counts->reserved)->toBe(0);
    expect($counts->total())->toBe(3);
    expect($counts->known())->toBeTrue();

    Pulse::flush();
});

it('lists the jobs waiting on the database driver', function () {
    Carbon::setTestNow('2000-01-02 03:04:05');

    Bus::dispatchToQueue(new InspectedJob);
    Bus::dispatchToQueue((new InspectedJob)->delay(now()->addHour()));

    $inspector = app(InspectorManager::class)->for('database');

    $pending = $inspector->pending('default');
    expect($pending)->toHaveCount(1);
    expect($pending[0])->toBeInstanceOf(PendingJob::class);
    expect($pending[0]->name)->toBe(InspectedJob::class);
    expect($pending[0]->class)->toBe(InspectedJob::class);
    expect($pending[0]->uuid)->not->toBeNull();
    expect($pending[0]->attempts)->toBe(0);

    $delayed = $inspector->delayed('default');
    expect($delayed)->toHaveCount(1);
    expect($delayed[0]->availableAt->timestamp)->toBe(now()->addHour()->timestamp);

    expect($inspector->reserved('default'))->toHaveCount(0);

    Pulse::flush();
});

it('discovers queue names from the jobs table', function () {
    Bus::dispatchToQueue((new InspectedJob)->onQueue('emails'));

    $queues = app(InspectorManager::class)->for('database')->queues();

    expect($queues->all())->toContain('emails');
    // The default queue is always listed, even when nothing is on it.
    expect($queues->all())->toContain('default');

    Pulse::flush();
});

it('returns zero counts for sync rather than pretending it does not know', function () {
    $counts = app(InspectorManager::class)->for('sync')->counts('default');

    expect($counts->pending)->toBe(0);
    expect($counts->known())->toBeTrue();
});

it('offers every configured connection', function () {
    expect(app(InspectorManager::class)->connections()->all())->toContain('database', 'sync');
});

it('honours an explicit connection list', function () {
    Config::set('pulse-boosted.queues.connections', ['database']);

    expect(app(InspectorManager::class)->connections()->all())->toBe(['database']);
});

class InspectedJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        //
    }
}

it('does not count connections that have no queue of their own', function () {
    // deferred and background run the job in this process; failover hands it
    // to other connections. Counting through any of them showed the same jobs
    // twice, or a row of zeros that meant nothing.
    Config::set('queue.connections.deferred', ['driver' => 'deferred']);
    Config::set('queue.connections.background', ['driver' => 'background']);
    Config::set('queue.connections.failover', ['driver' => 'failover', 'connections' => ['database', 'sync']]);

    $manager = app(InspectorManager::class);

    foreach (['deferred', 'background', 'failover'] as $connection) {
        expect($manager->for($connection)->capabilities()->counts)->toBeFalse();
    }
});
