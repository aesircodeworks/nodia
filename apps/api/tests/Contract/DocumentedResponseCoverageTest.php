<?php

use App\Identity\Capability;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Tests\Support\MigratedDatabase;
use Tests\Support\OpenApiSpec;
use Tests\Support\PostgresTestDatabase;

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    // contractPlatformBearer() recreates its membership and role idempotently
    // per call (its own docblock), so deleting them here every test is safe
    // and, unlike the contract-platform-staff/contract-incapable-staff User
    // rows deliberately kept by firstOrCreate, prevents a dangling
    // memberships row from blocking an unrelated later suite's blanket
    // User::query()->delete() (observed against StaffRefreshRotationContentionTest).
    app(TenantTransaction::class)->asTenant($sentinel, function (): void {
        DB::table('memberships')->delete();
    });

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

/**
 * Reused across dataset iterations the same way contractStaffUser() is:
 * firstOrCreate so repeated calls in this file reuse the same row instead
 * of colliding on the unique email, and the membership lookup below is
 * idempotent for the same reason (task breakdown item 7: the
 * tenancy.platform group now requires a bearer holding tenants.manage).
 */
function contractPlatformBearer(Capability $capability = Capability::TenantsManage): string
{
    $email = $capability === Capability::TenantsManage
        ? 'contract-platform-staff@example.com'
        : 'contract-incapable-staff@example.com';

    $user = User::query()->firstOrCreate(
        ['email' => $email],
        User::factory()->raw(['email' => $email]),
    );

    $response = test()->postJson('/v1/auth/staff/token', [
        'email' => $email,
        'password' => 'password',
    ]);

    $sentinel = config()->string('tenancy.platform_tenant_id');

    app(TenantTransaction::class)->asPlatform(function () use ($user, $capability, $sentinel): void {
        $hasMembership = Membership::query()
            ->where('user_id', $user->id)
            ->where('tenant_id', $sentinel)
            ->exists();

        if (! $hasMembership) {
            Membership::factory()->platform()->create([
                'user_id' => $user->id,
                'role_id' => Role::factory()->create([
                    'tenant_id' => $sentinel,
                    'capabilities' => [$capability->value],
                ])->id,
            ]);
        }
    });

    /** @var string $token */
    $token = $response->json('access_token');

    return $token;
}

function contractTenant(): Tenant
{
    return app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create(['default_locale' => 'en', 'supported_locales' => ['en']]),
    );
}

function contractDomain(array $attributes = []): TenantDomain
{
    return app(TenantTransaction::class)->asPlatform(
        fn () => TenantDomain::factory()->create($attributes),
    );
}

function contractStaffUser(): User
{
    // firstOrCreate so repeated calls across dataset iterations in this
    // file (contract tests do not truncate `users` between cases) reuse
    // the same row instead of colliding on the unique email.
    return User::query()->firstOrCreate(
        ['email' => 'contract-staff@example.com'],
        User::factory()->raw(['email' => 'contract-staff@example.com']),
    );
}

function contractStaffBearer(): string
{
    return contractStaffTokenPair()['access_token'];
}

/**
 * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
 */
function contractStaffTokenPair(): array
{
    contractStaffUser();

    $response = test()->postJson('/v1/auth/staff/token', [
        'email' => 'contract-staff@example.com',
        'password' => 'password',
    ]);

    /** @var array{access_token: string, refresh_token: string, token_type: string, expires_in: int} $pair */
    $pair = $response->json();

    return $pair;
}

/**
 * ADR 019: conformance assertions only gate the responses tests exercise, so
 * every response documented in docs/openapi/openapi.yaml must register an
 * exerciser here that produces it. Documenting a new response without one
 * fails the coverage test until the exerciser (and the shape it proves) lands.
 *
 * Error-path exercisers stay schema-valid on the request side (the request
 * schemas are deliberately loose there) so assertConformsToOpenApi can
 * assert both directions on every documented response.
 *
 * @return array<string, Closure(): TestResponse<JsonResponse>>
 */
