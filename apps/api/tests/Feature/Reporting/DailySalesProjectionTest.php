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
use App\Orders\Actions\MarkTicketsRefunded;
use App\Orders\Data\CreateOrderData;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\TicketStatus;
use App\Orders\Events\TicketIssued;
use App\Orders\Models\Order;
use App\Orders\Models\Ticket;
use App\Reporting\Jobs\ProjectDailySales;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OutboxRecorder;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-11 plan, Slice 1 Feature tests: TicketIssued and TicketRefunded
 * delivered through the real pipeline (QUEUE_CONNECTION=sync stands in
 * for Redis locally, the same posture every other outbox feature test in
 * this codebase already runs under), plus the mandated duplicate-
 * delivery test (master plan Method section, mandate three).
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    $this->travelTo(CarbonImmutable::parse('2026-07-13T12:00:00Z'));
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
 * @return array{tenantId: string, eventId: string, ticketTypeId: string}
 */
function dailySalesFixture(): array
{
    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    ['eventId' => $eventId, 'ticketTypeId' => $ticketTypeId] = app(TenantTransaction::class)->asTenant(
        $tenantId,
        function () use ($tenantId): array {
            $event = Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published]);
            $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id]);

            TicketTypeInventory::factory()->create([
                'tenant_id' => $tenantId,
                'ticket_type_id' => $ticketType->id,
                'quantity' => 50,
                'held' => 0,
                'sold' => 0,
            ]);

            return ['eventId' => $event->id, 'ticketTypeId' => $ticketType->id];
        },
    );

    return ['tenantId' => $tenantId, 'eventId' => $eventId, 'ticketTypeId' => $ticketTypeId];
}

/**
 * One paid order of one ticket, issued through the real hold-to-paid
 * pipeline (mirrors tests/Feature/Payments/RefundCompletionTest.php's
 * completionFixture): TicketIssued records and, under the sync queue,
 * ProjectDailySales already runs once before this function returns.
 *
 * @return array{orderId: string, ticketId: string, unitPriceAmount: int, currency: string}
 */
function issueOneTicket(string $tenantId, string $eventId, string $ticketTypeId): array
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $eventId, $ticketTypeId): array {
        $customer = Customer::factory()->create(['tenant_id' => $tenantId]);

        $holdId = app(CreateHold::class)(
            CreateHoldData::from([
                'event_id' => $eventId,
                'items' => [['ticket_type_id' => $ticketTypeId, 'quantity' => 1]],
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
        $ticketId = DB::table('tickets')->where('order_id', $orderId)->value('id');

        return [
            'orderId' => $orderId,
            'ticketId' => $ticketId,
            'unitPriceAmount' => (int) $item->unit_price_amount,
            'currency' => $item->currency,
        ];
    });
}

/**
 * @return object|null
 */
function dailySalesRow(string $tenantId, string $eventId, string $ticketTypeId)
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => DB::table('report_daily_sales')
            ->where('event_id', $eventId)
            ->where('ticket_type_id', $ticketTypeId)
            ->first(),
    );
}

it('produces exactly one row with correct counts, amounts, currency, and UTC sales_date for one issued ticket', function (): void {
    $fx = dailySalesFixture();
    $ticket = issueOneTicket($fx['tenantId'], $fx['eventId'], $fx['ticketTypeId']);

    $rows = app(TenantTransaction::class)->asTenant(
        $fx['tenantId'],
        fn () => DB::table('report_daily_sales')->where('event_id', $fx['eventId'])->get(),
    );

    expect($rows)->toHaveCount(1);

    $row = $rows->first();

    expect($row->ticket_type_id)->toBe($fx['ticketTypeId'])
        ->and((int) $row->tickets_issued_count)->toBe(1)
        ->and((int) $row->tickets_refunded_count)->toBe(0)
        ->and((int) $row->gross_amount)->toBe($ticket['unitPriceAmount'])
        ->and((int) $row->refunded_amount)->toBe(0)
        ->and($row->currency)->toBe($ticket['currency'])
        ->and((string) $row->sales_date)->toBe('2026-07-13');
});

it('increments the same row when a second ticket of the same event, type, and day is issued', function (): void {
    $fx = dailySalesFixture();

    $first = issueOneTicket($fx['tenantId'], $fx['eventId'], $fx['ticketTypeId']);
    issueOneTicket($fx['tenantId'], $fx['eventId'], $fx['ticketTypeId']);

    $rows = app(TenantTransaction::class)->asTenant(
        $fx['tenantId'],
        fn () => DB::table('report_daily_sales')->where('event_id', $fx['eventId'])->get(),
    );

    expect($rows)->toHaveCount(1);

    $row = $rows->first();

    expect((int) $row->tickets_issued_count)->toBe(2)
        ->and((int) $row->gross_amount)->toBe($first['unitPriceAmount'] * 2);
});

