<?php

namespace App\Inventory\Actions;

use App\Inventory\Enums\HoldStatus;
use App\Inventory\Models\Hold;
use Illuminate\Support\Facades\Date;

/**
 * Whether CommitHold would currently accept this hold: active and not
 * past expires_at, the same guard CommitHold's conditional UPDATE
 * applies. A pre-flight read, not a claim; the commit itself remains
 * the only authority, so callers use this to avoid starting work whose
 * commit is already doomed (a gateway charge for a dead hold), never to
 * skip the guarded commit.
 */
final class CheckHoldCommittable
{
    public function __invoke(string $holdId): bool
    {
        return Hold::query()
            ->whereKey($holdId)
            ->where('status', HoldStatus::Active)
            ->where('expires_at', '>', Date::now())
            ->exists();
    }
}
