<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Enums\HoldStatus;
use App\Orders\Models\PromoCode;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-07 plan, TDD sequencing Slice 5, feature layer: order creation
 * with a promo code prices discount_amount correctly and links
 * promo_code_id; invalid codes roll the whole conversion back; the
 * check endpoint previews without incrementing.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ($tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
            DB::table('tickets')->where('tenant_id', $tenantId)->delete();
            DB::table('order_items')->where('tenant_id', $tenantId)->delete();
            DB::table('orders')->where('tenant_id', $tenantId)->delete();
            DB::table('promo_codes')->where('tenant_id', $tenantId)->delete();
            DB::table('hold_items')->where('tenant_id', $tenantId)->delete();
            DB::table('holds')->where('tenant_id', $tenantId)->delete();
            DB::table('customers')->where('tenant_id', $tenantId)->delete();
            DB::table('ticket_type_inventory')->where('tenant_id', $tenantId)->delete();
            DB::table('ticket_types')->where('tenant_id', $tenantId)->delete();
            DB::table('events')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

/**
 * A tenant with one 10000-minor-unit ticket type, a customer bearer,
 * and a fresh two-ticket hold (subtotal 20000).
 *
 * @return array{host: string, tenantId: string, token: string, holdId: string}
 */
function promoCheckoutFixture(): array
{
    ['tenant' => $tenant, 'host' => $host] = app(TenantTransaction::class)->asPlatform(function (): array {
        $tenant = Tenant::factory()->create();
        $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

        return ['tenant' => $tenant, 'host' => $domain->domain];
    });

    $holdId = app(TenantTransaction::class)->asTenant($tenant->id, function () use ($tenant): string {
        $event = Event::factory()->create(['tenant_id' => $tenant->id, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create([
            'tenant_id' => $tenant->id,
            'event_id' => $event->id,
            'price' => Money::of(10000, 'USD'),
        ]);
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'promo-buyer@example.com',
            'password' => 'password',
        ]);

        DB::table('ticket_type_inventory')->insert([
            'id' => Illuminate\Support\Str::uuid7()->toString(),
            'tenant_id' => $tenant->id,
            'ticket_type_id' => $ticketType->id,
            'quantity' => 10,
            'held' => 0,
            'sold' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return app(CreateHold::class)(
            CreateHoldData::from([
                'event_id' => $event->id,
                'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
            ]),
            $customer->id,
        )->id;
    });

    $token = test()->postJson('http://'.$host.'/v1/auth/customer/token', [
        'email' => 'promo-buyer@example.com',
        'password' => 'password',
    ])->json('access_token');

    return ['host' => $host, 'tenantId' => $tenant->id, 'token' => $token, 'holdId' => $holdId];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function checkoutPromo(string $tenantId, array $attributes = []): PromoCode
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => PromoCode::factory()->create(['tenant_id' => $tenantId, ...$attributes]),
    );
}

describe('POST /v1/storefront/orders with promo_code', function (): void {
    it('prices the discount and links promo_code_id', function (): void {
        $fixture = promoCheckoutFixture();
        $promo = checkoutPromo($fixture['tenantId'], [
            'code' => 'TENOFF',
            'discount_type' => 'percentage',
            'discount_value' => 1000,
            'currency' => null,
        ]);

        $response = $this->postJson('http://'.$fixture['host'].'/v1/storefront/orders', [
            'hold_id' => $fixture['holdId'],
            'promo_code' => 'TENOFF',
        ], ['Authorization' => 'Bearer '.$fixture['token']]);

        $response->assertStatus(201)->assertConformsToOpenApi();
        $response->assertJsonPath('subtotal.amount', 20000)
            ->assertJsonPath('discount.amount', 2000)
            ->assertJsonPath('total.amount', 18000)
            ->assertJsonPath('promo_code', 'TENOFF');

        $order = app(TenantTransaction::class)->asTenant(
            $fixture['tenantId'],
            fn () => DB::table('orders')->where('hold_id', $fixture['holdId'])->first(),
        );

        expect($order->promo_code_id)->toBe($promo->id);
    });

    it('rolls the whole conversion back for an inactive code', function (): void {
        $fixture = promoCheckoutFixture();
        checkoutPromo($fixture['tenantId'], [
            'code' => 'EXPIRED',
            'discount_type' => 'percentage',
            'discount_value' => 1000,
            'currency' => null,
            'valid_to' => now()->subDay(),
        ]);

        $response = $this->postJson('http://'.$fixture['host'].'/v1/storefront/orders', [
            'hold_id' => $fixture['holdId'],
            'promo_code' => 'EXPIRED',
        ], ['Authorization' => 'Bearer '.$fixture['token']]);

        $response->assertStatus(422)->assertConformsToOpenApi();
        $response->assertJsonPath('code', 'promo_code_not_active');

        $state = app(TenantTransaction::class)->asTenant($fixture['tenantId'], fn (): array => [
            'orders' => DB::table('orders')->count(),
            'holdStatus' => DB::table('holds')->where('id', $fixture['holdId'])->value('status'),
            'holdCustomer' => DB::table('holds')->where('id', $fixture['holdId'])->value('customer_id'),
        ]);

        expect($state['orders'])->toBe(0)
            ->and($state['holdStatus'])->toBe(HoldStatus::Active->value);
    });

    it('maps unknown, exhausted, and mismatched-currency codes to their problem codes', function (): void {
        $fixture = promoCheckoutFixture();
        checkoutPromo($fixture['tenantId'], [
            'code' => 'GONE',
            'discount_type' => 'percentage',
            'discount_value' => 1000,
            'currency' => null,
            'usage_limit' => 1,
            'usage_count' => 1,
        ]);
        checkoutPromo($fixture['tenantId'], [
            'code' => 'EUROS',
            'discount_type' => 'fixed_amount',
            'discount_value' => 500,
            'currency' => 'EUR',
        ]);

        foreach ([
            'UNKNOWN' => 'promo_code_invalid',
            'GONE' => 'promo_code_exhausted',
            'EUROS' => 'promo_code_currency_mismatch',
        ] as $code => $problem) {
            $response = $this->postJson('http://'.$fixture['host'].'/v1/storefront/orders', [
                'hold_id' => $fixture['holdId'],
                'promo_code' => $code,
            ], ['Authorization' => 'Bearer '.$fixture['token']]);

            $response->assertStatus(422);
            $response->assertJsonPath('code', $problem);
        }

        $orders = app(TenantTransaction::class)->asTenant(
            $fixture['tenantId'],
            fn () => DB::table('orders')->count(),
        );

        expect($orders)->toBe(0);
    });
});

describe('POST /v1/storefront/promo-codes/check', function (): void {
    it('previews a valid code without incrementing usage', function (): void {
        $fixture = promoCheckoutFixture();
        $promo = checkoutPromo($fixture['tenantId'], [
            'code' => 'PREVIEW',
            'discount_type' => 'percentage',
            'discount_value' => 1500,
            'currency' => null,
            'usage_limit' => 10,
            'usage_count' => 0,
        ]);

        $response = $this->postJson('http://'.$fixture['host'].'/v1/storefront/promo-codes/check', [
            'code' => 'PREVIEW',
            'hold_id' => $fixture['holdId'],
        ], ['Authorization' => 'Bearer '.$fixture['token']]);

        $response->assertStatus(200)->assertConformsToOpenApi();
        $response->assertJsonPath('valid', true)
            ->assertJsonPath('discount.amount', 3000)
            ->assertJsonPath('reason_code', null);

        $count = app(TenantTransaction::class)->asTenant(
            $fixture['tenantId'],
            fn () => DB::table('promo_codes')->where('id', $promo->id)->value('usage_count'),
        );

        expect($count)->toBe(0);
    });

    it('reports an invalid code with its reason and no discount', function (): void {
        $fixture = promoCheckoutFixture();

        $response = $this->postJson('http://'.$fixture['host'].'/v1/storefront/promo-codes/check', [
            'code' => 'UNKNOWN',
            'hold_id' => $fixture['holdId'],
        ], ['Authorization' => 'Bearer '.$fixture['token']]);

        $response->assertStatus(200)->assertConformsToOpenApi();
        $response->assertJsonPath('valid', false)
            ->assertJsonPath('discount', null)
            ->assertJsonPath('reason_code', 'promo_code_invalid');
    });
});
