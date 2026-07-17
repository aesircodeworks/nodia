<?php

use Illuminate\Support\Facades\DB;

it('runs against a real PostgreSQL connection', function () {
    $driver = DB::connection()->getDriverName();

    $this->assertSame(
        'pgsql',
        $driver,
        "The Isolation suite proves row-level security, which only PostgreSQL provides; the active driver is [{$driver}]. "
        .'Start the local stack with `make up` so PostgreSQL serves 127.0.0.1:5432 with the `nodia_test` database, '
        .'or point the NODIA_TEST_DB_* environment variables at another PostgreSQL server. '
        .'This suite must never run on SQLite.',
    );

    expect(DB::selectOne('select current_database() as name')->name)
        ->toBe(config('database.connections.pgsql.database'));
});
