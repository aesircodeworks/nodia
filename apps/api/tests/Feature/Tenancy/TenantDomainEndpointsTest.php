<?php

use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PlatformStaff;
use Tests\Support\PostgresTestDatabase;

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    // task breakdown item 7: the tenancy.platform group now requires a
    // Passport bearer plus tenants.manage (see
    // tests/Feature/Tenancy/PlatformCapabilityAuthorizationTest.php for the
    // denial matrix); every request below needs a capable bearer to reach
    // the handlers this file actually exercises.
    $this->withHeaders(['Authorization' => 'Bearer '.PlatformStaff::token()]);
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    app(TenantTransaction::class)->asTenant($sentinel, function (): void {
        DB::table('memberships')->delete();
    });

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });

    User::query()->delete();
});

function domainTenant(array $attributes = []): Tenant
{
    return app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create($attributes),
    );
}

function domainRow(Tenant $tenant, array $attributes = []): TenantDomain
{
    return app(TenantTransaction::class)->asPlatform(
        fn () => TenantDomain::factory()->create(['tenant_id' => $tenant->id, ...$attributes]),
    );
}

function primaryDomainCount(Tenant $tenant): int
{
    return app(TenantTransaction::class)->asPlatform(
        fn () => TenantDomain::query()->where('tenant_id', $tenant->id)->where('is_primary', true)->count(),
    );
}

describe('POST /v1/tenants/{tenant}/domains', function () {
    it('registers a domain and returns the TenantDomainData wire shape', function () {
        $tenant = domainTenant();

        $response = $this->postJson('/v1/tenants/'.$tenant->id.'/domains', [
            'domain' => 'tickets.acme.com',
        ]);

        $response->assertCreated()
            ->assertConformsToOpenApi()
            ->assertJson([
                'tenant_id' => $tenant->id,
                'domain' => 'tickets.acme.com',
                'is_primary' => false,
            ]);

        $body = $response->json();

        expect(Str::isUuid($body['id']))->toBeTrue()
            ->and($body['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
            ->and($body['updated_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
            ->and(array_keys($body))->toBe([
                'id', 'tenant_id', 'domain', 'is_primary', 'created_at', 'updated_at',
            ]);
    });

    it('normalizes a mixed-case domain to lowercase on the wire', function () {
        $tenant = domainTenant();

        $this->postJson('/v1/tenants/'.$tenant->id.'/domains', [
            'domain' => 'Tickets.ACME.Com',
        ])
            ->assertCreated()
            ->assertConformsToOpenApi()
            ->assertJsonPath('domain', 'tickets.acme.com');
    });

    it('honors an explicit is_primary flag', function () {
        $tenant = domainTenant();

        $this->postJson('/v1/tenants/'.$tenant->id.'/domains', [
            'domain' => 'primary.acme.com',
            'is_primary' => true,
        ])
            ->assertCreated()
            ->assertConformsToOpenApi()
            ->assertJsonPath('is_primary', true);
    });

    it('returns a tenant_not_found problem for an unknown tenant id', function () {
        $this->postJson('/v1/tenants/'.Str::uuid7().'/domains', ['domain' => 'ghost.acme.com'])
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'tenant_not_found')
            ->assertJsonPath('status', 404);
    });

    it('returns a domain_already_registered problem when the domain is taken by any tenant', function () {
        $owner = domainTenant();
        domainRow($owner, ['domain' => 'taken.acme.com']);

        $other = domainTenant();

        $this->postJson('/v1/tenants/'.$other->id.'/domains', ['domain' => 'taken.acme.com'])
            ->assertConflict()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'domain_already_registered')
            ->assertJsonPath('status', 409);
    });

    it('detects the duplicate across case, since the stored domain is the lowercase form', function () {
        $tenant = domainTenant();
        domainRow($tenant, ['domain' => 'taken.acme.com']);

        $this->postJson('/v1/tenants/'.$tenant->id.'/domains', ['domain' => 'Taken.Acme.COM'])
            ->assertConflict()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'domain_already_registered');
    });

    it('returns a tenant_domain_is_primary problem when registering a second primary domain', function () {
        $tenant = domainTenant();
        domainRow($tenant, ['domain' => 'first.acme.com', 'is_primary' => true]);

        $this->postJson('/v1/tenants/'.$tenant->id.'/domains', [
            'domain' => 'second.acme.com',
            'is_primary' => true,
        ])
            ->assertConflict()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'tenant_domain_is_primary')
            ->assertJsonPath('status', 409);

        expect(primaryDomainCount($tenant))->toBe(1);
    });

    it('rejects a malformed hostname with a request.validation_failed problem carrying the errors map', function (string $domain) {
        $tenant = domainTenant();

        $response = $this->postJson('/v1/tenants/'.$tenant->id.'/domains', ['domain' => $domain]);

        $response->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed')
            ->assertJsonPath('status', 422);

        expect($response->json('errors'))->toHaveKey('domain');
    })->with([
        'empty' => [''],
        'space inside' => ['not a hostname'],
        'trailing root dot' => ['acme.com.'],
        'underscore label' => ['bad_label.acme.com'],
    ]);
});

