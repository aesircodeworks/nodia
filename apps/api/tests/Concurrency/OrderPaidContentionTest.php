<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Models\TicketTypeInventory;
use App\Orders\Actions\ConvertHoldToOrder;
use App\Orders\Actions\MarkOrderAwaitingPayment;
use App\Orders\Actions\MarkOrderPaid;
use App\Orders\Data\CreateOrderData;
use App\Orders\Enums\OrderStatus;
use App\Orders\Exceptions\InvalidOrderTransitionException;
use App\Orders\Models\Order;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;

/**
 * N parallel MarkOrderPaid calls on one awaiting_payment order produce
 * exactly one status change, exactly the ordered quantity of tickets,
 * held decremented and sold incremented exactly once, and exactly one
 * TicketIssued event per ticket (stage-07 plan, TDD sequencing Slice 3;
 * exit criterion 5). Written before the tickets migration exists per
 * the master plan's non-negotiable.
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
            DB::table('media')->where('tenant_id', $tenantId)->delete();
            DB::table('tickets')->where('tenant_id', $tenantId)->delete();
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

it('pays an order exactly once under parallel confirmation', function (): void {
    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    ['orderId' => $orderId, 'ticketTypeId' => $ticketTypeId] = app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): array {
        $event = Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id]);
        $customer = Customer::factory()->create(['tenant_id' => $tenantId, 'email' => 'paid-race@example.com']);

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
                'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 3]],
            ]),
            $customer->id,
        )->id;

        $orderId = app(ConvertHoldToOrder::class)(
            CreateOrderData::from(['hold_id' => $holdId]),
            $customer->id,
        )->id;

        app(MarkOrderAwaitingPayment::class)($orderId);

        return ['orderId' => $orderId, 'ticketTypeId' => $ticketType->id];
    });

    $results = ParallelRunner::run(6, function () use ($tenantId, $orderId): string {
        try {
            app(TenantTransaction::class)->asTenant($tenantId, fn () => app(MarkOrderPaid::class)($orderId));

            return 'won';
        } catch (InvalidOrderTransitionException) {
            return 'lost';
        }
    });

    $order = app(TenantTransaction::class)->asTenant($tenantId, fn () => Order::query()->findOrFail($orderId));
    $inventory = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $ticketTypeId)->firstOrFail(),
    );
    $tickets = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => DB::table('tickets')->where('order_id', $orderId)->count(),
    );
    $ticketIssued = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => OutboxEvent::query()->where('tenant_id', $tenantId)->where('type', 'TicketIssued')->count(),
    );

    expect(array_count_values($results)['won'] ?? 0)->toBe(1)
        ->and($order->status)->toBe(OrderStatus::Paid)
        ->and($tickets)->toBe(3)
        ->and($inventory->held)->toBe(0)
        ->and($inventory->sold)->toBe(3)
        ->and($ticketIssued)->toBe(3);
});
