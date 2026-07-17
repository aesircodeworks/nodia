<?php

namespace App\Identity\Actions;

use App\Identity\Data\InviteUserData;
use App\Identity\Data\MembershipData;
use App\Identity\Enums\MembershipScope;
use App\Identity\Events\UserInvited;
use App\Identity\Exceptions\MembershipExistsException;
use App\Identity\Mail\StaffInvitationMail;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Identity\Models\StaffInvitationToken;
use App\Identity\Support\InvitationTokenHasher;
use App\Models\User;
use App\Support\Outbox\OutboxRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * POST /v1/memberships (stage-03 plan, task breakdown item 9). The whole
 * admin request already runs inside one database transaction
 * (App\Tenancy\Http\Middleware\ResolveTenantFromHeader wraps the entire
 * handler in TenantTransaction::asTenant()), so this Action needs no
 * transaction of its own: the user lookup-or-create and the membership
 * insert below are already atomic with each other. UserInvited is recorded
 * into the outbox in that same transaction (stage-04 plan, Slice 6).
 */
final class InviteUser
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly OutboxRecorder $outbox,
    ) {}

    public function __invoke(InviteUserData $data, string $invitedByUserId): MembershipData
    {
        $role = Role::visibleOrFail($data->roleId);

        $user = User::query()->where('email', $data->email)->first();

        if ($user === null) {
            // A random, unusable password (stage-03 plan, Roles and
            // memberships endpoint table: "InviteUser creates the user if
            // absent (random unusable password)"): nobody, including the
            // inviter, ever sees it; the invitee only ever gets in through
            // the acceptance token below. User::$casts hashes it on save.
            $user = User::create([
                'name' => $data->name,
                'email' => $data->email,
                'password' => Str::password(40),
                'password_initialized' => false,
            ]);
        }

        try {
            $membership = Membership::create([
                'user_id' => $user->id,
                'tenant_id' => $this->tenantContext->tenantId(),
                'role_id' => $role->id,
                'scope' => MembershipScope::Tenant,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'memberships_user_id_tenant_id_unique')) {
                throw MembershipExistsException::for($data->email);
            }

            throw $e;
        }

        $this->outbox->record(UserInvited::fromMembership($membership, $invitedByUserId));

        // A high-entropy random string mailed in plaintext; only its sha256
        // digest is stored, and acceptance consumes the row atomically
        // (App\Identity\Actions\AcceptInvitation), so a captured token
        // cannot be replayed once redeemed. Persisted inside the enclosing
        // request transaction, mirroring RequestPasswordReset's own
        // single-use-token minting.
        $token = Str::random(64);

        StaffInvitationToken::query()->create([
            'user_id' => $user->id,
            'token_hash' => InvitationTokenHasher::hash($token),
            'expires_at' => Date::now()->addMinutes(config()->integer('identity.invitation_token_ttl_minutes')),
        ]);

        // Deferred to the enclosing request transaction's commit, not sent
        // from inside it: DB::afterCommit() runs synchronously (no queue,
        // matching the plan's "no queue dependency") the moment the whole
        // request transaction actually commits, so a rolled-back request
        // (a later middleware failure, for instance) never mails an
        // acceptance token for a membership that was never really created.
        DB::afterCommit(function () use ($user, $token): void {
            Mail::to($user->email)->send(new StaffInvitationMail($user->name, $token));
        });

        return MembershipData::fromModel($membership->load(['user', 'role']));
    }
}
