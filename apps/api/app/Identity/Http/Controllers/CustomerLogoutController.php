<?php

namespace App\Identity\Http\Controllers;

use App\Identity\Actions\RevokeSession;
use App\Identity\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Passport\AccessToken;

class CustomerLogoutController
{
    public function __invoke(Request $request, RevokeSession $revokeSession): Response
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        /** @var AccessToken $accessToken */
        $accessToken = $customer->token();

        $revokeSession($accessToken);

        return response()->noContent();
    }
}
