<?php

namespace App\Identity\Actions;

use App\Identity\Data\AcceptInvitationData;
use App\Identity\Exceptions\InvitationTokenExpiredException;
use App\Identity\Exceptions\InvitationTokenInvalidException;
use App\Identity\Models\StaffInvitationToken;
use App\Identity\Support\InvitationTokenHasher;
use App\Models\User;

/**
 * POST /v1/auth/staff/invitation/accept (stage-03 plan, task breakdown
 * item 9). Redeems the single-use token InviteUser mailed and sets the
 * user's first password. The affected-row-count guard
 * (App\Identity\Actions\ConsumeInvitationToken) decides success before any
 * other lookup runs; only on its failure does this class look the row up
 * again, purely to distinguish invitation_token_expired (a live row whose
 * expiry passed) from invitation_token_invalid (unknown, tampered, or
 * already consumed), mirroring App\Identity\Actions\ConfirmPasswordReset's
 * own precedent. Making acceptance single-use is what stops a still-valid
 * token from replaying into a password reset, taking over the account
 * even after the legitimate recipient accepted.
 *
 * Not named in the Domain events section: invitation acceptance sets first
 * credentials, it does not itself change a membership or role, so there is
 * no outbox attachment point here (UserInvited already recorded at
 * InviteUser's own point). No token revocation either, unlike
 * ConfirmPasswordReset: this is a first credential, so the user holds no
 * live sessions to revoke.
 */
final class AcceptInvitation
{
    public function __construct(private readonly ConsumeInvitationToken $consume) {}

    public function __invoke(AcceptInvitationData $data): void
    {
        $tokenHash = InvitationTokenHasher::hash($data->token);

        if (! ($this->consume)($tokenHash)) {
            $existing = StaffInvitationToken::query()->where('token_hash', $tokenHash)->first();

            if ($existing !== null && $existing->consumed_at === null) {
                throw InvitationTokenExpiredException::make();
            }

            throw InvitationTokenInvalidException::make();
        }

        $invitationToken = StaffInvitationToken::query()->where('token_hash', $tokenHash)->firstOrFail();

        $user = User::query()->find($invitationToken->user_id);

        if ($user === null) {
            // The token's own hash already matched a live, just-consumed
            // row; a missing user only happens if the account was deleted
            // after the token was issued, which Stage 3 has no path for
            // yet. Rendered identically to a tampered token so the response
            // never distinguishes the two.
            throw InvitationTokenInvalidException::make();
        }

        $user->update(['password' => $data->password]);
    }
}
