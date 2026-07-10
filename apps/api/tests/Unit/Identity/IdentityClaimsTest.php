<?php

use App\Identity\OAuth\IdentityClaims;

it('builds exactly the staff claim set for the users provider', function (): void {
    expect(IdentityClaims::for('users'))->toBe(['identity_type' => 'staff']);
});

it('builds exactly the customer claim set for the customers provider', function (): void {
    expect(IdentityClaims::for('customers', 'a-tenant-id'))->toBe([
        'identity_type' => 'customer',
        'tenant_id' => 'a-tenant-id',
    ]);
});

it('never attaches a tenant claim for staff', function (): void {
    expect(IdentityClaims::for('users'))->not->toHaveKey('tenant_id');
});

it('rejects an unknown provider rather than silently building an empty claim set', function (): void {
    IdentityClaims::for('something-else');
})->throws(RuntimeException::class, 'Unknown OAuth provider [something-else] for identity claims.');

it('requires a tenant id for customer claims rather than silently omitting it', function (): void {
    IdentityClaims::for('customers');
})->throws(RuntimeException::class, 'Customer identity claims require a tenant id.');
