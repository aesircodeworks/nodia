<?php

use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;

/*
 * Stage-03 plan, Slice 4b (task breakdown item 8): role CRUD under the
 * tenancy.admin group. Reading needs only a valid tenant membership;
 * mutating additionally requires roles.manage, evaluated by
 * RequireCapability the same way task breakdown item 7 wired
 * tenants.manage on the platform group. Every test below issues its own
 * bearer through a real token exchange rather than faking one, mirroring
 * TenantEndpointsTest's PlatformStaff::token() precedent.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $this->otherTenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $this->withHeaders([
        'Authorization' => 'Bearer '.roleEndpointBearer($this->tenantId, ['roles.manage']),
        'X-Tenant-Id' => $this->tenantId,
    ]);
});

afterEach(function (): void {
    foreach ([$this->tenantId, $this->otherTenantId] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('memberships')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    User::query()->delete();
});

/**
 * @param  list<string>  $capabilities
 */
function roleEndpointBearer(string $tenantId, array $capabilities): string
{
    $user = User::factory()->create();
    $token = StaffTokens::issue($user);

    app(TenantTransaction::class)->asTenant($tenantId, function () use ($user, $tenantId, $capabilities): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'role_id' => Role::factory()->create(['tenant_id' => $tenantId, 'capabilities' => $capabilities])->id,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    return $token;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeRoleRow(string $tenantId, array $attributes = []): Role
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Role::factory()->create(['tenant_id' => $tenantId, ...$attributes]),
    );
}

function templateRoleId(string $name = 'Owner'): string
{
    return app(TenantTransaction::class)->asPlatform(
        fn () => Role::query()->whereNull('tenant_id')->where('name', $name)->firstOrFail()->id,
    );
}

describe('GET /v1/roles', function () {
    it('lists the templates and this tenant\'s own custom roles, never a foreign tenant\'s', function () {
        makeRoleRow($this->tenantId, ['name' => 'Own Custom Role']);
        makeRoleRow($this->otherTenantId, ['name' => 'Foreign Custom Role']);

        $names = collect($this->getJson('/v1/roles?sort=name')->assertOk()->assertConformsToOpenApi()->json('data'))
            ->pluck('name');

        expect($names)->toContain('Own Custom Role', 'Owner')
            ->and($names)->not->toContain('Foreign Custom Role');
    });

    it('filters by name with filter[name]', function () {
        makeRoleRow($this->tenantId, ['name' => 'Alpha Handlers']);
        makeRoleRow($this->tenantId, ['name' => 'Beta Handlers']);

        $this->getJson('/v1/roles?filter[name]=alpha')
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alpha Handlers');
    });

    it('sorts by name in both directions', function (string $sort, string $first) {
        makeRoleRow($this->tenantId, ['name' => 'AAA Sort Probe']);
        makeRoleRow($this->tenantId, ['name' => 'ZZZ Sort Probe']);

        $this->getJson('/v1/roles?filter[name]=Sort Probe&sort='.$sort)
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonPath('data.0.name', $first);
    })->with([
        'name' => ['name', 'AAA Sort Probe'],
        '-name' => ['-name', 'ZZZ Sort Probe'],
    ]);

    it('rejects an unknown filter with an invalid_query_parameter problem', function () {
        $this->getJson('/v1/roles?filter[unknown]=x')
            ->assertBadRequest()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'invalid_query_parameter');
    });

    it('rejects an unknown sort with an invalid_query_parameter problem', function () {
        $this->getJson('/v1/roles?sort=capabilities')
            ->assertBadRequest()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'invalid_query_parameter');
    });

    it('succeeds for a member holding no capabilities at all: reading needs only membership', function () {
        $this->withHeaders(['Authorization' => 'Bearer '.roleEndpointBearer($this->tenantId, [])])
            ->getJson('/v1/roles')
            ->assertOk()
            ->assertConformsToOpenApi();
    });

    it('rejects a request with no bearer', function () {
        $this->withoutToken()
            ->getJson('/v1/roles', ['X-Tenant-Id' => $this->tenantId])
            ->assertUnauthorized()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'auth.unauthenticated');
    });

    it('rejects a bearer with no membership in the asserted tenant', function () {
        $strangerToken = StaffTokens::issue(User::factory()->create());

        $this->withHeaders(['Authorization' => 'Bearer '.$strangerToken])
            ->getJson('/v1/roles')
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'tenant_access_denied');
    });
});

describe('POST /v1/roles', function () {
    it('creates a tenant custom role and returns the RoleData wire shape', function () {
        $response = $this->postJson('/v1/roles', [
            'name' => 'Ticketing Support',
            'capabilities' => ['events.view', 'orders.view'],
        ]);

        $response->assertCreated()
            ->assertConformsToOpenApi()
            ->assertJson([
                'tenant_id' => $this->tenantId,
                'name' => 'Ticketing Support',
                'capabilities' => ['events.view', 'orders.view'],
                'is_template' => false,
            ]);

        expect(Str::isUuid($response->json('id')))->toBeTrue();
    });

    it('rejects an invalid payload with a request.validation_failed problem', function () {
        $this->postJson('/v1/roles', ['name' => '', 'capabilities' => []])
            ->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');
    });

    it('rejects a capability outside the registry with unknown_capability', function () {
        $this->postJson('/v1/roles', ['name' => 'Bad Role', 'capabilities' => ['events.destroy']])
            ->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'unknown_capability');
    });

    it('rejects a name already used in the tenant with role_name_taken', function () {
        makeRoleRow($this->tenantId, ['name' => 'Taken Name']);

        $this->postJson('/v1/roles', ['name' => 'Taken Name', 'capabilities' => []])
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'role_name_taken');
    });

    it('allows the same name in two different tenants', function () {
        makeRoleRow($this->otherTenantId, ['name' => 'Shared Name']);

        $this->postJson('/v1/roles', ['name' => 'Shared Name', 'capabilities' => []])
            ->assertCreated()
            ->assertConformsToOpenApi();
    });

    it('rejects a request with no bearer', function () {
        $this->withoutToken()
            ->postJson('/v1/roles', ['name' => 'X', 'capabilities' => []], ['X-Tenant-Id' => $this->tenantId])
            ->assertUnauthorized()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'auth.unauthenticated');
    });

    it('rejects a bearer lacking roles.manage with missing_capability', function () {
        $this->withHeaders(['Authorization' => 'Bearer '.roleEndpointBearer($this->tenantId, ['events.view'])])
            ->postJson('/v1/roles', ['name' => 'X', 'capabilities' => []])
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'missing_capability');
    });
});

