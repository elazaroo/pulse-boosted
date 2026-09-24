# Release Notes

## [v1.0.0](https://github.com/elazaroo/pulse-boosted/releases/tag/v1.0.0) - 2026-09-24

Initial fork of [laravel/pulse](https://github.com/laravel/pulse) v1.8.1.

### Added

- SQL Server support in the database storage driver and migrations.
- One row per job in `pulse_boosted_jobs`, covering the whole lifecycle: queued, processing, processed, released, failed and timed out. Unlike the counter recorder this also covers the `sync` connection.
- Opt-in capture of job arguments, read by reflecting over the live job object as it is queued and redacted before storage. The serialized payload is never unserialized on read.
- Live queue inspection per driver, with capability flags so the dashboard degrades instead of showing an empty table. `database` and `redis` can be listed; `sqs` and `beanstalkd` report counts only; `sync` and `null` have nothing to show.
- A queue explorer at `/pulse-boosted/queues` and a job detail view at `/pulse-boosted/jobs/{uuid}`.
- Retry, delete, flush, pause, resume and worker restart actions behind a `managePulseBoostedQueues` gate, separate from `viewPulseBoosted` and denied by default.
- A Queue Status card on the dashboard: live counts per queue, paused state, and pause/resume in place.
- A Workers card, built from a heartbeat each worker writes as it loops, since Laravel does not track them. Distinguishes a worker that stopped cleanly from one that went quiet.
- Traces: requests, commands, scheduled tasks and jobs become execution contexts, and the queries, cache reads, dispatched jobs, outgoing calls, exceptions, log lines, mail and notifications inside them are recorded against them with a timeline. A job carries its parent's trace id on the queue payload, so work is still joined to whatever asked for it across processes. Sampled at the entry point, capped per trace, and kept for a day.
- A runnable demo application in `workbench/`, with `demo:seed-queue`.
- Issues, grouping exceptions by class, file and line, with resolve, ignore and reopen, a PHP Error / Exception split, and sorting by recency or frequency. It replaces the Exceptions card on the default dashboard.
- Logging: log lines and exceptions in one stream. Exceptions are never sampled; the log line Laravel writes for each reported exception is left out so it is not shown twice. On the default dashboard it is the Issues card's *Every entry* view rather than a card of its own.
- Mail, Notifications, Commands and Scheduled Tasks cards, read from traces. Commands and Scheduled Tasks show the 95th percentile next to the average.
- `PulseBoosted::context()` to attach the application's own attributes to a trace; the Traces search matches them.
- Alert rules over seven metrics, with `AlertTriggered` and `AlertResolved` events and `pulse-boosted:alerts`.
- A redesigned dashboard: sections with a following sidebar, an Overview row of headline figures, and cards that only refresh while visible.
- Handled and unhandled exceptions told apart; each issue keeps its stack with source, the versions it happened on, and a Copy as Markdown button.
- Email for new and regressed issues through the application's mailer, and `IssueOpened` / `IssueRegressed` events.
- Retroactive sampling: executions that fail, throw or run slow are kept even when they lose the draw, and left out of rates and percentiles.
- Request timelines split into lifecycle stages; per-execution counts and peak memory; the line each query was run from; headers and, optionally, the body of requests that ended in a 5xx.
- Routes and Queries cards; Livewire requests named by their components.
- Cache writes, deletes and failures per key.
- Every attempt at a job, each linked to its own trace.
- Deployment tracking with `pulse-boosted:deploy`, and issues marked New in the latest deploy.
- Performance thresholds that open issues, and auto-resolving quiet issues.
- A user filter in the header, the Sample middleware, and the reject/redact API for trace events.
- `php artisan pulse-boosted:install`: publishes the config, asks whether the queues run on Redis, and publishes only the migrations that answer needs.
- The job history — completed and failed jobs and their attempts — can be kept in Redis instead of the database, with no tables, expiring after the same retention. `PULSE_BOOSTED_JOBS_STORAGE` is `redis`, `database`, or `auto` to follow the default queue connection.
- A settings page at `/pulse-boosted/settings` for email recipients, webhooks, log levels, auto-resolving, performance thresholds, the slow card thresholds, sample rates, what traces are always kept, and alert rules. Saved values win over config and `.env` field by field and reach every server within a minute.
- Issues can be assigned, taken on and commented on, with a history of who resolved, ignored, reopened or reassigned them, regressions, and issues resolved for being quiet. The list filters to issues assigned to you or to nobody, and the assignee is emailed when their issue comes back. `IssueAssigned` is fired for each change.
- Missed scheduled tasks: each `schedule:run` writes the whole schedule down, and a task whose due time passed without it starting is shown as missed and announced once with `ScheduledTaskMissed`. A scheduler that has stopped is shown on the card.
- Webhooks for Slack, Discord, Microsoft Teams, Google Chat, Mattermost, Telegram and any URL that takes signed JSON, each in its own format, for new, regressed and assigned issues, alerts triggering and resolving, and missed tasks. Added on the settings page and kept in the database, with addresses encrypted and never shown again; each has its own events, a test button and its last delivery. Sent straight away with a timeout, or from a queue by id.
- Route and query panels: percentiles, responses, a timeline over the period, the slowest requests and failures for a route; every place a query runs from, and the executions that ran it most, for a query.
- A panel for each user, opened from wherever their name appears: what they did, the issues they ran into, the routes they used and their latest executions.
- Ctrl+K search across issues, traces, jobs, routes, scheduled tasks and users, each result a link.
- Servers sits in the Overview section.
- The dashboard shows one section at a time, chosen from the sidebar or, on narrow screens, a strip of tabs, and kept in the address. Cards in the other sections neither load nor refresh until opened, and a section's cards load together in one request.
- The dashboard's scripts and stylesheet are served from a versioned `/assets` route with a year-long cache instead of being inlined in every page, and Livewire's script is the minified build: the page went from about 970 KB to about 80 KB.
- The trace timeline is its own component on every dashboard, so opening a trace from Issues, Routes or any other card works whether or not the Traces card has loaded.
- Logged warnings and errors become issues, grouped by level, origin and message with the varying parts folded away, and resolved, ignored and reopened like exceptions. `issues.log_level` sets the least severe line that opens one; `issues.notify.log_level` the least severe that sends an email.

### Changed

- Renamed throughout to `elazaroo/pulse-boosted` and the `Elazaroo\PulseBoosted\` namespace, so the package is independent of `laravel/pulse` rather than a replacement for it. See [Moving from Laravel Pulse](README.md#moving-from-laravel-pulse).
- Requires PHP 8.2 and Laravel 11 or later. Laravel 10 and PHP 8.1 are no longer supported.
- Searches ignore case on PostgreSQL too, as they already did on MySQL, MariaDB, SQLite and SQL Server.
- The dashboard's scripts and stylesheet are served from `/pulse-boosted/assets/…` and cached by the browser, rather than inlined in every page.

## Upstream history

Release notes for Laravel Pulse up to v1.8.1, from which this fork is derived, are in [that project's CHANGELOG](https://github.com/laravel/pulse/blob/1.x/CHANGELOG.md).
