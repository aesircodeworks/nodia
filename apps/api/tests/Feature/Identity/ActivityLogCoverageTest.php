<?php

use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Customer;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Identity\Support\ClaimToken;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PlatformStaff;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;
use Tests\TestCase;

/*
 * Stage-03 plan, Slice 7 (task breakdown item 15): "a data-driven test
 * over the route list proving every mutating endpoint shipped in Stages
 * 2 and 3 writes an entry in the acting tenant (Stage 2 tenant/domain
 * CRUD included per task-07)." One dataset row per mutating route that
 * exists by this point in the plan; each row is self-contained (builds
 * whatever fixtures its own route needs, performs the request, and
 * asserts the acting tenant's activity_log count grew by exactly one),
 * mirroring the "each row proves itself end to end" style
 * tests/Feature/Tenancy/PlatformRoleAuditTest.php's own closure-dataset
 * cases already establish.
 *
 * Stage 2's five tenant/domain mutations are already exhaustively proven
 * by PlatformRoleAuditTest.php's own "platform crud requests" dataset
 * (every platform-group request, mutating or not, gets one entry under
 * the sentinel tenant, since that group's audit is PlatformRoleAudit, not
 * this file's RecordActivityAudit); two representative rows are repeated
 * here only so this file is a single, complete, literal answer to "every
 * mutating endpoint," not a claim that Stage 2 needed a second audit
 * mechanism (it does not: RecordActivityAudit is never attached to the
 * platform.php routes).
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ([...$tenantIds, $sentinel] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
            DB::table('memberships')->where('tenant_id', $tenantId)->delete();
            DB::table('customers')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });

    User::query()->delete();
});

function activityLogCountForCoverageTenant(string $tenantId): int
{
    return app(TenantTransaction::class)->asPlatform(
        fn () => DB::table('activity_log')->where('tenant_id', $tenantId)->count(),
    );
}

/**
 * @param  list<string>  $capabilities
 */
