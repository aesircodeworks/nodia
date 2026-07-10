<?php

declare(strict_types=1);

use App\Identity\Actions\ClaimGuestAccount;
use App\Identity\Data\ConfirmClaimData;
use App\Identity\Exceptions\ClaimTokenInvalidException;
use App\Identity\Exceptions\CustomerAlreadyClaimedException;
use App\Identity\Models\Customer;
use App\Identity\Support\ClaimToken;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-03 plan, task breakdown item 13: "Unit tests for ...
 * ClaimGuestAccount." Driven through TenantTransaction::asTenant(),
 * mirroring tests/Unit/Identity/RegisterCustomerTest.php's own precedent,
 * rather than through HTTP
 * (tests/Feature/Identity/CustomerClaimTest.php already proves the
 * endpoint end to end).
 */

const CGA_TENANT = '019797f7-0000-7000-8000-0000000000c1';

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::factory()->create(['id' => CGA_TENANT]);
    });
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant(CGA_TENANT, function (): void {
        DB::table('customers')->where('tenant_id', CGA_TENANT)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });
});

function claimableGuest(array $attributes = []): Customer
{
    return app(TenantTransaction::class)->asTenant(
        CGA_TENANT,
        fn () => Customer::factory()->create(['tenant_id' => CGA_TENANT, 'password' => null, ...$attributes]),
    );
}

it('sets the password for a matching unclaimed guest', function (): void {
    $guest = claimableGuest();
    $token = ClaimToken::issue($guest->id);

    app(TenantTransaction::class)->asTenant(
        CGA_TENANT,
        fn () => app(ClaimGuestAccount::class)(new ConfirmClaimData($token, 'a-new-password')),
    );

    $row = app(TenantTransaction::class)->asTenant(CGA_TENANT, fn () => Customer::query()->findOrFail($guest->id));

    expect($row->password)->not->toBeNull()
        ->and(Hash::check('a-new-password', $row->password))->toBeTrue();
});

it('throws customer_already_claimed for a customer who already holds a password', function (): void {
    $claimed = app(TenantTransaction::class)->asTenant(
        CGA_TENANT,
        fn () => Customer::factory()->create(['tenant_id' => CGA_TENANT, 'password' => 'existing-password']),
    );
    $token = ClaimToken::issue($claimed->id);

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        CGA_TENANT,
        fn () => app(ClaimGuestAccount::class)(new ConfirmClaimData($token, 'a-new-password')),
    );

    expect($invoke)->toThrow(CustomerAlreadyClaimedException::class);
});

it('throws claim_token_invalid for a customer id that does not resolve under the asserted tenant', function (): void {
    $token = ClaimToken::issue((string) Str::uuid7());

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        CGA_TENANT,
        fn () => app(ClaimGuestAccount::class)(new ConfirmClaimData($token, 'a-new-password')),
    );

    expect($invoke)->toThrow(ClaimTokenInvalidException::class);
});
