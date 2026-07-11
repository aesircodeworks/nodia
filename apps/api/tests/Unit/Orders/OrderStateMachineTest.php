<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CreateHoldData;
use App\Orders\Actions\CancelOrder;
use App\Orders\Actions\MarkOrderAwaitingPayment;
use App\Orders\Actions\MarkOrderExpired;
use App\Orders\Actions\MarkOrderFailed;
use App\Orders\Actions\MarkOrderPaid;
use App\Orders\Enums\OrderStatus;
use App\Orders\Exceptions\InvalidOrderTransitionException;
use App\Orders\Exceptions\OrderNotCancelableException;
use App\Orders\Exceptions\OrderNotFoundException;
use App\Orders\Models\Order;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * The data-driven table over every ordered pair of non-refund states
 * (stage-07 plan, TDD sequencing Slice 2): exactly the system-design
 * 7.1 arcs succeed and every other pair raises with zero rows affected.
 * The refund states are asserted unreachable in this stage: no
 * transition Action targets them, and no Action leaves them, so Stage
 * 8b extends this table additively instead of rewriting frozen
 * assertions.
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
 * An order seeded directly into the given status, backed by a real
 * active hold so the release and commit wiring inside the transition
 * Actions operates on genuine Inventory rows.
 */
function stateMachineOrder(string $tenantId, OrderStatus $status): Order
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $status): Order {
        $event = Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id]);
        $customer = Customer::factory()->create([
            'tenant_id' => $tenantId,
            'email' => Str::uuid7()->toString().'@example.com',
        ]);

        DB::table('ticket_type_inventory')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => $tenantId,
            'ticket_type_id' => $ticketType->id,
            'quantity' => 10,
            'held' => 0,
            'sold' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $holdId = app(CreateHold::class)(
            CreateHoldData::from([
                'event_id' => $event->id,
                'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
            ]),
            $customer->id,
        )->id;

        $order = Order::factory()->create([
            'tenant_id' => $tenantId,
            'customer_id' => $customer->id,
            'event_id' => $event->id,
            'hold_id' => $holdId,
            'status' => $status,
        ]);

        $order->items()->create([
            'tenant_id' => $tenantId,
            'ticket_type_id' => $ticketType->id,
            'quantity' => 1,
            'unit_price' => $ticketType->price,
            'attendee_names' => null,
        ]);

        return $order;
    });
}

/**
 * @return array<string, class-string>
 */
function transitionActions(): array
{
    return [
        'awaiting_payment' => MarkOrderAwaitingPayment::class,
        'paid' => MarkOrderPaid::class,
        'expired' => MarkOrderExpired::class,
        'failed' => MarkOrderFailed::class,
        'canceled' => CancelOrder::class,
    ];
}

dataset('valid arcs', [
    'pending to awaiting_payment' => [OrderStatus::Pending, 'awaiting_payment'],
    'pending to canceled' => [OrderStatus::Pending, 'canceled'],
    'awaiting_payment to paid' => [OrderStatus::AwaitingPayment, 'paid'],
    'awaiting_payment to expired' => [OrderStatus::AwaitingPayment, 'expired'],
    'awaiting_payment to failed' => [OrderStatus::AwaitingPayment, 'failed'],
]);

dataset('invalid arcs', function (): Generator {
    $states = [
        OrderStatus::Pending,
        OrderStatus::AwaitingPayment,
        OrderStatus::Paid,
        OrderStatus::Expired,
        OrderStatus::Failed,
        OrderStatus::Canceled,
        OrderStatus::PartiallyRefunded,
        OrderStatus::Refunded,
    ];

    $valid = [
        'pending>awaiting_payment',
        'pending>canceled',
        'awaiting_payment>paid',
        'awaiting_payment>expired',
        'awaiting_payment>failed',
    ];

    foreach ($states as $from) {
        foreach (array_keys(transitionActions()) as $to) {
            if (! in_array($from->value.'>'.$to, $valid, true)) {
                yield $from->value.' to '.$to => [$from, $to];
            }
        }
    }
});

test('exactly the system-design 7.1 arcs succeed', function (OrderStatus $from, string $to) {
    $order = stateMachineOrder($this->tenantId, $from);

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(transitionActions()[$to])($order->id),
    );

    $status = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Order::query()->findOrFail($order->id)->status,
    );

    expect($status->value)->toBe($to);
})->with('valid arcs');

test('every other ordered pair raises and affects zero rows', function (OrderStatus $from, string $to) {
    $order = stateMachineOrder($this->tenantId, $from);

    $expected = $to === 'canceled' ? OrderNotCancelableException::class : InvalidOrderTransitionException::class;

    expect(fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(transitionActions()[$to])($order->id),
    ))->toThrow($expected);

    $status = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Order::query()->findOrFail($order->id)->status,
    );

    expect($status)->toBe($from);
})->with('invalid arcs');

test('a transition on an unknown order raises order_not_found', function () {
    expect(fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(MarkOrderAwaitingPayment::class)(Str::uuid7()->toString()),
    ))->toThrow(OrderNotFoundException::class);
});

test('no transition action targets a refund state', function () {
    expect(array_keys(transitionActions()))->toBe([
        'awaiting_payment',
        'paid',
        'expired',
        'failed',
        'canceled',
    ]);
});
