<?php

use Elazaroo\PulseBoosted\Facades\Pulse;
use Illuminate\Support\Facades\DB;

/**
 * MySQL and MariaDB reject any identifier longer than 64 characters, and
 * Laravel builds index names from the table and every column in them. The
 * `pulse_boosted_` prefix is long enough that this is easy to trip over: the
 * aggregates unique index came to 69 characters and had to be named by hand.
 *
 * SQLite and PostgreSQL do not complain, so without this the next oversized
 * index would only show up on someone's MySQL server.
 */
it('keeps every index name within the MySQL identifier limit', function () {
    $names = Pulse::ignore(fn () => DB::table('sqlite_master')
        ->where('type', 'index')
        ->where('tbl_name', 'like', 'pulse_boosted_%')
        ->whereNotNull('name')
        ->pluck('name'))
        // Indexes SQLite creates for itself, which no other driver has.
        ->reject(fn (string $name) => str_starts_with($name, 'sqlite_autoindex_'));

    expect($names)->not->toBeEmpty();

    $tooLong = $names->filter(fn (string $name) => strlen($name) > 64);

    expect($tooLong->all())->toBe([]);
})->skip(
    fn () => DB::connection()->getDriverName() !== 'sqlite',
    'Index names are read from sqlite_master.'
);
