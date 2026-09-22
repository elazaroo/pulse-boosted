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
- A runnable demo application in `workbench/`, with `demo:seed-queue`.

### Changed

- Renamed throughout to `elazaroo/pulse-boosted` and the `Elazaroo\PulseBoosted\` namespace, so the package is independent of `laravel/pulse` rather than a replacement for it. See [UPGRADE.md](UPGRADE.md).
- Line endings pinned to LF.
- The test suite runs against an in-memory database, so it no longer shares one with the demo application.

## Upstream history

Release notes for Laravel Pulse up to v1.8.1, from which this fork is derived, are in [that project's CHANGELOG](https://github.com/laravel/pulse/blob/1.x/CHANGELOG.md).
