<?php

use App\Identity\Support\TotpVerifier;
use Carbon\CarbonImmutable;
use PragmaRX\Google2FA\Google2FA;

/*
 * Stage-03 plan, Slice 5 / task breakdown item 11: "unit tests for the
 * TOTP verification window under the fake clock". Google2FA's own
 * getCurrentOtp()/getTimestamp() read PHP's uncontrollable wall clock
 * (microtime(true)), so every code here is computed directly from a
 * chosen counter via oathTotp() instead, matching exactly how
 * TotpVerifier::verify() derives its counter from Date::now() (the
 * framework clock freezeTime()/travelTo() control).
 */

function codeAt(string $secret, CarbonImmutable $instant, int $period = 30): string
{
    $engine = new Google2FA;

    return $engine->oathTotp($secret, intdiv($instant->getTimestamp(), $period));
}

it('verifies a code generated for the exact current period', function (): void {
    $instant = CarbonImmutable::parse('2026-07-09T12:00:00Z');
    $this->travelTo($instant);

    $verifier = app(TotpVerifier::class);
    $secret = $verifier->generateSecret();

    expect($verifier->verify($secret, codeAt($secret, $instant)))->toBeTrue();
});

it('still verifies a code from one period in the past, within the default window', function (): void {
    $instant = CarbonImmutable::parse('2026-07-09T12:00:00Z');
    $secret = app(TotpVerifier::class)->generateSecret();
    $pastCode = codeAt($secret, $instant->subSeconds(30));

    $this->travelTo($instant);

    expect(app(TotpVerifier::class)->verify($secret, $pastCode))->toBeTrue();
});

it('still verifies a code from one period in the future, within the default window', function (): void {
    $instant = CarbonImmutable::parse('2026-07-09T12:00:00Z');
    $secret = app(TotpVerifier::class)->generateSecret();
    $futureCode = codeAt($secret, $instant->addSeconds(30));

    $this->travelTo($instant);

    expect(app(TotpVerifier::class)->verify($secret, $futureCode))->toBeTrue();
});

it('rejects a code well outside the verification window', function (): void {
    $instant = CarbonImmutable::parse('2026-07-09T12:00:00Z');
    $secret = app(TotpVerifier::class)->generateSecret();
    $farCode = codeAt($secret, $instant->addMinutes(10));

    $this->travelTo($instant);

    expect(app(TotpVerifier::class)->verify($secret, $farCode))->toBeFalse();
});

it('rejects a code that never matches any period for this secret', function (): void {
    $instant = CarbonImmutable::parse('2026-07-09T12:00:00Z');
    $this->travelTo($instant);

    $verifier = app(TotpVerifier::class);
    $secret = $verifier->generateSecret();

    expect($verifier->verify($secret, '000000'))->toBeFalse();
});
