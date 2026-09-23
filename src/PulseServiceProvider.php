<?php

namespace Elazaroo\PulseBoosted;

use Composer\InstalledVersions;
use Elazaroo\PulseBoosted\Alerts\AlertManager;
use Elazaroo\PulseBoosted\Alerts\EvaluateAlerts;
use Elazaroo\PulseBoosted\Contracts\Ingest;
use Elazaroo\PulseBoosted\Contracts\ResolvesUsers;
use Elazaroo\PulseBoosted\Contracts\Storage;
use Elazaroo\PulseBoosted\Events\IsolatedBeat;
use Elazaroo\PulseBoosted\Ingests\NullIngest;
use Elazaroo\PulseBoosted\Ingests\RedisIngest;
use Elazaroo\PulseBoosted\Ingests\StorageIngest;
use Elazaroo\PulseBoosted\Issues\IssueRepository;
use Elazaroo\PulseBoosted\Queues\Contracts\JobRepository;
use Elazaroo\PulseBoosted\Queues\DatabaseJobRepository;
use Elazaroo\PulseBoosted\Queues\InspectorManager;
use Elazaroo\PulseBoosted\Queues\QueueActions;
use Elazaroo\PulseBoosted\Recorders\Traces as TracesRecorder;
use Elazaroo\PulseBoosted\Storage\DatabaseStorage;
use Elazaroo\PulseBoosted\Traces\Tracer;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\Queue as QueueBase;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Lottery;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Factory as ViewFactory;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TickReceived;
use Laravel\Sentinel\Http\Middleware\SentinelMiddleware;
use Livewire\LivewireManager;
use RuntimeException;

/**
 * @internal
 */
class PulseServiceProvider extends ServiceProvider
{
    /**
     * Register any package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/pulse-boosted.php', 'pulse-boosted'
        );

        $this->app->singleton(Pulse::class);
        $this->app->bind(Storage::class, DatabaseStorage::class);
        $this->app->singletonIf(ResolvesUsers::class, Users::class);

        // Singleton because it buffers writes between flushes.
        $this->app->singleton(JobRepository::class, DatabaseJobRepository::class);
        $this->app->singleton(InspectorManager::class);

        // Singleton because it holds the execution context for this process.
        $this->app->singleton(Tracer::class);
        $this->app->singleton(IssueRepository::class);
        $this->app->singleton(AlertManager::class);

        $this->registerIngest();
    }

    /**
     * Register the ingest implementation.
     */
    protected function registerIngest(): void
    {
        $this->app->bind(Ingest::class, fn (Application $app) => match ($app->make('config')->get('pulse-boosted.ingest.driver')) {
            'storage' => $app->make(StorageIngest::class),
            'redis' => $app->make(RedisIngest::class),
            null, 'null' => $app->make(NullIngest::class),
            default => throw new RuntimeException("Unknown ingest driver [{$app->make('config')->get('pulse-boosted.ingest.driver')}]."),
        });
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        if ($this->app->make('config')->get('pulse-boosted.enabled')) {
            $this->app->make(Pulse::class)->register($this->app->make('config')->get('pulse-boosted.recorders'));
            $this->listenForEvents();
        } else {
            $this->app->make(Pulse::class)->stopRecording();
        }

        Route::middlewareGroup('pulse-boosted', [
            SentinelMiddleware::class.':pulse-boosted',
            ...$this->app->make('config')->get('pulse-boosted.middleware', []),
        ]);

        $this->registerAuthorization();
        $this->registerRoutes();
        $this->registerComponents();
        $this->registerResources();
        $this->registerPublishing();
        $this->registerCommands();
    }

    /**
     * Register the package authorization.
     */
    protected function registerAuthorization(): void
    {
        $this->callAfterResolving(Gate::class, function (Gate $gate, Application $app) {
            $gate->define('viewPulseBoosted', fn ($user = null) => $app->environment('local'));

            // Reading metrics must not imply being able to retry or delete
            // production jobs, so this is a separate gate and denies by default.
            $gate->define(QueueActions::GATE, fn ($user = null) => false);
        });
    }

