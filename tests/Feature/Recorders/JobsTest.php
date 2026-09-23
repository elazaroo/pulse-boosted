<?php

use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Queues\Contracts\JobRepository;
use Elazaroo\PulseBoosted\Recorders\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

beforeEach(function () {
    Config::set('queue.default', 'database');
});

it('records a job through its whole lifecycle', function () {
    Str::createUuidsUsingSequence(['e2cb5fa7-6c2e-4bc5-82c9-45e79c3e8fdd']);
    Carbon::setTestNow('2000-01-02 03:04:05');

    Bus::dispatchToQueue(new RecordedJob);
    app(JobRepository::class)->flush();

    $job = job('e2cb5fa7-6c2e-4bc5-82c9-45e79c3e8fdd');

    expect($job->status)->toBe('queued');
    expect($job->connection)->toBe('database');
    expect($job->queue)->toBe('default');
    expect($job->name)->toBe(RecordedJob::class);
    expect($job->class)->toBe(RecordedJob::class);
    expect($job->queued_at)->toBe(now()->timestamp);
    expect($job->started_at)->toBeNull();
    expect($job->payload)->toBeNull();

    Carbon::setTestNow('2000-01-02 03:04:10');
    Artisan::call('queue:work', ['--max-jobs' => 1, '--stop-when-empty' => true, '--sleep' => 0]);

    $job = job('e2cb5fa7-6c2e-4bc5-82c9-45e79c3e8fdd');

    expect($job->status)->toBe('processed');
    expect($job->attempts)->toBe(1);
    expect($job->started_at)->not->toBeNull();
    expect($job->finished_at)->not->toBeNull();
    expect($job->duration_ms)->not->toBeNull();

    // Still one row: the lifecycle updates in place rather than appending.
    expect(rows())->toHaveCount(1);

    Pulse::flush();
});

it('records the exception when a job fails', function () {
    Str::createUuidsUsingSequence(['aaaaaaaa-6c2e-4bc5-82c9-45e79c3e8fdd']);

    Bus::dispatchToQueue(new FailingJob);
    Artisan::call('queue:work', ['--max-jobs' => 1, '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1]);

    $job = job('aaaaaaaa-6c2e-4bc5-82c9-45e79c3e8fdd');

    expect($job->status)->toBe('failed');
    expect($job->exception_class)->toBe(RuntimeException::class);
    expect($job->exception)->toContain('This job always fails');

    Pulse::flush();
});

it('records a synchronous job, which the counter recorder skips', function () {
    Config::set('queue.default', 'sync');
    Str::createUuidsUsingSequence(['bbbbbbbb-6c2e-4bc5-82c9-45e79c3e8fdd']);

    Bus::dispatchToQueue(new RecordedJob);
    app(JobRepository::class)->flush();

    expect(job('bbbbbbbb-6c2e-4bc5-82c9-45e79c3e8fdd')->connection)->toBe('sync');

    Pulse::flush();
});

it('does not capture arguments by default', function () {
    Str::createUuidsUsingSequence(['cccccccc-6c2e-4bc5-82c9-45e79c3e8fdd']);

    Bus::dispatchToQueue(new JobWithSecrets('alice', 'hunter2'));
    app(JobRepository::class)->flush();

    expect(job('cccccccc-6c2e-4bc5-82c9-45e79c3e8fdd')->payload)->toBeNull();

    Pulse::flush();
});

it('captures and redacts arguments when asked to', function () {
    Config::set('pulse-boosted.recorders.'.Jobs::class.'.capture_payload', true);
    Str::createUuidsUsingSequence(['dddddddd-6c2e-4bc5-82c9-45e79c3e8fdd']);

    Bus::dispatchToQueue(new JobWithSecrets('alice', 'hunter2'));
    app(JobRepository::class)->flush();

    $payload = json_decode(job('dddddddd-6c2e-4bc5-82c9-45e79c3e8fdd')->payload, true);

    expect($payload['username'])->toBe('alice');
    expect($payload['apiToken'])->toBe('[redacted]');
    expect($payload['options']['password'])->toBe('[redacted]');
    expect($payload['options']['locale'])->toBe('es');

    Pulse::flush();
});

it('can ignore jobs by name', function () {
    Config::set('pulse-boosted.recorders.'.Jobs::class.'.ignore', ['/RecordedJob/']);

    Bus::dispatchToQueue(new RecordedJob);
    app(JobRepository::class)->flush();

    expect(rows())->toHaveCount(0);

    Pulse::flush();
});

it('counts recorded jobs by status', function () {
    Bus::dispatchToQueue(new RecordedJob);
    Bus::dispatchToQueue(new RecordedJob);
    app(JobRepository::class)->flush();

    expect(app(JobRepository::class)->countsByStatus()['queued'])->toBe(2);
    expect(app(JobRepository::class)->countsByStatus()['failed'])->toBe(0);

    Pulse::flush();
});

it('trims records past the retention window', function () {
    Config::set('pulse-boosted.recorders.'.Jobs::class.'.trim.keep', '1 hour');
    Carbon::setTestNow('2000-01-02 03:04:05');

    Bus::dispatchToQueue(new RecordedJob);
    app(JobRepository::class)->flush();

    expect(rows())->toHaveCount(1);

    Carbon::setTestNow('2000-01-02 05:04:05');
    app(JobRepository::class)->trim();

    expect(rows())->toHaveCount(0);

    Pulse::flush();
});

/**
 * Fetch a recorded job by UUID, wherever the history is kept.
 */
function job(string $uuid): object
{
    return app(JobRepository::class)->find($uuid);
}

/**
 * Every recorded job.
 */
function rows(): Collection
{
    return app(JobRepository::class)->jobs([], 1000);
}

class RecordedJob implements ShouldQueue
{
    public function handle(): void
    {
        //
    }
}

class FailingJob implements ShouldQueue
{
    public function handle(): void
    {
        throw new RuntimeException('This job always fails');
    }
}

class JobWithSecrets implements ShouldQueue
{
    public array $options;

    public function __construct(
        public string $username,
        public string $apiToken,
    ) {
        $this->options = ['password' => 'sesame', 'locale' => 'es'];
    }

    public function handle(): void
    {
        //
    }
}
