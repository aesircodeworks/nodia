<?php

namespace App\Identity\Http\Controllers;

use App\Identity\Actions\EnrollMfa;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MfaEnrollmentController
{
    // laravel-data's Responsable defaults a POST response to 201; MFA
    // enrollment issues a pending secret, it does not create a resource,
    // so the status is set explicitly (mirrors StaffTokenController).
    public function __invoke(Request $request, EnrollMfa $enrollMfa): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('staff');

        return response()->json($enrollMfa($user));
    }
}
