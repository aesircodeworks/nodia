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
 *
 * An anonymized customer (stage-12 plan, Slice 1 Feature tests: "the
 * guest-claim flow rejects the anonymized customer") also carries a null
 * password, since App\Identity\Actions\AnonymizeCustomer nulls it as part
 * of erasure: without the anonymized_at check below, that would let a
 * still-valid claim token re-arm a supposedly erased account with a real
 * credential. Rendered identically to a missing customer (claim_token_
 * invalid) rather than a new code, since the plan names no third error
 * for this endpoint and the response must not reveal that the account was
 * ever erased.
 */
final class ClaimGuestAccount
{
    public function __invoke(ConfirmClaimData $data): void
    {
        $customerId = ClaimToken::verify($data->token);

        $customer = Customer::query()->find($customerId);

        if ($customer === null || $customer->anonymized_at !== null) {
            // The token's own signature and expiry already verified; a
            // missing customer only happens if the row is gone (no
            // deletion path exists yet this stage) or belongs to a
            // different tenant than the one this request resolved (RLS
            // hides it), rendered identically to a tampered token so the
            // response never distinguishes the cases, now joined by an
            // anonymized customer for the same reason.
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
