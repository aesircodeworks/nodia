<?php

namespace App\Identity\Http\Controllers;

use App\Identity\Actions\RequestCustomerClaim;
use App\Identity\Data\ClaimRequestData;
use Illuminate\Http\Response;

class RequestCustomerClaimController
{
    public function __invoke(ClaimRequestData $data, RequestCustomerClaim $requestCustomerClaim): Response
    {
        $requestCustomerClaim($data);

        // Always 202, whether or not the email exists or is already
        // claimed, so the response never leaks which (stage-03 plan,
        // task breakdown item 13).
        return response()->noContent(202);
    }
}
