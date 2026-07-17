<?php

namespace App\Identity\Http\Controllers;

use App\Identity\Actions\RotateStaffToken;
use App\Identity\Data\RefreshTokenRequestData;
use Illuminate\Http\JsonResponse;

class StaffRefreshController
{
    // Same laravel-data Responsable default-status caveat as
    // StaffTokenController: a token exchange issues, it does not create a
    // resource, so the status is set explicitly.
    public function __invoke(RefreshTokenRequestData $data, RotateStaffToken $rotateStaffToken): JsonResponse
    {
        return response()->json($rotateStaffToken($data));
    }
}
