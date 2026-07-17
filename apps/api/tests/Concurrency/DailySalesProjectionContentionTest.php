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
use App\Reporting\Jobs\ProjectDailySales;
use App\Support\Outbox\Jobs\ProcessOutboxDelivery;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OrderedConsumption;
use App\Support\Outbox\ProjectionLock;
use App\Support\Outbox\SubscriberRegistry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-11 plan, Slice 1 Concurrency test: N parallel workers projecting
 * N distinct TicketIssued events for the same (event, ticket_type, day)
 * cell converge to exactly N increments; the upsert-with-increments
 * write path (never read-then-write, master plan test-first rule 2)
 * loses no updates under real contention.
 */

const WORKERS = 8;

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
            foreach (['report_daily_sales', 'outbox_deliveries', 'outbox_events', 'media', 'tickets', 'order_items', 'orders', 'hold_items', 'holds', 'customers', 'ticket_type_inventory', 'ticket_types', 'events'] as $table) {
                DB::table($table)->where('tenant_id', $tenantId)->delete();
            }
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

/**
 * One order of WORKERS units of one ticket type: IssueTickets records
 * one TicketIssued per unit (App\Orders\Actions\IssueTickets), so this
 * produces WORKERS distinct outbox events all resolving to the same
 * event, ticket type, and day cell. Queue::fake() keeps every delivery
 * pending so the parallel workers below are the first and only
 * processors, the same discipline
 * tests/Concurrency/OutboxDeliveryContentionTest.php uses.
 *
 * @return array{tenantId: string, eventId: string, ticketTypeId: string, unitPriceAmount: int, eventIds: list<string>}
 */
function dailySalesContentionFixture(): array
{
    Queue::fake();

    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $state = app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): array {
        $event = Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id]);

        TicketTypeInventory::factory()->create([
            'tenant_id' => $tenantId,
            'ticket_type_id' => $ticketType->id,
            'quantity' => WORKERS + 5,
            'held' => 0,
            'sold' => 0,
        ]);

        $customer = Customer::factory()->create(['tenant_id' => $tenantId]);

        $holdId = app(CreateHold::class)(
            CreateHoldData::from([
                'event_id' => $event->id,
                'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => WORKERS]],
            ]),
            $customer->id,
        )->id;

        $orderId = app(ConvertHoldToOrder::class)(
            CreateOrderData::from(['hold_id' => $holdId]),
            $customer->id,
        )->id;

        app(MarkOrderAwaitingPayment::class)($orderId);
        app(MarkOrderPaid::class)($orderId);

        $item = DB::table('order_items')->where('order_id', $orderId)->first();

        $eventIds = OutboxEvent::query()->where('type', 'TicketIssued')->where('tenant_id', $tenantId)->pluck('id')->all();

        return [
            'eventId' => $event->id,
            'ticketTypeId' => $ticketType->id,
            'unitPriceAmount' => (int) $item->unit_price_amount,
            'eventIds' => $eventIds,
        ];
    });

    return array_merge(['tenantId' => $tenantId], $state);
}

it('converges to exactly N increments when N distinct TicketIssued deliveries race the same cell', function (): void {
    $fx = dailySalesContentionFixture();

    expect($fx['eventIds'])->toHaveCount(WORKERS);

    ParallelRunner::runEach(...array_map(
        fn (string $eventId): callable => function () use ($eventId): bool {
            app(ProcessOutboxDelivery::class, [
                'eventId' => $eventId,
                'subscriber' => ProjectDailySales::NAME,
            ])->handle(
                app(TenantTransaction::class),
                app(SubscriberRegistry::class),
                app(OrderedConsumption::class),
                app(ProjectionLock::class),
            );

            return true;
        },
        $fx['eventIds'],
    ));

    $row = app(TenantTransaction::class)->asTenant(
        $fx['tenantId'],
        fn () => DB::table('report_daily_sales')
            ->where('event_id', $fx['eventId'])
            ->where('ticket_type_id', $fx['ticketTypeId'])
            ->first(),
    );

    expect((int) $row->tickets_issued_count)->toBe(WORKERS)
        ->and((int) $row->gross_amount)->toBe($fx['unitPriceAmount'] * WORKERS);
});
