<?php

use App\Identity\Capability;
use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\MigratedDatabase;
use Tests\Support\OpenApiSpec;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\TotpCodes;

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    // The MFA exercisers (task breakdown item 11) confirm MFA for several
    // contract-mfa-*@example.com users, which are otherwise kept forever
    // the same way contract-staff@example.com is; their mfa_recovery_codes
    // rows would otherwise dangle and block an unrelated later suite's
    // blanket User::query()->delete() the same way a stale memberships row
    // would (see the comment below).
    DB::table('mfa_recovery_codes')->delete();

    // contractPlatformBearer() recreates its membership and role idempotently
    // per call (its own docblock), so deleting them here every test is safe
    // and, unlike the contract-platform-staff/contract-incapable-staff User
    // rows deliberately kept by firstOrCreate, prevents a dangling
    // memberships row from blocking an unrelated later suite's blanket
    // User::query()->delete() (observed against StaffRefreshRotationContentionTest).
    app(TenantTransaction::class)->asTenant($sentinel, function (): void {
        DB::table('memberships')->delete();
    });

    // contractRoleTenantBearer() (task breakdown item 8) writes tenant-scope
    // memberships into contractRoleTenant()'s own row, not the sentinel
    // tenant, so those also need clearing before the tenant delete below can
    // succeed: memberships.tenant_id carries no cascade.
    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ($tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('memberships')->where('tenant_id', $tenantId)->delete();
        });
    }

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
 * Platform-scope memberships are unconditionally MFA-enforcing (task
 * breakdown item 11), so the reused row is created MFA-confirmed from the
 * start rather than flipped afterward the way
 * Tests\Support\PlatformStaff::token() does for a fresh row each call:
 * firstOrCreate would otherwise reuse a row whose MFA got confirmed by an
 * earlier dataset iteration, and the plain password-only exchange below
 * would then fail with mfa_required on every call after the first. A
 * fresh TOTP code for the persisted secret is computed on every call
 * instead, since a stale code would fail once its window passes.
 *
 * users.mfa_secret, mfa_enabled, and mfa_confirmed_at are deliberately
 * absent from User's #[Fillable(...)] list (only name/email/password are
 * mass-assignable), so setting them through User::factory()->raw() merged
 * into firstOrCreate()'s create attributes would be silently dropped;
 * forceFill() is required here, the same way every MFA Action
 * (App\Identity\Actions\EnrollMfa and friends) already sets them.
 */
