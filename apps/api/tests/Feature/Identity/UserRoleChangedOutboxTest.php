<?php

declare(strict_types=1);

use App\Identity\Actions\AssignRole;
use App\Identity\Data\ChangeMembershipRoleData;
use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;

/*
 * Stage-04 plan, Slice 6 (task-12): UserRoleChanged producer on AssignRole.
 * Successful role change over HTTP persists exactly one outbox row with the
 * correct type, aggregate, tenant, correlation ID, and payload shape;
 * failing requests record nothing.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $this->actor = User::factory()->create();
    $this->actorToken = StaffTokens::issue($this->actor);

    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        Membership::factory()->create([
            'user_id' => $this->actor->id,
            'tenant_id' => $this->tenantId,
            'role_id' => Role::factory()->create([
                'tenant_id' => $this->tenantId,
                'capabilities' => ['memberships.manage'],
            ])->id,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->actorToken,
        'X-Tenant-Id' => $this->tenantId,
    ]);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('outbox_deliveries')->where('tenant_id', $this->tenantId)->delete();
        DB::table('outbox_events')->where('tenant_id', $this->tenantId)->delete();
        DB::table('memberships')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    User::query()->delete();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function userRoleChangedMembership(string $tenantId, array $attributes = []): Membership
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Membership::factory()->create([
            'tenant_id' => $tenantId,
            'role_id' => Role::factory()->create(['tenant_id' => $tenantId])->id,
            'scope' => MembershipScope::Tenant,
            ...$attributes,
        ]),
    );
}

function userRoleChangedRoleId(string $tenantId): string
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Role::factory()->create(['tenant_id' => $tenantId])->id,
    );
}

it('persists exactly one UserRoleChanged outbox row on a successful role change over HTTP', function () {
    $membership = userRoleChangedMembership($this->tenantId);
    $previousRoleId = $membership->role_id;
    $newRoleId = userRoleChangedRoleId($this->tenantId);
    $correlationId = 'role-change-correlation-'.Str::uuid7()->toString();

    $response = $this->withHeaders(['X-Correlation-Id' => $correlationId])
        ->patchJson('/v1/memberships/'.$membership->id, [
            'role_id' => $newRoleId,
        ]);

    $response->assertOk()
        ->assertJsonPath('role_id', $newRoleId);

    $rows = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'UserRoleChanged')->get(),
    );

    expect($rows)->toHaveCount(1);

    $row = $rows->first();

    expect($row->type)->toBe('UserRoleChanged')
        ->and($row->aggregate_type)->toBe('membership')
        ->and($row->aggregate_id)->toBe($membership->id)
        ->and($row->tenant_id)->toBe($this->tenantId)
        ->and($row->correlation_id)->toBe($correlationId)
        ->and($row->payload)->toMatchArray([
            'membership_id' => $membership->id,
            'user_id' => $membership->user_id,
            'previous_role_id' => $previousRoleId,
            'new_role_id' => $newRoleId,
            'changed_by_user_id' => $this->actor->id,
        ])
        ->and(array_keys($row->payload))->toEqualCanonicalizing([
            'membership_id',
            'user_id',
            'previous_role_id',
            'new_role_id',
            'changed_by_user_id',
        ])
        ->and($row->payload)->not->toHaveKey('email')
        ->and($row->payload)->not->toHaveKey('name');
});

it('records nothing when the role_id is unchanged (same-role no-op PATCH)', function () {
    $membership = userRoleChangedMembership($this->tenantId);
    $sameRoleId = $membership->role_id;

    $response = $this->patchJson('/v1/memberships/'.$membership->id, [
        'role_id' => $sameRoleId,
    ]);

    $response->assertOk()
        ->assertJsonPath('role_id', $sameRoleId);

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'UserRoleChanged')->count(),
    );

    expect($count)->toBe(0);
});

it('records nothing when the role change request fails validation', function () {
    $membership = userRoleChangedMembership($this->tenantId);

    $this->patchJson('/v1/memberships/'.$membership->id, [
        'role_id' => 'not-a-uuid',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'request.validation_failed');

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'UserRoleChanged')->count(),
    );

    expect($count)->toBe(0);
});

it('records nothing when the role change is denied for missing capability', function () {
    $membership = userRoleChangedMembership($this->tenantId);

    $this->withHeaders([
        'Authorization' => 'Bearer '.userRoleChangedUnauthorizedBearer($this->tenantId),
    ])
        ->patchJson('/v1/memberships/'.$membership->id, [
            'role_id' => userRoleChangedRoleId($this->tenantId),
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'missing_capability');

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'UserRoleChanged')->count(),
    );

    expect($count)->toBe(0);
});

it('records nothing when demoting the only Owner is rejected', function () {
    $ownerRoleId = app(TenantTransaction::class)->asPlatform(
        fn () => Role::query()->whereNull('tenant_id')->where('name', 'Owner')->firstOrFail()->id,
    );
    $onlyOwner = userRoleChangedMembership($this->tenantId, ['role_id' => $ownerRoleId]);
    $otherRoleId = userRoleChangedRoleId($this->tenantId);

    $this->patchJson('/v1/memberships/'.$onlyOwner->id, [
        'role_id' => $otherRoleId,
    ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'last_owner_removal');

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'UserRoleChanged')->count(),
    );

    expect($count)->toBe(0);
});

it('leaves no outbox row when the producing transaction rolls back after recording', function () {
    $membership = userRoleChangedMembership($this->tenantId);
    $newRoleId = userRoleChangedRoleId($this->tenantId);
    $actorId = $this->actor->id;

    try {
        app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($membership, $newRoleId, $actorId): void {
            app(AssignRole::class)(
                $membership,
                new ChangeMembershipRoleData($newRoleId),
                $actorId,
            );
            throw new RuntimeException('force rollback after UserRoleChanged record');
        });
    } catch (RuntimeException) {
        // expected
    }

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'UserRoleChanged')->count(),
    );

    expect($count)->toBe(0);
});

/**
 * @param  list<string>  $capabilities
 */
function userRoleChangedUnauthorizedBearer(string $tenantId): string
{
    $user = User::factory()->create();
    $token = StaffTokens::issue($user);

    app(TenantTransaction::class)->asTenant($tenantId, function () use ($user, $tenantId): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'role_id' => Role::factory()->create([
                'tenant_id' => $tenantId,
                'capabilities' => ['events.view'],
            ])->id,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    return $token;
}
