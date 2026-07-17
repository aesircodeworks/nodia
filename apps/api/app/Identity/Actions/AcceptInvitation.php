<?php

namespace App\Identity\Actions;

use App\Identity\Data\AcceptInvitationData;
use App\Identity\Exceptions\InvitationTokenExpiredException;
use App\Identity\Exceptions\InvitationTokenInvalidException;
use App\Identity\Models\StaffInvitationToken;
use App\Identity\Support\InvitationTokenHasher;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * POST /v1/auth/staff/invitation/accept (stage-03 plan, task breakdown
 * item 9). Redeems the single-use token InviteUser mailed and sets the
 * user's first password. password_initialized distinguishes an initial
 * credential invitation from a later membership invitation for an
 * established global user. The user row lock, conditional credential
 * update, and sibling-token invalidation make the first acceptance the
 * only invitation that can establish a password; later invitations can
 * never act as password resets.
 *
 * Not named in the Domain events section: invitation acceptance sets first
 * credentials, it does not itself change a membership or role, so there is
 * no outbox attachment point here (UserInvited already recorded at
 * InviteUser's own point). No token revocation either, unlike
 * ConfirmPasswordReset: the only credential-changing path is a first
 * credential, so the user holds no live sessions to revoke.
 */
final class AcceptInvitation
{
    public function __construct(private readonly ConsumeInvitationToken $consume) {}

    public function __invoke(AcceptInvitationData $data): void
    {
        DB::transaction(function () use ($data): void {
            $tokenHash = InvitationTokenHasher::hash($data->token);
            $invitationToken = StaffInvitationToken::query()->where('token_hash', $tokenHash)->first();

            if ($invitationToken === null) {
                throw InvitationTokenInvalidException::make();
            }

            $user = User::query()->whereKey($invitationToken->user_id)->lockForUpdate()->first();

            if ($user === null) {
                throw InvitationTokenInvalidException::make();
            }

            if (! ($this->consume)($tokenHash)) {
                $invitationToken->refresh();

                if ($invitationToken->consumed_at === null) {
                    throw InvitationTokenExpiredException::make();
                }

                throw InvitationTokenInvalidException::make();
            }

            User::query()
                ->whereKey($user->id)
                ->where('password_initialized', false)
                ->update([
                    'password' => Hash::make($data->password),
                    'password_initialized' => true,
                    'updated_at' => Date::now(),
                ]);

            $now = Date::now();
            StaffInvitationToken::query()
                ->where('user_id', $user->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => $now, 'updated_at' => $now]);
        });
    }
}
