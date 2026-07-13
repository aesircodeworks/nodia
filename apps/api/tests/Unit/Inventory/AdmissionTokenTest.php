<?php

use App\Inventory\Support\AdmissionToken;
use Illuminate\Support\Str;

/*
 * Stage-10 plan, TDD sequencing Slice 5 (Unit, first), task breakdown
 * item 8: token payload and HMAC round-trip; key-ID rotation (old key
 * verifies until retired, unknown key ID rejected); expiry checked
 * against the injected clock.
 */

beforeEach(function (): void {
    config([
        'onsale.admission_token.current_key_id' => 'k2',
        'onsale.admission_token.current_key_secret' => 'current-secret',
        'onsale.admission_token.previous_key_id' => 'k1',
        'onsale.admission_token.previous_key_secret' => 'previous-secret',
    ]);

    $this->entrantId = Str::uuid7()->toString();
    $this->eventId = Str::uuid7()->toString();
    $this->tenantId = Str::uuid7()->toString();
});

test('issue then verify round-trips the entrant id under the current key', function (): void {
    $now = now();
    $expiresAt = $now->copy()->addMinutes(5);

    $token = AdmissionToken::issue($this->entrantId, $this->eventId, $this->tenantId, $expiresAt);

    expect(AdmissionToken::verify($token, $this->eventId, $this->tenantId, $now))->toBe($this->entrantId);
});

test('a token signed under the previous key still verifies until that key is retired', function (): void {
    // Issue under what will become the previous key.
    config(['onsale.admission_token.current_key_id' => 'k1', 'onsale.admission_token.current_key_secret' => 'previous-secret']);

    $now = now();
    $token = AdmissionToken::issue($this->entrantId, $this->eventId, $this->tenantId, $now->copy()->addMinutes(5));

    // Rotate: k2 is now current, k1 demoted to previous.
    config([
        'onsale.admission_token.current_key_id' => 'k2',
        'onsale.admission_token.current_key_secret' => 'current-secret',
        'onsale.admission_token.previous_key_id' => 'k1',
        'onsale.admission_token.previous_key_secret' => 'previous-secret',
    ]);

    expect(AdmissionToken::verify($token, $this->eventId, $this->tenantId, $now))->toBe($this->entrantId);

    // Retire k1 entirely.
    config(['onsale.admission_token.previous_key_id' => null, 'onsale.admission_token.previous_key_secret' => null]);

    expect(AdmissionToken::verify($token, $this->eventId, $this->tenantId, $now))->toBeNull();
});

test('a token signed under the current key is rejected if the key id is later reused for a different secret', function (): void {
    $now = now();
    $token = AdmissionToken::issue($this->entrantId, $this->eventId, $this->tenantId, $now->copy()->addMinutes(5));

    config(['onsale.admission_token.current_key_secret' => 'a-different-secret']);

    expect(AdmissionToken::verify($token, $this->eventId, $this->tenantId, $now))->toBeNull();
});

test('an unknown key id is rejected without needing a well-formed payload', function (): void {
    $token = 'unknown-key-id.'.base64_encode('not even json');

    expect(AdmissionToken::verify($token, $this->eventId, $this->tenantId, now()))->toBeNull();
});

test('a malformed token is rejected', function (): void {
    expect(AdmissionToken::verify('not-a-valid-token-at-all', $this->eventId, $this->tenantId, now()))->toBeNull();
});

test('a token for the wrong event is rejected', function (): void {
    $now = now();
    $token = AdmissionToken::issue($this->entrantId, $this->eventId, $this->tenantId, $now->copy()->addMinutes(5));

    expect(AdmissionToken::verify($token, Str::uuid7()->toString(), $this->tenantId, $now))->toBeNull();
});

test('a token for the wrong tenant is rejected', function (): void {
    $now = now();
    $token = AdmissionToken::issue($this->entrantId, $this->eventId, $this->tenantId, $now->copy()->addMinutes(5));

    expect(AdmissionToken::verify($token, $this->eventId, Str::uuid7()->toString(), $now))->toBeNull();
});

test('a tampered payload is rejected', function (): void {
    $now = now();
    $token = AdmissionToken::issue($this->entrantId, $this->eventId, $this->tenantId, $now->copy()->addMinutes(5));

    [$keyId, $encoded] = explode('.', $token, 2);
    $tampered = $keyId.'.'.strrev($encoded);

    expect(AdmissionToken::verify($tampered, $this->eventId, $this->tenantId, $now))->toBeNull();
});

test('expiry is checked against the injected clock, the exact expiry instant counts as expired', function (): void {
    $now = now();
    $token = AdmissionToken::issue($this->entrantId, $this->eventId, $this->tenantId, $now->copy()->addMinutes(5));

    expect(AdmissionToken::verify($token, $this->eventId, $this->tenantId, $now->copy()->addMinutes(4)->addSeconds(59)))->toBe($this->entrantId)
        ->and(AdmissionToken::verify($token, $this->eventId, $this->tenantId, $now->copy()->addMinutes(5)))->toBeNull()
        ->and(AdmissionToken::verify($token, $this->eventId, $this->tenantId, $now->copy()->addMinutes(6)))->toBeNull();
});
