<?php

use App\Identity\Enums\MembershipScope;
use App\Identity\Mail\StaffInvitationMail;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;

/*
 * Stage-03 plan, Slice 4c (task breakdown item 9): membership endpoints,
 * InviteUser and AssignRole, and invitation acceptance. Reading needs only
 * a valid tenant membership, mirroring roles.php; mutating additionally
 * requires memberships.manage, evaluated by RequireCapability the same
 * way task breakdown item 8 wired roles.manage.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $this->otherTenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $this->withHeaders([
        'Authorization' => 'Bearer '.membershipEndpointBearer($this->tenantId, ['memberships.manage']),
        'X-Tenant-Id' => $this->tenantId,
    ]);
});

afterEach(function (): void {
    foreach ([$this->tenantId, $this->otherTenantId] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
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
function membershipEndpointBearer(string $tenantId, array $capabilities): string
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

function membershipTemplateRoleId(string $name = 'Owner'): string
{
    return app(TenantTransaction::class)->asPlatform(
        fn () => Role::query()->whereNull('tenant_id')->where('name', $name)->firstOrFail()->id,
    );
}

/**
 * role_id defaults to a fresh tenant-scoped custom role rather than
 * MembershipFactory's own bare Role::factory() default: the roles table's
 * RLS insert policy (task-04) requires tenant_id to match the asserted
 * tenant, which a template row (tenant_id null) never satisfies under
 * nodia_app, so every call site across this codebase passes role_id
 * explicitly (mirroring RoleEndpointsIsolationTest.php and friends).
 *
 * @param  array<string, mixed>  $attributes
 */
function makeMembershipRow(string $tenantId, array $attributes = []): Membership
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Membership::factory()->create([
            'tenant_id' => $tenantId,
            'role_id' => Role::factory()->create(['tenant_id' => $tenantId])->id,
            ...$attributes,
        ]),
    );
}

describe('POST /v1/memberships', function () {
    it('invites an absent user with a random unusable password and mails a signed acceptance token', function () {
        Mail::fake();

        $roleId = app(TenantTransaction::class)->asTenant(
            $this->tenantId,
            fn () => Role::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'Box Office', 'capabilities' => ['orders.view']])->id,
        );

        $response = $this->postJson('/v1/memberships', [
            'email' => 'invitee@example.com',
            'name' => 'Invitee Person',
            'role_id' => $roleId,
        ]);

        $response->assertCreated()
            ->assertConformsToOpenApi()
            ->assertJson([
                'user_name' => 'Invitee Person',
                'user_email' => 'invitee@example.com',
                'tenant_id' => $this->tenantId,
                'role_id' => $roleId,
                'role_name' => 'Box Office',
                'scope' => 'tenant',
            ]);

        expect(Str::isUuid($response->json('id')))->toBeTrue()
            ->and(Str::isUuid($response->json('user_id')))->toBeTrue();

        $user = User::query()->where('email', 'invitee@example.com')->firstOrFail();
        expect(Hash::check('password', $user->password))->toBeFalse();

        // The random password is genuinely unusable: no one, including the
        // inviter, knows it, so a login attempt fails exactly like an
        // unknown email would (no enumeration).
        $this->withoutToken()
            ->postJson('/v1/auth/staff/token', ['email' => 'invitee@example.com', 'password' => 'password'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'invalid_credentials');

        Mail::assertSent(StaffInvitationMail::class, fn (StaffInvitationMail $mail): bool => $mail->hasTo('invitee@example.com')
            && $mail->recipientName === 'Invitee Person'
            && $mail->token !== '');
    });

    it('reuses the existing user account when the invited email already has one', function () {
        Mail::fake();

        $existing = User::factory()->create(['email' => 'existing@example.com']);
        $roleId = membershipTemplateRoleId('Finance');

        $response = $this->postJson('/v1/memberships', [
            'email' => 'existing@example.com',
            'name' => 'Existing Name Ignored',
            'role_id' => $roleId,
        ]);

        $response->assertCreated()->assertConformsToOpenApi();

        expect($response->json('user_id'))->toBe($existing->id)
            ->and(User::query()->where('email', 'existing@example.com')->count())->toBe(1);
    });

    it('rejects an invite for a user already a member of the tenant with membership_exists', function () {
        Mail::fake();

        $existing = User::factory()->create(['email' => 'already-member@example.com']);
        makeMembershipRow($this->tenantId, ['user_id' => $existing->id]);

        $this->postJson('/v1/memberships', [
            'email' => 'already-member@example.com',
            'name' => 'Already Member',
            'role_id' => membershipTemplateRoleId(),
        ])
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'membership_exists');
    });

    it('rejects an invite naming an unknown or foreign-tenant role with request.not_found', function () {
        Mail::fake();

        $foreignRoleId = app(TenantTransaction::class)->asTenant(
            $this->otherTenantId,
            fn () => Role::factory()->create(['tenant_id' => $this->otherTenantId])->id,
        );

        $this->postJson('/v1/memberships', [
            'email' => 'nobody@example.com',
            'name' => 'Nobody',
            'role_id' => $foreignRoleId,
        ])
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('rejects an invalid payload with request.validation_failed', function () {
        $this->postJson('/v1/memberships', ['email' => 'not-an-email', 'name' => '', 'role_id' => 'not-a-uuid'])
            ->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');
    });

    it('rejects a request with no bearer', function () {
        $this->withoutToken()
            ->postJson('/v1/memberships', [
                'email' => 'x@example.com',
                'name' => 'X',
                'role_id' => membershipTemplateRoleId(),
            ], ['X-Tenant-Id' => $this->tenantId])
            ->assertUnauthorized()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'auth.unauthenticated');
    });

    it('rejects a bearer lacking memberships.manage with missing_capability', function () {
        $this->withHeaders(['Authorization' => 'Bearer '.membershipEndpointBearer($this->tenantId, ['events.view'])])
            ->postJson('/v1/memberships', [
                'email' => 'x@example.com',
                'name' => 'X',
                'role_id' => membershipTemplateRoleId(),
            ])
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'missing_capability');
    });
});

