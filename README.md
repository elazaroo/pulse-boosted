# Pulse Boosted

Pulse Boosted is a fork of [Laravel Pulse](https://github.com/laravel/pulse) that adds first-class SQL Server support and deep queue and job observability.

It is a standalone package, not a drop-in replacement. Its namespace, config file, routes, commands, environment variables and database tables are all its own, so it can be installed alongside Laravel Pulse without colliding with it.

## Why this fork exists

Laravel Pulse is excellent at aggregated metrics, but two things were missing for our use:

**SQL Server.** Pulse supports MySQL, MariaDB, PostgreSQL and SQLite. Pulse Boosted adds `sqlsrv` to the storage driver and the migrations, so the dashboard runs against SQL Server without a bridging package.

**Per-job visibility.** Pulse records queue activity as bucketed counters — how many jobs were queued, processed or failed in a period. It cannot tell you *which* job failed, what arguments it was given, how many times it was retried, or what the stack trace was. Pulse Boosted records one row per job and gives you a queue explorer and a job detail view on top of it.

## Requirements

- PHP 8.1+
- Laravel 10.48.4+, 11.0.8+, 12.x or 13.x
- Livewire 3.6.4+ or 4.x

The queue observability features require **Laravel 13+**, which is the first version to expose `pendingSize()`, `delayedSize()` and `reservedSize()` on the queue contract. On earlier versions the dashboard falls back to recorded event data and hides the live queue counters.

## Installation

```sh
composer require elazaroo/pulse-boosted
```

Publish and run the migrations:

```sh
php artisan vendor:publish --tag=pulse-boosted-migrations
php artisan migrate
```

The dashboard is then available at `/pulse-boosted`.

## Configuration

Publish the config file if you need to change anything:

```sh
php artisan vendor:publish --tag=pulse-boosted-config
```

Every option is also settable through the environment. The most common ones:

| Variable | Default | Purpose |
| --- | --- | --- |
| `PULSE_BOOSTED_ENABLED` | `true` | Master switch for all recorders |
| `PULSE_BOOSTED_PATH` | `pulse-boosted` | Dashboard path |
| `PULSE_BOOSTED_DOMAIN` | `null` | Dashboard subdomain |
| `PULSE_BOOSTED_DB_CONNECTION` | app default | Connection holding the `pulse_boosted_*` tables |
| `PULSE_BOOSTED_INGEST_DRIVER` | `storage` | `storage`, `redis` or `null` |

### SQL Server

Point the storage connection at a `sqlsrv` connection and the migrations will build the SQL Server schema:

```env
PULSE_BOOSTED_DB_CONNECTION=sqlsrv
```

SQL Server has no generated-column equivalent of the `key_hash` trick used on MySQL and PostgreSQL, so the hash is computed in PHP and stored as a `char(32)`. This is transparent to you, but it is why the schema differs slightly per driver.

## Authorization

By default the dashboard is only reachable from the `local` environment. To open it elsewhere, define the `viewPulseBoosted` gate:

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;

Gate::define('viewPulseBoosted', function (User $user) {
    return $user->isAdministrator();
});
```

Actions that change queue state — retrying a job, deleting one, flushing failed jobs — are behind a **separate** gate, `managePulseBoostedQueues`, which is denied unless you define it. Being able to read the dashboard never implies being able to mutate your queues.

```php
Gate::define('managePulseBoostedQueues', function (User $user) {
    return $user->isAdministrator();
});
```

## Job payloads contain secrets

Queue payloads routinely carry personal data, API tokens and credentials. Because of that, **payload capture is off by default**. When you turn it on, values whose keys match the redaction list are replaced before anything is written:

```php
'jobs' => [
    'capture_payload' => env('PULSE_BOOSTED_JOBS_CAPTURE_PAYLOAD', false),
    'redact' => ['password', 'token', 'secret', 'api_key', 'authorization'],
],
```

Job arguments are captured when the job is *queued*, by reflecting over the live object, and stored as JSON. Pulse Boosted never calls `unserialize()` on the stored payload when reading it back — doing so would mean executing code derived from database contents.

## Commands

| Command | Purpose |
| --- | --- |
| `pulse-boosted:check` | Take a snapshot of the current server |
| `pulse-boosted:work` | Process the ingest stream |
| `pulse-boosted:restart` | Signal workers to restart |
| `pulse-boosted:clear` | Purge stored data |

## Relationship to Laravel Pulse

This fork keeps Laravel Pulse's architecture: recorders write entries, an ingest buffers them, a storage driver aggregates them into buckets, and Livewire cards read the aggregates. New functionality lives in its own directories rather than being woven into the upstream files.

Upstream releases are ported by hand. The `1.x` branch in this repository tracks `laravel/pulse` unchanged and exists to generate those diffs.

## Credits

Pulse Boosted is derived from [Laravel Pulse](https://github.com/laravel/pulse) by Taylor Otwell and the Laravel contributors, and remains under the same licence. Laravel and Laravel Pulse are trademarks of their respective owners; this project is not affiliated with or endorsed by them.

## Licence

MIT. See [LICENSE.md](LICENSE.md).