function documentedResponseExercisers(): array
{
    return [
        'get /v1/health 200' => fn (): TestResponse => test()->getJson('/v1/health'),
        'get /v1/health 503' => function (): TestResponse {
            config()->set('database.redis.health.host', '127.0.0.1');
            config()->set('database.redis.health.port', 1);

            return test()->getJson('/v1/health');
        },
        'post /v1/tenants 201' => fn (): TestResponse => test()->postJson('/v1/tenants', [
            'name' => 'Contract Tenant',
            'default_locale' => 'en',
            'supported_locales' => ['en'],
        ], ['Authorization' => 'Bearer '.contractPlatformBearer()]),
        'post /v1/tenants 401' => fn (): TestResponse => test()->postJson('/v1/tenants', [
            'name' => 'Contract Tenant',
            'default_locale' => 'en',
            'supported_locales' => ['en'],
        ]),
        'post /v1/tenants 403' => fn (): TestResponse => test()->postJson('/v1/tenants', [
            'name' => 'Contract Tenant',
            'default_locale' => 'en',
            'supported_locales' => ['en'],
        ], ['Authorization' => 'Bearer '.contractPlatformBearer(Capability::EventsView)]),
        'post /v1/tenants 422' => fn (): TestResponse => test()->postJson('/v1/tenants', [
            'name' => '',
            'default_locale' => 'en',
            'supported_locales' => ['en'],
        ], ['Authorization' => 'Bearer '.contractPlatformBearer()]),
        'get /v1/tenants 200' => function (): TestResponse {
            contractTenant();

            return test()->getJson('/v1/tenants', ['Authorization' => 'Bearer '.contractPlatformBearer()]);
        },
        'get /v1/tenants 400' => fn (): TestResponse => test()->getJson(
            '/v1/tenants?sort=payout_schedule',
            ['Authorization' => 'Bearer '.contractPlatformBearer()],
        ),
        'get /v1/tenants 401' => fn (): TestResponse => test()->getJson('/v1/tenants'),
        'get /v1/tenants 403' => fn (): TestResponse => test()->getJson(
            '/v1/tenants',
            ['Authorization' => 'Bearer '.contractPlatformBearer(Capability::EventsView)],
        ),
        'get /v1/tenants/{tenant} 200' => fn (): TestResponse => test()->getJson(
            '/v1/tenants/'.contractTenant()->id,
            ['Authorization' => 'Bearer '.contractPlatformBearer()],
        ),
        'get /v1/tenants/{tenant} 401' => fn (): TestResponse => test()->getJson('/v1/tenants/'.contractTenant()->id),
        'get /v1/tenants/{tenant} 403' => fn (): TestResponse => test()->getJson(
            '/v1/tenants/'.contractTenant()->id,
            ['Authorization' => 'Bearer '.contractPlatformBearer(Capability::EventsView)],
        ),
        'get /v1/tenants/{tenant} 404' => fn (): TestResponse => test()->getJson(
            '/v1/tenants/'.Str::uuid7(),
            ['Authorization' => 'Bearer '.contractPlatformBearer()],
        ),
        'patch /v1/tenants/{tenant} 200' => fn (): TestResponse => test()->patchJson(
            '/v1/tenants/'.contractTenant()->id,
            ['name' => 'Renamed Contract Tenant'],
            ['Authorization' => 'Bearer '.contractPlatformBearer()],
        ),
        'patch /v1/tenants/{tenant} 401' => fn (): TestResponse => test()->patchJson(
            '/v1/tenants/'.contractTenant()->id,
            ['name' => 'Renamed Contract Tenant'],
        ),
        'patch /v1/tenants/{tenant} 403' => fn (): TestResponse => test()->patchJson(
            '/v1/tenants/'.contractTenant()->id,
            ['name' => 'Renamed Contract Tenant'],
            ['Authorization' => 'Bearer '.contractPlatformBearer(Capability::EventsView)],
        ),
        'patch /v1/tenants/{tenant} 404' => fn (): TestResponse => test()->patchJson(
            '/v1/tenants/'.Str::uuid7(),
            ['name' => 'Ghost'],
            ['Authorization' => 'Bearer '.contractPlatformBearer()],
        ),
        'patch /v1/tenants/{tenant} 422' => fn (): TestResponse => test()->patchJson(
            '/v1/tenants/'.contractTenant()->id,
            ['default_locale' => 'fr'],
            ['Authorization' => 'Bearer '.contractPlatformBearer()],
        ),
        'post /v1/tenants/{tenant}/domains 201' => fn (): TestResponse => test()->postJson(
            '/v1/tenants/'.contractTenant()->id.'/domains',
            ['domain' => 'contract.example.com'],
            ['Authorization' => 'Bearer '.contractPlatformBearer()],
        ),
        'post /v1/tenants/{tenant}/domains 401' => fn (): TestResponse => test()->postJson(
            '/v1/tenants/'.contractTenant()->id.'/domains',
            ['domain' => 'contract.example.com'],
        ),
        'post /v1/tenants/{tenant}/domains 403' => fn (): TestResponse => test()->postJson(
            '/v1/tenants/'.contractTenant()->id.'/domains',
            ['domain' => 'contract.example.com'],
            ['Authorization' => 'Bearer '.contractPlatformBearer(Capability::EventsView)],
        ),
        'post /v1/tenants/{tenant}/domains 404' => fn (): TestResponse => test()->postJson(
            '/v1/tenants/'.Str::uuid7().'/domains',
            ['domain' => 'ghost.example.com'],
            ['Authorization' => 'Bearer '.contractPlatformBearer()],
        ),
        'post /v1/tenants/{tenant}/domains 409' => function (): TestResponse {
            $domain = contractDomain(['domain' => 'taken.example.com']);

            return test()->postJson(
                '/v1/tenants/'.$domain->tenant_id.'/domains',
                ['domain' => 'taken.example.com'],
                ['Authorization' => 'Bearer '.contractPlatformBearer()],
            );
        },
        'post /v1/tenants/{tenant}/domains 422' => fn (): TestResponse => test()->postJson(
            '/v1/tenants/'.contractTenant()->id.'/domains',
            ['domain' => 'not a hostname'],
            ['Authorization' => 'Bearer '.contractPlatformBearer()],
        ),
        'get /v1/tenants/{tenant}/domains 200' => function (): TestResponse {
            $domain = contractDomain();

            return test()->getJson(
                '/v1/tenants/'.$domain->tenant_id.'/domains',
                ['Authorization' => 'Bearer '.contractPlatformBearer()],
            );
        },
        'get /v1/tenants/{tenant}/domains 401' => function (): TestResponse {
            $domain = contractDomain();

            return test()->getJson('/v1/tenants/'.$domain->tenant_id.'/domains');
        },
        'get /v1/tenants/{tenant}/domains 403' => function (): TestResponse {
            $domain = contractDomain();

            return test()->getJson(
                '/v1/tenants/'.$domain->tenant_id.'/domains',
                ['Authorization' => 'Bearer '.contractPlatformBearer(Capability::EventsView)],
            );
        },
        'get /v1/tenants/{tenant}/domains 404' => fn (): TestResponse => test()->getJson(
            '/v1/tenants/'.Str::uuid7().'/domains',
            ['Authorization' => 'Bearer '.contractPlatformBearer()],
        ),
        'patch /v1/tenant-domains/{tenant_domain} 200' => fn (): TestResponse => test()->patchJson(
            '/v1/tenant-domains/'.contractDomain()->id,
            ['is_primary' => true],
            ['Authorization' => 'Bearer '.contractPlatformBearer()],
        ),
        'patch /v1/tenant-domains/{tenant_domain} 401' => fn (): TestResponse => test()->patchJson(
            '/v1/tenant-domains/'.contractDomain()->id,
            ['is_primary' => true],
        ),
        'patch /v1/tenant-domains/{tenant_domain} 403' => fn (): TestResponse => test()->patchJson(
            '/v1/tenant-domains/'.contractDomain()->id,
            ['is_primary' => true],
            ['Authorization' => 'Bearer '.contractPlatformBearer(Capability::EventsView)],
        ),
        'patch /v1/tenant-domains/{tenant_domain} 404' => fn (): TestResponse => test()->patchJson(
            '/v1/tenant-domains/'.Str::uuid7(),
            ['is_primary' => true],
            ['Authorization' => 'Bearer '.contractPlatformBearer()],
        ),
        'patch /v1/tenant-domains/{tenant_domain} 422' => fn (): TestResponse => test()->patchJson(
            '/v1/tenant-domains/'.contractDomain()->id,
            ['is_primary' => false],
            ['Authorization' => 'Bearer '.contractPlatformBearer()],
        ),
        'delete /v1/tenant-domains/{tenant_domain} 401' => fn (): TestResponse => test()->deleteJson(
            '/v1/tenant-domains/'.contractDomain()->id,
        ),
        'delete /v1/tenant-domains/{tenant_domain} 403' => fn (): TestResponse => test()->deleteJson(
            '/v1/tenant-domains/'.contractDomain()->id,
            [],
            ['Authorization' => 'Bearer '.contractPlatformBearer(Capability::EventsView)],
        ),
        'delete /v1/tenant-domains/{tenant_domain} 404' => fn (): TestResponse => test()->deleteJson(
            '/v1/tenant-domains/'.Str::uuid7(),
            [],
            ['Authorization' => 'Bearer '.contractPlatformBearer()],
        ),
        'delete /v1/tenant-domains/{tenant_domain} 409' => fn (): TestResponse => test()->deleteJson(
            '/v1/tenant-domains/'.contractDomain(['is_primary' => true])->id,
            [],
            ['Authorization' => 'Bearer '.contractPlatformBearer()],
        ),
        // The 204 documents no content, so it has no coverage key here; the
        // feature test conformance-asserts it. The 422 exerciser keeps the
        // request spec-valid (the parameter schema is a loose string) by
        // sending a present-but-malformed domain rather than omitting it.
        'get /v1/internal/domain-verification 404' => fn (): TestResponse => test()->getJson(
            '/v1/internal/domain-verification?domain=unregistered.example.com',
        ),
        'get /v1/internal/domain-verification 422' => fn (): TestResponse => test()->getJson(
            '/v1/internal/domain-verification?domain='.urlencode('not a hostname'),
        ),
        'post /v1/auth/staff/token 200' => function (): TestResponse {
            contractStaffUser();

            return test()->postJson('/v1/auth/staff/token', [
                'email' => 'contract-staff@example.com',
                'password' => 'password',
            ]);
        },
        'post /v1/auth/staff/token 401' => fn (): TestResponse => test()->postJson('/v1/auth/staff/token', [
            'email' => 'ghost-contract@example.com',
            'password' => 'wrong-password',
        ]),
        'post /v1/auth/staff/token 422' => fn (): TestResponse => test()->postJson('/v1/auth/staff/token', [
            'email' => 'not-an-email',
            'password' => 'x',
        ]),
        'post /v1/auth/staff/refresh 200' => function (): TestResponse {
            $pair = contractStaffTokenPair();

            return test()->postJson('/v1/auth/staff/refresh', ['refresh_token' => $pair['refresh_token']]);
        },
        'post /v1/auth/staff/refresh 401' => fn (): TestResponse => test()->postJson('/v1/auth/staff/refresh', [
            'refresh_token' => 'not-a-real-refresh-token',
        ]),
        'post /v1/auth/staff/refresh 422' => fn (): TestResponse => test()->postJson('/v1/auth/staff/refresh', []),
        'post /v1/auth/staff/logout 401' => fn (): TestResponse => test()->postJson('/v1/auth/staff/logout'),
        'get /v1/me 200' => fn (): TestResponse => test()->getJson('/v1/me', [
            'Authorization' => 'Bearer '.contractStaffBearer(),
        ]),
        'get /v1/me 401' => fn (): TestResponse => test()->getJson('/v1/me'),
    ];
}

/**
 * Every documented "method /path status" triple, content types collapsed.
 *
 * @return list<string>
 */
function documentedResponses(): array
{
    $responses = [];

    foreach (array_keys(OpenApiSpec::documentedResponseSchemas()) as $key) {
        [$method, $path, $status] = explode(' ', $key);

        $responses["{$method} {$path} {$status}"] = true;
    }

    return array_keys($responses);
}

test('documented response is exercised with conformance asserted', function (string $response) {
    $exercisers = documentedResponseExercisers();

    Assert::assertArrayHasKey(
        $response,
        $exercisers,
        "Documented response [{$response}] has no exerciser; register one so its shape is conformance-asserted.",
    );

    $status = (int) substr($response, strrpos($response, ' ') + 1);

    $exercisers[$response]()
        ->assertStatus($status)
        ->assertConformsToOpenApi();
})->with(fn (): array => documentedResponses());

test('every exerciser targets a documented response', function () {
    $documented = documentedResponses();

    foreach (array_keys(documentedResponseExercisers()) as $response) {
        Assert::assertContains(
            $response,
            $documented,
            "Exerciser [{$response}] targets a response that is not documented in docs/openapi/openapi.yaml.",
        );
    }
});
