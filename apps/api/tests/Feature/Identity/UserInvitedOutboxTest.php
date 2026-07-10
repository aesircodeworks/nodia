<?php

declare(strict_types=1);

use App\Identity\Actions\InviteUser;
use App\Identity\Capability;
use App\Identity\Data\InviteUserData;
use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PlatformStaff;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;

/*
 * Stage-04 plan, Slice 6 (task-11): UserInvited producer on InviteUser.
 * Successful invite over HTTP persists exactly one outbox row with the
 * correct type, aggregate, tenant, correlation ID, and payload shape;
 * failing requests record nothing; platform-scope invites carry the
 * sentinel platform tenant.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $this->inviter = User::factory()->create();
    $this->inviterToken = StaffTokens::issue($this->inviter);

    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        Membership::factory()->create([
            'user_id' => $this->inviter->id,
            'tenant_id' => $this->tenantId,
            'role_id' => Role::factory()->create([
                'tenant_id' => $this->tenantId,
                'capabilities' => ['memberships.manage'],
            ])->id,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->inviterToken,
        'X-Tenant-Id' => $this->tenantId,
    ]);
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    foreach ([$this->tenantId, $sentinel] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
            DB::table('memberships')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });

    User::query()->delete();
});

function userInvitedRoleId(string $tenantId, array $capabilities = []): string
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Role::factory()->create(['tenant_id' => $tenantId, 'capabilities' => $capabilities])->id,
    );
}

it('persists exactly one UserInvited outbox row on a successful invite over HTTP', function () {
    Mail::fake();
    $roleId = userInvitedRoleId($this->tenantId);
    $correlationId = 'invite-correlation-'.Str::uuid7()->toString();

    $response = $this->withHeaders(['X-Correlation-Id' => $correlationId])
        ->postJson('/v1/memberships', [
            'email' => 'outbox-invitee@example.com',
            'name' => 'Outbox Invitee',
            'role_id' => $roleId,
        ]);

    $response->assertCreated();

    $membershipId = $response->json('id');
    $userId = $response->json('user_id');

    $rows = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'UserInvited')->get(),
    );

    expect($rows)->toHaveCount(1);

    $row = $rows->first();

    expect($row->type)->toBe('UserInvited')
        ->and($row->aggregate_type)->toBe('membership')
        ->and($row->aggregate_id)->toBe($membershipId)
        ->and($row->tenant_id)->toBe($this->tenantId)
        ->and($row->correlation_id)->toBe($correlationId)
        ->and($row->payload)->toMatchArray([
            'user_id' => $userId,
            'membership_id' => $membershipId,
            'tenant_id' => $this->tenantId,
            'role_id' => $roleId,
            'invited_by_user_id' => $this->inviter->id,
        ])
        ->and(array_keys($row->payload))->toEqualCanonicalizing([
            'user_id',
            'membership_id',
            'tenant_id',
            'role_id',
            'invited_by_user_id',
        ])
        ->and($row->payload)->not->toHaveKey('email')
        ->and($row->payload)->not->toHaveKey('name');
});

it('records nothing when the invite request fails validation', function () {
    $this->postJson('/v1/memberships', [
        'email' => 'not-an-email',
        'name' => '',
        'role_id' => 'not-a-uuid',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'request.validation_failed');

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'UserInvited')->count(),
    );

    expect($count)->toBe(0);
});

it('records nothing when the invite is denied for missing capability', function () {
    Mail::fake();

    $this->withHeaders([
        'Authorization' => 'Bearer '.userInvitedUnauthorizedBearer($this->tenantId),
    ])
        ->postJson('/v1/memberships', [
            'email' => 'denied@example.com',
            'name' => 'Denied',
            'role_id' => userInvitedRoleId($this->tenantId),
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'missing_capability');

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'UserInvited')->count(),
    );

    expect($count)->toBe(0);
});

it('records nothing when the invite collides with an existing membership', function () {
    Mail::fake();

    $existing = User::factory()->create(['email' => 'already-outbox@example.com']);
    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($existing): void {
        Membership::factory()->create([
            'user_id' => $existing->id,
            'tenant_id' => $this->tenantId,
            'role_id' => Role::factory()->create(['tenant_id' => $this->tenantId])->id,
        ]);
    });

    $this->postJson('/v1/memberships', [
        'email' => 'already-outbox@example.com',
        'name' => 'Already',
        'role_id' => userInvitedRoleId($this->tenantId),
    ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'membership_exists');

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'UserInvited')->count(),
    );

    expect($count)->toBe(0);
});

it('carries the sentinel platform tenant for a platform-scope invite', function () {
    Mail::fake();
    $sentinel = config()->string('tenancy.platform_tenant_id');
    $token = PlatformStaff::token(Capability::MembershipsManage);

    $roleId = app(TenantTransaction::class)->asPlatform(
        fn () => Role::factory()->create([
            'tenant_id' => $sentinel,
            'capabilities' => ['memberships.manage'],
        ])->id,
    );

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'X-Tenant-Id' => $sentinel,
        'X-Correlation-Id' => 'platform-invite-correlation',
    ])->postJson('/v1/memberships', [
        'email' => 'platform-invitee@example.com',
        'name' => 'Platform Invitee',
        'role_id' => $roleId,
    ]);

    $response->assertCreated();

    $rows = app(TenantTransaction::class)->asTenant(
        $sentinel,
        fn () => OutboxEvent::query()->where('type', 'UserInvited')->get(),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->tenant_id)->toBe($sentinel)
        ->and($rows->first()->payload['tenant_id'])->toBe($sentinel)
        ->and($rows->first()->aggregate_type)->toBe('membership')
        ->and($rows->first()->correlation_id)->toBe('platform-invite-correlation');
});

it('leaves no outbox row when the producing transaction rolls back after recording', function () {
    Mail::fake();
    $roleId = userInvitedRoleId($this->tenantId);
    $inviterId = $this->inviter->id;

    try {
        app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($roleId, $inviterId): void {
            app(InviteUser::class)(new InviteUserData('rollback@example.com', 'Rollback', $roleId), $inviterId);
            throw new RuntimeException('force rollback after UserInvited record');
        });
    } catch (RuntimeException) {
        // expected
    }

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'UserInvited')->count(),
    );

    expect($count)->toBe(0);
});

/**
 * @param  list<string>  $capabilities
 */
function userInvitedUnauthorizedBearer(string $tenantId): string
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