describe('GET /v1/tenants/{tenant}/domains', function () {
    it('returns the paginator envelope with only that tenant domains, sorted by domain', function () {
        $tenant = domainTenant();
        domainRow($tenant, ['domain' => 'b.acme.com']);
        domainRow($tenant, ['domain' => 'a.acme.com', 'is_primary' => true]);

        $other = domainTenant();
        domainRow($other, ['domain' => 'other.example.com']);

        $response = $this->getJson('/v1/tenants/'.$tenant->id.'/domains');

        $response->assertOk()->assertConformsToOpenApi();

        $body = $response->json();

        expect(array_keys($body))->toContain('data', 'links', 'meta')
            ->and(collect($body['data'])->pluck('domain')->all())->toBe(['a.acme.com', 'b.acme.com'])
            ->and(collect($body['data'])->pluck('tenant_id')->unique()->all())->toBe([$tenant->id]);
    });

    it('returns a tenant_not_found problem for an unknown tenant id', function () {
        $this->getJson('/v1/tenants/'.Str::uuid7().'/domains')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'tenant_not_found');
    });
});

describe('PATCH /v1/tenant-domains/{tenant_domain}', function () {
    it('promotes the target and demotes the previous primary', function () {
        $tenant = domainTenant();
        $primary = domainRow($tenant, ['domain' => 'old.acme.com', 'is_primary' => true]);
        $target = domainRow($tenant, ['domain' => 'new.acme.com']);

        $this->patchJson('/v1/tenant-domains/'.$target->id, ['is_primary' => true])
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonPath('id', $target->id)
            ->assertJsonPath('is_primary', true);

        $rows = app(TenantTransaction::class)->asPlatform(
            fn () => TenantDomain::query()->where('tenant_id', $tenant->id)->get()->keyBy('id'),
        );

        expect($rows[$target->id]->is_primary)->toBeTrue()
            ->and($rows[$primary->id]->is_primary)->toBeFalse()
            ->and(primaryDomainCount($tenant))->toBe(1);
    });

    it('is a no-op replay when the target is already primary', function () {
        $tenant = domainTenant();
        $primary = domainRow($tenant, ['domain' => 'main.acme.com', 'is_primary' => true]);

        $this->patchJson('/v1/tenant-domains/'.$primary->id, ['is_primary' => true])
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonPath('is_primary', true);

        expect(primaryDomainCount($tenant))->toBe(1);
    });

    it('returns the current state when is_primary is absent', function () {
        $tenant = domainTenant();
        $domain = domainRow($tenant, ['domain' => 'stay.acme.com']);

        $this->patchJson('/v1/tenant-domains/'.$domain->id, [])
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonPath('id', $domain->id)
            ->assertJsonPath('is_primary', false);
    });

    it('rejects a demotion with a request.validation_failed problem, since demotion happens by promoting another domain', function () {
        $tenant = domainTenant();
        $primary = domainRow($tenant, ['domain' => 'main.acme.com', 'is_primary' => true]);

        $this->patchJson('/v1/tenant-domains/'.$primary->id, ['is_primary' => false])
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');

        expect(primaryDomainCount($tenant))->toBe(1);
    });

    it('returns a tenant_domain_not_found problem for an unknown domain id', function () {
        $this->patchJson('/v1/tenant-domains/'.Str::uuid7(), ['is_primary' => true])
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'tenant_domain_not_found')
            ->assertJsonPath('status', 404);
    });

    it('returns a request.not_found problem for a malformed domain id that matches no route', function () {
        $this->patchJson('/v1/tenant-domains/not-a-uuid', ['is_primary' => true])
            ->assertNotFound()
            ->assertMatchesProblemSchema()
            ->assertJsonPath('code', 'request.not_found');
    });
});

describe('DELETE /v1/tenant-domains/{tenant_domain}', function () {
    it('removes a non-primary domain', function () {
        $tenant = domainTenant();
        $domain = domainRow($tenant, ['domain' => 'gone.acme.com']);

        $this->deleteJson('/v1/tenant-domains/'.$domain->id)
            ->assertNoContent()
            ->assertConformsToOpenApi();

        $remaining = app(TenantTransaction::class)->asPlatform(
            fn () => TenantDomain::query()->whereKey($domain->id)->exists(),
        );

        expect($remaining)->toBeFalse();
    });

    it('refuses to remove the primary domain with a tenant_domain_is_primary problem', function () {
        $tenant = domainTenant();
        $primary = domainRow($tenant, ['domain' => 'main.acme.com', 'is_primary' => true]);

        $this->deleteJson('/v1/tenant-domains/'.$primary->id)
            ->assertConflict()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'tenant_domain_is_primary')
            ->assertJsonPath('status', 409);

        expect(primaryDomainCount($tenant))->toBe(1);
    });

    it('returns a tenant_domain_not_found problem for an unknown domain id', function () {
        $this->deleteJson('/v1/tenant-domains/'.Str::uuid7())
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'tenant_domain_not_found');
    });
});
