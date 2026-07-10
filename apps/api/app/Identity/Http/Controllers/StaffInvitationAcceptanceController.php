<?php

namespace App\Identity\Http\Controllers;

use App\Identity\Actions\AcceptInvitation;
use App\Identity\Data\AcceptInvitationData;
use Illuminate\Http\Response;

class StaffInvitationAcceptanceController
{
    public function __invoke(AcceptInvitationData $data, AcceptInvitation $acceptInvitation): Response
    {
        $acceptInvitation($data);

        return response()->noContent();
    }
}
