<?php

use App\Identity\Enums\MembershipScope;
use App\Identity\Exceptions\InvalidMembershipScopeException;
use App\Identity\Models\Membership;
use Illuminate\Support\Str;

/*
 * The application invariant scope=platform implies the sentinel platform
 * tenant (stage-03 plan, Data model and Task breakdown item 4). Pure and
 * DB-free: Membership::assertScopeInvariant() is exercised directly
 * rather than through a real save, so this proves the invariant without
 * depending on Postgres or the RLS regime.
 */

it('allows a platform-scope membership pinned to the sentinel platform tenant', function () {
    Membership::assertScopeInvariant(MembershipScope::Platform, config()->string('tenancy.platform_tenant_id'));
})->throwsNoExceptions();

it('allows a tenant-scope membership for any tenant', function () {
    Membership::assertScopeInvariant(MembershipScope::Tenant, (string) Str::uuid7());
})->throwsNoExceptions();

it('rejects a platform-scope membership pinned to a non-sentinel tenant', function () {
    $tenantId = (string) Str::uuid7();

    expect(fn () => Membership::assertScopeInvariant(MembershipScope::Platform, $tenantId))
        ->toThrow(InvalidMembershipScopeException::class, $tenantId);
});
