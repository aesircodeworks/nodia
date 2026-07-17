<?php

declare(strict_types=1);

use App\Identity\Actions\AssignRole;
use App\Identity\Actions\RemoveMembership;
use App\Identity\Data\ChangeMembershipRoleData;
use App\Identity\Exceptions\LastOwnerRemovalException;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-03 plan, task breakdown item 9: "Unit tests for the Actions,"
 * covering App\Identity\Support\GuardsLastOwner, shared by AssignRole
 * (role change) and RemoveMembership (removal). Driven through
 * TenantTransaction::asTenant() directly, mirroring
 * ResolveActingMembershipTest's own precedent, rather than through HTTP
 * (tests/Feature/Identity/MembershipEndpointsTest.php already proves both
 * endpoints end to end).
 */

const LOG_TENANT = '019797f5-0000-7000-8000-0000000000d1';

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::factory()->create(['id' => LOG_TENANT]);
    });
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant(LOG_TENANT, function (): void {
        DB::table('outbox_deliveries')->where('tenant_id', LOG_TENANT)->delete();
        DB::table('outbox_events')->where('tenant_id', LOG_TENANT)->delete();
        DB::table('memberships')->where('tenant_id', LOG_TENANT)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    User::query()->delete();
});

function logOwnerTemplateRoleId(): string
{
    return app(TenantTransaction::class)->asPlatform(
        fn () => Role::query()->whereNull('tenant_id')->where('name', 'Owner')->firstOrFail()->id,
    );
}

/**
 * @param  array<string, mixed>  $attributes
 */
function logMembership(array $attributes = []): Membership
{
    return app(TenantTransaction::class)->asTenant(
        LOG_TENANT,
        fn () => Membership::factory()->create([
            'tenant_id' => LOG_TENANT,
            'role_id' => Role::factory()->create(['tenant_id' => LOG_TENANT])->id,
            ...$attributes,
        ]),
    );
}

it('AssignRole throws last_owner_removal when demoting the tenant\'s only Owner', function () {
    $ownerRoleId = logOwnerTemplateRoleId();
    $onlyOwner = logMembership(['role_id' => $ownerRoleId]);
    $otherRoleId = app(TenantTransaction::class)->asTenant(
        LOG_TENANT,
        fn () => Role::factory()->create(['tenant_id' => LOG_TENANT])->id,
    );
    $actorId = User::factory()->create()->id;

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        LOG_TENANT,
        fn () => app(AssignRole::class)($onlyOwner, new ChangeMembershipRoleData($otherRoleId), $actorId),
    );

    expect($invoke)->toThrow(LastOwnerRemovalException::class);
});

it('AssignRole allows demoting an Owner when another Owner membership remains', function () {
    $ownerRoleId = logOwnerTemplateRoleId();
    $firstOwner = logMembership(['role_id' => $ownerRoleId]);
    logMembership(['role_id' => $ownerRoleId]);
    $otherRoleId = app(TenantTransaction::class)->asTenant(
        LOG_TENANT,
        fn () => Role::factory()->create(['tenant_id' => LOG_TENANT])->id,
    );
    $actorId = User::factory()->create()->id;

    $result = app(TenantTransaction::class)->asTenant(
        LOG_TENANT,
        fn () => app(AssignRole::class)($firstOwner, new ChangeMembershipRoleData($otherRoleId), $actorId),
    );

    expect($result->roleId)->toBe($otherRoleId);
});

it('AssignRole allows reassigning a non-Owner membership freely', function () {
    $membership = logMembership();
    $otherRoleId = app(TenantTransaction::class)->asTenant(
        LOG_TENANT,
        fn () => Role::factory()->create(['tenant_id' => LOG_TENANT])->id,
    );
    $actorId = User::factory()->create()->id;

    $result = app(TenantTransaction::class)->asTenant(
        LOG_TENANT,
        fn () => app(AssignRole::class)($membership, new ChangeMembershipRoleData($otherRoleId), $actorId),
    );

    expect($result->roleId)->toBe($otherRoleId);
});

it('AssignRole allows reassigning the Owner\'s own membership to the same role as a no-op', function () {
    $ownerRoleId = logOwnerTemplateRoleId();
    $onlyOwner = logMembership(['role_id' => $ownerRoleId]);
    $actorId = User::factory()->create()->id;

    $result = app(TenantTransaction::class)->asTenant(
        LOG_TENANT,
        fn () => app(AssignRole::class)($onlyOwner, new ChangeMembershipRoleData($ownerRoleId), $actorId),
    );

    expect($result->roleId)->toBe($ownerRoleId);
});

it('RemoveMembership throws last_owner_removal when removing the tenant\'s only Owner', function () {
    $ownerRoleId = logOwnerTemplateRoleId();
    $onlyOwner = logMembership(['role_id' => $ownerRoleId]);

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        LOG_TENANT,
        fn () => app(RemoveMembership::class)($onlyOwner),
    );

    expect($invoke)->toThrow(LastOwnerRemovalException::class);

    app(TenantTransaction::class)->asTenant(LOG_TENANT, function () use ($onlyOwner): void {
        expect(Membership::query()->whereKey($onlyOwner->id)->exists())->toBeTrue();
    });
});

it('RemoveMembership allows removing an Owner when another Owner membership remains', function () {
    $ownerRoleId = logOwnerTemplateRoleId();
    $firstOwner = logMembership(['role_id' => $ownerRoleId]);
    logMembership(['role_id' => $ownerRoleId]);

    app(TenantTransaction::class)->asTenant(
        LOG_TENANT,
        fn () => app(RemoveMembership::class)($firstOwner),
    );

    app(TenantTransaction::class)->asTenant(LOG_TENANT, function () use ($firstOwner): void {
        expect(Membership::query()->whereKey($firstOwner->id)->exists())->toBeFalse();
    });
});

it('RemoveMembership allows removing a non-Owner membership freely', function () {
    $membership = logMembership();

    app(TenantTransaction::class)->asTenant(
        LOG_TENANT,
        fn () => app(RemoveMembership::class)($membership),
    );

    app(TenantTransaction::class)->asTenant(LOG_TENANT, function () use ($membership): void {
        expect(Membership::query()->whereKey($membership->id)->exists())->toBeFalse();
    });
});
