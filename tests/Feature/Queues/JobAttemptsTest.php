<?php

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Livewire\Jobs;
use Elazaroo\PulseBoosted\Queues\Contracts\JobRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Config::set('queue.default', 'database');
    Config::set('queue.failed.driver', 'database-uuids');
    Config::set('queue.failed.database', config('database.default'));
    Config::set('pulse-boosted.traces.sample_rate', 1.0);
    Config::set('pulse-boosted.traces.sample_rates', []);
    app()->forgetInstance('queue.failer');

    if (! Schema::hasTable('failed_jobs')) {
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('connection');
            $table->string('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }
});

function work(int $jobs): void
{
    Artisan::call('queue:work', ['--max-jobs' => $jobs, '--stop-when-empty' => true, '--sleep' => 0]);
}

it('keeps every attempt at a job, not only how it ended', function () {
    Str::createUuidsUsingSequence(['aaaaaaaa-0000-4000-8000-000000000001']);

    Bus::dispatchToQueue(new FlakyJob);

    // Two releases and a failure: three attempts.
    work(3);

    $repository = app(JobRepository::class);
    $repository->flush();

    $attempts = $repository->attempts('aaaaaaaa-0000-4000-8000-000000000001');

    expect($attempts->pluck('attempt')->all())->toBe([1, 2, 3]);
    expect($attempts->pluck('status')->all())->toBe(['released', 'released', 'failed']);
    expect($attempts->first()->exception_class)->toBe(RuntimeException::class);
    expect($attempts->first()->exception_message)->toBe('Still flaky');
    expect($attempts->every(fn ($attempt) => $attempt->duration_ms !== null))->toBeTrue();

    Pulse::flush();
});

it('links each attempt to its own trace', function () {
    Str::createUuidsUsingSequence(['aaaaaaaa-0000-4000-8000-000000000002']);

    Bus::dispatchToQueue(new FlakyJob);
    work(3);

    $repository = app(JobRepository::class);
    $repository->flush();

    $traceIds = $repository->attempts('aaaaaaaa-0000-4000-8000-000000000002')->pluck('trace_id');

    expect($traceIds->filter()->unique())->toHaveCount(3);

    Pulse::flush();
});

it('shows the attempts in the job panel', function () {
    Str::createUuidsUsingSequence(['aaaaaaaa-0000-4000-8000-000000000003']);

    Bus::dispatchToQueue(new FlakyJob);
    work(3);
    app(JobRepository::class)->flush();

    Livewire::test(Jobs::class, ['lazy' => false])
        ->call('select', 'aaaaaaaa-0000-4000-8000-000000000003')
        ->assertSee('Attempts')
        ->assertSee('#1')
        ->assertSee('#3')
        ->assertSee('Still flaky');

    Pulse::flush();
});

it('trims attempts with the jobs they belong to', function () {
    Str::createUuidsUsingSequence(['aaaaaaaa-0000-4000-8000-000000000004']);

    Bus::dispatchToQueue(new FlakyJob);
    work(3);

    $repository = app(JobRepository::class);
    $repository->flush();

    Illuminate\Support\Carbon::setTestNow(now()->addMonth());
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMonth());

    $repository->trim();

    CarbonImmutable::setTestNow();
    Illuminate\Support\Carbon::setTestNow();

    expect($repository->attempts('aaaaaaaa-0000-4000-8000-000000000004'))->toHaveCount(0);

    Pulse::flush();
});

class FlakyJob implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public function handle(): void
    {
        throw new RuntimeException('Still flaky');
    }
}