describe('GET /v1/memberships', function () {
    it('lists memberships in the acting tenant only', function () {
        $ownMember = User::factory()->create();
        makeMembershipRow($this->tenantId, ['user_id' => $ownMember->id]);
        makeMembershipRow($this->otherTenantId);

        $response = $this->getJson('/v1/memberships')->assertOk()->assertConformsToOpenApi();

        $tenantIds = collect($response->json('data'))->pluck('tenant_id')->unique();
        expect($tenantIds->all())->toBe([$this->tenantId]);
    });

    it('filters by user_id and role_id', function () {
        $user = User::factory()->create();
        $membership = makeMembershipRow($this->tenantId, ['user_id' => $user->id]);

        $this->getJson('/v1/memberships?filter[user_id]='.$user->id)
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $membership->id);

        $this->getJson('/v1/memberships?filter[role_id]='.$membership->role_id)
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonPath('data.0.role_id', $membership->role_id);
    });

    it('rejects a request with no bearer', function () {
        $this->withoutToken()
            ->getJson('/v1/memberships', ['X-Tenant-Id' => $this->tenantId])
            ->assertUnauthorized()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'auth.unauthenticated');
    });

    it('rejects a bearer with no membership in the asserted tenant', function () {
        $strangerToken = StaffTokens::issue(User::factory()->create());

        $this->withHeaders(['Authorization' => 'Bearer '.$strangerToken])
            ->getJson('/v1/memberships')
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'tenant_access_denied');
    });
});

describe('PATCH /v1/memberships/{membership}', function () {
    it('changes a membership\'s role', function () {
        $newRoleId = app(TenantTransaction::class)->asTenant(
            $this->tenantId,
            fn () => Role::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'New Role'])->id,
        );
        $membership = makeMembershipRow($this->tenantId);

        $this->patchJson('/v1/memberships/'.$membership->id, ['role_id' => $newRoleId])
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonPath('role_id', $newRoleId)
            ->assertJsonPath('role_name', 'New Role');
    });

    it('denies demoting the only Owner membership with last_owner_removal', function () {
        $onlyOwner = makeMembershipRow($this->tenantId, ['role_id' => membershipTemplateRoleId()]);
        $otherRoleId = app(TenantTransaction::class)->asTenant(
            $this->tenantId,
            fn () => Role::factory()->create(['tenant_id' => $this->tenantId])->id,
        );

        $this->patchJson('/v1/memberships/'.$onlyOwner->id, ['role_id' => $otherRoleId])
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'last_owner_removal');
    });

    it('allows demoting an Owner when another Owner membership remains', function () {
        $firstOwner = makeMembershipRow($this->tenantId, ['role_id' => membershipTemplateRoleId()]);
        makeMembershipRow($this->tenantId, ['role_id' => membershipTemplateRoleId()]);
        $otherRoleId = app(TenantTransaction::class)->asTenant(
            $this->tenantId,
            fn () => Role::factory()->create(['tenant_id' => $this->tenantId])->id,
        );

        $this->patchJson('/v1/memberships/'.$firstOwner->id, ['role_id' => $otherRoleId])
            ->assertOk()
            ->assertConformsToOpenApi();
    });

    it('renders request.not_found for a foreign-tenant membership id', function () {
        $foreignMembership = makeMembershipRow($this->otherTenantId);

        $this->patchJson('/v1/memberships/'.$foreignMembership->id, ['role_id' => membershipTemplateRoleId()])
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('rejects a bearer lacking memberships.manage with missing_capability', function () {
        $membership = makeMembershipRow($this->tenantId);

        $this->withHeaders(['Authorization' => 'Bearer '.membershipEndpointBearer($this->tenantId, ['events.view'])])
            ->patchJson('/v1/memberships/'.$membership->id, ['role_id' => membershipTemplateRoleId()])
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'missing_capability');
    });
});

describe('DELETE /v1/memberships/{membership}', function () {
    it('removes a membership', function () {
        $membership = makeMembershipRow($this->tenantId);

        $this->deleteJson('/v1/memberships/'.$membership->id)
            ->assertNoContent()
            ->assertConformsToOpenApi();

        app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($membership): void {
            expect(Membership::query()->whereKey($membership->id)->exists())->toBeFalse();
        });
    });

    it('denies removing the only Owner membership with last_owner_removal', function () {
        $onlyOwner = makeMembershipRow($this->tenantId, ['role_id' => membershipTemplateRoleId()]);

        $this->deleteJson('/v1/memberships/'.$onlyOwner->id)
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'last_owner_removal');
    });

    it('allows removing an Owner membership when another Owner remains', function () {
        $firstOwner = makeMembershipRow($this->tenantId, ['role_id' => membershipTemplateRoleId()]);
        makeMembershipRow($this->tenantId, ['role_id' => membershipTemplateRoleId()]);

        $this->deleteJson('/v1/memberships/'.$firstOwner->id)
            ->assertNoContent()
            ->assertConformsToOpenApi();
    });

    it('rejects a bearer lacking memberships.manage with missing_capability', function () {
        $membership = makeMembershipRow($this->tenantId);

        $this->withHeaders(['Authorization' => 'Bearer '.membershipEndpointBearer($this->tenantId, ['events.view'])])
            ->deleteJson('/v1/memberships/'.$membership->id)
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'missing_capability');
    });
});
