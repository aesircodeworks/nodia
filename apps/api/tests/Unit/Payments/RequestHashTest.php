<?php

use App\Payments\Support\RequestHash;

/*
 * Stage-08a plan, Slice 4: the canonicalized initiation payload hash
 * that detects Idempotency-Key reuse with a different request.
 */

it('is insensitive to key order at any depth', function (): void {
    $a = RequestHash::compute('card', ['token' => 'tok_approve', 'meta' => ['b' => 1, 'a' => 2]]);
    $b = RequestHash::compute('card', ['meta' => ['a' => 2, 'b' => 1], 'token' => 'tok_approve']);

    expect($a)->toBe($b);
});

it('differs when the method differs', function (): void {
    expect(RequestHash::compute('card', ['token' => 'tok_approve']))
        ->not->toBe(RequestHash::compute('pix', ['token' => 'tok_approve']));
});

it('differs when any detail differs', function (): void {
    expect(RequestHash::compute('card', ['token' => 'tok_approve']))
        ->not->toBe(RequestHash::compute('card', ['token' => 'tok_decline']));
});

it('keeps list order significant', function (): void {
    expect(RequestHash::compute('card', ['split' => [1, 2]]))
        ->not->toBe(RequestHash::compute('card', ['split' => [2, 1]]));
});
