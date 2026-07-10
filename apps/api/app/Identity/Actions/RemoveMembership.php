<?php

namespace App\Identity\Actions;

use App\Identity\Models\Membership;
use App\Identity\Support\GuardsLastOwner;
use Illuminate\Support\Facades\DB;

/**
 * DELETE /v1/memberships/{membership} (stage-03 plan, task breakdown item
 * 9). Not a named single-transaction Action in the Domain events section
 * (only InviteUser and AssignRole are: no UserRemoved event type exists
 * yet in the Identity event list, system-design 9.3), so there is no
 * outbox attachment point to mark here.
 */
final class RemoveMembership
{
    use GuardsLastOwner;

    public function __invoke(Membership $membership): void
    {
        $this->assertNotLastOwner($membership);

        DB::table('memberships')->where('id', $membership->id)->delete();
    }
}
