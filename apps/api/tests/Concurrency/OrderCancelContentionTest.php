<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Enums\HoldStatus;
use App\Inventory\Models\Hold;
use App\Inventory\Models\TicketTypeInventory;
use App\Orders\Actions\CancelOrder;
use App\Orders\Actions\ConvertHoldToOrder;
use App\Orders\Actions\MarkOrderAwaitingPayment;
use App\Orders\Data\CreateOrderData;
use App\Orders\Enums\OrderStatus;
use App\Orders\Exceptions\InvalidOrderTransitionException;
use App\Orders\Exceptions\OrderNotCancelableException;
use App\Orders\Models\Order;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;

/**
 * Cancel racing MarkOrderAwaitingPayment on one pending order (stage-07
 * plan, TDD sequencing Slice 2): exactly one wins, proven by the
 * conditional UPDATE's affected-row check on a concurrent
 * pre-transition.
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

it('lets exactly one of cancel and awaiting-payment win a pending order', function (): void {
    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    ['orderId' => $orderId, 'holdId' => $holdId] = app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): array {
        $event = Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id]);
        $customer = Customer::factory()->create(['tenant_id' => $tenantId, 'email' => 'race@example.com']);

        TicketTypeInventory::factory()->create([
            'tenant_id' => $tenantId,
            'ticket_type_id' => $ticketType->id,
            'quantity' => 10,
            'held' => 0,
            'sold' => 0,
        ]);

        $holdId = app(CreateHold::class)(
            CreateHoldData::from([
                'event_id' => $event->id,
                'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
            ]),
            $customer->id,
        )->id;

        $orderId = app(ConvertHoldToOrder::class)(
            CreateOrderData::from(['hold_id' => $holdId]),
            $customer->id,
        )->id;

        return ['orderId' => $orderId, 'holdId' => $holdId];
    });

    $results = ParallelRunner::runEach(
        function () use ($tenantId, $orderId): string {
            try {
                app(TenantTransaction::class)->asTenant($tenantId, fn () => app(CancelOrder::class)($orderId));

                return 'canceled';
            } catch (OrderNotCancelableException) {
                return 'lost';
            }
        },
        function () use ($tenantId, $orderId): string {
            try {
                app(TenantTransaction::class)->asTenant($tenantId, fn () => app(MarkOrderAwaitingPayment::class)($orderId));

                return 'awaiting_payment';
            } catch (InvalidOrderTransitionException) {
                return 'lost';
            }
        },
    );

    $winners = array_values(array_filter($results, fn (string $result): bool => $result !== 'lost'));

    $order = app(TenantTransaction::class)->asTenant($tenantId, fn () => Order::query()->findOrFail($orderId));
    $hold = app(TenantTransaction::class)->asTenant($tenantId, fn () => Hold::query()->findOrFail($holdId));

    expect($winners)->toHaveCount(1)
        ->and($order->status->value)->toBe($winners[0]);

    if ($order->status === OrderStatus::Canceled) {
        expect($hold->status)->toBe(HoldStatus::Released);
    } else {
        expect($hold->status)->toBe(HoldStatus::Active);
    }
});
