# Pulse Boosted

Pulse Boosted is a fork of [Laravel Pulse](https://github.com/laravel/pulse) that adds first-class SQL Server support and deep queue and job observability.

It is a standalone package, not a drop-in replacement. Its namespace, config file, routes, commands, environment variables and database tables are all its own, so it can be installed alongside Laravel Pulse without colliding with it.

## Why this fork exists

Laravel Pulse is excellent at aggregated metrics, but two things were missing for our use:

**SQL Server.** Pulse supports MySQL, MariaDB, PostgreSQL and SQLite. Pulse Boosted adds `sqlsrv` to the storage driver and the migrations, so the dashboard runs against SQL Server without a bridging package.

**No way to follow one execution.** Pulse aggregates: it can tell you a route is slow, not which queries made it slow on a given hit. Pulse Boosted records traces — every query, cache read, job, outgoing call, exception and log line tied to the request, command, task or job that caused it.

**Per-job visibility.** Pulse records queue activity as bucketed counters — how many jobs were queued, processed or failed in a period. It cannot tell you *which* job failed, what arguments it was given, how many times it was retried, or what the stack trace was. Pulse Boosted records one row per job and gives you a queue explorer and a job detail view on top of it.

## Requirements

- PHP 8.1+
- Laravel 10.48.4+, 11.0.8+, 12.x or 13.x
- Livewire 3.6.4+ or 4.x

Pausing queues from the dashboard needs **Laravel 13**, which is where `queue:pause` and the API behind it landed; on older versions the buttons are not shown. Recorded job history works on every supported version. Live queue counters do too for the `database` and `redis` drivers, which are read directly. For `sqs` and `beanstalkd` the counters come from `pendingSize()`, `delayedSize()` and `reservedSize()`, which Laravel added to the queue contract in **13.0**; on older versions those two drivers show their counts as unknown rather than guessing.

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

Actions that change queue state — pausing a queue, restarting workers, retrying a job, deleting one, flushing failed jobs — are behind a **separate** gate, `managePulseBoostedQueues`, which is denied unless you define it. Being able to read the dashboard never implies being able to mutate your queues.

```php
Gate::define('managePulseBoostedQueues', function (User $user) {
    return $user->isAdministrator();
});
```

## Job payloads contain secrets

Queue payloads routinely carry personal data, API tokens and credentials. Because of that, **payload capture is off by default**. When you turn it on, values whose keys match the redaction list are replaced before anything is written:

```php
// config/pulse-boosted.php
'recorders' => [
    Recorders\Jobs::class => [
        'capture_payload' => env('PULSE_BOOSTED_JOBS_CAPTURE_PAYLOAD', false),
        'redact' => ['password', 'secret', 'token', 'api_key', 'authorization', /* ... */],
        'trim' => ['keep' => env('PULSE_BOOSTED_JOBS_KEEP', '7 days')],
    ],
],
```

Keys are matched case-insensitively as substrings, so `redact` entry `token` also covers `apiToken` and `refresh_token`.

Job arguments are captured when the job is *queued*, by reflecting over the live object, and stored as JSON. Pulse Boosted never calls `unserialize()` on the stored payload when reading it back — doing so would mean executing code derived from database contents.

## Traces

Pulse counts things: how many exceptions this hour, how many slow queries. That
tells you something is wrong, not what led to it. A trace is the other half.

When a request, an Artisan command, a scheduled task or a queued job runs, it
becomes an **execution context**, and everything that happens inside it —
queries, cache reads, jobs it dispatched, outgoing HTTP calls, exceptions, log
lines, mail, notifications — is recorded against it, with the offset at which
it happened. The Traces card lists executions; opening one draws the timeline.

A job queued during a request carries the trace id on its payload, so when a
worker picks it up in another process minutes later, the two are still joined.
Opening a failed job's trace shows both the job and the request that asked for
the work.

### The cost, and how it is kept down

A trace is a row per event, which is far more than an aggregate. Three things
keep that in hand:

- **Sampling happens at the entry point.** An execution is recorded whole or
  not at all — half a trace is worse than none, because the gaps read as time
  the application spent idle. The default is 10% of requests, 50% of jobs, and
  everything for commands and scheduled tasks, which are rare.
- **A per-trace cap** of 500 events, so a loop that queries a thousand times
  writes 500 rows and the timeline says how many it dropped.
- **A day's retention**, against a week for the aggregates.

```php
// config/pulse-boosted.php
'traces' => [
    'enabled' => env('PULSE_BOOSTED_TRACES_ENABLED', true),
    'sample_rate' => env('PULSE_BOOSTED_TRACES_SAMPLE_RATE', 0.1),
    'sample_rates' => [
        'request' => 0.1,
        'job' => 0.5,
        'command' => 1.0,
        'schedule' => 1.0,
    ],
    'max_events' => 500,
    'log_level' => env('PULSE_BOOSTED_TRACES_LOG_LEVEL', 'debug'),
    'trim' => ['keep' => env('PULSE_BOOSTED_TRACES_KEEP', '24 hours')],
],
```

To keep a noisy block out of a timeline entirely:

```php
use Elazaroo\PulseBoosted\Traces\Tracer;

app(Tracer::class)->ignore(function () {
    // Nothing in here is recorded.
});
```

Log lines are picked up from Laravel's logger as they are written — there is no
channel to add to `LOG_STACK`. Set `log_level` to `error` if you only want the
ones that matter.
## On the dashboard

Two cards come with the fork, alongside the ones Pulse already has.

**Queue Status** lists every queue with its live counts, says which are paused,
and lets you pause or resume one without leaving the page. Each queue name
links into the explorer, already filtered.

**Workers** shows the workers that are running: what each is working on, how
many jobs it has finished, its memory, and when it was last heard from. Laravel
does not track workers, so this reads a heartbeat each one writes as it loops.
A worker that shuts down cleanly reports itself as stopped; one that is killed
outright cannot, so it is shown as stale once it goes quiet. There is also a
button to restart them all, which tells each to finish its current job and exit
for your process manager to bring back.
## The queue explorer

`/pulse-boosted/queues` reads from two places and says which is which.

**Live tabs** — Waiting, Delayed and Running — come from the queue backend itself, so they show what is on the queue at this moment. They are only offered by drivers that can be listed.

**Recorded tabs** — Completed and Failed — come from `pulse_boosted_jobs`. The backend deletes a job the moment it finishes, so this is the only place a completed job exists, and the only way to see anything at all on `sync`.

Clicking a recorded job opens `/pulse-boosted/jobs/{uuid}`: its timeline, its arguments if you captured them, the exception with its stack trace, and how many attempts it had left.

| Driver | Counts | Listing |
| --- | --- | --- |
| `database` | yes | yes |
| `redis` | yes | yes, except on cluster |
| `sqs` | yes, approximate, Laravel 13+ | no — reading an SQS queue means receiving its messages, which would hide them from your workers |
| `beanstalkd` | yes, Laravel 13+ | no |
| `sync`, `null` | nothing waits on these | — |

Listing never pops: the database inspector runs a `SELECT` and the Redis one uses `LRANGE` and `ZRANGE`, so opening the explorer cannot lose a job.

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
