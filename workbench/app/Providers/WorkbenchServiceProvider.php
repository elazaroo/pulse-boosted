<?php

namespace Workbench\App\Providers;

use Elazaroo\PulseBoosted\Queues\JobActions;
use Elazaroo\PulseBoosted\Recorders\Jobs;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Console\Commands\SeedQueueCommand;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Demo settings, applied here rather than in workbench/.env because
        // the test suite loads the workbench too and would inherit them.
        if (! $this->app->runningUnitTests()) {
            config(['pulse-boosted.recorders.'.Jobs::class.'.capture_payload' => true]);
        }

        // The dashboard is already open in `local`. This opens the destructive
        // actions too, which are denied by default, so retry and delete can be
        // tried out in the demo.
        Gate::define(JobActions::GATE, fn ($user = null) => $this->app->environment('local'));

        if ($this->app->runningInConsole()) {
            $this->commands([SeedQueueCommand::class]);
        }
    }
}
