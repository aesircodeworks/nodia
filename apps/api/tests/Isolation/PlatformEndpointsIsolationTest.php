<?php

use App\Models\User;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;
use Tests\Isolation\Support\TenantFixture;
use Tests\Support\PlatformStaff;

use function Tests\Isolation\Support\actingAsRole;

/**
 * The platform tenant CRUD endpoints run under the platform route group
 * only. A request that injects the tenant-resolution headers (X-Tenant-Id,
 * Host) must not coerce them into a tenant-scoped posture: the platform
 * posture comes from the route group, never from headers (stage-02 plan,
 * Slice 4). Every request below needs a bearer holding tenants.manage
 * (stage-03 plan, task breakdown item 7) to reach the handler at all.
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

    actingAsRole(Rls::PLATFORM_ROLE, null, function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
    });

    User::query()->delete();

    TenantFixture::clean();
});

it('lists every tenant even when the request injects tenant A resolution headers', function () {
    $token = PlatformStaff::token();

    $ids = collect(
        $this->getJson('/v1/tenants?sort=name', [
            'X-Tenant-Id' => TenantFixture::TENANT_A,
            'Host' => TenantFixture::DOMAIN_A,
            'Authorization' => 'Bearer '.$token,
        ])->assertOk()->json('data'),
    )->pluck('id');

    expect($ids)->toContain(TenantFixture::TENANT_A)
        ->and($ids)->toContain(TenantFixture::TENANT_B);
});

it('reads tenant B while injecting tenant A resolution headers', function () {
    $token = PlatformStaff::token();

    $this->getJson('/v1/tenants/'.TenantFixture::TENANT_B, [
        'X-Tenant-Id' => TenantFixture::TENANT_A,
        'Host' => TenantFixture::DOMAIN_A,
        'Authorization' => 'Bearer '.$token,
    ])
        ->assertOk()
        ->assertJsonPath('id', TenantFixture::TENANT_B);
});

it('updates tenant B while injecting tenant A resolution headers', function () {
    $token = PlatformStaff::token();

    $this->patchJson(
        '/v1/tenants/'.TenantFixture::TENANT_B,
        ['name' => 'Renamed Across The Injected Header'],
        [
            'X-Tenant-Id' => TenantFixture::TENANT_A,
            'Host' => TenantFixture::DOMAIN_A,
            'Authorization' => 'Bearer '.$token,
        ],
    )
        ->assertOk()
        ->assertJsonPath('id', TenantFixture::TENANT_B)
        ->assertJsonPath('name', 'Renamed Across The Injected Header');
});

it('creates a tenant under the platform posture regardless of injected headers', function () {
    $token = PlatformStaff::token();

    $created = $this->postJson('/v1/tenants', [
        'name' => 'Created Despite Injected Headers',
        'default_locale' => 'en',
        'supported_locales' => ['en'],
    ], [
        'X-Tenant-Id' => TenantFixture::TENANT_A,
        'Host' => TenantFixture::DOMAIN_A,
        'Authorization' => 'Bearer '.$token,
    ])->assertCreated()->json();

    expect($created['id'])->not->toBe(TenantFixture::TENANT_A);

    $this->getJson('/v1/tenants/'.$created['id'], ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('name', 'Created Despite Injected Headers');
});
