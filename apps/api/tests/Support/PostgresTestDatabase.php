<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Points the default connection at real PostgreSQL for tests that prove
 * nothing on the SQLite default (RLS, SET LOCAL, genuine lock contention).
 * When the environment already provides a pgsql default (the CI jobs
 * export DB_*), it is used as-is; otherwise the connection targets the
 * compose stack's dedicated test database, overridable through the
 * NODIA_TEST_DB_* variables. See the API README, "Testing".
 */
final class PostgresTestDatabase
{
    public static function use(): void
    {
        if (config('database.default') === 'pgsql') {
            return;
        }

        config()->set('database.connections.pgsql', [
            ...config('database.connections.pgsql'),
            'host' => env('NODIA_TEST_DB_HOST', '127.0.0.1'),
            'port' => env('NODIA_TEST_DB_PORT', '5432'),
            'database' => env('NODIA_TEST_DB_DATABASE', 'nodia_test'),
            'username' => env('NODIA_TEST_DB_USERNAME', 'nodia'),
            'password' => env('NODIA_TEST_DB_PASSWORD', 'nodia'),
        ]);
        config()->set('database.default', 'pgsql');
        DB::purge('pgsql');
    }
}
