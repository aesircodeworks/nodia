<?php

use Tests\Isolation\Support\TenantFixture;

/**
 * The domain endpoints run under the platform route group only, so a
 * request that injects the tenant-resolution headers (X-Tenant-Id, Host)
 * must not coerce them into a tenant-scoped posture (stage-02 plan, Slice
 * 5). The complementary assertion, that tenant A's admin listing can never
 * include tenant B's domains, activates in task-11 when the admin group
 * gains its resolution middleware; no admin route exists to exercise yet.
 */
beforeEach(function (): void {
    TenantFixture::seed();
});

afterEach(function (): void {
    TenantFixture::clean();
});

it('lists tenant B domains even when the request injects tenant A resolution headers', function () {
    $domains = collect(
        $this->getJson('/v1/tenants/'.TenantFixture::TENANT_B.'/domains', [
            'X-Tenant-Id' => TenantFixture::TENANT_A,
            'Host' => TenantFixture::DOMAIN_A,
        ])->assertOk()->json('data'),
    );

    expect($domains->pluck('domain'))->toContain(TenantFixture::DOMAIN_B)
        ->and($domains->pluck('tenant_id')->unique()->all())->toBe([TenantFixture::TENANT_B]);
});

it('registers a domain for tenant B while injecting tenant A resolution headers', function () {
    $this->postJson('/v1/tenants/'.TenantFixture::TENANT_B.'/domains', [
        'domain' => 'injected.tenant-b.example',
    ], [
        'X-Tenant-Id' => TenantFixture::TENANT_A,
        'Host' => TenantFixture::DOMAIN_A,
    ])
        ->assertCreated()
        ->assertJsonPath('tenant_id', TenantFixture::TENANT_B)
        ->assertJsonPath('domain', 'injected.tenant-b.example');
});

it('removes a tenant B domain while injecting tenant A resolution headers', function () {
    $created = $this->postJson('/v1/tenants/'.TenantFixture::TENANT_B.'/domains', [
        'domain' => 'removable.tenant-b.example',
    ])->assertCreated()->json();

    $this->deleteJson('/v1/tenant-domains/'.$created['id'], [], [
        'X-Tenant-Id' => TenantFixture::TENANT_A,
        'Host' => TenantFixture::DOMAIN_A,
    ])->assertNoContent();
});
