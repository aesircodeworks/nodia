<?php

use Tests\Isolation\Support\RlsHonestConnection;
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
| The Isolation suite additionally downgrades its connection to
| an unprivileged role whenever the configured user would bypass RLS
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
