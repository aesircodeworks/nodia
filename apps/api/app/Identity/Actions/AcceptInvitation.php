<?php

namespace App\Identity\Actions;

use App\Identity\Data\AcceptInvitationData;
use App\Identity\Exceptions\InvitationTokenInvalidException;
use App\Identity\Support\InvitationToken;
use App\Models\User;

/**
 * POST /v1/auth/staff/invitation/accept (stage-03 plan, task breakdown
 * item 9). Not named in the Domain events section: invitation acceptance
 * sets first credentials, it does not itself change a membership or role,
 * so there is no outbox attachment point here (UserInvited already
 * recorded, once Stage 4 attaches it, at InviteUser's own point).
 */
final class AcceptInvitation
{
    public function __invoke(AcceptInvitationData $data): void
    {
        $userId = InvitationToken::verify($data->token);

        $user = User::query()->find($userId);

        if ($user === null) {
            // The token's own signature and expiry already verified; a
            // missing user only happens if the account was deleted after
            // the token was issued, which Stage 3 has no path for yet.
            // Rendered identically to a tampered token so the response
            // never distinguishes the two.
            throw InvitationTokenInvalidException::make();
        }

        $user->update(['password' => $data->password]);
    }
}
