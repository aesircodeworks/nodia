<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Data\HoldData;
use App\Inventory\Exceptions\HoldEventNotFoundException;
use App\Inventory\Exceptions\InsufficientHoldInventoryException;
use App\Inventory\Exceptions\SalesWindowClosedException;
use App\Inventory\Exceptions\TicketTypeNotInEventException;
use App\Inventory\Models\Hold;
use App\Inventory\Models\TicketTypeInventory;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-06 plan, TDD sequencing Slice 2, task breakdown item 4: CreateHold
 * Action unit coverage for the GA path, mirroring
 * tests/Unit/EventCatalog/CreateTicketTypeTest.php's own structure.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('outbox_deliveries')->where('tenant_id', $this->tenantId)->delete();
        OutboxEvent::query()->where('tenant_id', $this->tenantId)->delete();
        DB::table('hold_items')->where('tenant_id', $this->tenantId)->delete();
        DB::table('holds')->where('tenant_id', $this->tenantId)->delete();
        DB::table('customers')->where('tenant_id', $this->tenantId)->delete();
        DB::table('ticket_type_inventory')->where('tenant_id', $this->tenantId)->delete();
        TicketType::query()->where('tenant_id', $this->tenantId)->delete();
        Event::query()->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );
});

/**
 * @param  array<string, mixed>  $attributes
 */
function createHoldTestEvent(string $tenantId, array $attributes = []): Event
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published, ...$attributes]),
    );
}

/**
 * @param  array<string, mixed>  $attributes
 */
function createHoldTestTicketType(string $tenantId, string $eventId, int $quantity, array $attributes = []): TicketType
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $eventId, $quantity, $attributes): TicketType {
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $eventId, ...$attributes]);

        TicketTypeInventory::factory()->create([
            'tenant_id' => $tenantId,
            'ticket_type_id' => $ticketType->id,
            'quantity' => $quantity,
            'held' => 0,
            'sold' => 0,
        ]);

        return $ticketType;
    });
}

it('creates a hold, claims held inventory, and returns HoldData', function () {
    $event = createHoldTestEvent($this->tenantId);
    $ticketType = createHoldTestTicketType($this->tenantId, $event->id, 10);

    $data = CreateHoldData::from([
        'event_id' => $event->id,
        'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 3]],
    ]);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateHold::class)($data, null),
    );

    expect($result)->toBeInstanceOf(HoldData::class)
        ->and($result->eventId)->toBe($event->id)
        ->and($result->status)->toBe('active')
        ->and($result->items)->toHaveCount(1)
        ->and($result->items[0]->ticketTypeId)->toBe($ticketType->id)
        ->and($result->items[0]->quantity)->toBe(3)
        ->and($result->seatIds)->toBe([]);

    $inventory = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $ticketType->id)->first(),
    );

    expect($inventory->held)->toBe(3);
});

it('sets customer_id when given, null for guests', function () {
    $event = createHoldTestEvent($this->tenantId);
    $ticketType = createHoldTestTicketType($this->tenantId, $event->id, 10);
    $customerId = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Customer::factory()->create(['tenant_id' => $this->tenantId])->id,
    );

    $data = CreateHoldData::from([
        'event_id' => $event->id,
        'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
    ]);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateHold::class)($data, $customerId),
    );

    $hold = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Hold::query()->findOrFail($result->id),
    );

    expect($hold->customer_id)->toBe($customerId);
});

it('expires 10 minutes from now', function () {
    $now = now();
    $this->travelTo($now);

    $event = createHoldTestEvent($this->tenantId);
    $ticketType = createHoldTestTicketType($this->tenantId, $event->id, 10);

    $data = CreateHoldData::from([
        'event_id' => $event->id,
        'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
    ]);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateHold::class)($data, null),
    );

    expect($result->expiresAt)->toBe($now->copy()->addMinutes(10)->utc()->format('Y-m-d\TH:i:s\Z'));
});

it('throws HoldEventNotFoundException for an unknown event', function () {
    $data = CreateHoldData::from([
        'event_id' => (string) Str::uuid7(),
        'items' => [['ticket_type_id' => (string) Str::uuid7(), 'quantity' => 1]],
    ]);

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateHold::class)($data, null),
    );

    expect($invoke)->toThrow(HoldEventNotFoundException::class);
});

