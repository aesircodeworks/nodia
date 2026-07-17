<?php

declare(strict_types=1);

use App\Identity\Actions\RegisterCustomer;
use App\Identity\Data\RegisterCustomerData;
use App\Identity\Exceptions\CustomerEmailTakenException;
use App\Identity\Models\Customer;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\LaravelData\Optional;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-03 plan, task breakdown item 13: "Unit tests for RegisterCustomer
 * ... plus locale fallback to tenant default." RegisterCustomer is driven
 * through TenantTransaction::asTenant() exactly the way
 * ResolveTenantFromHost runs it in production, mirroring
 * tests/Unit/Identity/InviteUserActionTest.php's own precedent, rather
 * than through HTTP (tests/Feature/Identity/CustomerRegistrationTest.php
 * already proves the endpoint end to end). The whole storefront request
 * already runs inside one transaction that this Action needs none of its
 * own (its own docblock); nothing here re-proves that atomicity beyond
 * what CreateRole and InviteUser's own unit suites already established
 * for the identical pattern.
 */

const RC_TENANT = '019797f6-0000-7000-8000-0000000000c1';

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::factory()->create(['id' => RC_TENANT, 'default_locale' => 'pt-BR', 'supported_locales' => ['pt-BR', 'en']]);
    });
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant(RC_TENANT, function (): void {
        DB::table('outbox_deliveries')->where('tenant_id', RC_TENANT)->delete();
        DB::table('outbox_events')->where('tenant_id', RC_TENANT)->delete();
        DB::table('customers')->where('tenant_id', RC_TENANT)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });
});

it('creates a guest with a null password when password is omitted', function (): void {
    $customer = app(TenantTransaction::class)->asTenant(
        RC_TENANT,
        fn () => app(RegisterCustomer::class)(new RegisterCustomerData('guest@example.com', 'Guest', new Optional, new Optional)),
    );

    expect($customer->isClaimed)->toBeFalse();

    $row = app(TenantTransaction::class)->asTenant(RC_TENANT, fn () => Customer::query()->findOrFail($customer->id));

    expect($row->password)->toBeNull();
});

it('creates a registered customer with a hashed password when password is present', function (): void {
    $customer = app(TenantTransaction::class)->asTenant(
        RC_TENANT,
        fn () => app(RegisterCustomer::class)(new RegisterCustomerData('registered@example.com', 'Registered', 'a-real-password', new Optional)),
    );

    expect($customer->isClaimed)->toBeTrue();

    $row = app(TenantTransaction::class)->asTenant(RC_TENANT, fn () => Customer::query()->findOrFail($customer->id));

    expect($row->password)->not->toBeNull()
        ->and(Hash::check('a-real-password', $row->password))->toBeTrue();
});

it('falls back to the tenant default locale when locale is omitted', function (): void {
    $customer = app(TenantTransaction::class)->asTenant(
        RC_TENANT,
        fn () => app(RegisterCustomer::class)(new RegisterCustomerData('locale-fallback@example.com', 'Fallback', new Optional, new Optional)),
    );

    expect($customer->locale)->toBe('pt-BR');
});

it('falls back to the tenant default locale when locale is explicitly null', function (): void {
    $customer = app(TenantTransaction::class)->asTenant(
        RC_TENANT,
        fn () => app(RegisterCustomer::class)(new RegisterCustomerData('locale-null@example.com', 'Null Locale', new Optional, null)),
    );

    expect($customer->locale)->toBe('pt-BR');
});

it('honors an explicit locale over the tenant default', function (): void {
    $customer = app(TenantTransaction::class)->asTenant(
        RC_TENANT,
        fn () => app(RegisterCustomer::class)(new RegisterCustomerData('locale-explicit@example.com', 'Explicit Locale', new Optional, 'en')),
    );

    expect($customer->locale)->toBe('en');
});

it('throws customer_email_taken for a duplicate email in the same tenant', function (): void {
    app(TenantTransaction::class)->asTenant(
        RC_TENANT,
        fn () => app(RegisterCustomer::class)(new RegisterCustomerData('dupe@example.com', 'First', new Optional, new Optional)),
    );

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        RC_TENANT,
        fn () => app(RegisterCustomer::class)(new RegisterCustomerData('dupe@example.com', 'Second', new Optional, new Optional)),
    );

    expect($invoke)->toThrow(CustomerEmailTakenException::class);
});
