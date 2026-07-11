<?php

use App\Identity\Capability;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\TenantStaff;

/*
 * Stage-07 plan, task breakdown item 12: promo code admin CRUD with
 * capability gates, (tenant_id, code) uniqueness, and immutability
 * after first use.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $this->headers = [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::PromoCodesManage),
        'X-Tenant-Id' => $this->tenantId,
    ];
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('memberships')->where('tenant_id', $this->tenantId)->delete();
        DB::table('roles')->where('tenant_id', $this->tenantId)->delete();
        DB::table('promo_codes')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );

    User::query()->delete();
});

/**
 * @return array<string, mixed>
 */
function promoPayload(array $overrides = []): array
{
    return [
        'code' => 'SPRING10',
        'discount_type' => 'percentage',
        'discount_value' => 1000,
        'currency' => null,
        'usage_limit' => 100,
        'valid_from' => null,
        'valid_to' => null,
        ...$overrides,
    ];
}

it('creates, shows, lists, and patches a promo code', function (): void {
    $create = $this->postJson('/v1/promo-codes', promoPayload(), $this->headers);

    $create->assertStatus(201)->assertConformsToOpenApi();
    $create->assertJsonPath('code', 'SPRING10')
        ->assertJsonPath('discount_type', 'percentage')
        ->assertJsonPath('discount_value', 1000)
        ->assertJsonPath('usage_count', 0);

    $id = $create->json('id');

    $show = $this->getJson('/v1/promo-codes/'.$id, $this->headers);
    $show->assertStatus(200)->assertConformsToOpenApi();
    $show->assertJsonPath('usage_count', 0);

    $list = $this->getJson('/v1/promo-codes?filter[code]=SPRING10', $this->headers);
    $list->assertStatus(200)->assertConformsToOpenApi();
    expect($list->json('data'))->toHaveCount(1);

    $patch = $this->patchJson('/v1/promo-codes/'.$id, [
        'valid_to' => now()->addMonth()->toIso8601String(),
    ], $this->headers);

    $patch->assertStatus(200)->assertConformsToOpenApi();
    expect($patch->json('valid_to'))->not->toBeNull();
});

it('rejects a duplicate code in the same tenant', function (): void {
    $this->postJson('/v1/promo-codes', promoPayload(), $this->headers)->assertStatus(201);

    $duplicate = $this->postJson('/v1/promo-codes', promoPayload(), $this->headers);

    $duplicate->assertStatus(422);
    $duplicate->assertJsonPath('code', 'request.validation_failed');
});

it('locks code, discount_type, discount_value, and currency once used', function (): void {
    $id = $this->postJson('/v1/promo-codes', promoPayload(), $this->headers)->json('id');

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($id): void {
        DB::table('promo_codes')->where('id', $id)->update(['usage_count' => 1]);
    });

    foreach ([
        ['code' => 'RENAMED'],
        ['discount_type' => 'fixed_amount', 'currency' => 'USD'],
        ['discount_value' => 2000],
        ['currency' => 'EUR'],
    ] as $payload) {
        $response = $this->patchJson('/v1/promo-codes/'.$id, $payload, $this->headers);

        $response->assertStatus(422)->assertConformsToOpenApi();
        $response->assertJsonPath('code', 'promo_code_immutable_field');
    }

    // Windows stay editable: deactivation is setting valid_to.
    $this->patchJson('/v1/promo-codes/'.$id, [
        'valid_to' => now()->subMinute()->toIso8601String(),
    ], $this->headers)->assertStatus(200);
});

it('still accepts locked fields when unchanged', function (): void {
    $id = $this->postJson('/v1/promo-codes', promoPayload(), $this->headers)->json('id');

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($id): void {
        DB::table('promo_codes')->where('id', $id)->update(['usage_count' => 1]);
    });

    $this->patchJson('/v1/promo-codes/'.$id, [
        'code' => 'SPRING10',
        'discount_value' => 1000,
    ], $this->headers)->assertStatus(200);
});

it('requires the promo_codes.manage capability', function (): void {
    $headers = [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::OrdersView),
        'X-Tenant-Id' => $this->tenantId,
    ];

    $this->getJson('/v1/promo-codes', $headers)->assertStatus(403);
    $this->postJson('/v1/promo-codes', promoPayload(), $headers)->assertStatus(403);
});

it('records an activity log entry for mutations', function (): void {
    $this->postJson('/v1/promo-codes', promoPayload(), $this->headers)->assertStatus(201);

    $entries = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DB::table('activity_log')->where('tenant_id', $this->tenantId)->count(),
    );

    expect($entries)->toBeGreaterThan(0);
});

it('renders request.not_found for an unknown id, mirroring roles and ticket types', function (): void {
    $response = $this->getJson('/v1/promo-codes/'.Str::uuid7(), $this->headers);

    $response->assertStatus(404);
    $response->assertJsonPath('code', 'request.not_found');
});

it('clears the currency when an unused fixed code becomes percentage', function (): void {
    $id = $this->postJson('/v1/promo-codes', promoPayload([
        'code' => 'FLIP',
        'discount_type' => 'fixed_amount',
        'discount_value' => 500,
        'currency' => 'USD',
    ]), $this->headers)->assertStatus(201)->json('id');

    $response = $this->patchJson('/v1/promo-codes/'.$id, [
        'discount_type' => 'percentage',
    ], $this->headers);

    $response->assertStatus(200);
    $response->assertJsonPath('discount_type', 'percentage')
        ->assertJsonPath('currency', null);
});

it('requires a currency when an unused percentage code becomes fixed_amount', function (): void {
    $id = $this->postJson('/v1/promo-codes', promoPayload(['code' => 'FLIP2']), $this->headers)
        ->assertStatus(201)
        ->json('id');

    $this->patchJson('/v1/promo-codes/'.$id, [
        'discount_type' => 'fixed_amount',
    ], $this->headers)->assertStatus(422)->assertJsonPath('code', 'request.validation_failed');

    $this->patchJson('/v1/promo-codes/'.$id, [
        'discount_type' => 'fixed_amount',
        'currency' => 'USD',
    ], $this->headers)->assertStatus(200)->assertJsonPath('currency', 'USD');
});

it('validates the currency-by-type invariant on create', function (): void {
    $this->postJson('/v1/promo-codes', promoPayload([
        'discount_type' => 'fixed_amount',
        'currency' => null,
    ]), $this->headers)->assertStatus(422)->assertJsonPath('code', 'request.validation_failed');

    $this->postJson('/v1/promo-codes', promoPayload([
        'code' => 'FIXED5',
        'discount_type' => 'fixed_amount',
        'discount_value' => 500,
        'currency' => 'USD',
    ]), $this->headers)->assertStatus(201);
});
