<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Exceptions\HoldNotFoundException;
use App\Inventory\Models\Hold;
use App\Inventory\Models\TicketTypeInventory;
use App\Orders\Actions\ConvertHoldToOrder;
use App\Orders\Data\CreateOrderData;
use App\Orders\Exceptions\HoldAlreadyConvertedException;
use App\Orders\Models\Order;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;

/**
 * The hold double-conversion and anonymous-hold attachment races
 * (stage-07 plan, TDD sequencing Slice 1; exit criterion 3), written
 * before ConvertHoldToOrder exists per the master plan's non-negotiable.
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
            DB::table('order_items')->where('tenant_id', $tenantId)->delete();
            DB::table('orders')->where('tenant_id', $tenantId)->delete();
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
 * @return array{tenantId: string, holdId: string, customerIds: list<string>}
 */
function conversionRaceFixture(int $customers = 1, ?string $holdCustomerId = null): array
{
    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $customers, $holdCustomerId): array {
        $event = Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id]);

        TicketTypeInventory::factory()->create([
            'tenant_id' => $tenantId,
            'ticket_type_id' => $ticketType->id,
            'quantity' => 10,
            'held' => 0,
            'sold' => 0,
        ]);

        $customerIds = [];

        for ($i = 0; $i < $customers; $i++) {
            $customerIds[] = Customer::factory()->create([
                'tenant_id' => $tenantId,
                'email' => sprintf('racer-%d@example.com', $i),
            ])->id;
        }

        $holdId = app(CreateHold::class)(
            CreateHoldData::from([
                'event_id' => $event->id,
                'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
            ]),
            null,
        )->id;

        if ($holdCustomerId !== null) {
            DB::table('holds')->where('id', $holdId)->update(['customer_id' => $holdCustomerId]);
        }

        return ['tenantId' => $tenantId, 'holdId' => $holdId, 'customerIds' => $customerIds];
    });
}

it('creates exactly one order from N parallel conversions of one hold', function (): void {
    ['tenantId' => $tenantId, 'holdId' => $holdId, 'customerIds' => $customerIds] = conversionRaceFixture(1);
    $customerId = $customerIds[0];

    $results = ParallelRunner::run(6, function () use ($tenantId, $holdId, $customerId): string {
        try {
            app(TenantTransaction::class)->asTenant(
                $tenantId,
                fn () => app(ConvertHoldToOrder::class)(CreateOrderData::from(['hold_id' => $holdId]), $customerId),
            );

            return 'won';
        } catch (HoldAlreadyConvertedException) {
            return 'already_converted';
        }
    });

    $orders = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Order::query()->where('hold_id', $holdId)->get(),
    );
    $itemCount = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => DB::table('order_items')->where('tenant_id', $tenantId)->count(),
    );
    $orderCreatedCount = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => OutboxEvent::query()->where('tenant_id', $tenantId)->where('type', 'OrderCreated')->count(),
    );

    expect(array_count_values($results)['won'] ?? 0)->toBe(1)
        ->and($orders)->toHaveCount(1)
        // Losers roll back clean: only the winner's single line item exists.
        ->and($itemCount)->toBe(1)
        ->and($orderCreatedCount)->toBe(1);
});

it('attaches an anonymous hold to exactly one of two racing customers', function (): void {
    ['tenantId' => $tenantId, 'holdId' => $holdId, 'customerIds' => $customerIds] = conversionRaceFixture(2);

    $results = ParallelRunner::runEach(...array_map(
        fn (string $customerId): Closure => function () use ($tenantId, $holdId, $customerId): string {
            try {
                app(TenantTransaction::class)->asTenant(
                    $tenantId,
                    fn () => app(ConvertHoldToOrder::class)(CreateOrderData::from(['hold_id' => $holdId]), $customerId),
                );

                return $customerId;
            } catch (HoldNotFoundException|HoldAlreadyConvertedException) {
                return 'lost';
            }
        },
        $customerIds,
    ));

    $winners = array_values(array_filter($results, fn (string $result): bool => $result !== 'lost'));

    $hold = app(TenantTransaction::class)->asTenant($tenantId, fn () => Hold::query()->findOrFail($holdId));
    $orders = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Order::query()->where('hold_id', $holdId)->get(),
    );

    expect($winners)->toHaveCount(1)
        ->and($orders)->toHaveCount(1)
        ->and($hold->customer_id)->toBe($winners[0])
        ->and($orders[0]->customer_id)->toBe($winners[0]);
});
