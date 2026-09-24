<?php

namespace Elazaroo\PulseBoosted\Schedule;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Elazaroo\PulseBoosted\Events\ScheduledTaskMissed;
use Elazaroo\PulseBoosted\Pulse;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use stdClass;
use Throwable;

/**
 * Knows what the scheduler should be running, and notices what it did not.
 *
 * A dashboard can only count what happened, and a task that never started
 * leaves nothing to count. So each time the scheduler runs, the whole
 * schedule is written down — every task, its expression and its timezone —
 * along with when each one last started. A task whose last due time has
 * passed without a start is missed; a schedule nobody has written down for a
 * few minutes means the scheduler itself has stopped.
 *
 * @internal
 */
class ScheduleMonitor
{
    /**
     * How long after its due time a task has to start before it counts as
     * missed. The scheduler runs once a minute, so a minute and a bit.
     */
    public const GRACE_SECONDS = 90;

    /**
     * How long the scheduler can go without running before it counts as
     * stopped.
     */
    public const SCHEDULER_STALE_SECONDS = 180;

    public function __construct(
        protected DatabaseManager $db,
        protected Repository $config,
        protected Pulse $pulse,
        protected Dispatcher $events,
        protected Application $app,
    ) {
        //
    }

    /**
     * Write the schedule down, as the scheduler sees it now.
     */
    public function snapshot(Schedule $schedule): void
    {
        $now = CarbonImmutable::now()->getTimestamp();
        $environment = (string) $this->app->environment();

        $this->pulse->ignore(function () use ($schedule, $now, $environment) {
            foreach ($schedule->events() as $event) {
                // A task limited to other environments is not due here, so it
                // is not this schedule's to miss.
                if (! $event->runsInEnvironment($environment)) {
                    continue;
                }

                $key = $this->key($event);

                $this->table()->upsert([[
                    'key' => $key,
                    'name' => self::name($event),
                    'expression' => $event->expression,
                    'timezone' => $this->timezone($event),
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]], ['key'], ['name', 'expression', 'timezone', 'last_seen_at']);
            }

            // Gone from the schedule for a day: removed, not late.
            $this->table()->where('last_seen_at', '<', $now - 86400)->delete();
        });
    }

    /**
     * Note that a task started.
     */
    public function started(Event $event): void
    {
        $this->touch($event, ['last_started_at' => CarbonImmutable::now()->getTimestamp()]);
    }

    /**
     * Note that a task finished, and how.
     */
    public function finished(Event $event, string $status, ?int $durationMs = null): void
    {
        $this->touch($event, [
            'last_finished_at' => CarbonImmutable::now()->getTimestamp(),
            'last_status' => $status,
            'last_duration_ms' => $durationMs,
        ]);
    }

    /**
     * Note that a task was due but its own conditions held it back.
     */
    public function skipped(Event $event): void
    {
        $this->touch($event, ['last_skipped_at' => CarbonImmutable::now()->getTimestamp()]);
    }

    /**
     * Every task, with when it was last due, when it is next due, and how
     * it is doing.
     *
     * @return Collection<int, stdClass>
     */
    public function tasks(): Collection
    {
        $now = CarbonImmutable::now();

        return $this->pulse->ignore(fn () => $this->table()->orderBy('name')->get())
            ->map(fn (stdClass $task) => $this->assess($task, $now));
    }

    /**
     * When the scheduler last wrote the schedule down, or null if it never
     * has.
     */
    public function lastRun(): ?int
    {
        $last = $this->pulse->ignore(fn () => $this->table()->max('last_seen_at'));

        return $last === null ? null : (int) $last;
    }

    /**
     * Whether the scheduler looks to have stopped.
     */
    public function schedulerStopped(): bool
    {
        $last = $this->lastRun();

        return $last !== null && CarbonImmutable::now()->getTimestamp() - $last > self::SCHEDULER_STALE_SECONDS;
    }

    /**
     * Announce each task that has missed its due time, once per due time.
     *
     * @return int How many were announced.
     */
    public function announceMissed(): int
    {
        $announced = 0;

        foreach ($this->tasks() as $task) {
            if ($task->state !== 'missed' || (int) $task->missed_notified_for === $task->previous_due) {
                continue;
            }

            $this->pulse->ignore(fn () => $this->table()
                ->where('key', $task->key)
                ->update(['missed_notified_for' => $task->previous_due]));

            $this->events->dispatch(new ScheduledTaskMissed($task, $task->previous_due));

            $announced++;
        }

        return $announced;
    }

