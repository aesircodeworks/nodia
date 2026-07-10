<?php

use App\Models\User;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;
use Tests\Isolation\Support\TenantFixture;
use Tests\Support\PlatformStaff;

use function Tests\Isolation\Support\actingAsRole;

/**
 * The domain endpoints run under the platform route group only, so a
 * request that injects the tenant-resolution headers (X-Tenant-Id, Host)
 * must not coerce them into a tenant-scoped posture (stage-02 plan, Slice
 * 5). The complementary assertion, that tenant A's admin listing can never
 * include tenant B's domains, activates in task-11 when the admin group
 * gains its resolution middleware; no admin route exists to exercise yet.
 * Every request below needs a bearer holding tenants.manage (stage-03
 * plan, task breakdown item 7) to reach the handler at all.
 */
beforeEach(function (): void {
    TenantFixture::seed();
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    actingAsRole(Rls::APP_ROLE, $sentinel, function () use ($sentinel): void {
        DB::table('outbox_deliveries')->where('tenant_id', $sentinel)->delete();
        DB::table('outbox_events')->where('tenant_id', $sentinel)->delete();
        DB::table('memberships')->delete();
    });

    foreach ([TenantFixture::TENANT_A, TenantFixture::TENANT_B] as $tenantId) {
        actingAsRole(Rls::APP_ROLE, $tenantId, function () use ($tenantId): void {
            DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
        });
    }

    actingAsRole(Rls::PLATFORM_ROLE, null, function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
    });

    User::query()->delete();

    TenantFixture::clean();
});

it('lists tenant B domains even when the request injects tenant A resolution headers', function () {
    $token = PlatformStaff::token();

    $domains = collect(
        $this->getJson('/v1/tenants/'.TenantFixture::TENANT_B.'/domains', [
            'X-Tenant-Id' => TenantFixture::TENANT_A,
            'Host' => TenantFixture::DOMAIN_A,
            'Authorization' => 'Bearer '.$token,
        ])->assertOk()->json('data'),
    );

    expect($domains->pluck('domain'))->toContain(TenantFixture::DOMAIN_B)
        ->and($domains->pluck('tenant_id')->unique()->all())->toBe([TenantFixture::TENANT_B]);
});

it('registers a domain for tenant B while injecting tenant A resolution headers', function () {
    $token = PlatformStaff::token();

    $this->postJson('/v1/tenants/'.TenantFixture::TENANT_B.'/domains', [
        'domain' => 'injected.tenant-b.example',
    ], [
        'X-Tenant-Id' => TenantFixture::TENANT_A,
        'Host' => TenantFixture::DOMAIN_A,
        'Authorization' => 'Bearer '.$token,
    ])
        ->assertCreated()
        ->assertJsonPath('tenant_id', TenantFixture::TENANT_B)
        ->assertJsonPath('domain', 'injected.tenant-b.example');
});

it('removes a tenant B domain while injecting tenant A resolution headers', function () {
    $token = PlatformStaff::token();

    $created = $this->postJson('/v1/tenants/'.TenantFixture::TENANT_B.'/domains', [
        'domain' => 'removable.tenant-b.example',
    ], ['Authorization' => 'Bearer '.$token])->assertCreated()->json();

    $this->deleteJson('/v1/tenant-domains/'.$created['id'], [], [
        'X-Tenant-Id' => TenantFixture::TENANT_A,
        'Host' => TenantFixture::DOMAIN_A,
        'Authorization' => 'Bearer '.$token,
    ])->assertNoContent();
});