describe('GET /v1/roles/{role}', function () {
    it('returns this tenant\'s own custom role', function () {
        $role = makeRoleRow($this->tenantId, ['name' => 'Readable Role']);

        $this->getJson('/v1/roles/'.$role->id)
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonPath('id', $role->id)
            ->assertJsonPath('name', 'Readable Role')
            ->assertJsonPath('is_template', false);
    });

    it('returns a global template role', function () {
        $this->getJson('/v1/roles/'.templateRoleId())
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonPath('name', 'Owner')
            ->assertJsonPath('tenant_id', null)
            ->assertJsonPath('is_template', true);
    });

    it('returns a request.not_found problem for an unknown role id', function () {
        $this->getJson('/v1/roles/'.Str::uuid7())
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('returns a request.not_found problem for a malformed role id that matches no route', function () {
        $this->getJson('/v1/roles/not-a-uuid')
            ->assertNotFound()
            ->assertMatchesProblemSchema()
            ->assertJsonPath('code', 'request.not_found');
    });
});

describe('PATCH /v1/roles/{role}', function () {
    it('updates name and capabilities', function () {
        $role = makeRoleRow($this->tenantId, ['name' => 'Before', 'capabilities' => ['events.view']]);

        $this->patchJson('/v1/roles/'.$role->id, [
            'name' => 'After',
            'capabilities' => ['events.view', 'orders.view'],
        ])
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJson(['name' => 'After', 'capabilities' => ['events.view', 'orders.view']]);
    });

    it('leaves fields absent from the payload untouched', function () {
        $role = makeRoleRow($this->tenantId, ['name' => 'Untouched', 'capabilities' => ['events.view']]);

        $this->patchJson('/v1/roles/'.$role->id, ['capabilities' => ['orders.view']])
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJson(['name' => 'Untouched', 'capabilities' => ['orders.view']]);
    });

    it('rejects a capability outside the registry with unknown_capability', function () {
        $role = makeRoleRow($this->tenantId);

        $this->patchJson('/v1/roles/'.$role->id, ['capabilities' => ['events.destroy']])
            ->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'unknown_capability');
    });

    it('rejects a rename to a name already taken in the tenant with role_name_taken', function () {
        makeRoleRow($this->tenantId, ['name' => 'Already Taken']);
        $role = makeRoleRow($this->tenantId, ['name' => 'Renaming Me']);

        $this->patchJson('/v1/roles/'.$role->id, ['name' => 'Already Taken'])
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'role_name_taken');
    });

    it('rejects mutating a global template role with role_not_editable', function () {
        $this->patchJson('/v1/roles/'.templateRoleId(), ['name' => 'Hijacked Template'])
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'role_not_editable');
    });

    it('returns a request.not_found problem for an unknown role id', function () {
        $this->patchJson('/v1/roles/'.Str::uuid7(), ['name' => 'Ghost'])
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('rejects a bearer lacking roles.manage with missing_capability', function () {
        $role = makeRoleRow($this->tenantId);

        $this->withHeaders(['Authorization' => 'Bearer '.roleEndpointBearer($this->tenantId, ['events.view'])])
            ->patchJson('/v1/roles/'.$role->id, ['name' => 'Renamed'])
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'missing_capability');
    });
});

describe('DELETE /v1/roles/{role}', function () {
    it('deletes an unused custom role', function () {
        $role = makeRoleRow($this->tenantId);

        $this->deleteJson('/v1/roles/'.$role->id)->assertNoContent();

        $stillThere = app(TenantTransaction::class)->asTenant(
            $this->tenantId,
            fn () => Role::query()->whereKey($role->id)->exists(),
        );

        expect($stillThere)->toBeFalse();
    });

    it('rejects deleting a global template role with role_not_editable', function () {
        $this->deleteJson('/v1/roles/'.templateRoleId())
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'role_not_editable');
    });

    it('rejects deleting a role still referenced by a membership with role_in_use', function () {
        $role = makeRoleRow($this->tenantId);

        app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($role): void {
            Membership::factory()->create([
                'user_id' => User::factory()->create()->id,
                'tenant_id' => $this->tenantId,
                'role_id' => $role->id,
                'scope' => MembershipScope::Tenant,
            ]);
        });

        $this->deleteJson('/v1/roles/'.$role->id)
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'role_in_use');
    });

    it('returns a request.not_found problem for an unknown role id', function () {
        $this->deleteJson('/v1/roles/'.Str::uuid7())
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('rejects a bearer lacking roles.manage with missing_capability', function () {
        $role = makeRoleRow($this->tenantId);

        $this->withHeaders(['Authorization' => 'Bearer '.roleEndpointBearer($this->tenantId, ['events.view'])])
            ->deleteJson('/v1/roles/'.$role->id)
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'missing_capability');
    });
});
