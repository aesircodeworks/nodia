<?php

namespace App\Inventory\Support;

/**
 * The default App\Inventory\Support\ChallengeVerifier binding
 * (App\Inventory\InventoryServiceProvider::register) until a real
 * provider lands behind an ADR (stage-10 plan, Non-goals: "This stage
 * proves the hook, the denial path, and the per-event toggle"). Accepts
 * any non-empty response: it never blocks a join once a
 * challenge_response is present, which is enough to prove the hook and
 * the challenge_required denial path (raised upstream in
 * App\Inventory\Actions\JoinQueue before this class ever runs) without
 * pretending to verify anything.
 */
final class NoOpChallengeVerifier implements ChallengeVerifier
{
    public function verify(string $response): bool
    {
        return true;
    }
}
