<?php

namespace App\Identity\Http\Controllers;

use App\Identity\Actions\IssueCustomerToken;
use App\Identity\Data\CustomerTokenRequestData;
use Illuminate\Http\JsonResponse;

class CustomerTokenController
{
    // Same laravel-data Responsable default-status caveat as
    // StaffTokenController: a token exchange issues, it does not create a
    // resource, so the status is set explicitly rather than relying on
    // that default.
    public function __invoke(CustomerTokenRequestData $data, IssueCustomerToken $issueCustomerToken): JsonResponse
    {
        return response()->json($issueCustomerToken($data));
    }
}
