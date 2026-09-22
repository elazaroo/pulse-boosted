<?php

use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Livewire\Jobs;
use Elazaroo\PulseBoosted\Livewire\QueueStatus;
use Elazaroo\PulseBoosted\Queues\Contracts\JobRepository;
use Elazaroo\PulseBoosted\Queues\QueueActions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Config::set('queue.default', 'database');
    Config::set('queue.failed.driver', 'database-uuids');
    Config::set('queue.failed.database', config('database.default'));
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

it('is on the dashboard', function () {
    $this->get('/pulse-boosted')->assertSeeLivewire(Jobs::class);
});

it('opens on everything rather than on what is waiting', function () {
    Livewire::test(Jobs::class, ['lazy' => false])->assertSet('tab', 'all');
});

it('lists jobs of every status together on the all tab', function () {
    Bus::dispatchToQueue(new PassingJob);
    Bus::dispatchToQueue(new BreakingJob);
    Artisan::call('queue:work', ['--max-jobs' => 2, '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1]);
    Bus::dispatchToQueue(new PassingJob);
    app(JobRepository::class)->flush();

    Livewire::test(Jobs::class, ['lazy' => false])
        ->assertSee('PassingJob')
        ->assertSee('BreakingJob');

    Pulse::flush();
});

it('opens a job detail in place, without navigating', function () {
    Str::createUuidsUsingSequence(['55555555-6c2e-4bc5-82c9-45e79c3e8fdd']);

    Bus::dispatchToQueue(new BreakingJob);
    Artisan::call('queue:work', ['--max-jobs' => 1, '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1]);

    Livewire::test(Jobs::class, ['lazy' => false])
        ->call('select', '55555555-6c2e-4bc5-82c9-45e79c3e8fdd')
        ->assertSee('Timeline')
        ->assertSee('RuntimeException')
        ->assertSee('Boom')
        ->call('deselect')
        ->assertDontSee('Timeline');

    Pulse::flush();
});

it('opens the detail of a job that is only on the queue', function () {
    Str::createUuidsUsingSequence(['66666666-6c2e-4bc5-82c9-45e79c3e8fdd']);

    Bus::dispatchToQueue(new PassingJob);

    // Drop our record of it, leaving only what the backend holds. The buffer
    // has to go out first, or it would simply write the row back.
    app(JobRepository::class)->flush();
    app(JobRepository::class)->purge();

    Livewire::test(Jobs::class, ['lazy' => false])
        ->call('select', '66666666-6c2e-4bc5-82c9-45e79c3e8fdd')
        ->assertSee('Read straight off the queue')
        ->assertSee('PassingJob');

    Pulse::flush();
});

it('says so when a job is nowhere to be found', function () {
    Livewire::test(Jobs::class, ['lazy' => false])
        ->call('select', '99999999-6c2e-4bc5-82c9-45e79c3e8fdd')
        ->assertSee('Job not found');
});

it('filters to a queue picked from the status card', function () {
    Bus::dispatchToQueue((new PassingJob)->onQueue('emails'));
    app(JobRepository::class)->flush();

    Livewire::test(Jobs::class, ['lazy' => false])
        ->call('focusQueue', 'database', 'emails')
        ->assertSet('queue', 'emails');

    Pulse::flush();
});

it('tells the jobs table which queue was picked', function () {
    Bus::dispatchToQueue(new PassingJob);

    Livewire::test(QueueStatus::class, ['lazy' => false])
        ->call('focus', 'database', 'default')
        ->assertDispatched('queue-selected', connection: 'database', queue: 'default');

    Pulse::flush();
});

it('retries a failed job from the panel', function () {
    Gate::define(QueueActions::GATE, fn ($user = null) => true);
    Str::createUuidsUsingSequence(['77777777-6c2e-4bc5-82c9-45e79c3e8fdd']);

    Bus::dispatchToQueue(new BreakingJob);
    Artisan::call('queue:work', ['--max-jobs' => 1, '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1]);

    expect(Pulse::ignore(fn () => count(app('queue.failer')->all())))->toBe(1);

    Livewire::test(Jobs::class, ['lazy' => false])
        ->call('select', '77777777-6c2e-4bc5-82c9-45e79c3e8fdd')
        ->call('retry', '77777777-6c2e-4bc5-82c9-45e79c3e8fdd');

    expect(Pulse::ignore(fn () => count(app('queue.failer')->all())))->toBe(0);

    Pulse::flush();
});

it('refuses to retry without the manage gate', function () {
    Str::createUuidsUsingSequence(['88888888-6c2e-4bc5-82c9-45e79c3e8fdd']);

    Bus::dispatchToQueue(new BreakingJob);
    Artisan::call('queue:work', ['--max-jobs' => 1, '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1]);

    Livewire::test(Jobs::class, ['lazy' => false])
        ->call('retry', '88888888-6c2e-4bc5-82c9-45e79c3e8fdd')
        ->assertForbidden();

    Pulse::flush();
});

it('redirects the old queue page to the dashboard', function () {
    $this->get('/pulse-boosted/queues')->assertRedirect('/pulse-boosted');
});

it('redirects an old job link to the dashboard with it open', function () {
    $this->get('/pulse-boosted/jobs/abc-123')
        ->assertRedirectContains('job=abc-123');
});

class PassingJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        //
    }
}

class BreakingJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        throw new RuntimeException('Boom');
    }
}
