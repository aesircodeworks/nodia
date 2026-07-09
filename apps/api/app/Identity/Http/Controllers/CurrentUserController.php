<?php

namespace App\Identity\Http\Controllers;

use App\Identity\Actions\ListOwnMemberships;
use App\Identity\Data\CurrentUserData;
use App\Models\User;
use Illuminate\Http\Request;

class CurrentUserController
{
    public function __construct(private readonly ListOwnMemberships $memberships) {}

    public function __invoke(Request $request): CurrentUserData
    {
        /** @var User $user */
        $user = $request->user('staff');

        return CurrentUserData::fromModel($user, $this->memberships->forUser($user));
    }
}
