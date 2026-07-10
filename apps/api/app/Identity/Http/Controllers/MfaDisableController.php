<?php

namespace App\Identity\Http\Controllers;

use App\Identity\Actions\DisableMfa;
use App\Identity\Data\DisableMfaData;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MfaDisableController
{
    public function __invoke(Request $request, DisableMfaData $data, DisableMfa $disableMfa): Response
    {
        /** @var User $user */
        $user = $request->user('staff');

        $disableMfa($user, $data);

        return response()->noContent();
    }
}