it('increments the refund columns when TicketRefunded is recorded for an issued ticket', function (): void {
    $fx = dailySalesFixture();
    $ticket = issueOneTicket($fx['tenantId'], $fx['eventId'], $fx['ticketTypeId']);

    app(TenantTransaction::class)->asTenant($fx['tenantId'], function () use ($ticket): void {
        DB::transaction(fn () => app(MarkTicketsRefunded::class)($ticket['orderId'], null, (string) Str::uuid7()));
    });

    $row = dailySalesRow($fx['tenantId'], $fx['eventId'], $fx['ticketTypeId']);

    expect((int) $row->tickets_issued_count)->toBe(1)
        ->and((int) $row->tickets_refunded_count)->toBe(1)
        ->and((int) $row->gross_amount)->toBe($ticket['unitPriceAmount'])
        ->and((int) $row->refunded_amount)->toBe($ticket['unitPriceAmount']);
});

it('causes exactly one increment when a TicketIssued delivery is repeated (the mandated duplicate-delivery test)', function (): void {
    $fx = dailySalesFixture();
    $ticket = issueOneTicket($fx['tenantId'], $fx['eventId'], $fx['ticketTypeId']);

    $eventId = app(TenantTransaction::class)->asTenant(
        $fx['tenantId'],
        fn () => OutboxEvent::query()
            ->where('type', 'TicketIssued')
            ->where('aggregate_id', $ticket['ticketId'])
            ->firstOrFail()
            ->id,
    );

    // The sync queue already delivered this once above (production
    // posture, mirrors tests/Feature/Orders/GenerateTicketPdfTest.php's
    // own duplicate-delivery test); these two further attempts prove the
    // conditional outbox_deliveries mark, not a fresh insert, admits
    // exactly one effect.
    processOutboxDeliveryTwice($eventId, ProjectDailySales::NAME);

    $row = dailySalesRow($fx['tenantId'], $fx['eventId'], $fx['ticketTypeId']);

    expect((int) $row->tickets_issued_count)->toBe(1)
        ->and((int) $row->gross_amount)->toBe($ticket['unitPriceAmount']);
});

it('causes exactly one increment when a TicketRefunded delivery is repeated', function (): void {
    $fx = dailySalesFixture();
    $ticket = issueOneTicket($fx['tenantId'], $fx['eventId'], $fx['ticketTypeId']);

    app(TenantTransaction::class)->asTenant($fx['tenantId'], function () use ($ticket): void {
        DB::transaction(fn () => app(MarkTicketsRefunded::class)($ticket['orderId'], null, (string) Str::uuid7()));
    });

    $eventId = app(TenantTransaction::class)->asTenant(
        $fx['tenantId'],
        fn () => OutboxEvent::query()
            ->where('type', 'TicketRefunded')
            ->where('aggregate_id', $ticket['ticketId'])
            ->firstOrFail()
            ->id,
    );

    processOutboxDeliveryTwice($eventId, ProjectDailySales::NAME);

    $row = dailySalesRow($fx['tenantId'], $fx['eventId'], $fx['ticketTypeId']);

    expect((int) $row->tickets_refunded_count)->toBe(1)
        ->and((int) $row->refunded_amount)->toBe($ticket['unitPriceAmount']);
});

it('skips a TicketIssued event whose ticket has no matching order_item rather than throwing', function (): void {
    $fx = dailySalesFixture();

    app(TenantTransaction::class)->asTenant($fx['tenantId'], function () use ($fx): void {
        $customer = Customer::factory()->create(['tenant_id' => $fx['tenantId']]);
        $order = Order::factory()->create([
            'tenant_id' => $fx['tenantId'],
            'customer_id' => $customer->id,
            'event_id' => $fx['eventId'],
            'status' => OrderStatus::Paid,
        ]);

        // Deliberately no order_items row for this order, so
        // GetTicketSaleFacts cannot price the ticket (the same gap
        // tests/Unit/Orders/MarkTicketsRefundedTest.php's fixture leaves,
        // proving the outbox delivery itself never fails on it).
        $ticket = Ticket::factory()->create([
            'tenant_id' => $fx['tenantId'],
            'order_id' => $order->id,
            'ticket_type_id' => $fx['ticketTypeId'],
            'event_id' => $fx['eventId'],
            'status' => TicketStatus::Issued,
        ]);

        DB::transaction(fn () => app(OutboxRecorder::class)->record(TicketIssued::fromTicket($ticket)));
    });

    $row = dailySalesRow($fx['tenantId'], $fx['eventId'], $fx['ticketTypeId']);

    expect($row)->toBeNull();
});
