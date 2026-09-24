<?php

namespace Elazaroo\PulseBoosted\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * The operator for a search a person typed, which should not care about
 * case: LIKE already does not on MySQL, MariaDB, SQLite and SQL Server as
 * they are usually set up, but does on PostgreSQL, which has ILIKE for it.
 *
 * @internal
 */
class Like
{
    public static function operator(Builder $query): string
    {
        $connection = $query->getConnection();

        return $connection instanceof Connection && $connection->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }
}