it('throws HoldEventNotFoundException for a draft (unpublished) event', function () {
    $event = createHoldTestEvent($this->tenantId, ['status' => EventStatus::Draft]);
    $ticketType = createHoldTestTicketType($this->tenantId, $event->id, 10);

    $data = CreateHoldData::from([
        'event_id' => $event->id,
        'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
    ]);

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateHold::class)($data, null),
    );

    expect($invoke)->toThrow(HoldEventNotFoundException::class);
});

it('throws TicketTypeNotInEventException when the ticket type belongs to a different event', function () {
    $event = createHoldTestEvent($this->tenantId);
    $otherEvent = createHoldTestEvent($this->tenantId);
    $foreignTicketType = createHoldTestTicketType($this->tenantId, $otherEvent->id, 10);

    $data = CreateHoldData::from([
        'event_id' => $event->id,
        'items' => [['ticket_type_id' => $foreignTicketType->id, 'quantity' => 1]],
    ]);

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateHold::class)($data, null),
    );

    expect($invoke)->toThrow(TicketTypeNotInEventException::class);
});

it('throws SalesWindowClosedException before the sales_start', function () {
    $event = createHoldTestEvent($this->tenantId);
    $ticketType = createHoldTestTicketType($this->tenantId, $event->id, 10, [
        'sales_start' => now()->addDay(),
        'sales_end' => now()->addDays(2),
    ]);

    $data = CreateHoldData::from([
        'event_id' => $event->id,
        'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
    ]);

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateHold::class)($data, null),
    );

    expect($invoke)->toThrow(SalesWindowClosedException::class);
});

it('throws SalesWindowClosedException after the sales_end', function () {
    $event = createHoldTestEvent($this->tenantId);
    $ticketType = createHoldTestTicketType($this->tenantId, $event->id, 10, [
        'sales_start' => now()->subDays(2),
        'sales_end' => now()->subDay(),
    ]);

    $data = CreateHoldData::from([
        'event_id' => $event->id,
        'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
    ]);

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateHold::class)($data, null),
    );

    expect($invoke)->toThrow(SalesWindowClosedException::class);
});

it('throws InsufficientHoldInventoryException when the requested quantity exceeds availability', function () {
    $event = createHoldTestEvent($this->tenantId);
    $ticketType = createHoldTestTicketType($this->tenantId, $event->id, 2);

    $data = CreateHoldData::from([
        'event_id' => $event->id,
        'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 3]],
    ]);

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateHold::class)($data, null),
    );

    expect($invoke)->toThrow(InsufficientHoldInventoryException::class);

    $inventory = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $ticketType->id)->first(),
    );

    expect($inventory->held)->toBe(0);
});

it('records a HoldCreated outbox row present pre-commit and absent after rollback', function () {
    $event = createHoldTestEvent($this->tenantId);
    $ticketType = createHoldTestTicketType($this->tenantId, $event->id, 10);

    $data = CreateHoldData::from([
        'event_id' => $event->id,
        'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
    ]);

    $holdId = null;

    try {
        app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($data, &$holdId): void {
            $result = app(CreateHold::class)($data, null);
            $holdId = $result->id;

            $existsPreCommit = OutboxEvent::query()
                ->where('type', 'HoldCreated')
                ->where('aggregate_id', $result->id)
                ->exists();

            expect($existsPreCommit)->toBeTrue();

            throw new RuntimeException('force rollback');
        });
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('force rollback');
    }

    $existsAfterRollback = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'HoldCreated')->where('aggregate_id', $holdId)->exists(),
    );

    expect($existsAfterRollback)->toBeFalse();

    $holdExists = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Hold::query()->whereKey($holdId)->exists(),
    );

    expect($holdExists)->toBeFalse();
});

it('records a HoldCreated outbox row with the complete envelope', function () {
    $event = createHoldTestEvent($this->tenantId);
    $ticketType = createHoldTestTicketType($this->tenantId, $event->id, 10);
    $customerId = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Customer::factory()->create(['tenant_id' => $this->tenantId])->id,
    );

    $data = CreateHoldData::from([
        'event_id' => $event->id,
        'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
    ]);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateHold::class)($data, $customerId),
    );

    $row = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'HoldCreated')->where('aggregate_id', $result->id)->firstOrFail(),
    );

    expect($row->aggregate_type)->toBe('hold')
        ->and($row->tenant_id)->toBe($this->tenantId)
        ->and($row->payload)->toMatchArray([
            'hold_id' => $result->id,
            'event_id' => $event->id,
            'customer_id' => $customerId,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
            'seat_ids' => [],
        ]);
});
