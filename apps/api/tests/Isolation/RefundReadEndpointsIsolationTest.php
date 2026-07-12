<?php

use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;
use Tests\Isolation\Support\RefundFixture;
use Tests\Isolation\Support\TenantFixture;
use Tests\Support\StaffTokens;

use function Tests\Isolation\Support\actingAsRole;

/*
 * Stage-08b plan, Slice 8 mandates endpoint-level isolation for the new
 * refund read routes: exercises the real GET /v1/refunds and
 * GET /v1/refunds/{refund} handlers through the tenancy.admin group,
 * proving the refunds table's tenant policy makes tenant A's refund
 * genuinely invisible to a tenant B caller who holds orders.view, not
 * merely denied by a capability check. Runs under the downgraded
 * nodia_isolation connection, so a RLS regression here fails loudly
 * rather than leaking through a BYPASSRLS default.
 *
 * One issueTenantBToken() call per test: Illuminate\Auth\RequestGuard
 * caches the resolved user on the guard instance once resolved, the same
 * hazard EventMediaEndpointsIsolationTest records.
 */

beforeEach(function (): void {
    RefundFixture::seed();
});

afterEach(function (): void {
    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
        DB::table('memberships')->where('tenant_id', TenantFixture::TENANT_B)->delete();
    });

    actingAsRole(Rls::PLATFORM_ROLE, null, function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        DB::table('users')->delete();
    });

    RefundFixture::clean();
});

function issueTenantBRefundToken(): string
{
    $roleId = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => Role::factory()->create([
            'tenant_id' => TenantFixture::TENANT_B,
            'name' => 'Tenant B Refund Reader',
            'capabilities' => ['orders.view'],
        ])->id,
    );

    $user = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => User::factory()->create());
    $token = StaffTokens::issue($user);

    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function () use ($user, $roleId): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => TenantFixture::TENANT_B,
            'role_id' => $roleId,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    return $token;
}

it('renders refund_not_found, not the record, for a foreign tenant refund id on show', function () {
    $token = issueTenantBRefundToken();

    test()->getJson('/v1/refunds/'.RefundFixture::REFUND_A, [
        'X-Tenant-Id' => TenantFixture::TENANT_B,
        'Authorization' => 'Bearer '.$token,
    ])
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'refund_not_found')
        ->assertJsonPath('status', 404);
});

it('never lists a foreign tenant refund on index', function () {
    $token = issueTenantBRefundToken();

    $response = test()->getJson('/v1/refunds', [
        'X-Tenant-Id' => TenantFixture::TENANT_B,
        'Authorization' => 'Bearer '.$token,
    ])->assertOk();

    $ids = array_column($response->json('data'), 'id');

    expect($ids)->toBe([RefundFixture::REFUND_B]);
});
