<?php

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Queues\Contracts\JobRepository;
use Elazaroo\PulseBoosted\Queues\DatabaseJobRepository;
use Elazaroo\PulseBoosted\Queues\JobStorage;
use Elazaroo\PulseBoosted\Queues\RedisJobRepository;
use Elazaroo\PulseBoosted\Recorders\Jobs;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * The repository for a storage driver, starting empty. Redis is skipped when
 * there is no server to talk to.
 */
function jobStore(string $driver): JobRepository
{
    // "redis" runs on predis, "phpredis" on the extension — Laravel's
    // default client, whose raw commands reply in their own shapes.
    [$driver, $client] = match ($driver) {
        'redis' => ['redis', 'predis'],
        'phpredis' => ['redis', 'phpredis'],
        default => [$driver, null],
    };

    if ($client === 'phpredis' && ! extension_loaded('redis')) {
        test()->markTestSkipped('PHP extension [redis] missing.');
    }

    Config::set('pulse-boosted.queues.storage.driver', $driver);
    app()->forgetInstance(JobRepository::class);

    if ($client !== null) {
        Config::set('database.redis.client', $client);
        app()->forgetInstance('redis');
        Redis::clearResolvedInstances();

        try {
            Redis::connection()->ping();
        } catch (Throwable) {
            test()->markTestSkipped('No Redis server to talk to.');
        }
    }

    $store = app(JobRepository::class);
    $store->purge();

    return $store;
}

/**
 * A job id as long as a real one: the column is char(36), which SQL Server
 * pads anything shorter to fill.
 */
function jobId(string $name): string
{
    return str_pad($name, 36, '-');
}

function aJob(JobRepository $store, string $uuid, array $attributes): void
{
    $store->record(jobId($uuid), $attributes + ['connection' => 'redis', 'queue' => 'default', 'name' => 'App\Jobs\SendInvoice']);
}

afterEach(function () {
    if (Config::get('pulse-boosted.queues.storage.driver') === 'redis') {
        rescue(fn () => app(JobRepository::class)->purge(), report: false);
    }
});

it('follows the queues when left on auto', function (string $queueDriver, string $expected) {
    Config::set('pulse-boosted.queues.storage.driver', 'auto');
    Config::set('queue.default', 'main');
    Config::set('queue.connections.main', ['driver' => $queueDriver]);
    app()->forgetInstance(JobRepository::class);

    expect(JobStorage::driver(config()))->toBe($expected);
    expect(app(JobRepository::class))->toBeInstanceOf($expected === 'redis' ? RedisJobRepository::class : DatabaseJobRepository::class);
})->with([
    ['redis', 'redis'],
    ['database', 'database'],
    ['sync', 'database'],
]);

it('records a job through its lifecycle and reads it back', function (string $driver) {
    $store = jobStore($driver);

    aJob($store, 'job-1', ['status' => 'queued', 'queued_at' => 1000, 'attempts' => 0]);
    $store->flush();

    aJob($store, 'job-1', ['status' => 'processing', 'started_at' => 1005, 'attempts' => 1]);
    $store->flush();

    aJob($store, 'job-1', ['status' => 'failed', 'finished_at' => 1010, 'duration_ms' => 4800, 'exception_class' => 'RuntimeException', 'exception' => 'Card declined']);
    $store->flush();

    $job = $store->find(jobId('job-1'));

    expect($job)
        ->uuid->toBe(jobId('job-1'))
        ->status->toBe('failed')
        ->queue->toBe('default')
        ->queued_at->toBe(1000)
        ->started_at->toBe(1005)
        // Compared as numbers: SQL Server hands bigints back as strings.
        ->duration_ms->toEqual(4800)
        ->attempts->toBe(1)
        ->exception_class->toBe('RuntimeException');
    expect($job->id)->toBeNumeric();

    expect($store->countsByStatus())->toMatchArray(['queued' => 0, 'processing' => 0, 'failed' => 1]);
    expect($store->find('nothing'))->toBeNull();
})->with(['database', 'redis', 'phpredis']);

