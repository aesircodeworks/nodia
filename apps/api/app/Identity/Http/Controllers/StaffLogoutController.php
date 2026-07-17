<?php

namespace App\Identity\Http\Controllers;

use App\Identity\Actions\RevokeSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Passport\AccessToken;

class StaffLogoutController
{
    public function __invoke(Request $request, RevokeSession $revokeSession): Response
    {
        /** @var User $user */
        $user = $request->user('staff');

        /** @var AccessToken $accessToken */
        $accessToken = $user->token();

        $revokeSession($accessToken);

        return response()->noContent();
    }
}
