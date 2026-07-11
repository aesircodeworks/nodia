<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Enums\HoldStatus;
use App\Orders\Actions\ConvertHoldToOrder;
use App\Orders\Data\CreateOrderData;
use App\Orders\Exceptions\PromoCodeExhaustedException;
use App\Orders\Models\Order;
use App\Orders\Models\PromoCode;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;

/**
 * N parallel order creations redeeming one code with usage_limit L
 * (L < N) end with usage_count exactly L, exactly L discounted orders,
 * and N minus L failures with promo_code_exhausted, holds intact for
 * the losers (stage-07 plan, TDD sequencing Slice 5; exit criterion 9).
 * Written before the promo_codes migration exists per the master
 * plan's non-negotiable.
 */
beforeEach(function (): void {
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

it('never redeems a promo code past its usage limit under parallel conversion', function (): void {
    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $workers = 6;
    $limit = 3;

    ['holds' => $holds, 'customerId' => $customerId, 'promoId' => $promoId] = app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $workers, $limit): array {
        $event = Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create([
            'tenant_id' => $tenantId,
            'event_id' => $event->id,
            'price' => Money::of(10000, 'USD'),
        ]);
        $customer = Customer::factory()->create(['tenant_id' => $tenantId, 'email' => 'promo-race@example.com']);

        DB::table('ticket_type_inventory')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => $tenantId,
            'ticket_type_id' => $ticketType->id,
            'quantity' => 50,
            'held' => 0,
            'sold' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $promoId = PromoCode::factory()->create([
            'tenant_id' => $tenantId,
            'code' => 'RACE10',
            'discount_type' => 'percentage',
            'discount_value' => 1000,
            'currency' => null,
            'usage_limit' => $limit,
            'usage_count' => 0,
        ])->id;

        $holds = [];

        for ($i = 0; $i < $workers; $i++) {
            $holds[] = app(CreateHold::class)(
                CreateHoldData::from([
                    'event_id' => $event->id,
                    'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
                ]),
                $customer->id,
            )->id;
        }

        return ['holds' => $holds, 'customerId' => $customer->id, 'promoId' => $promoId];
    });

    $results = ParallelRunner::runEach(...array_map(
        fn (string $holdId): Closure => function () use ($tenantId, $holdId, $customerId): string {
            try {
                app(TenantTransaction::class)->asTenant(
                    $tenantId,
                    fn () => app(ConvertHoldToOrder::class)(
                        CreateOrderData::from(['hold_id' => $holdId, 'promo_code' => 'RACE10']),
                        $customerId,
                    ),
                );

                return 'discounted';
            } catch (PromoCodeExhaustedException) {
                return 'exhausted';
            }
        },
        $holds,
    ));

    $counts = array_count_values($results);
    $promo = app(TenantTransaction::class)->asTenant($tenantId, fn () => PromoCode::query()->findOrFail($promoId));
    $orders = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Order::query()->where('tenant_id', $tenantId)->get(),
    );
    $loserHoldStates = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => DB::table('holds')
            ->whereNotIn('id', Order::query()->pluck('hold_id'))
            ->pluck('status'),
    );

    expect($counts['discounted'] ?? 0)->toBe($limit)
        ->and($counts['exhausted'] ?? 0)->toBe($workers - $limit)
        ->and($promo->usage_count)->toBe($limit)
        ->and($orders)->toHaveCount($limit)
        ->and($orders->pluck('discount_amount')->unique()->all())->toBe([1000])
        ->and($orders->pluck('promo_code_id')->unique()->all())->toBe([$promoId])
        // Losing conversions rolled back completely: their holds stay active.
        ->and($loserHoldStates->unique()->all())->toBe([HoldStatus::Active->value]);
});