it('lists newest first, by status, with paging and filters', function (string $driver) {
    $store = jobStore($driver);

    foreach (range(1, 5) as $i) {
        aJob($store, "job-{$i}", [
            'status' => $i % 2 ? 'processed' : 'failed',
            'queued_at' => 1000 + $i,
            'finished_at' => 2000 + $i,
            'queue' => $i === 5 ? 'invoices' : 'default',
            'exception_class' => $i % 2 ? null : 'App\Exceptions\CardDeclined',
        ]);
        // One at a time, as a worker records them.
        $store->flush();
    }

    expect($store->jobs()->pluck('uuid')->map(fn ($id) => rtrim($id, '-'))->all())->toBe(['job-5', 'job-4', 'job-3', 'job-2', 'job-1']);
    expect($store->jobs([], 2, 1)->pluck('uuid')->map(fn ($id) => rtrim($id, '-'))->all())->toBe(['job-4', 'job-3']);
    expect($store->jobs(['status' => 'failed'])->pluck('uuid')->map(fn ($id) => rtrim($id, '-'))->all())->toBe(['job-4', 'job-2']);
    expect($store->count(['status' => 'processed']))->toBe(3);
    expect($store->jobs(['queue' => 'invoices'])->pluck('uuid')->map(fn ($id) => rtrim($id, '-'))->all())->toBe(['job-5']);
    expect($store->jobs(['search' => 'carddeclined'])->pluck('uuid')->map(fn ($id) => rtrim($id, '-'))->all())->toBe(['job-4', 'job-2']);
    expect($store->count(['status' => 'failed', 'finished_after' => '2003']))->toBe(1);
    expect($store->countsByStatus(['queue' => 'default']))->toMatchArray(['processed' => 2, 'failed' => 2]);
    expect($store->recordedQueues()->map(fn ($row) => $row->connection.'/'.$row->queue)->all())->toBe(['redis/default', 'redis/invoices']);
})->with(['database', 'redis', 'phpredis']);

it('keeps every attempt at a job', function (string $driver) {
    $store = jobStore($driver);

    aJob($store, 'job-1', ['status' => 'processed']);
    $store->recordAttempt(jobId('job-1'), ['attempt' => 1, 'status' => 'failed', 'started_at' => 10, 'finished_at' => 11, 'duration_ms' => 900, 'exception_class' => 'RuntimeException', 'exception_message' => 'Timeout', 'trace_id' => 'trace-a']);
    $store->recordAttempt(jobId('job-1'), ['attempt' => 2, 'status' => 'processed', 'started_at' => 20, 'finished_at' => 21, 'duration_ms' => 300, 'trace_id' => 'trace-b']);
    $store->flush();

    $attempts = $store->attempts(jobId('job-1'));

    expect($attempts->pluck('attempt')->map(fn ($a) => (int) $a)->all())->toBe([1, 2]);
    expect($attempts->first())->status->toBe('failed')->exception_message->toBe('Timeout');
    expect(rtrim($attempts->first()->trace_id))->toBe('trace-a');
    expect($store->attempts('nothing'))->toBeEmpty();
})->with(['database', 'redis', 'phpredis']);

it('drops what is past the retention, and everything when purged', function (string $driver) {
    Config::set('pulse-boosted.recorders.'.Jobs::class.'.trim.keep', '1 day');
    $store = jobStore($driver);

    aJob($store, 'old', ['status' => 'processed', 'queued_at' => CarbonImmutable::now()->subDays(3)->getTimestamp(), 'finished_at' => CarbonImmutable::now()->subDays(3)->getTimestamp()]);
    aJob($store, 'new', ['status' => 'processed', 'queued_at' => CarbonImmutable::now()->getTimestamp(), 'finished_at' => CarbonImmutable::now()->getTimestamp()]);
    $store->flush();

    $store->trim();

    expect($store->jobs()->pluck('uuid')->map(fn ($id) => rtrim($id, '-'))->all())->toBe(['new']);
    expect($store->count(['status' => 'processed']))->toBe(1);

    $store->purge();

    expect($store->jobs())->toBeEmpty();
    expect($store->recordedQueues())->toBeEmpty();
})->with(['database', 'redis', 'phpredis']);

it('needs no tables for the history when it is kept in Redis', function () {
    jobStore('redis');

    aJob(app(JobRepository::class), 'job-1', ['status' => 'processed']);
    app(JobRepository::class)->flush();

    expect(app(JobRepository::class)->count())->toBe(1);
    expect(Pulse::ignore(fn () => DB::table('pulse_boosted_jobs')->count()))->toBe(0);
});