    /**
     * Register the package routes.
     */
    protected function registerRoutes(): void
    {
        $this->callAfterResolving('router', function (Router $router, Application $app) {
            if ($app->make(Pulse::class)->registersRoutes()) {
                $router->group([
                    'domain' => $app->make('config')->get('pulse-boosted.domain', null),
                    'prefix' => $app->make('config')->get('pulse-boosted.path'),
                    'middleware' => 'pulse-boosted',
                ], function (Router $router) {
                    $router->get('/', function (Pulse $pulse, ViewFactory $view) {
                        return $view->make('pulse-boosted::dashboard');
                    })->name('pulse-boosted');

                    // Everything lives on the dashboard now. These are kept so
                    // links people already have keep working, and so a job can
                    // still be linked to directly; both land on the dashboard
                    // with the right thing open.
                    $router->get('/queues', function () {
                        return redirect()->route('pulse-boosted');
                    })->name('pulse-boosted.queues');

                    $router->get('/jobs/{uuid}', function (string $uuid) {
                        return redirect()->route('pulse-boosted', ['job' => $uuid]);
                    })->name('pulse-boosted.jobs.show');
                });
            }
        });
    }

    /**
     * Listen for the events that are relevant to the package.
     */
    protected function listenForEvents(): void
    {
        $this->app->booted(function () {
            // A job queued inside a request should be joinable to it, even
            // though it runs later in another process. The id rides along in
            // the payload, which is the only thing that crosses the gap.
            QueueBase::createPayloadUsing(function () {
                $id = $this->app->make(Tracer::class)->currentId();

                return $id === null ? [] : [TracesRecorder::PAYLOAD_KEY => $id];
            });

            $this->callAfterResolving(Dispatcher::class, function (Dispatcher $event, Application $app) {
                $event->listen(function (Logout $event) use ($app) {
                    if ($event->user === null) {
                        return;
                    }

                    $pulse = $app->make(Pulse::class);

                    $pulse->rescue(fn () => $pulse->rememberUser($event->user));
                });

                $event->listen([
                    Looping::class,
                    WorkerStopping::class,
                ], function () use ($app) {
                    $app->make(Pulse::class)->ingest();
                    $this->flushJobs($app);
                    $this->flushTraces($app);
                });
            });

            $this->callAfterResolving(HttpKernel::class, function (HttpKernel $kernel, Application $app) {
                $kernel->whenRequestLifecycleIsLongerThan(-1, function () use ($app) { // @phpstan-ignore method.notFound
                    $app->make(Pulse::class)->ingest();
                    $this->flushJobs($app);
                    $this->flushTraces($app);
                });
            });

            $this->callAfterResolving(ConsoleKernel::class, function (ConsoleKernel $kernel, Application $app) {
                $kernel->whenCommandLifecycleIsLongerThan(-1, function () use ($app) { // @phpstan-ignore method.notFound
                    $app->make(Pulse::class)->ingest();
                    $this->flushJobs($app);
                    $this->flushTraces($app);
                });
            });
        });

        // Under a lock, so the rules are checked once across the fleet rather
        // than once per server. An alert is a thing that should fire once.
        $this->callAfterResolving(Dispatcher::class, function (Dispatcher $event, Application $app) {
            $event->listen(IsolatedBeat::class, EvaluateAlerts::class);
        });

        $this->callAfterResolving(Dispatcher::class, function (Dispatcher $event, Application $app) {
            $event->listen([
                RequestReceived::class, // @phpstan-ignore class.notFound
                TaskReceived::class, // @phpstan-ignore class.notFound
                TickReceived::class, // @phpstan-ignore class.notFound
            ], function ($event) {
                if ($event->sandbox->resolved(Pulse::class)) {
                    $event->sandbox->make(Pulse::class)->setContainer($event->sandbox);
                }
            });
        });
    }

    /**
     * Write any buffered job records.
     *
     * Recorded jobs do not travel through Pulse's ingest — an Entry has
     * nowhere to put a payload or a stack trace — so the repository keeps its
     * own buffer and is emptied on the same signals.
     */
    protected function flushJobs(Application $app): void
    {
        $pulse = $app->make(Pulse::class);

        $pulse->rescue(function () use ($app, $pulse) {
            $repository = $app->make(JobRepository::class);

            $pulse->ignore($repository->flush(...));

            // Trimmed on the same lottery the ingest uses, so retention costs
            // one delete every so often rather than one per flush.
            $odds = $app->make('config')->get('pulse-boosted.ingest.trim.lottery') ?? [1, 1_000];

            Lottery::odds(...$odds)
                ->winner(fn () => $pulse->ignore($repository->trim(...)))
                ->choose();
        });
    }

