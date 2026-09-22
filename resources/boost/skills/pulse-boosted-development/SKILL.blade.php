---
name: pulse-development
description: "Handles Laravel Pulse setup, configuration, and custom card development. Activates when installing Pulse; configuring the dashboard or authorization gate; setting up recorders and filtering; building custom Livewire cards; optimizing with Redis ingest or sampling; or when the user mentions /pulse, pulse-boosted:check, pulse-boosted:work, Pulse::record(), or application monitoring."
license: MIT
metadata:
  author: laravel
---
@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp
# Laravel Pulse Development

## Documentation

Use `search-docs` for detailed Laravel Pulse patterns and documentation, including card layout customization, user resolver configuration, all recorder options, sampling, dedicated database connections, Vite/CSS integration, Tailwind scoping, blade card components, and lazy loading.

## Installation

Pulse stores data in your application's database. The current package supports MySQL, MariaDB, PostgreSQL, and SQLite.

```bash
composer require laravel/pulse
{{ $assist->artisanCommand('vendor:publish --provider="Elazaroo\PulseBoosted\PulseServiceProvider"') }}
{{ $assist->artisanCommand('migrate') }}
```

The dashboard is available at `/pulse`. 

## Dashboard Authorization

Define the `viewPulseBoosted` gate in `AppServiceProvider::boot()` to enable production access:

@boostsnippet("Pulse Dashboard Authorization", "php")
use App\Models\User;
use Illuminate\Support\Facades\Gate;

Gate::define('viewPulseBoosted', function (User $user) {
    return $user->isAdmin();
});
@endboostsnippet

Without this gate, the dashboard is inaccessible in all non-local environments.

## Recorders

All 10 built-in recorders are configurable in `config/pulse-boosted.php`:

| Recorder | Key Config Options |
|---|---|
| `CacheInteractions` | `sample_rate`, `ignore`, `groups` (regex find/replace) |
| `Exceptions` | `sample_rate`, `ignore`, `location` |
| `Queues` | `sample_rate`, `ignore` |
| `SlowJobs` | `threshold` (ms, per-job regex map), `sample_rate`, `ignore` |
| `SlowOutgoingRequests` | `threshold` (ms, per-URL regex map), `sample_rate`, `ignore`, `groups` |
| `SlowQueries` | `threshold` (ms, per-query regex map), `sample_rate`, `ignore`, `location` |
| `SlowRequests` | `threshold` (ms, per-route regex map), `sample_rate`, `ignore` |
| `Servers` | `PULSE_BOOSTED_SERVER_NAME` env var, monitored disk paths |
| `UserJobs` | `sample_rate`, `ignore` |
| `UserRequests` | `sample_rate`, `ignore` |

Per-route and per-job threshold overrides use a regex-keyed map with a `default` fallback:

@boostsnippet("Per-Route Threshold Override", "php")
Recorders\SlowRequests::class => [
    'threshold' => [
        '#^/api/reports#' => 5000,
        'default'         => env('PULSE_BOOSTED_SLOW_REQUESTS_THRESHOLD', 1000),
    ],
],
@endboostsnippet

The `Servers` recorder requires `pulse-boosted:check` running as a persistent daemon (Supervisor recommended).

### Filtering Entries

Use `Pulse::filter()` in `AppServiceProvider::boot()` to exclude entries globally. Return `true` to record, `false` to skip:

@boostsnippet("Pulse Entry Filter", "php")
use Elazaroo\PulseBoosted\Entry;
use Elazaroo\PulseBoosted\Facades\Pulse;
use Elazaroo\PulseBoosted\Value;
use Illuminate\Support\Facades\Auth;

Pulse::filter(function (Entry|Value $entry) {
    return Auth::user()?->isNotAdmin() ?? true;
});
@endboostsnippet

## Performance

### Redis Ingest

Offload entry writes from the request cycle to a Redis stream (requires Redis 6.2+ and `phpredis` or `predis`):

```ini
PULSE_BOOSTED_INGEST_DRIVER=redis
PULSE_BOOSTED_REDIS_CONNECTION=pulse
```

Run a worker to drain the Redis stream into the database:

```bash
{{ $assist->artisanCommand('pulse-boosted:work') }}
```

Signal a graceful restart during deployment (requires a working cache driver):

```bash
{{ $assist->artisanCommand('pulse-boosted:restart') }}
```

## Custom Cards

Custom cards are Livewire components extending Pulse's base `Card` class.

### Recording Entries

Call `Pulse::record()` from a recorder, listener, or observer. Chain aggregation methods (`avg`, `count`, `max`, `min`, `sum`) in a single call:

@boostsnippet("Record Pulse Entry", "php")
use Elazaroo\PulseBoosted\Facades\Pulse;

Pulse::record('user_sale', $user->id, $sale->amount)
    ->sum()
    ->count();
@endboostsnippet

When the entry is tied to the authenticated user, use `Pulse::resolveAuthenticatedUserId()` instead of `Auth::id()` to respect custom user resolvers.

### Card Component

@boostsnippet("Custom Pulse Card", "php")
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
                'user'  => $users->find($row->key),
                'sum'   => $row->sum,
                'count' => $row->count,
            ]),
        ]);
    }
}
@endboostsnippet

`$this->aggregate(type, aggregates)` returns a `Collection` of `stdClass` objects with `key` and one property per aggregation method. `$this->aggregateTotal(type, aggregate)` returns a single scalar.

### Custom Recorders

A recorder is a plain class with a `$listen` array of Laravel events:

@boostsnippet("Custom Pulse Recorder", "php")
class SaleRecorder
{
    public array $listen = [
        \App\Events\SaleCompleted::class,
    ];

    public function record(\App\Events\SaleCompleted $event): void
    {
        \Elazaroo\PulseBoosted\Facades\Pulse::record('user_sale', $event->user->id, $event->sale->amount)
            ->sum()
            ->count();
    }
}
@endboostsnippet

Register the recorder in the `recorders` array in `config/pulse-boosted.php`.

## Verification

1. Run migrations and confirm `/pulse` is accessible in local
2. Define `viewPulseBoosted` gate and verify production access
3. Confirm `pulse-boosted:check` is running for the Servers card
4. If using Redis ingest, confirm `pulse-boosted:work` is running

## Common Pitfalls

- An empty dashboard or database errors usually mean the Pulse tables have not been published and migrated yet.
- The dashboard is local-only by default. Define the `viewPulseBoosted` gate to enable production access.
- The Servers card shows no data unless `pulse-boosted:check` runs as a persistent process. Supervisor is recommended.
- Redis ingest silently queues data. The dashboard appears empty if `pulse-boosted:work` is not running.
- `pulse-boosted:restart` requires a working cache driver. Without it, the signal is never received.
- Pulse exceptions fail silently. Use `Pulse::handleExceptionsUsing()` to surface errors during development.
- Multiple `Authenticatable` models can cause incorrect user tracking. Use `Pulse::resolveAuthenticatedUserId()` when recording user-keyed entries.
- SQS queues may appear duplicated in the Queue card. Use `ignore` regex patterns to suppress them.
- Sampled dashboard values are approximate and prefixed with `~`. They are not suitable for financial or audit reporting.
- Always use `search-docs` for the latest Pulse documentation rather than relying on this skill alone.
