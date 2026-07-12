<?php

use App\Inventory\Support\ChallengeVerifier;
use App\Inventory\Support\FakeChallengeVerifier;
use App\Inventory\Support\NoOpChallengeVerifier;

/*
 * Stage-10 plan, TDD sequencing Slice 4 (Unit, first), task breakdown
 * item 7: the ChallengeVerifier contract and its no-op default, isolated
 * from App\Inventory\Actions\JoinQueue's own use of it.
 */

test('the container binds ChallengeVerifier to NoOpChallengeVerifier by default', function (): void {
    expect(app(ChallengeVerifier::class))->toBeInstanceOf(NoOpChallengeVerifier::class);
});

test('NoOpChallengeVerifier accepts any non-empty response, proving the hook without verifying anything', function (string $response): void {
    expect((new NoOpChallengeVerifier)->verify($response))->toBeTrue();
})->with([
    'arbitrary text' => ['anything'],
    'a proof-of-work-shaped token' => ['00001a2b3c'],
]);

test('FakeChallengeVerifier is deterministic: only its fixed valid response succeeds', function (): void {
    $verifier = new FakeChallengeVerifier;

    expect($verifier->verify(FakeChallengeVerifier::VALID_RESPONSE))->toBeTrue()
        ->and($verifier->verify('wrong'))->toBeFalse()
        ->and($verifier->verify(''))->toBeFalse();
});
