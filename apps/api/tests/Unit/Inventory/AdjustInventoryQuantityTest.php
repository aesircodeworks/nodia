<?php

use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Inventory\Actions\AdjustInventoryQuantity;
use App\Inventory\Actions\InitializeTicketTypeInventory;
use App\Inventory\Exceptions\InsufficientInventoryException;
use App\Inventory\Models\TicketTypeInventory;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-06 plan, task breakdown item 2: unit coverage for
 * AdjustInventoryQuantity ("increase always succeeds; decrease below
 * sold + held affects zero rows and throws the typed domain exception").
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

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(InitializeTicketTypeInventory::class)($this->tenantId, $this->ticketType->id, 100),
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

function inventoryRow(string $tenantId, string $ticketTypeId): TicketTypeInventory
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $ticketTypeId)->firstOrFail(),
    );
}

it('always succeeds on an increase', function () {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(AdjustInventoryQuantity::class)($this->ticketType->id, 50),
    );

    expect(inventoryRow($this->tenantId, $this->ticketType->id)->quantity)->toBe(150);
});

it('applies a decrease that still covers sold and held', function () {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('ticket_type_inventory')
            ->where('ticket_type_id', $this->ticketType->id)
            ->update(['sold' => 10, 'held' => 5]);
    });

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(AdjustInventoryQuantity::class)($this->ticketType->id, -20),
    );

    expect(inventoryRow($this->tenantId, $this->ticketType->id)->quantity)->toBe(80);
});

it('throws InsufficientInventoryException and leaves the row unchanged when a decrease would go below sold plus held', function () {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('ticket_type_inventory')
            ->where('ticket_type_id', $this->ticketType->id)
            ->update(['sold' => 40, 'held' => 40]);
    });

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(AdjustInventoryQuantity::class)($this->ticketType->id, -30),
    );

    expect($invoke)->toThrow(InsufficientInventoryException::class);

    expect(inventoryRow($this->tenantId, $this->ticketType->id)->quantity)->toBe(100);
});