function coverageAdminBearer(string $tenantId, array $capabilities): string
{
    $user = User::factory()->create();
    $token = StaffTokens::issue($user);

    app(TenantTransaction::class)->asTenant($tenantId, function () use ($user, $tenantId, $capabilities): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'role_id' => Role::factory()->create(['tenant_id' => $tenantId, 'capabilities' => $capabilities])->id,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    return $token;
}

it('increases the acting tenant\'s activity_log count by exactly one for every mutating endpoint', function (callable $scenario) {
    $scenario($this);
})->with([
    'Stage 2: create tenant' => [function (TestCase $test): void {
        $token = PlatformStaff::token();
        $sentinel = config()->string('tenancy.platform_tenant_id');
        $before = activityLogCountForCoverageTenant($sentinel);

        $test->postJson('/v1/tenants', [
            'name' => 'Coverage Tenant Co',
            'default_locale' => 'en',
            'supported_locales' => ['en'],
        ], ['Authorization' => 'Bearer '.$token])->assertCreated();

        expect(activityLogCountForCoverageTenant($sentinel))->toBe($before + 1);
    }],
    'Stage 2: register a tenant domain' => [function (TestCase $test): void {
        $token = PlatformStaff::token();
        $sentinel = config()->string('tenancy.platform_tenant_id');
        $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
        $before = activityLogCountForCoverageTenant($sentinel);

        $test->postJson('/v1/tenants/'.$tenantId.'/domains', [
            'domain' => 'coverage.example.com',
        ], ['Authorization' => 'Bearer '.$token])->assertCreated();

        expect(activityLogCountForCoverageTenant($sentinel))->toBe($before + 1);
    }],
    'Stage 3: create a role' => [function (TestCase $test): void {
        $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
        $token = coverageAdminBearer($tenantId, ['roles.manage']);
        $before = activityLogCountForCoverageTenant($tenantId);

        $test->postJson('/v1/roles', [
            'name' => 'Coverage Role',
            'capabilities' => ['events.view'],
        ], ['Authorization' => 'Bearer '.$token, 'X-Tenant-Id' => $tenantId])->assertCreated();

        expect(activityLogCountForCoverageTenant($tenantId))->toBe($before + 1);
    }],
    'Stage 3: update a role' => [function (TestCase $test): void {
        $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
        $token = coverageAdminBearer($tenantId, ['roles.manage']);
        $roleId = app(TenantTransaction::class)->asTenant(
            $tenantId,
            fn () => Role::factory()->create(['tenant_id' => $tenantId])->id,
        );
        $before = activityLogCountForCoverageTenant($tenantId);

        $test->patchJson('/v1/roles/'.$roleId, [
            'name' => 'Renamed Coverage Role',
        ], ['Authorization' => 'Bearer '.$token, 'X-Tenant-Id' => $tenantId])->assertOk();

        expect(activityLogCountForCoverageTenant($tenantId))->toBe($before + 1);
    }],
    'Stage 3: delete a role' => [function (TestCase $test): void {
        $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
        $token = coverageAdminBearer($tenantId, ['roles.manage']);
        $roleId = app(TenantTransaction::class)->asTenant(
            $tenantId,
            fn () => Role::factory()->create(['tenant_id' => $tenantId])->id,
        );
        $before = activityLogCountForCoverageTenant($tenantId);

        $test->deleteJson('/v1/roles/'.$roleId, [], [
            'Authorization' => 'Bearer '.$token,
            'X-Tenant-Id' => $tenantId,
        ])->assertNoContent();

        expect(activityLogCountForCoverageTenant($tenantId))->toBe($before + 1);
    }],
    'Stage 3: invite a member' => [function (TestCase $test): void {
        $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
        $token = coverageAdminBearer($tenantId, ['memberships.manage']);
        $roleId = app(TenantTransaction::class)->asTenant(
            $tenantId,
            fn () => Role::factory()->create(['tenant_id' => $tenantId])->id,
        );
        $before = activityLogCountForCoverageTenant($tenantId);

        $test->postJson('/v1/memberships', [
            'email' => 'invitee@coverage.example.com',
            'name' => 'Invitee',
            'role_id' => $roleId,
        ], ['Authorization' => 'Bearer '.$token, 'X-Tenant-Id' => $tenantId])->assertCreated();

        expect(activityLogCountForCoverageTenant($tenantId))->toBe($before + 1);
    }],
    'Stage 3: change a membership\'s role' => [function (TestCase $test): void {
        $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
        $token = coverageAdminBearer($tenantId, ['memberships.manage']);

        $otherUser = User::factory()->create();
        [$membershipId, $newRoleId] = app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $otherUser): array {
            $roleId = Role::factory()->create(['tenant_id' => $tenantId])->id;
            $membership = Membership::factory()->create([
                'user_id' => $otherUser->id,
                'tenant_id' => $tenantId,
                'role_id' => $roleId,
                'scope' => MembershipScope::Tenant,
            ]);
            $newRoleId = Role::factory()->create(['tenant_id' => $tenantId])->id;

            return [$membership->id, $newRoleId];
        });

        $before = activityLogCountForCoverageTenant($tenantId);

        $test->patchJson('/v1/memberships/'.$membershipId, [
            'role_id' => $newRoleId,
        ], ['Authorization' => 'Bearer '.$token, 'X-Tenant-Id' => $tenantId])->assertOk();

        expect(activityLogCountForCoverageTenant($tenantId))->toBe($before + 1);
    }],
    'Stage 3: remove a membership' => [function (TestCase $test): void {
        $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
        $token = coverageAdminBearer($tenantId, ['memberships.manage']);

        $otherUser = User::factory()->create();
        $membershipId = app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $otherUser): string {
            return Membership::factory()->create([
                'user_id' => $otherUser->id,
                'tenant_id' => $tenantId,
                'role_id' => Role::factory()->create(['tenant_id' => $tenantId])->id,
                'scope' => MembershipScope::Tenant,
            ])->id;
        });

        $before = activityLogCountForCoverageTenant($tenantId);

        $test->deleteJson('/v1/memberships/'.$membershipId, [], [
            'Authorization' => 'Bearer '.$token,
            'X-Tenant-Id' => $tenantId,
        ])->assertNoContent();

        expect(activityLogCountForCoverageTenant($tenantId))->toBe($before + 1);
    }],
    'Stage 3: register a customer' => [function (TestCase $test): void {
        [$tenantId, $host] = app(TenantTransaction::class)->asPlatform(function (): array {
            $tenant = Tenant::factory()->create();
            $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

            return [$tenant->id, $domain->domain];
        });

        $before = activityLogCountForCoverageTenant($tenantId);

        $test->postJson('http://'.$host.'/v1/customers', [
            'email' => 'guest@coverage.example.com',
            'name' => 'Coverage Guest',
        ])->assertCreated();

        expect(activityLogCountForCoverageTenant($tenantId))->toBe($before + 1);
    }],
    'Stage 3: confirm a customer claim' => [function (TestCase $test): void {
        [$tenantId, $host] = app(TenantTransaction::class)->asPlatform(function (): array {
            $tenant = Tenant::factory()->create();
            $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

            return [$tenant->id, $domain->domain];
        });

        $guestId = app(TenantTransaction::class)->asTenant(
            $tenantId,
            fn () => Customer::factory()->create(['tenant_id' => $tenantId, 'password' => null])->id,
        );

        $token = ClaimToken::issue($guestId);
        $before = activityLogCountForCoverageTenant($tenantId);

        $test->postJson('http://'.$host.'/v1/auth/customer/claim/confirm', [
            'token' => $token,
            'password' => 'new-password',
        ])->assertNoContent();

        expect(activityLogCountForCoverageTenant($tenantId))->toBe($before + 1);
    }],
]);
