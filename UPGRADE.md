# Migrating from Laravel Pulse

Pulse Boosted is a fork, not a drop-in replacement. Everything it owns is named after itself, so it can sit next to `laravel/pulse` rather than colliding with it. That also means moving across is a rename, not a version bump.

You can run both at once while you migrate. They share no tables, routes, config, commands or gates.

## 1. Install

```sh
composer require elazaroo/pulse-boosted
```

Remove `laravel/pulse` once you are happy, or leave it in place while you compare the two.

## 2. Publish the new config and migrations

```sh
php artisan vendor:publish --tag=pulse-boosted-config
php artisan vendor:publish --tag=pulse-boosted-migrations
php artisan migrate
```

This creates `pulse_boosted_values`, `pulse_boosted_entries`, `pulse_boosted_aggregates` and `pulse_boosted_jobs`. Your existing `pulse_*` tables are untouched.

## 3. Rename what you had customised

| Laravel Pulse | Pulse Boosted |
| --- | --- |
| `Laravel\Pulse\` | `Elazaroo\PulseBoosted\` |
| `config/pulse.php` | `config/pulse-boosted.php` |
| `config('pulse.…')` | `config('pulse-boosted.…')` |
| `PULSE_*` | `PULSE_BOOSTED_*` |
| `Gate::define('viewPulse', …)` | `Gate::define('viewPulseBoosted', …)` |
| `/pulse` | `/pulse-boosted` |
| `php artisan pulse:work` | `php artisan pulse-boosted:work` |
| `Pulse::` facade | `PulseBoosted::` facade |
| `resources/views/vendor/pulse/` | `resources/views/vendor/pulse-boosted/` |
| `<x-pulse::card>` | `<x-pulse-boosted::card>` |
| `<livewire:pulse.queues />` | `<livewire:pulse-boosted.queues />` |

If you wrote your own recorders or cards, they extend the same classes under the new namespace and need no other changes.

## 4. Historical data

There is no automatic migration of recorded metrics. Pulse's data is short-lived by design — the default retention is seven days — so the simplest path is to run both dashboards side by side for a week and then drop the old tables.

If you would rather keep it, the schemas are identical, so a plain copy works:

```sql
INSERT INTO pulse_boosted_values     SELECT * FROM pulse_values;
INSERT INTO pulse_boosted_entries    SELECT * FROM pulse_entries;
INSERT INTO pulse_boosted_aggregates SELECT * FROM pulse_aggregates;
```

On MySQL and PostgreSQL the `key_hash` column is generated, so list the columns explicitly and leave it out.

## 5. Things that are new

- **`pulse_boosted_jobs`** records one row per job. Nothing in Laravel Pulse writes to it, so it starts empty and fills as jobs run.
- **`managePulseBoostedQueues`** gates retrying and deleting jobs. It denies until you define it, so the queue explorer is read-only out of the box.
- **Argument capture** is off by default. See the README before switching it on.

## Upstream history

Upgrade notes for Laravel Pulse releases up to v1.8.1 live in [that project's UPGRADE.md](https://github.com/laravel/pulse/blob/1.x/UPGRADE.md). They apply to this fork too, under the renamed identifiers above.
