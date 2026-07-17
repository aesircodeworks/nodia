<?php

namespace App\Identity\Actions;

use App\Identity\Data\ClaimRequestData;
use App\Identity\Mail\CustomerClaimMail;
use App\Identity\Models\Customer;
use App\Identity\Support\ClaimToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * POST /v1/auth/customer/claim (stage-03 plan, task breakdown item 13):
 * always renders 202 regardless of whether the email exists, already has
 * a password, or is a genuine unclaimed guest, mirroring InviteUser's own
 * afterCommit-deferred send (task breakdown item 9): a claim token is
 * only ever issued and mailed for a genuine unclaimed guest, deferred to
 * the enclosing tenant transaction's commit so a rolled-back request
 * never mails a token for a customer that was never really found.
 */
final class RequestCustomerClaim
{
    public function __invoke(ClaimRequestData $data): void
    {
        $customer = Customer::query()->where('email', $data->email)->first();

        if ($customer === null || $customer->password !== null) {
            return;
        }

        $token = ClaimToken::issue($customer->id);

        DB::afterCommit(function () use ($customer, $token): void {
            Mail::to($customer->email)->send(new CustomerClaimMail($customer->name, $token));
        });
    }
}
