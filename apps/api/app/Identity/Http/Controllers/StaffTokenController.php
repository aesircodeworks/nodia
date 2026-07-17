<?php

namespace App\Identity\Http\Controllers;

use App\Identity\Actions\IssueStaffToken;
use App\Identity\Data\StaffTokenRequestData;
use Illuminate\Http\JsonResponse;

class StaffTokenController
{
    // laravel-data's Responsable defaults a POST response to 201; a token
    // exchange issues, it does not create a resource, so the status is
    // set explicitly rather than relying on that default.
    public function __invoke(StaffTokenRequestData $data, IssueStaffToken $issueStaffToken): JsonResponse
    {
        return response()->json($issueStaffToken($data));
    }
}
