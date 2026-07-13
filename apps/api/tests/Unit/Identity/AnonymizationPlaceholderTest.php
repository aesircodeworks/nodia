<?php

use App\Identity\Support\AnonymizationPlaceholder;
use Illuminate\Support\Str;

/*
 * Stage-12 plan, Slice 1 Unit tests: "AnonymizeCustomer placeholder
 * derivation is deterministic, non-reversible, and per-tenant unique
 * against the email constraint."
 */

it('derives the same placeholder twice for the same customer id', function () {
    $customerId = (string) Str::uuid7();

    $first = AnonymizationPlaceholder::forCustomer($customerId);
    $second = AnonymizationPlaceholder::forCustomer($customerId);

    expect($first->name)->toBe($second->name)
        ->and($first->email)->toBe($second->email);
});

it('derives a different placeholder for a different customer id, which is what makes it per-tenant unique', function () {
    $first = AnonymizationPlaceholder::forCustomer((string) Str::uuid7());
    $second = AnonymizationPlaceholder::forCustomer((string) Str::uuid7());

    expect($first->email)->not->toBe($second->email)
        ->and($first->name)->not->toBe($second->name);
});

it('does not embed the customer id anywhere in the placeholder, so it cannot be recovered from it', function () {
    $customerId = (string) Str::uuid7();

    $placeholder = AnonymizationPlaceholder::forCustomer($customerId);

    expect($placeholder->email)->not->toContain($customerId)
        ->and($placeholder->name)->not->toContain($customerId);

    foreach (explode('-', $customerId) as $segment) {
        expect($placeholder->email)->not->toContain($segment);
    }
});

it('changes when the application key changes, proving the derivation is keyed and not recoverable without the secret', function () {
    $customerId = (string) Str::uuid7();

    $before = AnonymizationPlaceholder::forCustomer($customerId);

    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    $after = AnonymizationPlaceholder::forCustomer($customerId);

    expect($before->email)->not->toBe($after->email);
});

it('produces an email-shaped, non-routable placeholder address', function () {
    $placeholder = AnonymizationPlaceholder::forCustomer((string) Str::uuid7());

    expect($placeholder->email)->toMatch('/^erased\+[0-9a-f]{32}@erased\.invalid$/');
});
