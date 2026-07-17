<?php

use App\Identity\Capability;
use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Audit\Models\ActivityLogEntry;
use App\Support\Tenancy\PlatformRoleAudit;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PlatformStaff;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;
use Tests\TestCase;

/*
 * Slice 7 second half of the stage-02 plan, upgraded from a structured
 * log line to a real activity_log row by stage-03 task breakdown item 15
 * (App\Support\Audit\ActivityLogger): every request executing under
 * nodia_platform is recorded, keyed by the request correlation ID
 * (system-design 4.3). The task-11 decision put storefront host
 * resolution and the verification endpoint on nodia_resolver, so the
 * platform CRUD group is the only production surface under this audit.
 *
 * activity_log is append-only (task-14: no application role ever holds
 * UPDATE or DELETE privilege on it), so rows from earlier tests in this
 * same process are never removed and accumulate for the rest of the run.
 * Every assertion below filters by a correlation id unique to its own
 * request (either generated fresh here or read back from the response's
 * own echoed X-Correlation-Id header) rather than a total row count, so
 * accumulated rows from other tests can never produce a false pass.
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

    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ([...$tenantIds, $sentinel] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
            DB::table('memberships')->where('tenant_id', $tenantId)->delete();
        });
    }

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

/**
 * @return Collection<int, ActivityLogEntry>
 */
function platformAuditEntries(string $correlationId): Collection
{
    return app(TenantTransaction::class)->asPlatform(
        fn () => ActivityLogEntry::query()
            ->where('event', PlatformRoleAudit::EVENT)
            ->get()
            ->filter(fn (ActivityLogEntry $entry): bool => $entry->properties?->get('correlation_id') === $correlationId)
            ->values(),
    );
}

it('emits exactly one audit entry carrying the correlation id for every platform CRUD request, error paths included', function (string $method, string $uri, array $body, int $status) {
    $token = PlatformStaff::token();
    $path = auditPath($uri);

    $response = $this->json($method, $path, $body, [
        'Authorization' => 'Bearer '.$token,
    ])->assertStatus($status);

    $correlationId = $response->headers->get('X-Correlation-Id');
    $entries = platformAuditEntries($correlationId);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->description)->toBe(sprintf('%s %s', $method, $path))
        ->and($entries[0]->tenant_id)->toBe(config()->string('tenancy.platform_tenant_id'))
        ->and($entries[0]->properties->get('platform_scope'))->toBeTrue();
})->with('platform crud requests');

it('carries the generated correlation id when the request supplies none', function () {
    $token = PlatformStaff::token();

    $response = $this->getJson('/v1/tenants', ['Authorization' => 'Bearer '.$token])->assertOk();

    $correlationId = $response->headers->get('X-Correlation-Id');

    expect(Str::isUuid($correlationId))->toBeTrue();

    $entries = platformAuditEntries($correlationId);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->properties->get('correlation_id'))->toBe($correlationId);
});

it('still emits the audit entry when the platform handler fails and the transaction rolls back', function () {
    Route::middleware('tenancy.platform')->prefix('v1')->get('/__probe/audit-throwing', function (): never {
        throw new RuntimeException('handler failed');
    });

    $token = PlatformStaff::token();
    $correlationId = (string) Str::uuid();

    $this->getJson('/v1/__probe/audit-throwing', [
        'X-Correlation-Id' => $correlationId,
        'Authorization' => 'Bearer '.$token,
    ])->assertStatus(500);

    expect(platformAuditEntries($correlationId))->toHaveCount(1);
});

it('emits no audit entry when platform auth denies the request before the role is assumed (no bearer)', function () {
    $response = $this->getJson('/v1/tenants')->assertUnauthorized();

    expect(platformAuditEntries($response->headers->get('X-Correlation-Id')))->toBeEmpty();
});

it('still emits the audit entry when the bearer lacks tenants.manage, since the platform role was already assumed', function () {
    $token = PlatformStaff::token(Capability::EventsView);

    $response = $this->getJson('/v1/tenants', ['Authorization' => 'Bearer '.$token])->assertStatus(403);

    expect(platformAuditEntries($response->headers->get('X-Correlation-Id')))->toHaveCount(1);
});

it('emits no audit entry for requests that never assume the platform role', function (callable $request) {
    $correlationId = $request($this);

    expect(platformAuditEntries($correlationId))->toBeEmpty();
})->with([
    'domain verification (nodia_resolver)' => [function (TestCase $test): ?string {
        return $test->getJson('/v1/internal/domain-verification?domain=acme.nodia.example')
            ->assertNoContent()
            ->headers->get('X-Correlation-Id');
    }],
    'storefront host resolution (nodia_app)' => [function (TestCase $test): ?string {
        Route::middleware('tenancy.storefront')->prefix('v1')->get('/__probe/audit-storefront', fn () => response()->noContent());

        return $test->getJson('http://acme.nodia.example/v1/__probe/audit-storefront')
            ->assertNoContent()
            ->headers->get('X-Correlation-Id');
    }],
    'admin header resolution (nodia_app)' => [function (TestCase $test): ?string {
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

        $correlationId = $test->getJson('/v1/__probe/audit-admin', [
            'X-Tenant-Id' => AUDIT_TENANT_ID,
            'Authorization' => 'Bearer '.$token,
        ])->assertNoContent()->headers->get('X-Correlation-Id');

        app(TenantTransaction::class)->asTenant(AUDIT_TENANT_ID, function (): void {
            DB::table('memberships')->delete();
        });

        app(TenantTransaction::class)->asPlatform(function (): void {
            DB::table('roles')->whereNotNull('tenant_id')->delete();
        });

        User::query()->whereKey($staff->id)->delete();

        return $correlationId;
    }],
]);
