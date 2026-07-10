<?php

use App\Identity\Capability;
use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Tenancy\PlatformRoleAudit;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Monolog\Level;
use Tests\Support\LogCapture;
use Tests\Support\MigratedDatabase;
use Tests\Support\PlatformStaff;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;

/*
 * Slice 7 second half of the stage-02 plan: every request executing under
 * nodia_platform emits a structured audit log entry carrying the request
 * correlation ID (system-design 4.3), asserted via the log fake. The
 * task-11 decision put storefront host resolution and the verification
 * endpoint on nodia_resolver, so the platform CRUD group is the only
 * production surface under audit.
 */

const AUDIT_TENANT_ID = '019797f1-0000-7000-8000-0000000000aa';
const AUDIT_SECONDARY_DOMAIN_ID = '019797f1-0000-7000-8000-0000000000ab';
const AUDIT_UNKNOWN_UUID = '019797f1-0000-7000-8000-00000000dead';

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::factory()->create(['id' => AUDIT_TENANT_ID, 'name' => 'Acme']);

        TenantDomain::factory()->create([
            'tenant_id' => AUDIT_TENANT_ID,
            'domain' => 'acme.nodia.example',
            'is_primary' => true,
        ]);
        TenantDomain::factory()->create([
            'id' => AUDIT_SECONDARY_DOMAIN_ID,
            'tenant_id' => AUDIT_TENANT_ID,
            'domain' => 'tickets.acme.com',
        ]);
    });
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

dataset('platform crud requests', [
    'list tenants (200)' => ['GET', '/v1/tenants', [], 200],
    'create tenant (201)' => ['POST', '/v1/tenants', ['name' => 'Globex', 'default_locale' => 'en', 'supported_locales' => ['en']], 201],
    'create tenant, validation failure (422)' => ['POST', '/v1/tenants', ['name' => ''], 422],
    'show tenant (200)' => ['GET', '/v1/tenants/{tenant}', [], 200],
    'show unknown tenant (404)' => ['GET', '/v1/tenants/{unknown}', [], 404],
    'update tenant (200)' => ['PATCH', '/v1/tenants/{tenant}', ['name' => 'Renamed'], 200],
    'register domain (201)' => ['POST', '/v1/tenants/{tenant}/domains', ['domain' => 'audit.acme.com'], 201],
    'list domains (200)' => ['GET', '/v1/tenants/{tenant}/domains', [], 200],
    'make domain primary (200)' => ['PATCH', '/v1/tenant-domains/{secondary}', ['is_primary' => true], 200],
    'remove domain (204)' => ['DELETE', '/v1/tenant-domains/{secondary}', [], 204],
]);

function auditPath(string $uri): string
{
    return strtr($uri, [
        '{tenant}' => AUDIT_TENANT_ID,
        '{secondary}' => AUDIT_SECONDARY_DOMAIN_ID,
        '{unknown}' => AUDIT_UNKNOWN_UUID,
    ]);
}

it('emits exactly one audit entry carrying the correlation id for every platform CRUD request, error paths included', function (string $method, string $uri, array $body, int $status) {
    $token = PlatformStaff::token();
    $handler = LogCapture::fake();

    $path = auditPath($uri);

    $this->json($method, $path, $body, [
        'X-Correlation-Id' => 'audit-test-correlation-id',
        'Authorization' => 'Bearer '.$token,
    ])
        ->assertStatus($status);

    $entries = LogCapture::entriesNamed($handler, PlatformRoleAudit::MESSAGE);

    // toEqual, not toBe: the shared log context set by the CorrelationId
    // middleware merges its correlation_id key ahead of the entry's own.
    expect($entries)->toHaveCount(1)
        ->and($entries[0]->level)->toBe(Level::Info)
        ->and($entries[0]->context)->toEqual([
            'role' => 'nodia_platform',
            'correlation_id' => 'audit-test-correlation-id',
            'method' => $method,
            'path' => $path,
        ]);
})->with('platform crud requests');

