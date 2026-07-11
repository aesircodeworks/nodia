<?php

use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Inventory\Actions\InitializeTicketTypeInventory;
use App\Inventory\Actions\SetTicketTypeQuantity;
use App\Inventory\Exceptions\InsufficientInventoryException;
use App\Inventory\Models\TicketTypeInventory;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-06 plan, task breakdown item 3: unit coverage for
 * SetTicketTypeQuantity, the Inventory Action Catalog's create/update
 * ticket type Actions delegate an absolute quantity to, mirroring
 * tests/Unit/Inventory/InitializeTicketTypeInventoryTest.php's own
 * structure.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create()->id,
    );

    $this->ticketType = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        function () {
            $event = Event::factory()->create(['tenant_id' => $this->tenantId]);

            return TicketType::factory()->create(['tenant_id' => $this->tenantId, 'event_id' => $event->id]);
        },
    );
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('ticket_type_inventory')->where('tenant_id', $this->tenantId)->delete();
        TicketType::query()->where('tenant_id', $this->tenantId)->delete();
        Event::query()->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );
});

it('creates the counter row when none exists yet', function () {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(SetTicketTypeQuantity::class)($this->tenantId, $this->ticketType->id, 120),
    );

    $row = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $this->ticketType->id)->first(),
    );

    expect($row)->not->toBeNull()
        ->and($row->quantity)->toBe(120)
        ->and($row->held)->toBe(0)
        ->and($row->sold)->toBe(0);
});

it('increases an existing counter row to the given absolute quantity', function () {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(InitializeTicketTypeInventory::class)($this->tenantId, $this->ticketType->id, 50),
    );

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(SetTicketTypeQuantity::class)($this->tenantId, $this->ticketType->id, 90),
    );

    $row = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $this->ticketType->id)->first(),
    );

    expect($row->quantity)->toBe(90);
});

it('decreases an existing counter row when it still covers sold plus held', function () {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        app(InitializeTicketTypeInventory::class)($this->tenantId, $this->ticketType->id, 50);
        TicketTypeInventory::query()->where('ticket_type_id', $this->ticketType->id)->update(['sold' => 10]);
    });

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(SetTicketTypeQuantity::class)($this->tenantId, $this->ticketType->id, 20),
    );

    $row = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $this->ticketType->id)->first(),
    );

    expect($row->quantity)->toBe(20)->and($row->sold)->toBe(10);
});

it('throws InsufficientInventoryException and leaves quantity unchanged when the decrease would undercut sold plus held', function () {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        app(InitializeTicketTypeInventory::class)($this->tenantId, $this->ticketType->id, 50);
        TicketTypeInventory::query()->where('ticket_type_id', $this->ticketType->id)->update(['sold' => 40]);
    });

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(SetTicketTypeQuantity::class)($this->tenantId, $this->ticketType->id, 10),
    );

    expect($invoke)->toThrow(InsufficientInventoryException::class);

    $row = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $this->ticketType->id)->first(),
    );

    expect($row->quantity)->toBe(50);
});

it('is a no-op when the given quantity already matches the stored quantity', function () {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(InitializeTicketTypeInventory::class)($this->tenantId, $this->ticketType->id, 50),
    );

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(SetTicketTypeQuantity::class)($this->tenantId, $this->ticketType->id, 50),
    );

    $row = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $this->ticketType->id)->first(),
    );

    expect($row->quantity)->toBe(50);
});
