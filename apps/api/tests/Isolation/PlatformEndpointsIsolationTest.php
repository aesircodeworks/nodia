<?php

use Tests\Isolation\Support\TenantFixture;

/**
 * The platform tenant CRUD endpoints run under the platform route group
 * only. A request that injects the tenant-resolution headers (X-Tenant-Id,
 * Host) must not coerce them into a tenant-scoped posture: the platform
 * posture comes from the route group, never from headers (stage-02 plan,
 * Slice 4).
 */
beforeEach(function (): void {
    TenantFixture::seed();
});

afterEach(function (): void {
    TenantFixture::clean();
});

it('lists every tenant even when the request injects tenant A resolution headers', function () {
    $ids = collect(
        $this->getJson('/v1/tenants?sort=name', [
            'X-Tenant-Id' => TenantFixture::TENANT_A,
            'Host' => TenantFixture::DOMAIN_A,
        ])->assertOk()->json('data'),
    )->pluck('id');

    expect($ids)->toContain(TenantFixture::TENANT_A)
        ->and($ids)->toContain(TenantFixture::TENANT_B);
});

it('reads tenant B while injecting tenant A resolution headers', function () {
    $this->getJson('/v1/tenants/'.TenantFixture::TENANT_B, [
        'X-Tenant-Id' => TenantFixture::TENANT_A,
        'Host' => TenantFixture::DOMAIN_A,
    ])
        ->assertOk()
        ->assertJsonPath('id', TenantFixture::TENANT_B);
});

it('updates tenant B while injecting tenant A resolution headers', function () {
    $this->patchJson(
        '/v1/tenants/'.TenantFixture::TENANT_B,
        ['name' => 'Renamed Across The Injected Header'],
        ['X-Tenant-Id' => TenantFixture::TENANT_A, 'Host' => TenantFixture::DOMAIN_A],
    )
        ->assertOk()
        ->assertJsonPath('id', TenantFixture::TENANT_B)
        ->assertJsonPath('name', 'Renamed Across The Injected Header');
});

it('creates a tenant under the platform posture regardless of injected headers', function () {
    $created = $this->postJson('/v1/tenants', [
        'name' => 'Created Despite Injected Headers',
        'default_locale' => 'en',
        'supported_locales' => ['en'],
    ], [
        'X-Tenant-Id' => TenantFixture::TENANT_A,
        'Host' => TenantFixture::DOMAIN_A,
    ])->assertCreated()->json();

    expect($created['id'])->not->toBe(TenantFixture::TENANT_A);

    $this->getJson('/v1/tenants/'.$created['id'])
        ->assertOk()
        ->assertJsonPath('name', 'Created Despite Injected Headers');
});
