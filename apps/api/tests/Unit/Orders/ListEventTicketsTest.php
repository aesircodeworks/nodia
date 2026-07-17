<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Orders\Actions\ListEventTickets;
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
 * Stage-09 plan, Task 8: the per-event ticket listing Action CheckIn's
 * BuildManifest (Task 9) calls to build the manifest.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('tickets')->where('tenant_id', $this->tenantId)->delete();
        DB::table('order_items')->where('tenant_id', $this->tenantId)->delete();
        DB::table('orders')->where('tenant_id', $this->tenantId)->delete();
        DB::table('customers')->where('tenant_id', $this->tenantId)->delete();
        DB::table('ticket_types')->where('tenant_id', $this->tenantId)->delete();
        DB::table('events')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );
});

function listEventTicketsFixture(string $tenantId, int $count, array $overrides = []): array
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $count, $overrides): array {
        $event = Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id]);
        $customer = Customer::factory()->create([
            'tenant_id' => $tenantId,
            'email' => Str::uuid7()->toString().'@example.com',
        ]);
        $order = Order::factory()->create([
            'tenant_id' => $tenantId,
            'customer_id' => $customer->id,
            'event_id' => $event->id,
            'hold_id' => Str::uuid7()->toString(),
        ]);

        $tickets = collect(range(1, $count))->map(fn () => Ticket::factory()->create([
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'ticket_type_id' => $ticketType->id,
            'event_id' => $event->id,
            ...$overrides,
        ]));

        return [$event->id, $tickets];
    });
}

it('returns every ticket for the event, ordered by ticket ID', function (): void {
    [$eventId, $tickets] = listEventTicketsFixture($this->tenantId, 3);

    $entries = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => (app(ListEventTickets::class))($eventId),
    );

    expect($entries)->toHaveCount(3)
        ->and($entries->pluck('ticketId')->all())->toBe($tickets->pluck('id')->sort()->values()->all());
});

it('carries status, rotation counter, and updated_at for each ticket', function (): void {
    [$eventId, $tickets] = listEventTicketsFixture($this->tenantId, 1, [
        'status' => TicketStatus::Canceled,
        'qr_rotation_counter' => 3,
    ]);

    $entries = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => (app(ListEventTickets::class))($eventId),
    );

    $ticket = $tickets->first();
    $entry = $entries->first();

    expect($entry->ticketId)->toBe($ticket->id)
        ->and($entry->status)->toBe('canceled')
        ->and($entry->rotationCounter)->toBe(3)
        ->and($entry->updatedAt)->toBeString();
});

it('returns an empty collection for an event with no tickets', function (): void {
    $eventId = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::factory()->create(['tenant_id' => $this->tenantId])->id,
    );

    $entries = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => (app(ListEventTickets::class))($eventId),
    );

    expect($entries)->toHaveCount(0);
});
