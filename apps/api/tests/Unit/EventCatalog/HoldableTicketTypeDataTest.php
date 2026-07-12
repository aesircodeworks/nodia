<?php

use App\EventCatalog\Data\HoldableTicketTypeData;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-10 plan, Data model "ticket_types.max_per_customer" and TDD
 * Slice 1 (Unit, first): HoldableTicketTypeData::fromModel carries
 * max_per_customer into the read model App\EventCatalog\Actions\
 * ResolveEventForHold hands to Inventory's CreateHold, the only path
 * Inventory may read the field through (system-design 3.1,
 * tests/Architecture/ContextBoundariesTest.php). DB-backed, mirroring
 * TicketTypeModelTest's own precedent, since the value round-trips
 * through a real TicketType row rather than a bare constructor call.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $this->eventId = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::factory()->create(['tenant_id' => $this->tenantId])->id,
    );
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        function (): void {
            TicketType::query()->where('tenant_id', $this->tenantId)->delete();
            Event::query()->where('tenant_id', $this->tenantId)->delete();
        },
    );

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );
});

it('carries an explicit max_per_customer from the model', function () {
    $ticketType = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketType::factory()->create([
            'tenant_id' => $this->tenantId,
            'event_id' => $this->eventId,
            'max_per_customer' => 4,
        ]),
    );

    $data = HoldableTicketTypeData::fromModel($ticketType->fresh());

    expect($data)->toBeInstanceOf(HoldableTicketTypeData::class)
        ->and($data->maxPerCustomer)->toBe(4);
});

it('carries a null max_per_customer meaning unlimited', function () {
    $ticketType = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketType::factory()->create([
            'tenant_id' => $this->tenantId,
            'event_id' => $this->eventId,
        ]),
    );

    $data = HoldableTicketTypeData::fromModel($ticketType->fresh());

    expect($data->maxPerCustomer)->toBeNull();
});
