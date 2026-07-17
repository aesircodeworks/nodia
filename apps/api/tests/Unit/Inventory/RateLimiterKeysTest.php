<?php

use App\Identity\Models\Customer;
use App\Inventory\Support\RateLimiterKeys;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/*
 * Stage-10 plan, TDD sequencing Slice 2 (Unit, first): key derivation
 * for the `hold_creation` named rate limiter, isolated from the HTTP
 * kernel and InventoryServiceProvider's own registration closures.
 */

test('ip returns the request client ip', function () {
    $request = Request::create('/v1/storefront/holds', 'POST', server: ['REMOTE_ADDR' => '203.0.113.5']);

    expect(RateLimiterKeys::ip($request))->toBe('203.0.113.5');
});

test('customer returns null when no customer is authenticated', function () {
    $request = Request::create('/v1/storefront/holds', 'POST');
    $request->setUserResolver(fn (?string $guard = null) => null);

    expect(RateLimiterKeys::customer($request))->toBeNull();
});

test('customer returns null when a different guard resolves a user', function () {
    $staff = new class
    {
        public function getAuthIdentifier(): string
        {
            return (string) Str::uuid7();
        }
    };

    $request = Request::create('/v1/storefront/holds', 'POST');
    $request->setUserResolver(fn (?string $guard = null) => $guard === 'staff' ? $staff : null);

    expect(RateLimiterKeys::customer($request))->toBeNull();
});

test('customer returns the authenticated customer id when the customer guard resolves one', function () {
    $customer = new Customer(['tenant_id' => (string) Str::uuid7()]);
    $customer->id = (string) Str::uuid7();

    $request = Request::create('/v1/storefront/holds', 'POST');
    $request->setUserResolver(fn (?string $guard = null) => $guard === 'customer' ? $customer : null);

    expect(RateLimiterKeys::customer($request))->toBe($customer->id);
});