function contractPlatformBearer(Capability $capability = Capability::TenantsManage): string
{
    $email = $capability === Capability::TenantsManage
        ? 'contract-platform-staff@example.com'
        : 'contract-incapable-staff@example.com';

    $google2fa = new Google2FA;

    $user = User::query()->firstOrCreate(['email' => $email], User::factory()->raw(['email' => $email]));

    if (! $user->mfa_enabled) {
        $user->forceFill([
            'mfa_enabled' => true,
            'mfa_secret' => $google2fa->generateSecretKey(),
            'mfa_confirmed_at' => now(),
        ])->save();
    }

    $response = test()->postJson('/v1/auth/staff/token', [
        'email' => $email,
        'password' => 'password',
        'mfa_code' => $google2fa->getCurrentOtp($user->mfa_secret),
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

/**
 * A fresh tenant per call for the roles endpoint exercisers (task
 * breakdown item 8): unlike contractTenant()'s reuse-by-value callers,
 * several role exercisers need a tenant with no pre-existing custom roles
 * so role_name_taken and pagination assertions stay deterministic.
 */
function contractRoleTenant(): Tenant
{
    return contractTenant();
}

/**
 * @param  list<string>  $capabilities
 */
function contractRoleBearer(Tenant $tenant, array $capabilities = ['roles.manage']): string
{
    $user = User::factory()->create();

    $response = test()->postJson('/v1/auth/staff/token', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    app(TenantTransaction::class)->asTenant($tenant->id, function () use ($user, $tenant, $capabilities): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role_id' => Role::factory()->create(['tenant_id' => $tenant->id, 'capabilities' => $capabilities])->id,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    /** @var string $token */
    $token = $response->json('access_token');

    return $token;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function contractRole(Tenant $tenant, array $attributes = []): Role
{
    return app(TenantTransaction::class)->asTenant(
        $tenant->id,
        fn () => Role::factory()->create(['tenant_id' => $tenant->id, ...$attributes]),
    );
}

function contractTemplateRoleId(): string
{
    return app(TenantTransaction::class)->asPlatform(
        fn () => Role::query()->whereNull('tenant_id')->where('name', 'Owner')->firstOrFail()->id,
    );
}

/**
 * A fresh tenant per call, mirroring contractRoleTenant()'s own precedent
 * (task breakdown item 9): several membership exercisers need a tenant
 * with no pre-existing memberships beyond the bearer's own, so
 * membership_exists and last_owner_removal assertions stay deterministic.
 */
function contractMembershipTenant(): Tenant
{
    return contractTenant();
}

/**
 * @param  list<string>  $capabilities
 */
function contractMembershipBearer(Tenant $tenant, array $capabilities = ['memberships.manage']): string
{
    return contractRoleBearer($tenant, $capabilities);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function contractMembership(Tenant $tenant, array $attributes = []): Membership
{
    return app(TenantTransaction::class)->asTenant(
        $tenant->id,
        fn () => Membership::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => Role::factory()->create(['tenant_id' => $tenant->id])->id,
            ...$attributes,
        ]),
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
 * A fresh, unenrolled staff bearer for one MFA exerciser (stage-03 plan,
 * task breakdown item 11). Each exerciser below gets its own email, never
 * reused across cases the way contractStaffUser() deliberately is, since
 * MFA enrollment is stateful and the different exercisers need different
 * starting states (unenrolled, pending, confirmed).
 */
function contractMfaBearer(string $email): string
{
    $user = User::query()->firstOrCreate(['email' => $email], User::factory()->raw(['email' => $email]));

    return test()->postJson('/v1/auth/staff/token', ['email' => $user->email, 'password' => 'password'])
        ->json('access_token');
}

/**
 * Enrolls and confirms MFA for a fresh bearer through the real endpoints,
 * returning the bearer and the confirmed secret so an exerciser can
 * compute a currently valid TOTP code.
 *
 * @return array{0: string, 1: string} token, secret
 */
function contractMfaConfirmedBearer(string $email): array
{
    $token = contractMfaBearer($email);
    $headers = ['Authorization' => 'Bearer '.$token];

    $secret = test()->postJson('/v1/auth/mfa/enrollment', [], $headers)->json('secret');
    test()->postJson('/v1/auth/mfa/enrollment/confirm', ['code' => TotpCodes::current($secret)], $headers);

    return [$token, $secret];
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
        'post /v1/auth/mfa/enrollment 200' => fn (): TestResponse => test()->postJson('/v1/auth/mfa/enrollment', [], [
            'Authorization' => 'Bearer '.contractMfaBearer('contract-mfa-enroll@example.com'),
        ]),
        'post /v1/auth/mfa/enrollment 401' => fn (): TestResponse => test()->postJson('/v1/auth/mfa/enrollment'),
        'post /v1/auth/mfa/enrollment 409' => function (): TestResponse {
            [$token] = contractMfaConfirmedBearer('contract-mfa-already-enrolled@example.com');

            return test()->postJson('/v1/auth/mfa/enrollment', [], ['Authorization' => 'Bearer '.$token]);
        },
        'post /v1/auth/mfa/enrollment/confirm 200' => function (): TestResponse {
            $token = contractMfaBearer('contract-mfa-confirm@example.com');
            $headers = ['Authorization' => 'Bearer '.$token];
            $secret = test()->postJson('/v1/auth/mfa/enrollment', [], $headers)->json('secret');

            return test()->postJson('/v1/auth/mfa/enrollment/confirm', ['code' => TotpCodes::current($secret)], $headers);
        },
        'post /v1/auth/mfa/enrollment/confirm 401' => function (): TestResponse {
            $token = contractMfaBearer('contract-mfa-confirm-wrong-code@example.com');
            $headers = ['Authorization' => 'Bearer '.$token];
            test()->postJson('/v1/auth/mfa/enrollment', [], $headers);

            return test()->postJson('/v1/auth/mfa/enrollment/confirm', ['code' => '000000'], $headers);
        },
        'post /v1/auth/mfa/enrollment/confirm 409' => fn (): TestResponse => test()->postJson(
            '/v1/auth/mfa/enrollment/confirm',
            ['code' => '000000'],
            ['Authorization' => 'Bearer '.contractMfaBearer('contract-mfa-confirm-not-enrolled@example.com')],
        ),
        'post /v1/auth/mfa/enrollment/confirm 422' => fn (): TestResponse => test()->postJson(
            '/v1/auth/mfa/enrollment/confirm',
            [],
            ['Authorization' => 'Bearer '.contractMfaBearer('contract-mfa-confirm-validation@example.com')],
        ),
        'post /v1/auth/mfa/disable 401' => fn (): TestResponse => test()->postJson('/v1/auth/mfa/disable', ['code' => '000000']),
        'post /v1/auth/mfa/disable 403' => function (): TestResponse {
            [$token, $secret] = contractMfaConfirmedBearer('contract-mfa-disable-enforced@example.com');

            $sentinel = config()->string('tenancy.platform_tenant_id');
            $user = User::query()->where('email', 'contract-mfa-disable-enforced@example.com')->firstOrFail();

            app(TenantTransaction::class)->asPlatform(function () use ($user, $sentinel): void {
                Membership::factory()->platform()->create([
                    'user_id' => $user->id,
                    'role_id' => Role::factory()->create(['tenant_id' => $sentinel])->id,
                ]);
            });

            return test()->postJson('/v1/auth/mfa/disable', ['code' => TotpCodes::current($secret)], ['Authorization' => 'Bearer '.$token]);
        },
        'post /v1/auth/mfa/disable 409' => fn (): TestResponse => test()->postJson(
            '/v1/auth/mfa/disable',
            ['code' => '000000'],
            ['Authorization' => 'Bearer '.contractMfaBearer('contract-mfa-disable-not-enrolled@example.com')],
        ),
        'post /v1/auth/mfa/disable 422' => fn (): TestResponse => test()->postJson(
            '/v1/auth/mfa/disable',
            [],
            ['Authorization' => 'Bearer '.contractMfaBearer('contract-mfa-disable-validation@example.com')],
        ),
        'get /v1/me 200' => fn (): TestResponse => test()->getJson('/v1/me', [
            'Authorization' => 'Bearer '.contractStaffBearer(),
        ]),
        'get /v1/me 401' => fn (): TestResponse => test()->getJson('/v1/me'),
        'get /v1/roles 200' => function (): TestResponse {
            $tenant = contractRoleTenant();

            return test()->getJson('/v1/roles', [
                'Authorization' => 'Bearer '.contractRoleBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/roles 400' => function (): TestResponse {
            $tenant = contractRoleTenant();

            return test()->getJson('/v1/roles?sort=capabilities', [
                'Authorization' => 'Bearer '.contractRoleBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/roles 401' => function (): TestResponse {
            $tenant = contractRoleTenant();

            return test()->getJson('/v1/roles', ['X-Tenant-Id' => $tenant->id]);
        },
        'get /v1/roles 403' => function (): TestResponse {
            $tenant = contractRoleTenant();
            $stranger = User::factory()->create();

            $response = test()->postJson('/v1/auth/staff/token', [
                'email' => $stranger->email,
                'password' => 'password',
            ]);

            return test()->getJson('/v1/roles', [
                'Authorization' => 'Bearer '.$response->json('access_token'),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/roles 201' => function (): TestResponse {
            $tenant = contractRoleTenant();

            return test()->postJson(
                '/v1/roles',
                ['name' => 'Contract Role', 'capabilities' => ['events.view']],
                ['Authorization' => 'Bearer '.contractRoleBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'post /v1/roles 401' => function (): TestResponse {
            $tenant = contractRoleTenant();

            return test()->postJson(
                '/v1/roles',
                ['name' => 'Contract Role', 'capabilities' => []],
                ['X-Tenant-Id' => $tenant->id],
            );
        },
        'post /v1/roles 403' => function (): TestResponse {
            $tenant = contractRoleTenant();

            return test()->postJson(
                '/v1/roles',
                ['name' => 'Contract Role', 'capabilities' => []],
                ['Authorization' => 'Bearer '.contractRoleBearer($tenant, ['events.view']), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'post /v1/roles 409' => function (): TestResponse {
            $tenant = contractRoleTenant();
            contractRole($tenant, ['name' => 'Duplicate Name']);

            return test()->postJson(
                '/v1/roles',
                ['name' => 'Duplicate Name', 'capabilities' => []],
                ['Authorization' => 'Bearer '.contractRoleBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'post /v1/roles 422' => function (): TestResponse {
            $tenant = contractRoleTenant();

            return test()->postJson(
                '/v1/roles',
                ['name' => 'Contract Role', 'capabilities' => ['not.a.capability']],
                ['Authorization' => 'Bearer '.contractRoleBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'get /v1/roles/{role} 200' => function (): TestResponse {
            $tenant = contractRoleTenant();
            $role = contractRole($tenant);

            return test()->getJson('/v1/roles/'.$role->id, [
                'Authorization' => 'Bearer '.contractRoleBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/roles/{role} 401' => function (): TestResponse {
            $tenant = contractRoleTenant();
            $role = contractRole($tenant);

            return test()->getJson('/v1/roles/'.$role->id, ['X-Tenant-Id' => $tenant->id]);
        },
        'get /v1/roles/{role} 403' => function (): TestResponse {
            $tenant = contractRoleTenant();
            $role = contractRole($tenant);
            $stranger = User::factory()->create();

            $response = test()->postJson('/v1/auth/staff/token', [
                'email' => $stranger->email,
                'password' => 'password',
            ]);

            return test()->getJson('/v1/roles/'.$role->id, [
                'Authorization' => 'Bearer '.$response->json('access_token'),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/roles/{role} 404' => function (): TestResponse {
            $tenant = contractRoleTenant();

            return test()->getJson('/v1/roles/'.Str::uuid7(), [
                'Authorization' => 'Bearer '.contractRoleBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'patch /v1/roles/{role} 200' => function (): TestResponse {
            $tenant = contractRoleTenant();
            $role = contractRole($tenant);

            return test()->patchJson(
                '/v1/roles/'.$role->id,
                ['name' => 'Renamed Contract Role'],
                ['Authorization' => 'Bearer '.contractRoleBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/roles/{role} 401' => function (): TestResponse {
            $tenant = contractRoleTenant();
            $role = contractRole($tenant);

            return test()->patchJson(
                '/v1/roles/'.$role->id,
                ['name' => 'Renamed Contract Role'],
                ['X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/roles/{role} 403' => function (): TestResponse {
            $tenant = contractRoleTenant();
            $role = contractRole($tenant);

            return test()->patchJson(
                '/v1/roles/'.$role->id,
                ['name' => 'Renamed Contract Role'],
                ['Authorization' => 'Bearer '.contractRoleBearer($tenant, ['events.view']), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/roles/{role} 404' => function (): TestResponse {
            $tenant = contractRoleTenant();

            return test()->patchJson(
                '/v1/roles/'.Str::uuid7(),
                ['name' => 'Ghost'],
                ['Authorization' => 'Bearer '.contractRoleBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/roles/{role} 409' => function (): TestResponse {
            $tenant = contractRoleTenant();

            return test()->patchJson(
                '/v1/roles/'.contractTemplateRoleId(),
                ['name' => 'Hijacked Template'],
                ['Authorization' => 'Bearer '.contractRoleBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/roles/{role} 422' => function (): TestResponse {
            $tenant = contractRoleTenant();
            $role = contractRole($tenant);

            return test()->patchJson(
                '/v1/roles/'.$role->id,
                ['capabilities' => ['not.a.capability']],
                ['Authorization' => 'Bearer '.contractRoleBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'delete /v1/roles/{role} 401' => function (): TestResponse {
            $tenant = contractRoleTenant();
            $role = contractRole($tenant);

            return test()->deleteJson('/v1/roles/'.$role->id, [], ['X-Tenant-Id' => $tenant->id]);
        },
        'delete /v1/roles/{role} 403' => function (): TestResponse {
            $tenant = contractRoleTenant();
            $role = contractRole($tenant);

            return test()->deleteJson('/v1/roles/'.$role->id, [], [
                'Authorization' => 'Bearer '.contractRoleBearer($tenant, ['events.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'delete /v1/roles/{role} 404' => function (): TestResponse {
            $tenant = contractRoleTenant();

            return test()->deleteJson('/v1/roles/'.Str::uuid7(), [], [
                'Authorization' => 'Bearer '.contractRoleBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'delete /v1/roles/{role} 409' => function (): TestResponse {
            $tenant = contractRoleTenant();

            return test()->deleteJson('/v1/roles/'.contractTemplateRoleId(), [], [
                'Authorization' => 'Bearer '.contractRoleBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/capabilities 200' => fn (): TestResponse => test()->getJson('/v1/capabilities', [
            'Authorization' => 'Bearer '.contractStaffBearer(),
        ]),
        'get /v1/capabilities 401' => fn (): TestResponse => test()->getJson('/v1/capabilities'),
        'get /v1/memberships 200' => function (): TestResponse {
            $tenant = contractMembershipTenant();

            return test()->getJson('/v1/memberships', [
                'Authorization' => 'Bearer '.contractMembershipBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/memberships 400' => function (): TestResponse {
            $tenant = contractMembershipTenant();

            return test()->getJson('/v1/memberships?sort=email', [
                'Authorization' => 'Bearer '.contractMembershipBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/memberships 401' => function (): TestResponse {
            $tenant = contractMembershipTenant();

            return test()->getJson('/v1/memberships', ['X-Tenant-Id' => $tenant->id]);
        },
        'get /v1/memberships 403' => function (): TestResponse {
            $tenant = contractMembershipTenant();
            $stranger = User::factory()->create();

            $response = test()->postJson('/v1/auth/staff/token', [
                'email' => $stranger->email,
                'password' => 'password',
            ]);

            return test()->getJson('/v1/memberships', [
                'Authorization' => 'Bearer '.$response->json('access_token'),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/memberships 201' => function (): TestResponse {
            $tenant = contractMembershipTenant();
            Mail::fake();

            return test()->postJson('/v1/memberships', [
                'email' => 'contract-post-'.Str::uuid7().'@example.com',
                'name' => 'Contract Post Invitee',
                'role_id' => contractTemplateRoleId(),
            ], [
                'Authorization' => 'Bearer '.contractMembershipBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/memberships 401' => function (): TestResponse {
            $tenant = contractMembershipTenant();

            return test()->postJson('/v1/memberships', [
                'email' => 'contract-401@example.com',
                'name' => 'X',
                'role_id' => contractTemplateRoleId(),
            ], ['X-Tenant-Id' => $tenant->id]);
        },
        'post /v1/memberships 403' => function (): TestResponse {
            $tenant = contractMembershipTenant();

            return test()->postJson('/v1/memberships', [
                'email' => 'contract-403@example.com',
                'name' => 'X',
                'role_id' => contractTemplateRoleId(),
            ], [
                'Authorization' => 'Bearer '.contractMembershipBearer($tenant, ['events.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/memberships 404' => function (): TestResponse {
            $tenant = contractMembershipTenant();

            return test()->postJson('/v1/memberships', [
                'email' => 'contract-404@example.com',
                'name' => 'X',
                'role_id' => (string) Str::uuid7(),
            ], [
                'Authorization' => 'Bearer '.contractMembershipBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/memberships 409' => function (): TestResponse {
            $tenant = contractMembershipTenant();
            $existing = User::factory()->create(['email' => 'contract-membership-exists@example.com']);
            contractMembership($tenant, ['user_id' => $existing->id]);

            return test()->postJson('/v1/memberships', [
                'email' => 'contract-membership-exists@example.com',
                'name' => 'X',
                'role_id' => contractTemplateRoleId(),
            ], [
                'Authorization' => 'Bearer '.contractMembershipBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/memberships 422' => function (): TestResponse {
            $tenant = contractMembershipTenant();

            return test()->postJson('/v1/memberships', [
                'email' => 'not-an-email',
                'name' => '',
                'role_id' => 'not-a-uuid',
            ], [
                'Authorization' => 'Bearer '.contractMembershipBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'patch /v1/memberships/{membership} 200' => function (): TestResponse {
            $tenant = contractMembershipTenant();
            $membership = contractMembership($tenant);
            $newRoleId = contractRole($tenant)->id;

            return test()->patchJson(
                '/v1/memberships/'.$membership->id,
                ['role_id' => $newRoleId],
                ['Authorization' => 'Bearer '.contractMembershipBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/memberships/{membership} 401' => function (): TestResponse {
            $tenant = contractMembershipTenant();
            $membership = contractMembership($tenant);

            return test()->patchJson(
                '/v1/memberships/'.$membership->id,
                ['role_id' => contractTemplateRoleId()],
                ['X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/memberships/{membership} 403' => function (): TestResponse {
            $tenant = contractMembershipTenant();
            $membership = contractMembership($tenant);

            return test()->patchJson(
                '/v1/memberships/'.$membership->id,
                ['role_id' => contractTemplateRoleId()],
                ['Authorization' => 'Bearer '.contractMembershipBearer($tenant, ['events.view']), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/memberships/{membership} 404' => function (): TestResponse {
            $tenant = contractMembershipTenant();

            return test()->patchJson(
                '/v1/memberships/'.Str::uuid7(),
                ['role_id' => contractTemplateRoleId()],
                ['Authorization' => 'Bearer '.contractMembershipBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/memberships/{membership} 409' => function (): TestResponse {
            $tenant = contractMembershipTenant();
            $onlyOwner = contractMembership($tenant, ['role_id' => contractTemplateRoleId()]);
            $otherRoleId = contractRole($tenant)->id;

            return test()->patchJson(
                '/v1/memberships/'.$onlyOwner->id,
                ['role_id' => $otherRoleId],
                ['Authorization' => 'Bearer '.contractMembershipBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/memberships/{membership} 422' => function (): TestResponse {
            $tenant = contractMembershipTenant();
            $membership = contractMembership($tenant);

            return test()->patchJson(
                '/v1/memberships/'.$membership->id,
                ['role_id' => 'not-a-uuid'],
                ['Authorization' => 'Bearer '.contractMembershipBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'delete /v1/memberships/{membership} 401' => function (): TestResponse {
            $tenant = contractMembershipTenant();
            $membership = contractMembership($tenant);

            return test()->deleteJson('/v1/memberships/'.$membership->id, [], ['X-Tenant-Id' => $tenant->id]);
        },
        'delete /v1/memberships/{membership} 403' => function (): TestResponse {
            $tenant = contractMembershipTenant();
            $membership = contractMembership($tenant);

            return test()->deleteJson('/v1/memberships/'.$membership->id, [], [
                'Authorization' => 'Bearer '.contractMembershipBearer($tenant, ['events.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'delete /v1/memberships/{membership} 404' => function (): TestResponse {
            $tenant = contractMembershipTenant();

            return test()->deleteJson('/v1/memberships/'.Str::uuid7(), [], [
                'Authorization' => 'Bearer '.contractMembershipBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'delete /v1/memberships/{membership} 409' => function (): TestResponse {
            $tenant = contractMembershipTenant();
            $onlyOwner = contractMembership($tenant, ['role_id' => contractTemplateRoleId()]);

            return test()->deleteJson('/v1/memberships/'.$onlyOwner->id, [], [
                'Authorization' => 'Bearer '.contractMembershipBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        // The 204 documents no content, so it has no coverage key here
        // (mirroring the precedent above at 'get
        // /v1/internal/domain-verification'); the feature test
        // (tests/Feature/Identity/InvitationAcceptanceTest.php)
        // conformance-asserts it.
        'post /v1/auth/staff/invitation/accept 401' => fn (): TestResponse => test()->postJson('/v1/auth/staff/invitation/accept', [
            'token' => 'not-a-real-token',
            'password' => 'a-real-password',
        ]),
        'post /v1/auth/staff/invitation/accept 422' => fn (): TestResponse => test()->postJson('/v1/auth/staff/invitation/accept', [
            'token' => '',
            'password' => 'short',
        ]),
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
