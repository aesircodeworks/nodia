<?php

use Illuminate\Support\Facades\DB;
use Tests\Isolation\Support\RlsHonestConnection;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)->in('Feature', 'Unit', 'Contract');

/*
|--------------------------------------------------------------------------
| Isolation and Concurrency Suites
|--------------------------------------------------------------------------
|
| These suites only prove anything against real PostgreSQL (row-level
| security, genuine lock contention), so they never run on the SQLite
| default. When the environment already provides a pgsql default (the CI
| jobs export DB_*), it is used as-is; otherwise the connection is pointed
| at the compose stack's dedicated test database, overridable through the
| NODIA_TEST_DB_* variables. The Isolation suite additionally downgrades
| its connection to an unprivileged role whenever the configured user
| would bypass RLS (superuser or BYPASSRLS), because a bypassing role
| would make every isolation proof vacuous. See the API README, "Testing".
|
*/

$usePostgresTestDatabase = function (): void {
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
};

pest()->extend(TestCase::class)
    ->beforeEach($usePostgresTestDatabase)
    ->in('Concurrency');

pest()->extend(TestCase::class)
    ->beforeEach(function () use ($usePostgresTestDatabase): void {
        $usePostgresTestDatabase();
        RlsHonestConnection::ensure();
    })
    ->in('Isolation');

/*
|--------------------------------------------------------------------------
| Architecture Tests
|--------------------------------------------------------------------------
|
| Arch tests don't need the framework bootstrapped, so they run against the
| default PHPUnit test case rather than Tests\TestCase.
|
*/

uses()->group('arch')->in('Architecture');
