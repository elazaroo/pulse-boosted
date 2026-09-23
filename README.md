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

### The settings page

Most of what changes after launch — who is emailed, where webhooks go, what counts as slow, the sample rates, the alert rules — can also
be changed at `/pulse-boosted/settings`, the cog in the header, without a
deploy:

- **Email** — recipients, regressions, the assignee, and the least severe
  logged issue worth sending.
- **Webhooks** — Slack, Discord, Microsoft Teams, Google Chat, Mattermost,
  Telegram or any URL, each with its events, a test button and how its last
  delivery went; see [Webhooks](#webhooks).
- **Issues** — which log lines become issues, resolving quiet ones, and the
  people offered when assigning.
- **Thresholds** — the per-route, per-job, per-command and per-task thresholds
  that open performance issues, the thresholds of the Slow Requests, Slow
  Queries, Slow Jobs and Slow Outgoing Requests cards, and how slow a trace has
  to be to be kept.
- **Traces** — the sample rate overall and per kind, and what is always kept.
- **Alerts** — the rules: a metric, above or below a threshold, over a window,
  with options.
- **Scheduled tasks** — watching the schedule for missed tasks.

What is saved there wins over the config file and `.env`, field by field, and
each field says whether it is set on the page or comes from config; *use
config* hands it back. Saving a value equal to what config says removes the
override rather than storing a copy. Every server picks a change up within a
minute, workers and `pulse-boosted:check` included. Settings are read from
the cache on each request, not the database.

Anyone who can see the dashboard can read the page; changing anything needs
the `managePulseBoostedQueues` gate.

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

A request's timeline is split into the stages of its lifecycle — Bootstrap,
Middleware, Controller, Render, Middleware on the way out, Sending and
Terminating — with each query, cache operation and job drawn inside the stage
it happened in. Each query notes the line in the application that ran it. The
panel leads with how many of each kind of event there were and the peak
memory, and a request that ended in a 5xx keeps its headers and query string
(credentials replaced) and, if `traces.request.capture_payload` is on, its
body with passwords, tokens and card fields replaced by their length.

Workers, schedulers and other commands that host executions are never traced
themselves, so each job and scheduled task inside them gets its own trace.
Commands that run constantly and say nothing, such as `horizon:snapshot`, are
left out unless `traces.capture_vendor_commands` is set.

### The cost, and how it is kept down

A trace is a row per event, which is far more than an aggregate. Three things
keep that in hand:

- **Sampling happens at the entry point.** An execution is recorded whole or
  not at all — half a trace is worse than none, because the gaps read as time
  the application spent idle. The default is 10% of requests, 50% of jobs, and
  everything for commands and scheduled tasks, which are rare.
- **The verdict waits until the end.** Every execution is recorded in memory
  as it runs; the sample decides afterwards whether it is written. One that
  lost the draw is written anyway if it failed, threw — even an exception the
  application caught — or ran slower than `traces.keep.slower_than`
  milliseconds, because those are the traces you come looking for. They are
  marked *kept* in the list, and left out of error rates and percentiles,
  which are worked out from sampled executions alone so keeping every failure
  cannot make the error rate lie.
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

### Attaching your own context

A trace says what the application did. What it cannot know is what it was doing
it *about* — which tenant, which order, which feature flag was on. Slow requests
all look alike until one of them is carrying an order id:

```php
use Elazaroo\PulseBoosted\Facades\Pulse as PulseBoosted;

PulseBoosted::context([
    'tenant' => $tenant->id,
    'order' => $order->id,
    'plan' => $tenant->plan,
]);
```

It is safe to call anywhere. When the execution was not sampled in, or tracing
is off, it does nothing — your code does not have to ask first.

What you attach is shown on the trace's timeline, and the search box on the
Traces card matches it, so pasting an order id finds the request that handled
it. Values are reduced to something a column can hold: scalars are kept, enums
become their value, dates become ISO strings, arrays become JSON, objects
become their class name, and anything long is truncated. An execution may carry
25 attributes; past that, new keys are dropped while existing ones can still be
updated, so a loop cannot write an unbounded row.

### Choosing what is kept

A route can be traced at its own rate:

```php
use Elazaroo\PulseBoosted\Http\Middleware\Sample;

Route::post('/checkout', CheckoutController::class)->middleware(Sample::always());
Route::get('/health', HealthController::class)->middleware(Sample::never());
Route::get('/search', SearchController::class)->middleware(Sample::rate(0.01));
```

A failure on a route that is never sampled is still kept.

Events can be left out, or rewritten before they are kept:

```php
PulseBoosted::rejectQueries(fn (string $sql) => str_contains($sql, 'telescope_'));
PulseBoosted::rejectCacheKeys(['/^rate-limit:/']);
PulseBoosted::rejectOutgoingRequests(fn (string $url) => str_contains($url, 'metrics.internal'));
PulseBoosted::redactOutgoingRequests(fn (string $url) => preg_replace('/token=[^&]+/', 'token=***', $url));

// Or any kind of event: query, cache, http, log, mail, notification, job.
PulseBoosted::rejectTraceEvents('log', fn (string $message) => str_starts_with($message, 'Heartbeat'));
PulseBoosted::redactTraceEvents('log', fn (string $message) => preg_replace('/\d{16}/', '****', $message));
```

A reject callback returns true to leave the event out. A redact callback
returns the new label, a `[label, meta]` pair, or null to leave it alone.

### Deployments

Traces and issues are tagged with the version that was running, read from
`PULSE_BOOSTED_DEPLOY` or from whatever Laravel Cloud, Forge or Vapor already
sets. The first time a version reports in is kept as when it was deployed; a
deploy script can mark the moment itself with `php artisan pulse-boosted:deploy
<version>`. The Overview says what is deployed and how many issues are new
since, and issues first seen in the latest deploy are marked *New*.

## On the dashboard

The dashboard is split into sections — Overview (with Servers), Queues & jobs,
Traces, Errors & logs, Performance, Mail & notifications, Users — and shows one
at a time, picked from the sidebar, or from a strip of tabs on a narrow screen.
The section is in the address (`/pulse-boosted#errors`), so it can be linked to
and survives a reload. The navigation is built from the
`<x-pulse-boosted::section>` headings on the page: each owns the cards after it,
so a published dashboard gets whatever sections it defines, and one that
defines none shows every card at once, as before.

Only the section on screen costs anything. Its cards load together in one
request as they come into view, and only refresh while they are visible; cards
in the other sections do neither until you open them. The trace timeline is
part of the page rather than of the Traces card, so a trace opens from any card
in any section.

The page's scripts and stylesheet are served from
`/pulse-boosted/assets/...`, versioned by when each file last changed and
cached by the browser for good, rather than inlined in every page. In
production, the usual Laravel advice is what makes the rest fast: OPcache on,
and `php artisan config:cache`, `route:cache` and `view:cache` when deploying.
Xdebug loaded, even when idle, makes every request several times slower.

**Overview** opens the page with seven figures for the selected period: error
rate, p95 request time, exceptions, open issues, failed jobs, queue backlog and
alerts breaching now. Each is read exactly as an alert rule would read it, turns
red past the point where it probably needs looking at, and links to the section
that explains it.

These cards come with the fork, alongside the ones Pulse already has.

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

**Traces** lists executions and draws the timeline of whichever one you open.

**Issues** is everything that went wrong, in one place, seen two ways.

*Grouped*, it folds exceptions, the warnings and errors the application logs,
and executions over their threshold into the problems behind them: one thing
that went wrong four hundred times, rather than four hundred things that went
wrong. It tells PHP Errors — usually a bug in the code — apart from Exceptions
the application anticipated, sorts by most recent or most frequent, and shows
where each was thrown or written. An issue can be resolved or ignored, and a
resolved one that happens again reopens itself, which is how a regression
announces itself.

*Every entry* lists each log line and exception as it happened, newest first,
filterable by level or to exceptions alone. Exceptions are never sampled. Log
lines are trace events, so they follow the trace sample rate. Laravel logs each
exception it reports, so that log line is left out — the exception is already
there as itself, with its class and location.

It replaces Pulse's Exceptions card on the default dashboard; that card is
still available as `<livewire:pulse-boosted.exceptions />`, and the stream on
its own as `<livewire:pulse-boosted.logs />`.

**Mail** and **Notifications** list what was sent, with the execution it
happened inside. These are trace events too.

**Commands** and **Scheduled Tasks** total executions by name: how often each
ran, how long it took on average, its 95th percentile, and how often it failed.
The percentile is there because an average hides the slow tail — a command that
usually takes 40ms and occasionally takes eight seconds averages out to
something that looks fine. Scheduled Tasks also lists every task on the
schedule, with its expression and when it is next due, and says which were
missed — see [Scheduled tasks](#scheduled-tasks).

**Routes** and **Queries** open into a panel of their own. A route shows its
p50, p95 and p99, how it answered, requests and p95 over the selected period,
and its slowest requests and latest failures. A query shows every place it is
run from and the executions that ran it most: the same query forty times in one
request is an N+1, and this is where it shows.

Every name opens the user behind it: the Usage card, an issue's occurrences, a
route's slowest requests and a trace's header. A user's panel has what they
did over the period, the issues they ran into, the routes they used, the
warnings logged for them, and their latest executions, each opening its trace.

**Ctrl+K** — or **Cmd+K**, or **/** — searches everything at once: issues by
class or message, traces and jobs by name or by the first characters of their
id, routes, scheduled tasks, and users by name or email. Every result is a
link, so what it opens can also be bookmarked or sent to someone.

Every row on every card opens the trace behind it.
## Alerts

A dashboard tells you something is wrong once you go and look at it. A rule is
what saves you from having to. Each one is a metric, a threshold and a window;
when a reading breaches it an episode opens, and when the reading recovers the
episode closes. The Alerts card shows what is breaching now, what is being
watched, and what has breached before.

```php
// config/pulse-boosted.php
'alerts' => [
    'enabled' => true,
    'check_every' => '1 minute',
    'rules' => [
        [
            'name' => 'Error rate',
            'metric' => 'error_rate',
            'threshold' => 5,
            'comparison' => 'above',   // or 'below'
            'window' => '5 minutes',
            'options' => ['type' => 'request'],
            'description' => 'More than 5% of requests are failing.',
        ],
        [
            'name' => 'Default queue backing up',
            'metric' => 'queue_size',
            'threshold' => 1000,
            'options' => ['queue' => 'default'],
        ],
    ],
],
```

| Metric | What it reads |
| --- | --- |
| `exceptions` | Exceptions thrown in the window. Never sampled, so this is a true count. |
| `new_issues` | Bugs seen for the first time in the window. A spike is a deploy that went wrong. |
| `failed_jobs` | Jobs that failed in the window. Takes a `queue`. |
| `queue_size` | Jobs waiting right now. Takes a `queue` and a `connection`. |
| `error_rate` | Percentage of traced executions that failed. |
| `p95_duration` | What the slowest 5% of executions exceeded, in milliseconds. |
| `slow_executions` | Executions over `slower_than` milliseconds. |

The last three read traces, which are sampled, and take an optional `type` of
`request`, `job`, `command` or `schedule`. A ratio taken from a sample is still
the right ratio, but a count taken from one is a tenth of the truth at the
default sample rate — so thresholds on `slow_executions` should be set from
what the dashboard shows, not from what you believe your traffic to be.

A metric that cannot be read — a queue driver that cannot count itself, a
window with no traffic in it — reads as unknown, and a rule never trips on an
unknown. "We do not know" is not the same as "it is bad".

`queue_size` looks at one connection, the default unless the rule names
another, because adding every configured connection together would double count
where two of them are the same backend under a second name.

### Being told

Pulse Boosted does not send alerts anywhere. Where an alert should go is your
application's business, not the dashboard's, and you already have a way of
notifying people:

```php
use Elazaroo\PulseBoosted\Events\AlertResolved;
use Elazaroo\PulseBoosted\Events\AlertTriggered;

Event::listen(function (AlertTriggered $event) {
    Notification::route('slack', config('alerting.slack'))
        ->notify(new SomethingIsWrong($event->rule->name, $event->value));
});

Event::listen(function (AlertResolved $event) {
    // Worth sending too: an alert nobody is told has ended is an alert
    // somebody is still worrying about.
});
```

Rules are evaluated on the isolated beat of `pulse-boosted:check`, under a
lock, so they run once across the fleet rather than once per server. To see
what each rule reads right now:

```sh
php artisan pulse-boosted:alerts            # checks, and opens or closes episodes
php artisan pulse-boosted:alerts --dry-run  # reads only, changes nothing
```

It exits non-zero when anything is breaching, so it also works as a health
check from outside.

## Issues

Exceptions are grouped by class, file and line into issues, and each issue
says whether its latest occurrence was **handled** — caught and passed to
`report()` or `rescue()` — or **unhandled**, having escaped to the handler. It
keeps that occurrence's stack, with five lines of source either side of the
first ten application frames, and the Laravel and PHP versions it happened on.
Issues can be resolved or ignored; a resolved one that happens again reopens
itself.

### Who is on it

An issue can be assigned to someone, or taken on with *Assign to me*, and
anyone who can see the dashboard can write on it. Its history says who
resolved, ignored, reopened or reassigned it and when, alongside the times it
came back after being resolved and the times it was resolved for being quiet.
The list filters to issues assigned to you, or to nobody.

Assigning and changing an issue use the same `managePulseBoostedQueues` gate as
the rest of the changes the dashboard can make. The people offered are whoever
has assigned, commented on or changed an issue before, whoever is looking, and
any listed in `PULSE_BOOSTED_ISSUES_ASSIGNEES`. Whoever an issue is assigned to
is emailed when it comes back, as well as the usual addresses; turn that off
with `PULSE_BOOSTED_ISSUES_NOTIFY_ASSIGNEE=false`. `IssueAssigned` is fired
for anything else.

### Logged warnings and errors

```env
PULSE_BOOSTED_ISSUES_LOG_LEVEL=warning        # the least severe line that opens an issue; none to turn off
PULSE_BOOSTED_ISSUES_NOTIFY_LOG_LEVEL=error   # the least severe one that sends an email
```

A line written at `warning` or above becomes an issue under *Logged*, and is
resolved, ignored and reopened like any exception. Lines are grouped by level,
the place they were written from, and their message with the parts that vary
folded away — numbers, UUIDs, email addresses and quoted strings — so `Order 7
could not be charged` and `Order 9 could not be charged` are one issue. A line
that only logs an exception already reported is left out, so nothing counts
twice.

### Being told about new issues

```env
PULSE_BOOSTED_ISSUES_MAIL=oncall@example.com,lead@example.com
```

Those addresses are emailed through the application's own mailer the first
time an issue is seen, and again if one marked resolved comes back. For Slack
or anything that takes a webhook, see [Webhooks](#webhooks); for any other
channel, listen for `IssueOpened` and `IssueRegressed`.

### Slow executions are issues too

```php
// config/pulse-boosted.php
'issues' => [
    'thresholds' => [
        'request' => ['GET /checkout' => 800, '#^GET /api/#' => 300, '*' => 2000],
        'job' => ['App\Jobs\SendInvoice' => 5000],
    ],
],
```

An execution over its threshold opens an issue under *Performance* — listed,
resolvable and notified like any exception — and its trace is kept whatever
the sampling draw said.

### Resolving quiet issues

Set `issues.auto_resolve_after` to `'7 days'` or similar to resolve open issues
that have stopped happening. One that was not really fixed reopens the next
time it happens and is announced as a regression.

## Scheduled tasks

A task that never started leaves nothing behind to count, so the dashboard
cannot notice it from what happened. Instead, each time `schedule:run`
finishes, the whole schedule is written down — every task, its expression and
its timezone — with when each task last started, finished or was held back by
its own conditions.

A task whose due time has passed by a minute and a half without it starting is
**missed**. It is shown on the Scheduled Tasks card and announced once for
each due time it missed, with `ScheduledTaskMissed` and the `schedule.missed`
webhook. A schedule nobody has written down for three minutes means the
scheduler itself has stopped, and the card says so — there is then nothing
left to announce anything, so an alert on it has to come from outside.

It needs nothing set up beyond the scheduler you already run. A task limited
to other environments is left out, and one added after its due time is not
missed until it is next due. `PULSE_BOOSTED_SCHEDULE_MONITOR=false` turns it
off.

## Webhooks

Webhooks are added on the settings page, under **Webhooks**, and kept in the
database — not in `.env`. Paste the address and the service is worked out from
it; each gets its events in its own format:

| Service | Address | What it gets |
| --- | --- | --- |
| Slack | `https://hooks.slack.com/services/…` | A message with blocks and a link back |
| Discord | `https://discord.com/api/webhooks/…` | An embed, red, green or blue by event, that can never ping `@everyone` |
| Microsoft Teams | a Workflows address (`…logic.azure.com/workflows/…`) or an incoming webhook (`….webhook.office.com/…`) | An adaptive card with the details and an *Open* button |
| Google Chat | `https://chat.googleapis.com/v1/spaces/…/messages?key=…&token=…` | A message with a link back |
| Mattermost | its incoming webhook address | The same message as Slack, which it reads |
| Telegram | `https://api.telegram.org/bot<token>/sendMessage`, and the chat id | A message to that chat |
| Any URL | anything that takes JSON | The event itself, below |

```json
{
    "event": "issue.opened",
    "summary": "New issue: RuntimeException — Payment provider unreachable",
    "app": "Shop",
    "environment": "production",
    "url": "https://shop.example.com/pulse-boosted?issue=…#errors",
    "sent_at": "2026-09-23T10:14:00+00:00",
    "data": { "issue": { "class": "RuntimeException", "occurrences": 1, … } }
}
```

with the event in `X-Pulse-Boosted-Event` and, when a signing secret is set,
`X-Pulse-Boosted-Signature`: `sha256=` and the HMAC of the body. The events are
`issue.opened`, `issue.regressed`, `issue.assigned`, `alert.triggered`,
`alert.resolved` and `schedule.missed`, and each webhook gets the ones ticked
for it. Issues follow the same rules as the email: regressions can be turned
off, and issues made from log lines are only sent at `issues.notify.log_level`
or above.

**The address is the credential** for most of these services, so once saved it
is encrypted with the application key and never shown again — the page shows
only its host. A webhook can be renamed, pointed at a new address, given other
events, switched off, tested or removed, but not read back. After rotating
`APP_KEY`, the addresses have to be entered again; the page says which.

Each webhook shows how its last delivery went, and **Test** sends one and shows
the answer — the status and what the service said — so a revoked or mistyped
address is found before an incident. Webhooks are sent straight away with a
five second timeout, and a receiver that is down never fails the code that
caused the event. Name a queue connection under *Send from a queue* to send
them from a worker instead, retried if the receiver is down; the job carries
the webhook's id, never its address.

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
| `pulse-boosted:alerts` | Check the alert rules and show what each one reads |
| `pulse-boosted:deploy` | Mark a deployment |

## Moving from Laravel Pulse

Pulse Boosted is not a drop-in replacement. Everything it owns is named after
itself, so it can run next to `laravel/pulse` while you compare the two: they
share no tables, routes, config, commands or gates. Moving across is a rename.

| Laravel Pulse | Pulse Boosted |
| --- | --- |
| `Laravel\Pulse\` | `Elazaroo\PulseBoosted\` |
| `config/pulse.php`, `config('pulse.…')` | `config/pulse-boosted.php`, `config('pulse-boosted.…')` |
| `PULSE_*` | `PULSE_BOOSTED_*` |
| `viewPulse` gate | `viewPulseBoosted` gate |
| `/pulse` | `/pulse-boosted` |
| `pulse:work`, `pulse:check`, … | `pulse-boosted:work`, `pulse-boosted:check`, … |
| `resources/views/vendor/pulse/` | `resources/views/vendor/pulse-boosted/` |
| `<x-pulse::card>`, `<livewire:pulse.queues />` | `<x-pulse-boosted::card>`, `<livewire:pulse-boosted.queues />` |

Custom recorders and cards extend the same classes under the new namespace and
need nothing else.

Recorded metrics are not migrated. They only live for a week, so the simplest
path is to run both dashboards side by side for a week and then drop the old
`pulse_*` tables. The aggregate schemas are identical if you would rather copy
them across; list the columns explicitly and leave out the generated `key_hash`.

## Relationship to Laravel Pulse

This fork keeps Laravel Pulse's architecture: recorders write entries, an ingest buffers them, a storage driver aggregates them into buckets, and Livewire cards read the aggregates. New functionality lives in its own directories rather than being woven into the upstream files.

Upstream releases are ported by hand. The `1.x` branch in this repository tracks `laravel/pulse` unchanged and exists to generate those diffs.

## Credits

Pulse Boosted is derived from [Laravel Pulse](https://github.com/laravel/pulse) by Taylor Otwell and the Laravel contributors, and remains under the same licence. Laravel and Laravel Pulse are trademarks of their respective owners; this project is not affiliated with or endorsed by them.

## Licence

MIT. See [LICENSE.md](LICENSE.md). The original Laravel Pulse copyright notice
stays alongside this project's own, as the MIT licence requires of derived work.
