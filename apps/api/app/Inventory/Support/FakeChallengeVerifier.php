<?php

namespace App\Inventory\Support;

/**
 * A deterministic App\Inventory\Support\ChallengeVerifier for tests
 * (stage-10 plan, Scope "A challenge hook at queue entry": "shipped with
 * a deterministic fake verifier for tests"), mirroring
 * App\Payments\Gateways\FakeGateway's own in-repo-fake precedent. Never
 * bound by default (App\Inventory\InventoryServiceProvider binds
 * App\Inventory\Support\NoOpChallengeVerifier instead); tests swap the
 * container binding explicitly to exercise the full challenge_required /
 * challenge_failed / success matrix without a real provider.
 */
final class FakeChallengeVerifier implements ChallengeVerifier
{
    public const VALID_RESPONSE = 'valid-challenge-response';

    public function verify(string $response): bool
    {
        return $response === self::VALID_RESPONSE;
    }
}
