<?php

use Illuminate\Support\Facades\DB;
use Tests\Isolation\Support\RlsHonestConnection;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
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
| security, genuine lock contention). phpunit.xml already defaults every
| suite to the pgsql test database; Tests\Support\PostgresTestDatabase
| guards these two against an environment that forces another driver.
| The Isolation suite additionally migrates the test database once per
| process (as the configured user, so migrations run with provisioning
| privileges the way production does) and then downgrades its connection
| to an unprivileged role whenever the configured user would bypass RLS
| (superuser or BYPASSRLS), because a bypassing role would make every
| isolation proof vacuous. See the API README, "Testing".
|
*/

pest()->extend(TestCase::class)
    ->beforeEach(fn () => PostgresTestDatabase::use())
    ->in('Concurrency');

pest()->extend(TestCase::class)
    ->beforeEach(function (): void {
        PostgresTestDatabase::use();
        MigratedDatabase::ensure();
        RlsHonestConnection::ensure();
    })
    // In a full multi-suite run each Isolation test's downgraded
    // nodia_isolation connection outlives its test, held open by the
    // torn-down application instance surviving in a reference cycle PHP's
    // garbage collector does not get around to collecting, accumulating
    // one idle server backend per test until the Concurrency suite
    // starves against max_connections with "sorry, too many clients
    // already". Purging closes this test's own socket; collecting cycles
    // releases the previous tests' retained ones. Costs nothing here:
    // the next test's beforeEach reconnects on first query anyway.
    ->afterEach(function (): void {
        DB::purge();
        gc_collect_cycles();
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
