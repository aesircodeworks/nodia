<?php

namespace App\Identity\Actions;

use App\Identity\Models\StaffInvitationToken;
use Illuminate\Support\Facades\Date;

/**
 * Atomically consumes an invitation acceptance token by its digest
 * (stage-03 plan, task breakdown item 9). A conditional UPDATE checked by
 * affected-row count, never a read-then-write existence check first
 * (master plan test-first rule 2; CLAUDE.md), mirroring
 * App\Identity\Actions\ConsumePasswordResetToken's own precedent for the
 * sibling single-use-token guard: two parallel presentations of the same
 * token can only ever see the row transition from unconsumed to consumed
 * once.
 *
 * Deliberately silent on why a token fails to consume:
 * App\Identity\Actions\AcceptInvitation is the only caller, and it decides
 * invitation_token_invalid versus invitation_token_expired with its own
 * follow-up lookup, run only after this guard has already decided the
 * outcome.
 */
final class ConsumeInvitationToken
{
    public function __invoke(string $tokenHash): bool
    {
        $affected = StaffInvitationToken::query()
            ->where('token_hash', $tokenHash)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', Date::now())
            ->update(['consumed_at' => Date::now()]);

        return $affected > 0;
    }
}
