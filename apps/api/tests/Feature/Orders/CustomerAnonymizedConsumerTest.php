<?php

use App\EventCatalog\Models\Event;
use App\Identity\Actions\AnonymizeCustomer;
use App\Identity\Models\Customer;
use App\Orders\Jobs\ScrubTicketAttendeeNames;
use App\Orders\Models\Order;
use App\Orders\Models\Ticket;
use App\Support\Outbox\Jobs\ProcessOutboxDelivery;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OrderedConsumption;
use App\Support\Outbox\ProjectionLock;
use App\Support\Outbox\SubscriberRegistry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-12 plan, Domain events "Consumed" and task breakdown item 4: the
 * Orders subscriber for Identity's CustomerAnonymized scrubs
 * attendee_name on the anonymized customer's own tickets by updating
 * Orders' own tickets table only (event-conventions: a consumer never
 * writes to another context's tables). Duplicate delivery of the same
 * event id has exactly one effect through the Stage 4 outbox_deliveries
 * conditional transition; tickets belonging to other customers or other
 * tenants are untouched. Mirrors tests/Feature/Orders/
 * HoldExpiredConsumerTest.php's own structure.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $this->otherTenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    foreach ([$this->tenantId, $this->otherTenantId] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            foreach (['outbox_deliveries', 'outbox_events', 'tickets', 'orders', 'customers', 'events'] as $table) {
                DB::table($table)->where('tenant_id', $tenantId)->delete();
            }
        });
    }

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete(),
    );
});

/**
 * A fresh customer with one order in the given tenant, for a ticket
 * fixture to attach to.
 *
 * @return array{customerId: string, orderId: string, eventId: string}
 */
function orderForNewCustomer(string $tenantId): array
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): array {
        $event = Event::factory()->create(['tenant_id' => $tenantId]);
        $customer = Customer::factory()->create(['tenant_id' => $tenantId]);
        $order = Order::factory()->create([
            'tenant_id' => $tenantId,
            'customer_id' => $customer->id,
            'event_id' => $event->id,
        ]);

        return ['customerId' => $customer->id, 'orderId' => $order->id, 'eventId' => $event->id];
    });
}

function ticketFor(string $tenantId, string $orderId, string $eventId, ?string $attendeeName): Ticket
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Ticket::factory()->create([
            'tenant_id' => $tenantId,
            'order_id' => $orderId,
            'ticket_type_id' => Str::uuid7()->toString(),
            'event_id' => $eventId,
            'attendee_name' => $attendeeName,
        ]),
    );
}

/**
 * Anonymizes the given customer through the real Action (Identity's own
 * producer) and returns the CustomerAnonymized outbox event id it
 * records, mirroring tests/Feature/Payments/LedgerProjectionTest.php's
 * own posture of driving the producer for real rather than hand-rolling
 * an outbox row.
 */
function anonymizeAndRecordEvent(string $tenantId, string $customerId): string
{
    app(TenantTransaction::class)->asTenant($tenantId, function () use ($customerId): void {
        DB::transaction(function () use ($customerId): void {
            $customer = Customer::query()->findOrFail($customerId);
            app(AnonymizeCustomer::class)($customer, Str::uuid7()->toString());
        });
    });

    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => OutboxEvent::query()
            ->where('type', 'CustomerAnonymized')
            ->where('aggregate_id', $customerId)
            ->value('id'),
    );
}

function runScrubDelivery(string $outboxEventId): void
{
    (new ProcessOutboxDelivery($outboxEventId, ScrubTicketAttendeeNames::NAME))->handle(
        app(TenantTransaction::class),
        app(SubscriberRegistry::class),
        app(OrderedConsumption::class),
        app(ProjectionLock::class),
    );
}

function ticketAttendeeName(string $tenantId, string $ticketId): ?string
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Ticket::query()->findOrFail($ticketId)->attendee_name,
    );
}

it('registers the subscriber for CustomerAnonymized', function (): void {
    expect(app(SubscriberRegistry::class)->namesFor('CustomerAnonymized'))->toContain(ScrubTicketAttendeeNames::NAME);
});

it('scrubs attendee_name on the anonymized customers tickets, leaving a null attendee_name alone', function (): void {
    ['customerId' => $customerId, 'orderId' => $orderId, 'eventId' => $eventId] = orderForNewCustomer($this->tenantId);

    $named = ticketFor($this->tenantId, $orderId, $eventId, 'Alice Attendee');
    $unnamed = ticketFor($this->tenantId, $orderId, $eventId, null);

    $outboxEventId = anonymizeAndRecordEvent($this->tenantId, $customerId);

    runScrubDelivery($outboxEventId);

    expect(ticketAttendeeName($this->tenantId, $named->id))->toBe(ScrubTicketAttendeeNames::PLACEHOLDER)
        ->and(ticketAttendeeName($this->tenantId, $unnamed->id))->toBeNull();
});

it('has exactly one effect under duplicate delivery of the same event id', function (): void {
    ['customerId' => $customerId, 'orderId' => $orderId, 'eventId' => $eventId] = orderForNewCustomer($this->tenantId);

    $ticket = ticketFor($this->tenantId, $orderId, $eventId, 'Alice Attendee');

    $outboxEventId = anonymizeAndRecordEvent($this->tenantId, $customerId);

    runScrubDelivery($outboxEventId);
    runScrubDelivery($outboxEventId);

    expect(ticketAttendeeName($this->tenantId, $ticket->id))->toBe(ScrubTicketAttendeeNames::PLACEHOLDER);

    $processed = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DB::table('outbox_deliveries')
            ->where('outbox_event_id', $outboxEventId)
            ->where('subscriber', ScrubTicketAttendeeNames::NAME)
            ->where('status', 'processed')
            ->count(),
    );

    expect($processed)->toBe(1);
});

it('leaves tickets belonging to other customers and other tenants untouched', function (): void {
    ['customerId' => $customerId, 'orderId' => $orderId, 'eventId' => $eventId] = orderForNewCustomer($this->tenantId);
    $target = ticketFor($this->tenantId, $orderId, $eventId, 'Alice Attendee');

    ['orderId' => $otherOrderId, 'eventId' => $otherEventId] = orderForNewCustomer($this->tenantId);
    $otherCustomerTicket = ticketFor($this->tenantId, $otherOrderId, $otherEventId, 'Bob Attendee');

    ['orderId' => $crossTenantOrderId, 'eventId' => $crossTenantEventId] = orderForNewCustomer($this->otherTenantId);
    $crossTenantTicket = ticketFor($this->otherTenantId, $crossTenantOrderId, $crossTenantEventId, 'Carol Attendee');

    $outboxEventId = anonymizeAndRecordEvent($this->tenantId, $customerId);

    runScrubDelivery($outboxEventId);

    expect(ticketAttendeeName($this->tenantId, $target->id))->toBe(ScrubTicketAttendeeNames::PLACEHOLDER)
        ->and(ticketAttendeeName($this->tenantId, $otherCustomerTicket->id))->toBe('Bob Attendee')
        ->and(ticketAttendeeName($this->otherTenantId, $crossTenantTicket->id))->toBe('Carol Attendee');
});
