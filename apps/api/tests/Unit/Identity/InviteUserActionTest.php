<?php

declare(strict_types=1);

use App\Identity\Actions\InviteUser;
use App\Identity\Data\InviteUserData;
use App\Identity\Exceptions\MembershipExistsException;
use App\Identity\Exceptions\RoleNotFoundException;
use App\Identity\Mail\StaffInvitationMail;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Identity\Support\InvitationToken;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-03 plan, task breakdown item 9: "Unit tests for the Actions."
 * InviteUser is driven through TenantTransaction::asTenant() exactly the
 * way the tenancy.admin middleware runs it, mirroring
 * ResolveActingMembershipTest's own precedent, rather than through HTTP
 * (tests/Feature/Identity/MembershipEndpointsTest.php already proves the
 * endpoint end to end).
 */

const IU_TENANT = '019797f5-0000-7000-8000-0000000000c1';

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::factory()->create(['id' => IU_TENANT]);
    });
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant(IU_TENANT, function (): void {
        DB::table('outbox_deliveries')->where('tenant_id', IU_TENANT)->delete();
        DB::table('outbox_events')->where('tenant_id', IU_TENANT)->delete();
        DB::table('memberships')->where('tenant_id', IU_TENANT)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    User::query()->delete();
});

function inviteUserRoleId(array $capabilities = []): string
{
    return app(TenantTransaction::class)->asTenant(
        IU_TENANT,
        fn () => Role::factory()->create(['tenant_id' => IU_TENANT, 'capabilities' => $capabilities])->id,
    );
}

it('creates an absent user with a random, hashed, unusable password', function () {
    Mail::fake();
    $roleId = inviteUserRoleId();
    $inviterId = User::factory()->create()->id;

    app(TenantTransaction::class)->asTenant(
        IU_TENANT,
        fn () => app(InviteUser::class)(new InviteUserData('new-invitee@example.com', 'New Invitee', $roleId), $inviterId),
    );

    $user = User::query()->where('email', 'new-invitee@example.com')->firstOrFail();

    expect($user->name)->toBe('New Invitee')
        ->and($user->password)->not->toBe('password')
        ->and(strlen($user->password))->toBeGreaterThan(20)
        ->and(Hash::check('password', $user->password))->toBeFalse();
});

it('reuses an existing user rather than creating a duplicate', function () {
    Mail::fake();
    $existing = User::factory()->create(['email' => 'reused@example.com']);
    $roleId = inviteUserRoleId();
    $inviterId = User::factory()->create()->id;

    $membershipData = app(TenantTransaction::class)->asTenant(
        IU_TENANT,
        fn () => app(InviteUser::class)(new InviteUserData('reused@example.com', 'Ignored Name', $roleId), $inviterId),
    );

    expect($membershipData->userId)->toBe($existing->id)
        ->and(User::query()->where('email', 'reused@example.com')->count())->toBe(1);
});

it('throws membership_exists when the user is already a member of the tenant', function () {
    Mail::fake();
    $existing = User::factory()->create(['email' => 'already@example.com']);
    $firstRoleId = inviteUserRoleId();
    $secondRoleId = inviteUserRoleId();
    $inviterId = User::factory()->create()->id;

    app(TenantTransaction::class)->asTenant(IU_TENANT, function () use ($existing, $firstRoleId): void {
        Membership::factory()->create([
            'user_id' => $existing->id,
            'tenant_id' => IU_TENANT,
            'role_id' => $firstRoleId,
        ]);
    });

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        IU_TENANT,
        fn () => app(InviteUser::class)(new InviteUserData('already@example.com', 'Already', $secondRoleId), $inviterId),
    );

    expect($invoke)->toThrow(MembershipExistsException::class);
});

it('throws request.not_found for an unknown role id', function () {
    Mail::fake();
    $inviterId = User::factory()->create()->id;

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        IU_TENANT,
        fn () => app(InviteUser::class)(new InviteUserData('nobody@example.com', 'Nobody', (string) Str::uuid7()), $inviterId),
    );

    expect($invoke)->toThrow(RoleNotFoundException::class);
});

it('mails a StaffInvitationMail carrying a verifiable acceptance token once the transaction commits', function () {
    Mail::fake();
    $roleId = inviteUserRoleId();
    $inviterId = User::factory()->create()->id;

    app(TenantTransaction::class)->asTenant(
        IU_TENANT,
        fn () => app(InviteUser::class)(new InviteUserData('mailed@example.com', 'Mailed Invitee', $roleId), $inviterId),
    );

    Mail::assertSent(StaffInvitationMail::class, fn (StaffInvitationMail $mail): bool => $mail->hasTo('mailed@example.com')
        && InvitationToken::verify($mail->token) === User::query()->where('email', 'mailed@example.com')->firstOrFail()->id);
});
