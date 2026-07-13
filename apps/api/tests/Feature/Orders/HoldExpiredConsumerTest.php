<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Actions\ReleaseExpiredHolds;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Models\TicketTypeInventory;
use App\Orders\Actions\ConvertHoldToOrder;
use App\Orders\Data\CreateOrderData;
use App\Orders\Enums\OrderStatus;
use App\Orders\Jobs\CancelOrderOnHoldExpired;
use App\Orders\Models\Order;
use App\Support\Outbox\Jobs\ProcessOutboxDelivery;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OrderedConsumption;
use App\Support\Outbox\ProjectionLock;
use App\Support\Outbox\SubscriberRegistry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-07 plan, TDD sequencing Slice 2, task breakdown item 6: the
 * HoldExpired subscriber cancels stranded pending orders; duplicate
 * delivery of the same event ID has exactly one effect; an order
 * already in awaiting_payment is untouched (its hold is extended per
 * system-design 7.4 and Stage 8a's payment expiry owns that path).
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('outbox_deliveries')->where('tenant_id', $this->tenantId)->delete();
        DB::table('outbox_events')->where('tenant_id', $this->tenantId)->delete();
        DB::table('order_items')->where('tenant_id', $this->tenantId)->delete();
        DB::table('orders')->where('tenant_id', $this->tenantId)->delete();
        DB::table('hold_items')->where('tenant_id', $this->tenantId)->delete();
        DB::table('holds')->where('tenant_id', $this->tenantId)->delete();
        DB::table('customers')->where('tenant_id', $this->tenantId)->delete();
        DB::table('ticket_type_inventory')->where('tenant_id', $this->tenantId)->delete();
        DB::table('ticket_types')->where('tenant_id', $this->tenantId)->delete();
        DB::table('events')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );
});

/**
 * A pending order whose hold the sweeper has expired, with the
 * HoldExpired outbox event id (stage-06 sweeper, stage-07 consumer).
 *
 * @return array{orderId: string, holdId: string, outboxEventId: string}
 */
function expiredHoldOrder(string $tenantId): array
{
    $now = now();
    test()->travelTo($now);

    ['orderId' => $orderId, 'holdId' => $holdId] = app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): array {
        $event = Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id]);
        $customer = Customer::factory()->create(['tenant_id' => $tenantId, 'email' => 'stranded@example.com']);

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
                'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
            ]),
            $customer->id,
        )->id;

        $orderId = app(ConvertHoldToOrder::class)(
            CreateOrderData::from(['hold_id' => $holdId]),
            $customer->id,
        )->id;

        return ['orderId' => $orderId, 'holdId' => $holdId];
    });

    test()->travelTo($now->copy()->addMinutes(11));

    expect(app(ReleaseExpiredHolds::class)())->toBe(1);

    $outboxEventId = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => OutboxEvent::query()
            ->where('type', 'HoldExpired')
            ->where('aggregate_id', $holdId)
            ->firstOrFail()
            ->id,
    );

    return ['orderId' => $orderId, 'holdId' => $holdId, 'outboxEventId' => $outboxEventId];
}

function runHoldExpiredDelivery(string $outboxEventId): void
{
    (new ProcessOutboxDelivery($outboxEventId, CancelOrderOnHoldExpired::NAME))->handle(
        app(TenantTransaction::class),
        app(SubscriberRegistry::class),
        app(OrderedConsumption::class),
        app(ProjectionLock::class),
    );
}

function holdExpiredOrderStatus(string $tenantId, string $orderId): OrderStatus
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Order::query()->findOrFail($orderId)->status,
    );
}

it('registers the subscriber for HoldExpired', function (): void {
    expect(app(SubscriberRegistry::class)->namesFor('HoldExpired'))->toContain(CancelOrderOnHoldExpired::NAME);
});

it('cancels the stranded pending order when HoldExpired is delivered', function (): void {
    ['orderId' => $orderId, 'outboxEventId' => $outboxEventId] = expiredHoldOrder($this->tenantId);

    runHoldExpiredDelivery($outboxEventId);

    expect(holdExpiredOrderStatus($this->tenantId, $orderId))->toBe(OrderStatus::Canceled);
});

it('has exactly one effect under duplicate delivery of the same event id', function (): void {
    ['orderId' => $orderId, 'outboxEventId' => $outboxEventId] = expiredHoldOrder($this->tenantId);

    runHoldExpiredDelivery($outboxEventId);
    runHoldExpiredDelivery($outboxEventId);

    expect(holdExpiredOrderStatus($this->tenantId, $orderId))->toBe(OrderStatus::Canceled);

    $processed = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DB::table('outbox_deliveries')
            ->where('outbox_event_id', $outboxEventId)
            ->where('subscriber', CancelOrderOnHoldExpired::NAME)
            ->where('status', 'processed')
            ->count(),
    );

    expect($processed)->toBe(1);
});

it('leaves an order already in awaiting_payment untouched', function (): void {
    ['orderId' => $orderId, 'outboxEventId' => $outboxEventId] = expiredHoldOrder($this->tenantId);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($orderId): void {
        DB::table('orders')->where('id', $orderId)->update(['status' => OrderStatus::AwaitingPayment->value]);
    });

    runHoldExpiredDelivery($outboxEventId);

    expect(holdExpiredOrderStatus($this->tenantId, $orderId))->toBe(OrderStatus::AwaitingPayment);
});
