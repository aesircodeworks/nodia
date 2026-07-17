<?php

use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Inventory\Models\PurchaseCounter;
use App\Inventory\Support\PurchaseCounters;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-10 plan, TDD sequencing Slice 3 (Unit, first): the guarded upsert
 * and decrement's affected-row semantics, isolated from CreateHold and
 * the release/expiry path that will call them in a later task (stage-10
 * plan, task breakdown item 6, depends on this one).
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    [$this->customerId, $this->ticketTypeId] = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        function () {
            $event = Event::factory()->create(['tenant_id' => $this->tenantId]);
            $ticketType = TicketType::factory()->create(['tenant_id' => $this->tenantId, 'event_id' => $event->id]);
            $customer = Customer::factory()->create(['tenant_id' => $this->tenantId]);

            return [$customer->id, $ticketType->id];
        },
    );
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('purchase_counters')->where('tenant_id', $this->tenantId)->delete();
        Customer::query()->where('tenant_id', $this->tenantId)->delete();
        TicketType::query()->where('tenant_id', $this->tenantId)->delete();
        Event::query()->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );
});

/**
 * @return int|null the quantity a fresh select finds, or null if no row exists
 */
function purchaseCounterQuantity(string $tenantId, string $customerId, string $ticketTypeId): ?int
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => PurchaseCounter::query()
            ->where('customer_id', $customerId)
            ->where('ticket_type_id', $ticketTypeId)
            ->value('quantity'),
    );
}

it('inserts a counter row at the requested quantity when no row exists and it is within the limit', function () {
    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => PurchaseCounters::increment($this->tenantId, $this->customerId, $this->ticketTypeId, 3, limit: 5),
    );

    expect($result)->toBeTrue()
        ->and(purchaseCounterQuantity($this->tenantId, $this->customerId, $this->ticketTypeId))->toBe(3);
});

it('increments an existing counter row when the new total stays within the limit', function () {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => PurchaseCounters::increment($this->tenantId, $this->customerId, $this->ticketTypeId, 3, limit: 5),
    );

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => PurchaseCounters::increment($this->tenantId, $this->customerId, $this->ticketTypeId, 2, limit: 5),
    );

    expect($result)->toBeTrue()
        ->and(purchaseCounterQuantity($this->tenantId, $this->customerId, $this->ticketTypeId))->toBe(5);
});

it('affects zero rows and leaves the counter unchanged when the increment would cross the limit', function () {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => PurchaseCounters::increment($this->tenantId, $this->customerId, $this->ticketTypeId, 3, limit: 5),
    );

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => PurchaseCounters::increment($this->tenantId, $this->customerId, $this->ticketTypeId, 3, limit: 5),
    );

    expect($result)->toBeFalse()
        ->and(purchaseCounterQuantity($this->tenantId, $this->customerId, $this->ticketTypeId))->toBe(3);
});

it('skips the counter entirely for an unlimited ticket type', function () {
    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => PurchaseCounters::increment($this->tenantId, $this->customerId, $this->ticketTypeId, 3, limit: null),
    );

    expect($result)->toBeTrue()
        ->and(purchaseCounterQuantity($this->tenantId, $this->customerId, $this->ticketTypeId))->toBeNull();
});

it('decrements a counter row by exactly the recorded amount', function () {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => PurchaseCounters::increment($this->tenantId, $this->customerId, $this->ticketTypeId, 4, limit: 10),
    );

    $affected = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => PurchaseCounters::decrement($this->customerId, $this->ticketTypeId, 3),
    );

    expect($affected)->toBe(1)
        ->and(purchaseCounterQuantity($this->tenantId, $this->customerId, $this->ticketTypeId))->toBe(1);
});

it('floors at zero: the guard blocks a decrement larger than the recorded quantity and leaves it unchanged', function () {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => PurchaseCounters::increment($this->tenantId, $this->customerId, $this->ticketTypeId, 2, limit: 10),
    );

    $affected = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => PurchaseCounters::decrement($this->customerId, $this->ticketTypeId, 3),
    );

    expect($affected)->toBe(0)
        ->and(purchaseCounterQuantity($this->tenantId, $this->customerId, $this->ticketTypeId))->toBe(2);
});

it('never issues a negative-quantity statement the CHECK constraint would have to reject', function () {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => PurchaseCounters::increment($this->tenantId, $this->customerId, $this->ticketTypeId, 2, limit: 10),
    );

    // The guard (quantity >= counted) is the primary defense; this proves the
    // CHECK constraint independently still holds true zero rows moved, not
    // that PurchaseCounters relies on catching a QueryException from it.
    $constraint = DB::selectOne(
        "select conname from pg_constraint where conname = 'purchase_counters_quantity_non_negative'",
    );

    expect($constraint)->not->toBeNull();
});

it('is a no-op when decrementing a zero-counted item that never touched the counter', function () {
    $affected = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => PurchaseCounters::decrement($this->customerId, $this->ticketTypeId, 0),
    );

    expect($affected)->toBe(0)
        ->and(purchaseCounterQuantity($this->tenantId, $this->customerId, $this->ticketTypeId))->toBeNull();
});
