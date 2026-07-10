<?php

namespace App\Identity\Http\Controllers;

use App\Identity\Actions\RotateCustomerToken;
use App\Identity\Data\RefreshTokenRequestData;
use Illuminate\Http\JsonResponse;

class CustomerRefreshController
{
    public function __invoke(RefreshTokenRequestData $data, RotateCustomerToken $rotateCustomerToken): JsonResponse
    {
        return response()->json($rotateCustomerToken($data));
    }
}
