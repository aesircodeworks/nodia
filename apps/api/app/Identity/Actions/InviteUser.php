<?php

namespace App\Identity\Actions;

use App\Identity\Data\InviteUserData;
use App\Identity\Data\MembershipData;
use App\Identity\Enums\MembershipScope;
use App\Identity\Exceptions\MembershipExistsException;
use App\Identity\Mail\StaffInvitationMail;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Identity\Support\InvitationToken;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * POST /v1/memberships (stage-03 plan, task breakdown item 9). The whole
 * admin request already runs inside one database transaction
 * (App\Tenancy\Http\Middleware\ResolveTenantFromHeader wraps the entire
 * handler in TenantTransaction::asTenant()), so this Action needs no
 * transaction of its own: the user lookup-or-create and the membership
 * insert below are already atomic with each other. That is also the
 * "single transaction with an obvious Stage 4 outbox attachment point"
 * the Domain events section asks for; the point is marked below, right
 * after the membership commits to state, mirroring how App\Identity\Actions\CreateRole
 * needed no wrapper either for its own single write.
 */
final class InviteUser
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function __invoke(InviteUserData $data): MembershipData
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

        // Stage 4 attachment point: Outbox::record(UserInvited::class, ...)
        // belongs here, inside the same transaction as the membership
        // insert above (stage-03 plan, Domain events).

        $token = InvitationToken::issue($user->id);

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
