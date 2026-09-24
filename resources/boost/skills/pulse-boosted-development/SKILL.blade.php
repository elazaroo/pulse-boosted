---
name: pulse-boosted-development
description: "Handles Pulse Boosted setup, configuration, and custom card development. Activates when installing Pulse Boosted; configuring the dashboard, its gates or the settings page; choosing where the job history is kept; setting up recorders, traces, issues, alerts or webhooks; building custom Livewire cards; or when the user mentions /pulse-boosted, pulse-boosted:install, pulse-boosted:check, pulse-boosted:work, PulseBoosted::record(), or application monitoring."
license: MIT
metadata:
  author: elazaroo
---
@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp
# Pulse Boosted Development

Pulse Boosted (`elazaroo/pulse-boosted`) is a fork of Laravel Pulse with SQL Server support, traces, issues, alerts, webhooks and per-job queue observability. Everything it owns is named after itself — the `Elazaroo\PulseBoosted\` namespace, `config/pulse-boosted.php`, `PULSE_BOOSTED_*` variables, `pulse_boosted_*` tables, `pulse-boosted:*` commands — so it can run next to `laravel/pulse` without colliding.

## Installation

Pulse Boosted stores data in the application's database: MySQL, MariaDB, PostgreSQL, SQLite or SQL Server.

```bash
composer require elazaroo/pulse-boosted
{{ $assist->artisanCommand('pulse-boosted:install') }}
```

The installer publishes the config, asks whether the queues run on Redis, writes the answer to `.env` as `PULSE_BOOSTED_JOBS_STORAGE`, and publishes only the migrations that answer needs: with Redis, the job history lives there and `pulse_boosted_jobs` and `pulse_boosted_job_attempts` are not created. In scripts, answer up front:

```bash
{{ $assist->artisanCommand('pulse-boosted:install --redis --connection=default --migrate --no-interaction') }}
{{ $assist->artisanCommand('pulse-boosted:install --database --migrate --no-interaction') }}
```

Do not publish the migrations with `vendor:publish` instead: it would add the job history tables whatever the choice.

The dashboard is at `/pulse-boosted` (`PULSE_BOOSTED_PATH`), and most settings can be changed at `/pulse-boosted/settings`.

## Authorization

Two gates, defined in `AppServiceProvider::boot()`:

@boostsnippet("Pulse Boosted Gates", "php")
use App\Models\User;
use Illuminate\Support\Facades\Gate;

// Reading the dashboard. Without it, only the local environment can.
Gate::define('viewPulseBoosted', function (User $user) {
    return $user->isAdmin();
});

// Changing things: queue actions, issues, the settings page. Denied unless defined.
Gate::define('managePulseBoostedQueues', function (User $user) {
    return $user->isAdmin();
});
@endboostsnippet

## Processes to run

- `pulse-boosted:check` on every server, under a process manager: server stats, worker heartbeats, alert rules, trimming.
- `pulse-boosted:work` only with `PULSE_BOOSTED_INGEST_DRIVER=redis`, to drain the Redis stream into the database.
- `pulse-boosted:restart` after deploying, to restart both (needs a working cache driver).
- `pulse-boosted:deploy <version>` in the deploy script, optionally, to mark the deployment.

## Recorders

Recorders are configured under `recorders` in `config/pulse-boosted.php`: `CacheInteractions`, `Exceptions`, `Issues`, `Jobs`, `Queues`, `Servers`, `SlowJobs`, `SlowOutgoingRequests`, `SlowQueries`, `SlowRequests`, `Traces`, `UserJobs`, `UserRequests` and `Workers`. Most take `sample_rate` and `ignore`; the slow ones take a `threshold` map keyed by regex with a `default`:

@boostsnippet("Per-Route Threshold Override", "php")
Recorders\SlowRequests::class => [
    'threshold' => [
        '#^/api/reports#' => 5000,
        'default' => env('PULSE_BOOSTED_SLOW_REQUESTS_THRESHOLD', 1000),
    ],
],
@endboostsnippet

Thresholds saved on the settings page win over the config file, field by field.

Job arguments are only captured with `PULSE_BOOSTED_JOBS_CAPTURE_PAYLOAD=true`, and keys in the Jobs recorder's `redact` list are replaced before storage.

### Filtering entries

@boostsnippet("Pulse Boosted Entry Filter", "php")
use Elazaroo\PulseBoosted\Entry;
use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Value;
use Illuminate\Support\Facades\Auth;

Pulse::filter(function (Entry|Value $entry) {
    return Auth::user()?->isNotAdmin() ?? true;
});
@endboostsnippet

## Traces

Requests, commands, scheduled tasks and jobs are traced with their queries, cache operations, jobs, HTTP calls, exceptions, logs, mail and notifications. Sampling is decided per execution (`traces.sample_rates`), but failures, exceptions and slow executions are kept anyway.

@boostsnippet("Trace Context and Sampling", "php")
use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Http\Middleware\Sample;

// Searchable attributes on the current trace; a no-op when it is not sampled.
Pulse::context(['tenant' => $tenant->id, 'order' => $order->id]);

// Per-route sampling.
Route::post('/checkout', CheckoutController::class)->middleware(Sample::always());
Route::get('/health', HealthController::class)->middleware(Sample::never());

// Leave noisy events out.
Pulse::rejectQueries(fn (string $sql) => str_contains($sql, 'telescope_'));
@endboostsnippet

## Issues, alerts and notifications

- Exceptions, logged warnings and errors, and executions over `issues.thresholds` become issues, which can be resolved, ignored and assigned; a resolved one that comes back reopens.
- Alert rules live under `alerts.rules`, and each is a metric, a threshold and a window. They fire `AlertTriggered` and `AlertResolved`. `pulse-boosted:alerts --dry-run` shows what each rule reads.
- Email recipients and webhooks (Slack, Discord, Microsoft Teams, Google Chat, Mattermost, Telegram, signed JSON) are managed on the settings page and stored in the database, encrypted — never in `.env`.
- For any other channel, listen for `IssueOpened`, `IssueRegressed`, `IssueAssigned`, `AlertTriggered`, `AlertResolved` or `ScheduledTaskMissed` in `Elazaroo\PulseBoosted\Events`.

## Custom Cards

Record entries from a recorder, listener or observer, chaining the aggregations:

@boostsnippet("Record Pulse Boosted Entry", "php")
use Elazaroo\PulseBoosted\Facades\Pulse;

Pulse::record('user_sale', $user->id, $sale->amount)
    ->sum()
    ->count();
@endboostsnippet

When the entry is tied to the signed-in user, use `Pulse::resolveAuthenticatedUserId()` rather than `Auth::id()`, so custom user resolvers are respected.

@boostsnippet("Custom Pulse Boosted Card", "php")
namespace App\Livewire\Pulse;

use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Livewire\Card;
use Livewire\Attributes\Lazy;

#[Lazy]
class TopSellers extends Card
{
    public function render(): \Illuminate\View\View
    {
        $aggregates = $this->aggregate('user_sale', ['sum', 'count']);
        $users = Pulse::resolveUsers($aggregates->pluck('key'));

        return view('livewire.pulse.top-sellers', [
            'sellers' => $aggregates->map(fn ($row) => (object) [
                'user' => $users->find($row->key),
                'sum' => $row->sum,
                'count' => $row->count,
            ]),
        ]);
    }
}
@endboostsnippet

Wrap the view in `<x-pulse-boosted::card>`, and add the card to a published dashboard with `{{ $assist->artisanCommand('vendor:publish --tag=pulse-boosted-dashboard') }}`, which writes `resources/views/vendor/pulse-boosted/dashboard.blade.php`.

A recorder is a plain class with a `$listen` array of events and a `record()` method. Register it under `recorders` in `config/pulse-boosted.php`.

## Common Pitfalls

- An empty dashboard or database errors usually mean `pulse-boosted:install` was not run, or its migrations were not run.
- Nothing on the Servers, Workers or Alerts cards means `pulse-boosted:check` is not running.
- With `PULSE_BOOSTED_INGEST_DRIVER=redis`, nothing reaches the dashboard until `pulse-boosted:work` runs.
- With the job history in Redis, keep the connection's `maxmemory-policy` at `noeviction` or a `volatile-*` policy, or history can be evicted early.
- Pulse Boosted swallows its own exceptions. Use `Pulse::handleExceptionsUsing()` to surface them during development.
- Trace-based figures are sampled: values are approximate and prefixed with `~`.
