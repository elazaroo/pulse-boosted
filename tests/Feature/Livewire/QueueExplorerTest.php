<?php

use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Livewire\JobDetail;
use Elazaroo\PulseBoosted\Livewire\QueueExplorer;
use Elazaroo\PulseBoosted\Queues\Contracts\JobRepository;
use Elazaroo\PulseBoosted\Queues\QueueActions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Config::set('queue.default', 'database');

    // Testbench points the failed job store at DB_CONNECTION, which is not the
    // connection the tests run on.
    // The test environment disables the failed job store entirely, and
    // retrying is meaningless without one.
    Config::set('queue.failed.driver', 'database-uuids');
    Config::set('queue.failed.database', config('database.default'));
    app()->forgetInstance('queue.failer');

    // Testbench ships no failed_jobs table, and retrying is meaningless
    // without one.
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

it('renders the explorer page', function () {
    $this->get('/pulse-boosted/queues')->assertOk()->assertSee('Waiting');
});

it('lists jobs waiting on the queue', function () {
    Bus::dispatchToQueue(new ExploredJob);

    Livewire::test(QueueExplorer::class)
        ->assertSet('tab', 'pending')
        ->assertSee(ExploredJob::class);

    Pulse::flush();
});

it('shows recorded failures on the failed tab', function () {
    Str::createUuidsUsingSequence(['11111111-6c2e-4bc5-82c9-45e79c3e8fdd']);

    Bus::dispatchToQueue(new ExplodingJob);
    Artisan::call('queue:work', ['--max-jobs' => 1, '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1]);

    Livewire::test(QueueExplorer::class)
        ->set('tab', 'failed')
        ->assertSee(ExplodingJob::class)
        ->assertSee('RuntimeException');

    Pulse::flush();
});

it('hides live tabs for a driver that cannot list', function () {
    Config::set('queue.default', 'sync');

    Livewire::test(QueueExplorer::class)
        ->set('connection', 'sync')
        ->assertSee('runs jobs immediately')
        ->assertSee('Completed')
        ->assertSet('tab', 'failed');

    Pulse::flush();
});

it('hides destructive actions unless the manage gate allows them', function () {
    Livewire::test(QueueExplorer::class)->assertDontSee('Delete all failed');

    Gate::define(QueueActions::GATE, fn ($user = null) => true);

    Livewire::test(QueueExplorer::class)->assertSee('Delete all failed');

    Pulse::flush();
});

it('refuses to retry a job without the manage gate', function () {
    Str::createUuidsUsingSequence(['22222222-6c2e-4bc5-82c9-45e79c3e8fdd']);

    Bus::dispatchToQueue(new ExplodingJob);
    Artisan::call('queue:work', ['--max-jobs' => 1, '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1]);

    Livewire::test(QueueExplorer::class)
        ->call('retry', '22222222-6c2e-4bc5-82c9-45e79c3e8fdd')
        ->assertForbidden();

    Pulse::flush();
});

it('retries a failed job when the gate allows it', function () {
    Gate::define(QueueActions::GATE, fn ($user = null) => true);
    Str::createUuidsUsingSequence(['33333333-6c2e-4bc5-82c9-45e79c3e8fdd']);

    Bus::dispatchToQueue(new ExplodingJob);
    Artisan::call('queue:work', ['--max-jobs' => 1, '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1]);

    expect(failedCount())->toBe(1);

    Livewire::test(QueueExplorer::class)
        ->call('retry', '33333333-6c2e-4bc5-82c9-45e79c3e8fdd')
        ->assertHasNoErrors();

    // Back on the queue, and no longer in the failed table.
    expect(failedCount())->toBe(0);

    Pulse::flush();
});

it('shows a job detail page', function () {
    Str::createUuidsUsingSequence(['44444444-6c2e-4bc5-82c9-45e79c3e8fdd']);

    Bus::dispatchToQueue(new ExplodingJob);
    Artisan::call('queue:work', ['--max-jobs' => 1, '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1]);

    Livewire::test(JobDetail::class, ['uuid' => '44444444-6c2e-4bc5-82c9-45e79c3e8fdd'])
        ->assertSee(ExplodingJob::class)
        ->assertSee('Failed')
        ->assertSee('RuntimeException')
        ->assertSee('Not captured');

    Pulse::flush();
});

it('says so when a job was never recorded', function () {
    Livewire::test(JobDetail::class, ['uuid' => '99999999-6c2e-4bc5-82c9-45e79c3e8fdd'])
        ->assertSee('No record of this job');
});

it('filters recorded jobs by search term', function () {
    Bus::dispatchToQueue(new ExploredJob);
    app(JobRepository::class)->flush();

    Livewire::test(QueueExplorer::class)
        ->set('tab', 'queued')
        ->set('search', 'ExploredJob')
        ->assertSee(ExploredJob::class)
        ->set('search', 'SomethingElse')
        ->assertDontSee(ExploredJob::class);

    Pulse::flush();
});

it('renders the dashboard when the application registers its own routes', function () {
    // Pulse::ignoreRoutes() leaves the named routes undefined, so anything in
    // the layout or the cards that calls route() has to cope.
    Pulse::ignoreRoutes();

    Route::get('/my-pulse', fn () => view('pulse-boosted::dashboard'))->middleware('pulse-boosted');

    $this->get('/my-pulse')
        ->assertOk()
        ->assertDontSee('Explore');
});

function failedCount(): int
{
    return Pulse::ignore(fn () => count(app('queue.failer')->all()));
}

class ExploredJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        //
    }
}

class ExplodingJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        throw new RuntimeException('Boom');
    }
}
