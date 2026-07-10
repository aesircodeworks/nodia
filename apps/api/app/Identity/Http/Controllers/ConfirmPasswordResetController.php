<?php

namespace App\Identity\Http\Controllers;

use App\Identity\Actions\ConfirmPasswordReset;
use App\Identity\Data\ConfirmPasswordResetData;
use Illuminate\Http\Response;

class ConfirmPasswordResetController
{
    public function __invoke(ConfirmPasswordResetData $data, ConfirmPasswordReset $confirmPasswordReset): Response
    {
        $confirmPasswordReset($data);

        return response()->noContent();
    }
}
