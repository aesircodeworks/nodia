<?php

use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Slice 7 first half of the stage-02 plan: the Caddy on-demand TLS ask
 * endpoint (system-design 16.3). Unauthenticated by design and
 * network-internal; existence of the normalized domain in tenant_domains
 * is the gate, read under the nodia_resolver posture per the stage's
 * domain-resolution decision.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    app(TenantTransaction::class)->asPlatform(function (): void {
        $tenant = Tenant::factory()->create();

        TenantDomain::factory()->create([
            'tenant_id' => $tenant->id,
            'domain' => 'tickets.acme.com',
        ]);
    });
});

afterEach(function (): void {
    app(TenantTransaction::class)->asPlatform(function (): void {
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });
});

it('returns 204 with no body for a registered domain', function () {
    $this->getJson('/v1/internal/domain-verification?domain=tickets.acme.com')
        ->assertNoContent()
        ->assertConformsToOpenApi();
});

it('matches domains case-insensitively', function () {
    $this->getJson('/v1/internal/domain-verification?domain=Tickets.ACME.Com')
        ->assertNoContent()
        ->assertConformsToOpenApi();
});

it('returns the 404 unknown_domain problem for an unregistered domain', function () {
    $this->getJson('/v1/internal/domain-verification?domain=unknown.example.com')
        ->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'unknown_domain')
        ->assertJsonPath('status', 404);
});

it('returns 422 request.validation_failed when the domain parameter is missing', function () {
    // The spec marks the parameter required, so this request is
    // deliberately spec-invalid; the problem body is validated against the
    // component schema instead of the path entry.
    $this->getJson('/v1/internal/domain-verification')
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertMatchesProblemSchema('ValidationProblem')
        ->assertJsonPath('code', 'request.validation_failed')
        ->assertJsonPath('errors.domain.0', fn (string $message) => $message !== '');
});

it('returns 422 request.validation_failed for a malformed domain parameter', function (string $domain) {
    $this->getJson('/v1/internal/domain-verification?domain='.urlencode($domain))
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'request.validation_failed')
        ->assertJsonPath('errors.domain.0', fn (string $message) => $message !== '');
})->with([
    'embedded space' => 'not a hostname',
    'trailing root dot' => 'tickets.acme.com.',
    'port suffix' => 'tickets.acme.com:8443',
    'scheme prefix' => 'https://tickets.acme.com',
]);

it('answers without authentication, tenant context, or transaction residue', function () {
    $this->getJson('/v1/internal/domain-verification?domain=tickets.acme.com')->assertNoContent();

    expect(app(TenantContext::class)->hasTenant())->toBeFalse()
        ->and(DB::transactionLevel())->toBe(0);
});
