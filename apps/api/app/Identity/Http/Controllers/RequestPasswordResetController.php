<?php

namespace App\Identity\Http\Controllers;

use App\Identity\Actions\RequestPasswordReset;
use App\Identity\Data\RequestPasswordResetData;
use Illuminate\Http\Response;

class RequestPasswordResetController
{
    public function __invoke(RequestPasswordResetData $data, RequestPasswordReset $requestPasswordReset): Response
    {
        $requestPasswordReset($data);

        // Always 202, whether or not the email resolves to a user, so the
        // response never leaks which (stage-03 plan, task breakdown item
        // 16).
        return response()->noContent(202);
    }
}
