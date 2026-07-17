<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Inventory\Actions\GetEventAvailability;
use App\Inventory\Exceptions\HoldEventNotFoundException;
use App\Inventory\Models\TicketTypeInventory;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-06 plan, TDD sequencing Slice 3, task breakdown item 7: unit
 * coverage for GetEventAvailability's own arithmetic and on_sale
 * boundary invariants, mirroring
 * tests/Unit/Inventory/InitializeTicketTypeInventoryTest.php's own
 * structure.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create()->id,
    );
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('ticket_type_inventory')->where('tenant_id', $this->tenantId)->delete();
        DB::table('ticket_types')->where('tenant_id', $this->tenantId)->delete();
        DB::table('events')->where('tenant_id', $this->tenantId)->delete();
    });
});

/**
 * @param  array<string, mixed>  $ticketTypeAttributes
 */
function unitAvailabilityTicketType(string $tenantId, string $eventId, int $quantity, int $sold, int $held, array $ticketTypeAttributes = []): TicketType
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $eventId, $quantity, $sold, $held, $ticketTypeAttributes): TicketType {
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $eventId, ...$ticketTypeAttributes]);

        TicketTypeInventory::factory()->create([
            'tenant_id' => $tenantId,
            'ticket_type_id' => $ticketType->id,
            'quantity' => $quantity,
            'sold' => $sold,
            'held' => $held,
        ]);

        return $ticketType;
    });
}

it('computes available as quantity minus sold minus held', function (): void {
    $event = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::factory()->create(['tenant_id' => $this->tenantId, 'status' => EventStatus::Published]),
    );
    unitAvailabilityTicketType($this->tenantId, $event->id, quantity: 20, sold: 5, held: 3);

    $availability = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(GetEventAvailability::class)($event->id),
    );

    expect($availability->ticketTypes[0]->available)->toBe(12);
});

it('reports zero available when sold plus held exhausts quantity', function (): void {
    $event = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::factory()->create(['tenant_id' => $this->tenantId, 'status' => EventStatus::Published]),
    );
    unitAvailabilityTicketType($this->tenantId, $event->id, quantity: 5, sold: 3, held: 2);

    $availability = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(GetEventAvailability::class)($event->id),
    );

    expect($availability->ticketTypes[0]->available)->toBe(0);
});

it('reports on_sale true when now is exactly at sales_start or sales_end', function (): void {
    $event = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::factory()->create(['tenant_id' => $this->tenantId, 'status' => EventStatus::Published]),
    );
    $now = now();
    $this->travelTo($now);
    unitAvailabilityTicketType($this->tenantId, $event->id, quantity: 5, sold: 0, held: 0, ticketTypeAttributes: [
        'sales_start' => $now,
        'sales_end' => $now->copy()->addHour(),
    ]);

    $availability = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(GetEventAvailability::class)($event->id),
    );

    expect($availability->ticketTypes[0]->onSale)->toBeTrue();
});

it('reports on_sale false before sales_start and after sales_end', function (): void {
    $event = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::factory()->create(['tenant_id' => $this->tenantId, 'status' => EventStatus::Published]),
    );
    unitAvailabilityTicketType($this->tenantId, $event->id, quantity: 5, sold: 0, held: 0, ticketTypeAttributes: [
        'sales_start' => now()->addDay(),
        'sales_end' => now()->addDays(2),
    ]);

    $availability = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(GetEventAvailability::class)($event->id),
    );

    expect($availability->ticketTypes[0]->onSale)->toBeFalse();

    $this->travelTo(now()->addDays(3));

    $availabilityAfter = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(GetEventAvailability::class)($event->id),
    );

    expect($availabilityAfter->ticketTypes[0]->onSale)->toBeFalse();
});

it('throws HoldEventNotFoundException for an unknown event', function (): void {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(GetEventAvailability::class)((string) Str::uuid7()),
    );
})->throws(HoldEventNotFoundException::class);

it('throws HoldEventNotFoundException for a draft (unpublished) event', function (): void {
    $event = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::factory()->create(['tenant_id' => $this->tenantId, 'status' => EventStatus::Draft]),
    );

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(GetEventAvailability::class)($event->id),
    );
})->throws(HoldEventNotFoundException::class);
