<?php

use App\Orders\Actions\ApplyPromoCode;
use App\Orders\Actions\EvaluatePromoCode;
use App\Orders\Exceptions\PromoCodeCurrencyMismatchException;
use App\Orders\Exceptions\PromoCodeExhaustedException;
use App\Orders\Exceptions\PromoCodeInvalidException;
use App\Orders\Exceptions\PromoCodeNotActiveException;
use App\Orders\Models\PromoCode;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-07 plan, TDD sequencing Slice 5, unit layer: discount math
 * (basis points floor, fixed-amount cap at subtotal, currency
 * mismatch), window evaluation against the fake clock, ApplyPromoCode
 * increments through a conditional UPDATE only.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('promo_codes')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );
});

/**
 * @param  array<string, mixed>  $attributes
 */
function promoCode(string $tenantId, array $attributes = []): PromoCode
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => PromoCode::factory()->create(['tenant_id' => $tenantId, ...$attributes]),
    );
}

it('floors percentage discounts computed in basis points', function (): void {
    promoCode($this->tenantId, ['code' => 'BP', 'discount_type' => 'percentage', 'discount_value' => 1250, 'currency' => null]);

    $applied = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(ApplyPromoCode::class)('BP', Money::of(999, 'USD')),
    );

    // floor(999 * 1250 / 10000) = floor(124.875) = 124
    expect($applied->discount->amount)->toBe(124)
        ->and($applied->discount->currency)->toBe('USD');
});

it('caps a fixed-amount discount at the subtotal so totals never go negative', function (): void {
    promoCode($this->tenantId, ['code' => 'BIG', 'discount_type' => 'fixed_amount', 'discount_value' => 5000, 'currency' => 'USD']);

    $applied = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(ApplyPromoCode::class)('BIG', Money::of(3000, 'USD')),
    );

    expect($applied->discount->amount)->toBe(3000);
});

it('rejects a fixed-amount code whose currency differs from the order currency', function (): void {
    promoCode($this->tenantId, ['code' => 'EUROS', 'discount_type' => 'fixed_amount', 'discount_value' => 500, 'currency' => 'EUR']);

    expect(fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(ApplyPromoCode::class)('EUROS', Money::of(3000, 'USD')),
    ))->toThrow(PromoCodeCurrencyMismatchException::class);
});

it('rejects an unknown code for this tenant', function (): void {
    expect(fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(ApplyPromoCode::class)('NOPE', Money::of(3000, 'USD')),
    ))->toThrow(PromoCodeInvalidException::class);
});

it('evaluates validity windows against the clock', function (): void {
    $now = now();
    $this->travelTo($now);

    promoCode($this->tenantId, [
        'code' => 'WINDOW',
        'discount_type' => 'percentage',
        'discount_value' => 1000,
        'currency' => null,
        'valid_from' => $now->copy()->addDay(),
        'valid_to' => $now->copy()->addDays(2),
    ]);

    $notYet = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(ApplyPromoCode::class)('WINDOW', Money::of(1000, 'USD')),
    );

    expect($notYet)->toThrow(PromoCodeNotActiveException::class);

    $this->travelTo($now->copy()->addDay()->addHour());

    $applied = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(ApplyPromoCode::class)('WINDOW', Money::of(1000, 'USD')),
    );

    expect($applied->discount->amount)->toBe(100);

    $this->travelTo($now->copy()->addDays(3));

    expect($notYet)->toThrow(PromoCodeNotActiveException::class);
});

it('increments usage through a conditional update and stops at the limit', function (): void {
    $promo = promoCode($this->tenantId, [
        'code' => 'LIMITED',
        'discount_type' => 'percentage',
        'discount_value' => 1000,
        'currency' => null,
        'usage_limit' => 1,
        'usage_count' => 0,
    ]);

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(ApplyPromoCode::class)('LIMITED', Money::of(1000, 'USD')),
    );

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => PromoCode::query()->findOrFail($promo->id)->usage_count,
    );

    expect($count)->toBe(1);

    expect(fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(ApplyPromoCode::class)('LIMITED', Money::of(1000, 'USD')),
    ))->toThrow(PromoCodeExhaustedException::class);
});

it('previews without incrementing usage', function (): void {
    $promo = promoCode($this->tenantId, [
        'code' => 'PREVIEW',
        'discount_type' => 'percentage',
        'discount_value' => 1000,
        'currency' => null,
        'usage_limit' => 5,
        'usage_count' => 0,
    ]);

    $evaluation = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(EvaluatePromoCode::class)('PREVIEW', Money::of(1000, 'USD')),
    );

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => PromoCode::query()->findOrFail($promo->id)->usage_count,
    );

    expect($evaluation->valid)->toBeTrue()
        ->and($evaluation->discount->amount)->toBe(100)
        ->and($count)->toBe(0);
});