it('carries the generated correlation id when the request supplies none', function () {
    $token = PlatformStaff::token();
    $handler = LogCapture::fake();

    $response = $this->getJson('/v1/tenants', ['Authorization' => 'Bearer '.$token])->assertOk();

    $correlationId = $response->headers->get('X-Correlation-Id');

    expect(Str::isUuid($correlationId))->toBeTrue();

    $entries = LogCapture::entriesNamed($handler, PlatformRoleAudit::MESSAGE);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->context['correlation_id'])->toBe($correlationId);
});

it('still emits the audit entry when the platform handler fails and the transaction rolls back', function () {
    Route::middleware('tenancy.platform')->prefix('v1')->get('/__probe/audit-throwing', function (): never {
        throw new RuntimeException('handler failed');
    });

    $token = PlatformStaff::token();
    $handler = LogCapture::fake();

    $this->getJson('/v1/__probe/audit-throwing', [
        'X-Correlation-Id' => 'audit-rollback-correlation-id',
        'Authorization' => 'Bearer '.$token,
    ])
        ->assertStatus(500);

    $entries = LogCapture::entriesNamed($handler, PlatformRoleAudit::MESSAGE);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->context['correlation_id'])->toBe('audit-rollback-correlation-id');
});

it('emits no audit entry when platform auth denies the request before the role is assumed (no bearer)', function () {
    $handler = LogCapture::fake();

    $this->getJson('/v1/tenants')->assertUnauthorized();

    expect(LogCapture::entriesNamed($handler, PlatformRoleAudit::MESSAGE))->toBeEmpty();
});

it('still emits the audit entry when the bearer lacks tenants.manage, since the platform role was already assumed', function () {
    $token = PlatformStaff::token(Capability::EventsView);
    $handler = LogCapture::fake();

    $this->getJson('/v1/tenants', ['Authorization' => 'Bearer '.$token])->assertStatus(403);

    $entries = LogCapture::entriesNamed($handler, PlatformRoleAudit::MESSAGE);

    expect($entries)->toHaveCount(1);
});

it('emits no audit entry for requests that never assume the platform role', function (callable $request) {
    $handler = LogCapture::fake();

    $request($this);

    expect(LogCapture::entriesNamed($handler, PlatformRoleAudit::MESSAGE))->toBeEmpty();
})->with([
    'domain verification (nodia_resolver)' => [function ($test): void {
        $test->getJson('/v1/internal/domain-verification?domain=acme.nodia.example')->assertNoContent();
    }],
    'storefront host resolution (nodia_app)' => [function ($test): void {
        Route::middleware('tenancy.storefront')->prefix('v1')->get('/__probe/audit-storefront', fn () => response()->noContent());

        $test->getJson('http://acme.nodia.example/v1/__probe/audit-storefront')->assertNoContent();
    }],
    'admin header resolution (nodia_app)' => [function ($test): void {
        Route::middleware('tenancy.admin')->prefix('v1')->get('/__probe/audit-admin', fn () => response()->noContent());

        $staff = User::factory()->create();
        $token = StaffTokens::issue($staff);

        app(TenantTransaction::class)->asTenant(AUDIT_TENANT_ID, function () use ($staff): void {
            Membership::factory()->create([
                'user_id' => $staff->id,
                'tenant_id' => AUDIT_TENANT_ID,
                'role_id' => Role::factory()->create(['tenant_id' => AUDIT_TENANT_ID])->id,
                'scope' => MembershipScope::Tenant,
            ]);
        });

        $test->getJson('/v1/__probe/audit-admin', [
            'X-Tenant-Id' => AUDIT_TENANT_ID,
            'Authorization' => 'Bearer '.$token,
        ])->assertNoContent();

        app(TenantTransaction::class)->asTenant(AUDIT_TENANT_ID, function (): void {
            DB::table('memberships')->delete();
        });

        app(TenantTransaction::class)->asPlatform(function (): void {
            DB::table('roles')->whereNotNull('tenant_id')->delete();
        });

        User::query()->whereKey($staff->id)->delete();
    }],
]);
