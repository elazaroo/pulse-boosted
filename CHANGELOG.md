# Release Notes

## Unreleased

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
- Logging: log lines and exceptions in one stream. Exceptions are never sampled; the log line Laravel writes for each reported exception is left out so it is not shown twice.
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

### Changed

- A worker's jobs and a scheduler's tasks are now traced. `queue:work` and `schedule:run` opened a trace of their own that never closed, so nothing inside them was recorded.
- Exceptions thrown by the application now reach every recorder. Only the Exceptions card saw them before; issues and trace timelines only saw exceptions passed to `Pulse::report()` by hand.
- Renamed throughout to `elazaroo/pulse-boosted` and the `Elazaroo\PulseBoosted\` namespace, so the package is independent of `laravel/pulse` rather than a replacement for it. See [Moving from Laravel Pulse](README.md#moving-from-laravel-pulse).
- Line endings pinned to LF.
- The test suite runs against an in-memory database, so it no longer shares one with the demo application.

## Upstream history

Release notes for Laravel Pulse up to v1.8.1, from which this fork is derived, are in [that project's CHANGELOG](https://github.com/laravel/pulse/blob/1.x/CHANGELOG.md).
