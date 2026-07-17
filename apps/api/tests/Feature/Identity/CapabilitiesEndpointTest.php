<?php

use App\Identity\Capability;
use App\Models\User;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;

/*
 * Stage-03 plan, Slice 4b (task breakdown item 8): GET /v1/capabilities is
 * a static read of the Capability registry, staff bearer only, no
 * X-Tenant-Id or membership check.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    User::query()->delete();
});

it('returns every capability in the registry with its financially privileged flag', function () {
    $token = StaffTokens::issue(User::factory()->create());

    $response = test()->getJson('/v1/capabilities', ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertConformsToOpenApi();

    $body = collect($response->json('data'))->keyBy('name');

    expect($body)->toHaveCount(count(Capability::cases()));

    foreach (Capability::cases() as $capability) {
        expect($body[$capability->value]['is_financially_privileged'])
            ->toBe($capability->isFinanciallyPrivileged());
    }
});

it('rejects a request with no bearer', function () {
    test()->getJson('/v1/capabilities')
        ->assertUnauthorized()
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'auth.unauthenticated');
});
