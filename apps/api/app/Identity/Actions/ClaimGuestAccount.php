<?php

namespace App\Identity\Actions;

use App\Identity\Data\ConfirmClaimData;
use App\Identity\Exceptions\ClaimTokenInvalidException;
use App\Identity\Exceptions\CustomerAlreadyClaimedException;
use App\Identity\Models\Customer;
use App\Identity\Support\ClaimToken;

/**
 * POST /v1/auth/customer/claim/confirm (stage-03 plan, task breakdown
 * item 13; system-design 3.2, 5.2). Sets the guest's first password,
 * enabling POST /v1/auth/customer/token to authenticate them from here
 * on (ADR 007). A customer already holding a password (claimed earlier,
 * or created through registration rather than guest checkout) is denied
 * customer_already_claimed rather than silently overwriting an existing
 * credential; this is also what makes replaying a still-valid claim
 * token after its first successful use harmless, since no single-use
 * tracking exists on the token itself (App\Identity\Support\ClaimToken).
 */
final class ClaimGuestAccount
{
    public function __invoke(ConfirmClaimData $data): void
    {
        $customerId = ClaimToken::verify($data->token);

        $customer = Customer::query()->find($customerId);

        if ($customer === null) {
            // The token's own signature and expiry already verified; a
            // missing customer only happens if the row is gone (no
            // deletion path exists yet this stage) or belongs to a
            // different tenant than the one this request resolved (RLS
            // hides it), rendered identically to a tampered token so the
            // response never distinguishes the cases.
            throw ClaimTokenInvalidException::make();
        }

        if ($customer->password !== null) {
            throw CustomerAlreadyClaimedException::make();
        }

        $customer->update([
            'password' => $data->password,
            'email_verified_at' => now(),
        ]);
    }
}
