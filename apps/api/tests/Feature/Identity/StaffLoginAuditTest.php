<?php

use App\Models\User;
use App\Support\Audit\Models\ActivityLogEntry;
use App\Support\Tenancy\TenantTransaction;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-03 plan, Slice 7 (task breakdown item 15): "successful staff
 * token issuance writes an entry under the sentinel platform tenant with
 * the causer user." Staff login has no acting tenant of its own (POST
 * /v1/auth/staff/token asserts no X-Tenant-Id and opens no tenant
 * transaction), so the entry always carries the sentinel platform
 * tenant, mirroring PlatformRoleAudit's own precedent for a request with
 * no ambient tenant context.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    User::query()->delete();
});

it('records an activity_log entry under the sentinel tenant with the user as causer on successful login', function () {
    $user = User::factory()->create(['email' => 'staff@example.com']);

    $this->postJson('/v1/auth/staff/token', [
        'email' => 'staff@example.com',
        'password' => 'password',
    ])->assertOk();

    // Filtered by causer_id, not "the latest row": activity_log is
    // append-only and accumulates staff_login entries from every other
    // test in the same process, some of which can share this row's
    // created_at down to Postgres's own timestamp resolution, making
    // latest()->first() non-deterministic without a tiebreaker.
    $entry = app(TenantTransaction::class)->asPlatform(
        fn () => ActivityLogEntry::query()->where('event', 'staff_login')->where('causer_id', $user->id)->first(),
    );

    expect($entry)->not->toBeNull()
        ->and($entry->tenant_id)->toBe(config()->string('tenancy.platform_tenant_id'))
        ->and($entry->causer_id)->toBe($user->id)
        ->and($entry->causer_type)->toBe(User::class)
        ->and($entry->properties->get('platform_scope'))->toBeTrue();
});

it('records no login entry for a failed authentication attempt', function () {
    $before = app(TenantTransaction::class)->asPlatform(
        fn () => ActivityLogEntry::query()->where('event', 'staff_login')->count(),
    );

    User::factory()->create(['email' => 'staff@example.com']);

    $this->postJson('/v1/auth/staff/token', [
        'email' => 'staff@example.com',
        'password' => 'wrong-password',
    ])->assertUnauthorized();

    $after = app(TenantTransaction::class)->asPlatform(
        fn () => ActivityLogEntry::query()->where('event', 'staff_login')->count(),
    );

    expect($after)->toBe($before);
});
