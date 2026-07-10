<?php

namespace App\Identity\Http\Controllers;

use App\Identity\Actions\ConfirmMfaEnrollment;
use App\Identity\Data\ConfirmMfaData;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MfaEnrollmentConfirmController
{
    // Same 201-default override as MfaEnrollmentController: confirming
    // enrollment is an action, not a resource creation.
    public function __invoke(Request $request, ConfirmMfaData $data, ConfirmMfaEnrollment $confirmMfaEnrollment): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('staff');

        return response()->json($confirmMfaEnrollment($user, $data));
    }
}