    /**
     * Drop everything.
     */
    public function purge(): void
    {
        $this->pulse->ignore(fn () => $this->table()->delete());
    }

    /**
     * Work out where a task stands.
     */
    protected function assess(stdClass $task, CarbonImmutable $now): stdClass
    {
        $task->previous_due = null;
        $task->next_due = null;

        try {
            $cron = new CronExpression($task->expression);
            $timezone = $task->timezone ?: $this->config->get('app.timezone', 'UTC');

            $task->previous_due = $cron->getPreviousRunDate($now, 0, true, $timezone)->getTimestamp();
            $task->next_due = $cron->getNextRunDate($now, 0, false, $timezone)->getTimestamp();
        } catch (Throwable) {
            //
        }

        $started = $task->last_started_at === null ? null : (int) $task->last_started_at;
        $finished = $task->last_finished_at === null ? null : (int) $task->last_finished_at;
        $skipped = $task->last_skipped_at === null ? null : (int) $task->last_skipped_at;

        $task->state = match (true) {
            $started !== null && ($finished === null || $finished < $started) => 'running',
            $this->missed($task, $now, $started, $skipped) => 'missed',
            $task->last_status === 'failed' => 'failed',
            $skipped !== null && ($started === null || $skipped > $started) => 'skipped',
            $started === null => 'waiting',
            default => 'ok',
        };

        return $task;
    }

    /**
     * Whether a task was due, the scheduler was watching it at the time, and
     * it neither started nor was held back by its own conditions.
     */
    protected function missed(stdClass $task, CarbonImmutable $now, ?int $started, ?int $skipped): bool
    {
        $due = $task->previous_due;

        if ($due === null || $now->getTimestamp() - $due < self::GRACE_SECONDS) {
            return false;
        }

        // Only listed after it was due: added since, so not missed.
        if ((int) $task->first_seen_at > $due - 30) {
            return false;
        }

        // A start or a skip a little before the minute counts: the scheduler
        // runs at the top of the minute, give or take.
        $lastLook = max($started ?? 0, $skipped ?? 0);

        return $lastLook < $due - 30;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function touch(Event $event, array $values): void
    {
        $this->pulse->rescue(fn () => $this->pulse->ignore(function () use ($event, $values) {
            $key = $this->key($event);

            // Whether it exists, rather than how many rows an update touched:
            // MySQL counts a row whose values did not change as untouched.
            if ($this->table()->where('key', $key)->exists()) {
                $this->table()->where('key', $key)->update($values);

                return;
            }

            $now = CarbonImmutable::now()->getTimestamp();

            // Run before any schedule:run wrote the schedule down — by
            // schedule:test, or the first time after installing. Not
            // insertOrIgnore, which SQL Server does not have; a scheduler on
            // another server inserting it first is the only way this fails.
            try {
                $this->table()->insert([
                    'key' => $key,
                    'name' => self::name($event),
                    'expression' => $event->expression,
                    'timezone' => $this->timezone($event),
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                    ...$values,
                ]);
            } catch (QueryException) {
                $this->table()->where('key', $key)->update($values);
            }
        }));
    }

    /**
     * A task's readable name, the same one its traces are filed under.
     */
    public static function name(Event $event): string
    {
        $description = $event->description ?? null;

        if (is_string($description) && $description !== '') {
            return $description;
        }

        $command = $event->command ?? null;

        if (is_string($command) && $command !== '') {
            // The scheduler writes "php" "artisan" around a command's name.
            return trim((string) preg_replace("/^.*?['\"]?artisan['\"]?\s+/", '', $command)) ?: $command;
        }

        return 'Closure';
    }

    protected function key(Event $event): string
    {
        return md5(self::name($event).'|'.$event->expression.'|'.$this->timezone($event));
    }

    protected function timezone(Event $event): ?string
    {
        $timezone = $event->timezone;

        if ($timezone instanceof \DateTimeZone) {
            return $timezone->getName();
        }

        return is_string($timezone) && $timezone !== '' ? $timezone : null;
    }

    protected function table(): Builder
    {
        return $this->connection()->table('pulse_boosted_scheduled_tasks');
    }

    protected function connection(): Connection
    {
        return $this->db->connection(
            $this->config->get('pulse-boosted.storage.database.connection')
        );
    }
}
