<?php

namespace Elazaroo\PulseBoosted\Queues;

use Illuminate\Contracts\Config\Repository;

/**
 * Where the job history is kept: the database, or Redis.
 *
 * 'auto' follows the queues: an application whose default queue connection
 * runs on Redis keeps the history there too, and needs no tables for it; one
 * on the database or sync driver keeps it in the database. Installing asks,
 * and writes the answer down, so it does not change under anyone's feet if
 * the queue connection does later.
 *
 * @internal
 */
class JobStorage
{
    public static function driver(Repository $config): string
    {
        $driver = (string) ($config->get('pulse-boosted.queues.storage.driver') ?? 'auto');

        if (in_array($driver, ['database', 'redis'], true)) {
            return $driver;
        }

        $connection = $config->get('queue.default');

        return $config->get("queue.connections.{$connection}.driver") === 'redis' ? 'redis' : 'database';
    }

    public static function usesRedis(Repository $config): bool
    {
        return self::driver($config) === 'redis';
    }

    /**
     * The migrations the job history needs when it is kept in the database,
     * and only then.
     *
     * @return list<string>
     */
    public static function migrations(): array
    {
        return [
            'create_pulse_boosted_jobs_table',
            'create_pulse_boosted_job_attempts_table',
        ];
    }
}
