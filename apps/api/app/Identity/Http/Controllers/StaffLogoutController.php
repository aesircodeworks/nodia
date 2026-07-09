<?php

namespace App\Identity\Http\Controllers;

use App\Identity\Actions\RevokeStaffSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Passport\AccessToken;

class StaffLogoutController
{
    public function __invoke(Request $request, RevokeStaffSession $revokeStaffSession): Response
    {
        /** @var User $user */
        $user = $request->user('staff');

        /** @var AccessToken $accessToken */
        $accessToken = $user->token();

        $revokeStaffSession($accessToken);

        return response()->noContent();
    }
}
