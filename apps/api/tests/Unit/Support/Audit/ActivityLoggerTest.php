<?php

use App\Models\User;
use App\Support\Audit\ActivityLogger;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-03 plan, Slice 7 (task breakdown item 15): "unit test for logger
 * tenant attribution (acting tenant vs sentinel)". ActivityLogger does
 * none of its own SET LOCAL or transaction work (its own docblock, and
 * ResolveActingMembership/ResolveTenantAccess's precedent), so every case
 * below wraps the call in the posture it is meant to be recorded under
 * and asserts the resulting row's tenant_id and platform_scope flag.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('memberships')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    User::query()->delete();
});

it('attributes the entry to the acting tenant, not the sentinel, under an ordinary tenant transaction', function () {
    $entry = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(ActivityLogger::class)->record(description: 'tenant-scope write'),
    );

    expect($entry->tenant_id)->toBe($this->tenantId)
        ->and($entry->tenant_id)->not->toBe(config()->string('tenancy.platform_tenant_id'))
        ->and($entry->properties->get('platform_scope'))->toBeFalse();
});

it('attributes the entry to the sentinel platform tenant under the platform posture', function () {
    $entry = app(TenantTransaction::class)->asPlatform(
        fn () => app(ActivityLogger::class)->record(description: 'platform-scope write'),
    );

    expect($entry->tenant_id)->toBe(config()->string('tenancy.platform_tenant_id'))
        ->and($entry->properties->get('platform_scope'))->toBeTrue();
});

it('attributes the entry to the real target tenant, flagged platform-scope, once elevated mid-transaction', function () {
    // Mirrors ResolveTenantAccess's platform-scope fallback (stage-03
    // task-05): elevateToPlatformRole() re-enters TenantContext under
    // nodia_platform against the *already-asserted* tenant id, not the
    // sentinel, so a subsequent write records under the real tenant while
    // still being flagged platform-scope.
    $entry = app(TenantTransaction::class)->asTenant($this->tenantId, function () {
        app(TenantTransaction::class)->elevateToPlatformRole();

        return app(ActivityLogger::class)->record(description: 'elevated write');
    });

    expect($entry->tenant_id)->toBe($this->tenantId)
        ->and($entry->properties->get('platform_scope'))->toBeTrue();
});

it('associates the given causer by type and key', function () {
    $user = User::factory()->create();

    $entry = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(ActivityLogger::class)->record(description: 'caused write', causer: $user),
    );

    expect($entry->causer_id)->toBe($user->id)
        ->and($entry->causer_type)->toBe(User::class);
});

it('records no causer when none is given', function () {
    $entry = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(ActivityLogger::class)->record(description: 'anonymous write'),
    );

    expect($entry->causer_id)->toBeNull()
        ->and($entry->causer_type)->toBeNull();
});

it('merges caller-supplied properties alongside the automatic platform_scope flag', function () {
    $entry = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(ActivityLogger::class)->record(
            description: 'write with properties',
            properties: ['correlation_id' => 'abc-123'],
        ),
    );

    expect($entry->properties->get('correlation_id'))->toBe('abc-123')
        ->and($entry->properties->get('platform_scope'))->toBeFalse();
});

it('throws when called with no tenant transaction active', function () {
    expect(fn () => app(ActivityLogger::class)->record(description: 'no posture'))
        ->toThrow(LogicException::class);
});
