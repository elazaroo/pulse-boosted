<?php

namespace Elazaroo\PulseBoosted;

use Composer\InstalledVersions;
use Elazaroo\PulseBoosted\Contracts\Ingest;
use Elazaroo\PulseBoosted\Contracts\ResolvesUsers;
use Elazaroo\PulseBoosted\Contracts\Storage;
use Elazaroo\PulseBoosted\Ingests\NullIngest;
use Elazaroo\PulseBoosted\Ingests\RedisIngest;
use Elazaroo\PulseBoosted\Ingests\StorageIngest;
use Elazaroo\PulseBoosted\Storage\DatabaseStorage;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
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
                });
            });

            $this->callAfterResolving(HttpKernel::class, function (HttpKernel $kernel, Application $app) {
                $kernel->whenRequestLifecycleIsLongerThan(-1, function () use ($app) { // @phpstan-ignore method.notFound
                    $app->make(Pulse::class)->ingest();
                });
            });

            $this->callAfterResolving(ConsoleKernel::class, function (ConsoleKernel $kernel, Application $app) {
                $kernel->whenCommandLifecycleIsLongerThan(-1, function () use ($app) { // @phpstan-ignore method.notFound
                    $app->make(Pulse::class)->ingest();
                });
            });
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
