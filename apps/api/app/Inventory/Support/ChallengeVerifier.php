<?php

namespace App\Inventory\Support;

/**
 * The challenge hook at queue entry (stage-10 plan, Scope "A challenge
 * hook at queue entry"; Endpoints "POST /v1/storefront/events/{event}/
 * queue-entries"). App\Inventory\Actions\JoinQueue calls this only when
 * the event's on_sale_policy.challenge_required is set and a
 * challenge_response was supplied in the request; a missing response
 * fails challenge_required before this interface is ever reached, so
 * $response is always non-empty as far as callers are concerned. The
 * concrete provider (a CAPTCHA vendor or a proof-of-work scheme) is
 * deferred to a later ADR (stage-10 plan, Non-goals: "A real challenge
 * provider... behind the ChallengeVerifier interface, selected later by
 * ADR"); this stage ships only the hook, the denial path, and two
 * in-repo implementations bound in App\Inventory\InventoryServiceProvider:
 * App\Inventory\Support\NoOpChallengeVerifier (the container's default)
 * and App\Inventory\Support\FakeChallengeVerifier (deterministic, for
 * tests), mirroring App\Payments\Gateways\GatewayAdapter's own
 * interface-plus-in-repo-fake precedent from stage-08.
 */
interface ChallengeVerifier
{
    public function verify(string $response): bool;
}
