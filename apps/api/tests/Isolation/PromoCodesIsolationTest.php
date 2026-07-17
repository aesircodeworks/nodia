<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\PromoCodeFixture;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * promo_codes is the standard Rls::applyTenantPolicies posture with no
 * extra policies (stage-07 plan, Data model "promo_codes"). Written
 * first per the master plan's TDD sequencing (stage-07 plan, task
 * breakdown item 11).
 */

/**
 * @return array<string, mixed>
 */
function validPromoCodeRow(array $overrides = []): array
{
    return [
        'id' => Str::uuid7()->toString(),
        'tenant_id' => TenantFixture::TENANT_A,
        'code' => 'ROWCHECK',
        'discount_type' => 'percentage',
        'discount_value' => 1000,
        'currency' => null,
        'usage_limit' => null,
        'usage_count' => 0,
        'valid_from' => null,
        'valid_to' => null,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

beforeEach(fn () => PromoCodeFixture::seed());

afterEach(fn () => PromoCodeFixture::clean());

it('shows a tenant only its own promo codes', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('promo_codes')->pluck('id'),
    );

    expect($ids->all())->toBe([PromoCodeFixture::PROMO_A]);
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('promo_codes')->where('id', PromoCodeFixture::PROMO_B)->update(['usage_count' => 99]),
    );

    expect($affected)->toBe(0);
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('promo_codes')->where('id', PromoCodeFixture::PROMO_B)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('promo_codes')->where('id', PromoCodeFixture::PROMO_B)->exists(),
    );

    expect($exists)->toBeTrue();
});

it('rejects an insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('promo_codes')->insert(validPromoCodeRow(['tenant_id' => TenantFixture::TENANT_B])),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('scopes the same code string independently per tenant', function () {
    $codeA = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('promo_codes')->where('code', PromoCodeFixture::SHARED_CODE)->value('id'),
    );

    $codeB = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('promo_codes')->where('code', PromoCodeFixture::SHARED_CODE)->value('id'),
    );

    expect($codeA)->toBe(PromoCodeFixture::PROMO_A)
        ->and($codeB)->toBe(PromoCodeFixture::PROMO_B);
});

it('lets nodia_platform read every tenant\'s promo codes without any tenant context', function () {
    $ids = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('promo_codes')->pluck('id'));

    expect($ids->all())->toContain(PromoCodeFixture::PROMO_A, PromoCodeFixture::PROMO_B);
});

it('rejects a nodia_platform write with no tenant asserted, promo_codes has no platform write policy', function () {
    $affected = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('promo_codes')->where('id', PromoCodeFixture::PROMO_A)->update(['usage_count' => 99]),
    );

    expect($affected)->toBe(0);
});
