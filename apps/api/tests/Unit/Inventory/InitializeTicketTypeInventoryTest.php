<?php

use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Inventory\Actions\InitializeTicketTypeInventory;
use App\Inventory\Models\TicketTypeInventory;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-06 plan, task breakdown item 2: unit coverage for
 * InitializeTicketTypeInventory, mirroring
 * tests/Unit/EventCatalog/CreateTicketTypeTest.php's own structure.
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

it('creates a counter row with the given quantity', function () {
    $inventory = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(InitializeTicketTypeInventory::class)($this->tenantId, $this->ticketType->id, 250),
    );

    expect($inventory)->toBeInstanceOf(TicketTypeInventory::class)
        ->and($inventory->tenant_id)->toBe($this->tenantId)
        ->and($inventory->ticket_type_id)->toBe($this->ticketType->id)
        ->and($inventory->quantity)->toBe(250)
        ->and($inventory->sold)->toBe(0)
        ->and($inventory->held)->toBe(0);

    $row = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $this->ticketType->id)->first(),
    );

    expect($row)->not->toBeNull()
        ->and($row->quantity)->toBe(250);
});