    /**
     * Close the current trace and write whatever is buffered.
     *
     * A request has no event of its own to say it finished, so this runs on
     * the same lifecycle hooks Pulse uses for its ingest.
     */
    protected function flushTraces(Application $app): void
    {
        $pulse = $app->make(Pulse::class);

        $pulse->rescue(function () use ($app) {
            $tracer = $app->make(Tracer::class);

            $tracer->finish();
            $tracer->flush();

            $issues = $app->make(IssueRepository::class);
            $issues->flush();

            $alerts = $app->make(AlertManager::class);

            $odds = $app->make('config')->get('pulse-boosted.ingest.trim.lottery') ?? [1, 1_000];

            Lottery::odds(...$odds)
                ->winner(function () use ($tracer, $issues, $alerts) {
                    $tracer->trim();
                    $issues->trim();
                    $alerts->trim();
                })
                ->choose();
        });
    }

    /**
     * Register the package's components.
     */
    protected function registerComponents(): void
    {
        $this->callAfterResolving('blade.compiler', function (BladeCompiler $blade) {
            $blade->anonymousComponentPath(__DIR__.'/../resources/views/components', 'pulse-boosted');
        });

        $this->callAfterResolving('livewire', function (LivewireManager $livewire, Application $app) {
            $middleware = collect($app->make('config')->get('pulse-boosted.middleware')) // @phpstan-ignore argument.templateType, argument.templateType
                ->map(fn ($middleware) => is_string($middleware)
                    ? Str::before($middleware, ':')
                    : $middleware)
                ->all();

            $livewire->addPersistentMiddleware($middleware);

            $livewire->component('pulse-boosted.cache', Livewire\Cache::class);
            $livewire->component('pulse-boosted.usage', Livewire\Usage::class);
            $livewire->component('pulse-boosted.queues', Livewire\Queues::class);
            $livewire->component('pulse-boosted.servers', Livewire\Servers::class);
            $livewire->component('pulse-boosted.slow-jobs', Livewire\SlowJobs::class);
            $livewire->component('pulse-boosted.exceptions', Livewire\Exceptions::class);
            $livewire->component('pulse-boosted.slow-requests', Livewire\SlowRequests::class);
            $livewire->component('pulse-boosted.slow-queries', Livewire\SlowQueries::class);
            $livewire->component('pulse-boosted.period-selector', Livewire\PeriodSelector::class);
            $livewire->component('pulse-boosted.slow-outgoing-requests', Livewire\SlowOutgoingRequests::class);
            $livewire->component('pulse-boosted.jobs', Livewire\Jobs::class);
            $livewire->component('pulse-boosted.queue-status', Livewire\QueueStatus::class);
            $livewire->component('pulse-boosted.workers', Livewire\Workers::class);
            $livewire->component('pulse-boosted.traces', Livewire\Traces::class);
            $livewire->component('pulse-boosted.issues', Livewire\Issues::class);
            $livewire->component('pulse-boosted.logs', Livewire\Logs::class);
            $livewire->component('pulse-boosted.mail', Livewire\Mail::class);
            $livewire->component('pulse-boosted.notifications', Livewire\Notifications::class);
            $livewire->component('pulse-boosted.commands', Livewire\Commands::class);
            $livewire->component('pulse-boosted.scheduled-tasks', Livewire\ScheduledTasks::class);
            $livewire->component('pulse-boosted.alerts', Livewire\Alerts::class);
        });
    }

    /**
     * Register the package's resources.
     */
    protected function registerResources(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'pulse-boosted');
    }

    /**
     * Register the package's publishable resources.
     */
    protected function registerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/pulse-boosted.php' => config_path('pulse-boosted.php'),
            ], ['pulse-boosted', 'pulse-boosted-config']);

            $this->publishes([
                __DIR__.'/../resources/views/dashboard.blade.php' => resource_path('views/vendor/pulse-boosted/dashboard.blade.php'),
            ], ['pulse-boosted', 'pulse-boosted-dashboard']);

            $method = method_exists($this, 'publishesMigrations') ? 'publishesMigrations' : 'publishes';

            $this->{$method}([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], ['pulse-boosted', 'pulse-boosted-migrations']);
        }
    }

    /**
     * Register the package's commands.
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\WorkCommand::class,
                Commands\CheckCommand::class,
                Commands\RestartCommand::class,
                Commands\ClearCommand::class,
                Commands\AlertsCommand::class,
            ]);

            AboutCommand::add('Pulse Boosted', fn () => [
                'Version' => InstalledVersions::getPrettyVersion('elazaroo/pulse-boosted'),
                'Enabled' => AboutCommand::format(config('pulse-boosted.enabled'), console: fn ($value) => $value ? '<fg=yellow;options=bold>ENABLED</>' : 'OFF'),
            ]);

            if (method_exists($this, 'reloads')) {
                $this->reloads('pulse-boosted:restart', 'pulse-boosted');
            }
        }
    }
}
