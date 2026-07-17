<?php

use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Orders\Actions\MarkTicketsRefunded;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\TicketStatus;
use App\Orders\Models\Order;
use App\Orders\Models\Ticket;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-08b plan, task 10: the Orders voiding Action flips issued
 * tickets to refunded through conditional UPDATEs and records one
 * TicketRefunded per voided ticket in the same transaction; a repeat
 * pass affects zero rows and records nothing.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    [$this->orderId, $this->ticketIds, $this->tenantId] = (function (): array {
        $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

        return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): array {
            $customer = Customer::factory()->create(['tenant_id' => $tenantId]);
            $event = Event::factory()->create(['tenant_id' => $tenantId]);
            $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id]);

            $order = Order::factory()->create([
                'tenant_id' => $tenantId,
                'customer_id' => $customer->id,
                'event_id' => $event->id,
                'status' => OrderStatus::Paid,
            ]);

            $ticketIds = Ticket::factory()->count(2)->create([
                'tenant_id' => $tenantId,
                'order_id' => $order->id,
                'ticket_type_id' => $ticketType->id,
                'event_id' => $event->id,
                'status' => TicketStatus::Issued,
            ])->pluck('id')->all();

            return [$order->id, $ticketIds, $tenantId];
        });
    })();
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        foreach (['outbox_deliveries', 'outbox_events', 'tickets', 'orders', 'ticket_types', 'customers', 'events'] as $table) {
            DB::table($table)->where('tenant_id', $this->tenantId)->delete();
        }
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKey($this->tenantId)->delete();
    });
});

function refundedEvents(string $tenantId): array
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => DB::table('outbox_events')->where('type', 'TicketRefunded')->orderBy('sequence')->get()->all(),
    );
}

it('voids a selection and records one TicketRefunded per ticket', function (): void {
    $refundId = Str::uuid7()->toString();

    $voided = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(MarkTicketsRefunded::class)($this->orderId, [$this->ticketIds[0]], $refundId),
    );

    $statuses = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Ticket::query()->whereIn('id', $this->ticketIds)->orderByRaw("array_position(array['".implode("','", $this->ticketIds)."']::uuid[], id)")->pluck('status')->all(),
    );

    $events = refundedEvents($this->tenantId);

    expect($voided)->toBe(1)
        ->and($statuses[0])->toBe(TicketStatus::Refunded)
        ->and($statuses[1])->toBe(TicketStatus::Issued)
        ->and($events)->toHaveCount(1)
        ->and(json_decode($events[0]->payload, true)['ticket_id'])->toBe($this->ticketIds[0])
        ->and(json_decode($events[0]->payload, true)['refund_id'])->toBe($refundId);
});

it('voids every issued ticket of the order when the selection is null', function (): void {
    $voided = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(MarkTicketsRefunded::class)($this->orderId, null, Str::uuid7()->toString()),
    );

    expect($voided)->toBe(2)
        ->and(refundedEvents($this->tenantId))->toHaveCount(2);
});

it('is idempotent: a second pass affects zero tickets and records nothing', function (): void {
    $refundId = Str::uuid7()->toString();

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(MarkTicketsRefunded::class)($this->orderId, null, $refundId),
    );

    $second = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(MarkTicketsRefunded::class)($this->orderId, null, $refundId),
    );

    expect($second)->toBe(0)
        ->and(refundedEvents($this->tenantId))->toHaveCount(2);
});

it('rolls the void and its events back together', function (): void {
    try {
        app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
            app(MarkTicketsRefunded::class)($this->orderId, null, Str::uuid7()->toString());

            throw new RuntimeException('force rollback');
        });
    } catch (RuntimeException) {
    }

    $issued = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Ticket::query()->where('order_id', $this->orderId)->where('status', TicketStatus::Issued)->count(),
    );

    expect($issued)->toBe(2)
        ->and(refundedEvents($this->tenantId))->toHaveCount(0);
});
