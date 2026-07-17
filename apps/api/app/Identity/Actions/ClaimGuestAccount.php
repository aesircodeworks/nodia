<?php

namespace App\Identity\Actions;

use App\Identity\Data\ConfirmClaimData;
use App\Identity\Exceptions\ClaimTokenInvalidException;
use App\Identity\Exceptions\CustomerAlreadyClaimedException;
use App\Identity\Models\Customer;
use App\Identity\Support\ClaimToken;
use Illuminate\Support\Facades\Hash;

/**
 * POST /v1/auth/customer/claim/confirm (stage-03 plan, task breakdown
 * item 13; system-design 3.2, 5.2). Sets the guest's first password,
 * enabling POST /v1/auth/customer/token to authenticate them from here
 * on (ADR 007). A customer already holding a password (claimed earlier,
 * or created through registration rather than guest checkout) is denied
 * customer_already_claimed rather than silently overwriting an existing
 * credential. The password write is one conditional UPDATE guarded by
 * password IS NULL and its affected-row count, so overlapping uses of the
 * same still-valid token have exactly one winner.
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

        $affected = Customer::query()
            ->whereKey($customerId)
            ->whereNull('password')
            ->whereNull('anonymized_at')
            ->update([
                'password' => Hash::make($data->password),
                'email_verified_at' => now(),
                'updated_at' => now(),
            ]);

        if ($affected === 1) {
            return;
        }

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

        throw CustomerAlreadyClaimedException::make();
    }
}
