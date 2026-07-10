<?php

namespace App\Identity\Http\Controllers;

use App\Identity\Actions\ClaimGuestAccount;
use App\Identity\Data\ConfirmClaimData;
use Illuminate\Http\Response;

class ConfirmCustomerClaimController
{
    public function __invoke(ConfirmClaimData $data, ClaimGuestAccount $claimGuestAccount): Response
    {
        $claimGuestAccount($data);

        return response()->noContent();
    }
}
