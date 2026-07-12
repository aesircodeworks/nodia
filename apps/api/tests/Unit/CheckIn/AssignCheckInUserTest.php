<?php

use App\CheckIn\Actions\AssignCheckInUser;
use App\CheckIn\Data\CheckInAssignmentData;
use App\CheckIn\Exceptions\AlreadyAssignedException;
use App\CheckIn\Exceptions\UserNotMemberException;
use App\EventCatalog\Models\Event;
use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-09 plan, Endpoints "Check-in assignments": AssignCheckInUser
 * enforces the target user already has a membership in the acting
 * tenant (422 user_not_member) and that the (event, user) pair is not
 * already assigned, guarded by the unique index rather than a prior
 * read (409 already_assigned).
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $this->eventId = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::factory()->create(['tenant_id' => $this->tenantId])->id,
    );

    $this->memberId = app(TenantTransaction::class)->asPlatform(fn () => User::factory()->create()->id);

    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        $role = Role::factory()->create(['tenant_id' => $this->tenantId]);
        Membership::factory()->create([
            'user_id' => $this->memberId,
            'tenant_id' => $this->tenantId,
            'role_id' => $role->id,
            'scope' => MembershipScope::Tenant,
        ]);
    });
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('check_in_assignments')->where('tenant_id', $this->tenantId)->delete();
        DB::table('memberships')->where('tenant_id', $this->tenantId)->delete();
        DB::table('roles')->where('tenant_id', $this->tenantId)->delete();
        DB::table('events')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );

    User::query()->delete();
});

it('creates an assignment for a member', function (): void {
    $data = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn (): CheckInAssignmentData => (app(AssignCheckInUser::class))($this->eventId, $this->memberId),
    );

    expect($data->eventId)->toBe($this->eventId)
        ->and($data->userId)->toBe($this->memberId);
});

it('rejects a user with no membership in the tenant', function (): void {
    $outsiderId = app(TenantTransaction::class)->asPlatform(fn () => User::factory()->create()->id);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($outsiderId): void {
        expect(fn () => (app(AssignCheckInUser::class))($this->eventId, $outsiderId))
            ->toThrow(UserNotMemberException::class);
    });

    app(TenantTransaction::class)->asPlatform(fn () => User::query()->whereKey($outsiderId)->delete());
});

it('rejects a duplicate assignment for the same event and user', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        (app(AssignCheckInUser::class))($this->eventId, $this->memberId);

        expect(fn () => (app(AssignCheckInUser::class))($this->eventId, $this->memberId))
            ->toThrow(AlreadyAssignedException::class);
    });
});
