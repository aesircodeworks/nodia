<?php

use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\Route;
use Tests\Isolation\Support\TenantFixture;

/**
 * The storefront resolution middleware puts the whole request under the
 * nodia_app posture for the tenant its Host resolved to, so tenant-scoped
 * queries behind the storefront group are RLS-isolated end to end: tenant
 * A's host can never observe tenant B's rows (stage-02 plan, Slice 6).
 */
beforeEach(function (): void {
    TenantFixture::seed();

    Route::middleware('tenancy.storefront')->prefix('v1')->get(
        '/__probe/storefront-domains',
        fn () => response()->json(['domains' => TenantDomain::query()->orderBy('domain')->pluck('domain')]),
    );
});

afterEach(function (): void {
    TenantFixture::clean();
});

it('shows tenant A\'s host only tenant A\'s domains', function () {
    $this->getJson('http://'.TenantFixture::DOMAIN_A.'/v1/__probe/storefront-domains')
        ->assertOk()
        ->assertExactJson(['domains' => [TenantFixture::DOMAIN_A]]);
});

it('shows tenant B\'s host only tenant B\'s domains', function () {
    $this->getJson('http://'.TenantFixture::DOMAIN_B.'/v1/__probe/storefront-domains')
        ->assertOk()
        ->assertExactJson(['domains' => [TenantFixture::DOMAIN_B]]);
});
