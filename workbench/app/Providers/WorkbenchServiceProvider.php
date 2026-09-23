<?php

namespace Workbench\App\Providers;

use Elazaroo\PulseBoosted\Queues\QueueActions;
use Elazaroo\PulseBoosted\Recorders\Jobs;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Console\Commands\DemoTrafficCommand;
use Workbench\App\Console\Commands\SeedQueueCommand;
use Workbench\App\Jobs\RebuildSearchIndex;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Demo settings, applied here rather than in workbench/.env because
        // the test suite loads the workbench too and would inherit them.
        if (! $this->app->runningUnitTests()) {
            config(['pulse-boosted.recorders.'.Jobs::class.'.capture_payload' => true]);

            // Record every execution in the demo, so the traces card has
            // something in it without having to generate traffic.
            config(['pulse-boosted.traces.sample_rate' => 1.0]);
            config(['pulse-boosted.traces.sample_rates' => []]);

            // New issues are emailed; the log mailer writes them to
            // storage/logs so they can be read without a mail server.
            config(['pulse-boosted.issues.notify.mail' => 'oncall@example.com']);
            config(['mail.default' => 'log']);

            // The version running, so traces and issues are tagged with it and
            // the Overview can say what is new since the last deploy.
            config(['pulse-boosted.deployment' => 'v2.4.0']);

            // Anything slower than these opens a performance issue.
            config(['pulse-boosted.issues.thresholds' => [
                'request' => ['GET /demo/report' => 500, '*' => 5000],
                'job' => [RebuildSearchIndex::class => 300],
                'command' => [],
                'schedule' => [],
            ]]);

            config(['pulse-boosted.issues.auto_resolve_after' => '30 days']);

            // Keep the body of a request that ended in a 5xx, redacted.
            config(['pulse-boosted.traces.request.capture_payload' => true]);

            // A few rules, so the Alerts card has something to watch.
            config(['pulse-boosted.alerts.rules' => [
                ['name' => 'Error rate', 'metric' => 'error_rate', 'threshold' => 5, 'window' => '1 hour', 'options' => ['type' => 'request'], 'description' => 'More than 5% of requests are failing.'],
                ['name' => 'Exceptions', 'metric' => 'exceptions', 'threshold' => 3, 'window' => '1 hour', 'description' => 'More than three exceptions in the last hour.'],
                ['name' => 'Default queue backing up', 'metric' => 'queue_size', 'threshold' => 50, 'options' => ['queue' => 'default']],
                ['name' => 'Slow requests', 'metric' => 'p95_duration', 'threshold' => 2000, 'window' => '1 hour', 'options' => ['type' => 'request']],
            ]]);

            // A couple of scheduled tasks, so the Scheduled Tasks card has runs.
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                $schedule->command('inspire')->everyMinute();
                $schedule->call(fn () => DB::table('users')->count())->everyMinute()->name('demo:count-users');

                // One that fails, one its own condition holds back, and two
                // that are due at times the demo has already passed, so the
                // card shows every state a task can be in.
                $schedule->call(fn () => throw new \RuntimeException('Price feed timed out'))->everyMinute()->name('demo:sync-prices');
                $schedule->call(fn () => null)->everyMinute()->name('demo:only-on-weekdays')->when(fn () => false);
                $schedule->call(fn () => null)->dailyAt('03:00')->name('demo:nightly-report');
                $schedule->call(fn () => null)->hourlyAt(5)->name('demo:rotate-api-keys');
            });
        }

        // The dashboard is already open in `local`. This opens the destructive
        // actions too, which are denied by default, so retry and delete can be
        // tried out in the demo.
        Gate::define(QueueActions::GATE, fn ($user = null) => $this->app->environment('local'));

        // Demo routes, so there is something to trace besides the dashboard.
        $this->loadRoutesFrom(__DIR__.'/../../routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([SeedQueueCommand::class, DemoTrafficCommand::class]);
        }
    }
}
