<?php

use App\EventCatalog\Actions\UpdateTicketType;
use App\EventCatalog\Data\TicketTypeData;
use App\EventCatalog\Data\UpdateTicketTypeData;
use App\EventCatalog\Exceptions\CurrencyMismatchException;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Inventory\Actions\InitializeTicketTypeInventory;
use App\Inventory\Exceptions\InsufficientInventoryException;
use App\Inventory\Models\TicketTypeInventory;
use App\Support\Money\Money;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-05a plan, task breakdown item 8: UpdateTicketType Action unit
 * coverage, mirroring tests/Unit/EventCatalog/UpdateEventTest.php's own
 * structure.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create(['settlement_currency' => 'USD'])->id,
    );

    $this->event = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::factory()->create(['tenant_id' => $this->tenantId]),
    );

    $this->ticketType = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketType::factory()->create([
            'tenant_id' => $this->tenantId,
            'event_id' => $this->event->id,
            'name' => 'Before',
            'price' => Money::of(5000, 'USD'),
        ]),
    );
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        // outbox_deliveries before outbox_events: App\EventCatalog\Jobs\
        // RefreshSearchIndex now subscribes to EventUpdated (stage-05c
        // plan, task breakdown item 7), and UpdateTicketType records that
        // type for its parent event, so a delivery row exists here and its
        // outbox_event_id foreign key blocks the parent delete otherwise,
        // mirroring tests/Feature/EventCatalog/
        // TicketTypeEventUpdatedOutboxTest.php's own cleanup order.
        DB::table('outbox_deliveries')->where('tenant_id', $this->tenantId)->delete();
        OutboxEvent::query()->where('tenant_id', $this->tenantId)->delete();
        DB::table('ticket_type_inventory')->where('tenant_id', $this->tenantId)->delete();
        TicketType::query()->where('tenant_id', $this->tenantId)->delete();
        Event::query()->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );
});

it('updates only the given fields, returning the refreshed TicketTypeData', function () {
    $data = UpdateTicketTypeData::from(['name' => 'After']);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateTicketType::class)($this->ticketType, $data),
    );

    expect($result)->toBeInstanceOf(TicketTypeData::class)
        ->and($result->name)->toBe('After')
        ->and($result->price->amount)->toBe(5000)
        ->and($result->price->currency)->toBe('USD');
});

it('updates the price when given', function () {
    $data = UpdateTicketTypeData::from(['price' => ['amount' => 7500, 'currency' => 'USD']]);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateTicketType::class)($this->ticketType, $data),
    );

    expect($result->price->amount)->toBe(7500)
        ->and($result->price->currency)->toBe('USD');
});

it('throws CurrencyMismatchException when the updated price currency differs from the tenant settlement currency', function () {
    $data = UpdateTicketTypeData::from(['price' => ['amount' => 7500, 'currency' => 'EUR']]);

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateTicketType::class)($this->ticketType, $data),
    );

    expect($invoke)->toThrow(CurrencyMismatchException::class);

    $stored = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketType::query()->find($this->ticketType->id),
    );

    expect($stored->price->currency)->toBe('USD');
});

it('records an EventUpdated outbox row for the parent event on every successful call', function () {
    $data = UpdateTicketTypeData::from(['name' => 'After']);

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateTicketType::class)($this->ticketType, $data),
    );

    $rows = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventUpdated')->where('aggregate_id', $this->event->id)->get(),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->payload)->toBe(['event_id' => $this->event->id]);
});

it('sets the counter row to the given quantity when none existed yet', function () {
    $data = UpdateTicketTypeData::from(['quantity' => 100]);

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateTicketType::class)($this->ticketType, $data),
    );

    $inventory = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $this->ticketType->id)->first(),
    );

    expect($inventory)->not->toBeNull()->and($inventory->quantity)->toBe(100);
});

it('adjusts an existing counter row to the given absolute quantity', function () {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(InitializeTicketTypeInventory::class)($this->tenantId, $this->ticketType->id, 50),
    );

    $data = UpdateTicketTypeData::from(['quantity' => 80]);

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateTicketType::class)($this->ticketType, $data),
    );

    $inventory = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $this->ticketType->id)->first(),
    );

    expect($inventory->quantity)->toBe(80);
});

it('throws InsufficientInventoryException when the given quantity would undercut sold plus held', function () {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        app(InitializeTicketTypeInventory::class)($this->tenantId, $this->ticketType->id, 50);
        TicketTypeInventory::query()->where('ticket_type_id', $this->ticketType->id)->update(['sold' => 40]);
    });

    $data = UpdateTicketTypeData::from(['quantity' => 10]);

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateTicketType::class)($this->ticketType, $data),
    );

    expect($invoke)->toThrow(InsufficientInventoryException::class);
});

it('leaves the counter row untouched when quantity is absent from the payload', function () {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(InitializeTicketTypeInventory::class)($this->tenantId, $this->ticketType->id, 50),
    );

    $data = UpdateTicketTypeData::from(['name' => 'Renamed']);

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateTicketType::class)($this->ticketType, $data),
    );

    $inventory = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $this->ticketType->id)->first(),
    );

    expect($inventory->quantity)->toBe(50);
});

it('resets the counter quantity to zero when converting a GA ticket type to requires_seat', function () {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(InitializeTicketTypeInventory::class)($this->tenantId, $this->ticketType->id, 50),
    );

    $data = UpdateTicketTypeData::from(['requires_seat' => true]);

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateTicketType::class)($this->ticketType, $data),
    );

    $inventory = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $this->ticketType->id)->first(),
    );

    expect($inventory)->not->toBeNull()->and($inventory->quantity)->toBe(0);
});

it('throws a validation exception for a quantity given on a requires_seat ticket type', function () {
    $seatedTicketType = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketType::factory()->create([
            'tenant_id' => $this->tenantId,
            'event_id' => $this->event->id,
            'requires_seat' => true,
        ]),
    );

    $data = UpdateTicketTypeData::from(['quantity' => 100]);

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateTicketType::class)($seatedTicketType, $data),
    );

    expect($invoke)->toThrow(ValidationException::class);
});

it('throws a validation exception for a quantity given alongside requires_seat true in the same payload', function () {
    $data = UpdateTicketTypeData::from(['requires_seat' => true, 'quantity' => 100]);

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateTicketType::class)($this->ticketType, $data),
    );

    expect($invoke)->toThrow(ValidationException::class);

    $inventory = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $this->ticketType->id)->first(),
    );

    expect($inventory)->toBeNull();
});
