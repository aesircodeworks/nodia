<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Actions\ReleaseExpiredHolds;
use App\Inventory\Actions\ReleaseHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Enums\HoldStatus;
use App\Inventory\Models\Hold;
use App\Inventory\Models\TicketTypeInventory;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-06 plan, TDD sequencing Slice 3, task breakdown item 6: fake-clock
 * TTL matrix for ReleaseExpiredHolds (not yet expired, exactly at
 * expires_at, long past), counter reconciliation, HoldExpired recording.
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
        DB::table('ticket_type_inventory')->where('tenant_id', $this->tenantId)->delete();
        TicketType::query()->where('tenant_id', $this->tenantId)->delete();
        Event::query()->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );
});

/**
 * @return array{ticketTypeId: string, holdId: string}
 */
function sweeperHoldFixture(string $tenantId, int $quantity = 10, int $held = 3): array
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $quantity, $held): array {
        $event = Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id]);

        TicketTypeInventory::factory()->create([
            'tenant_id' => $tenantId,
            'ticket_type_id' => $ticketType->id,
            'quantity' => $quantity,
            'held' => 0,
            'sold' => 0,
        ]);

        $hold = app(CreateHold::class)(
            CreateHoldData::from([
                'event_id' => $event->id,
                'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => $held]],
            ]),
            null,
        );

        return ['ticketTypeId' => $ticketType->id, 'holdId' => $hold->id];
    });
}

it('leaves a hold well before its expires_at untouched', function (): void {
    $now = now();
    test()->travelTo($now);

    ['ticketTypeId' => $ticketTypeId, 'holdId' => $holdId] = sweeperHoldFixture($this->tenantId, held: 3);

    test()->travelTo($now->copy()->addMinutes(5));

    $expired = app(ReleaseExpiredHolds::class)();

    $hold = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => Hold::query()->findOrFail($holdId));
    $inventory = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $ticketTypeId)->first(),
    );

    expect($expired)->toBe(0)
        ->and($hold->status)->toBe(HoldStatus::Active)
        ->and($inventory->held)->toBe(3);
});

it('expires a hold exactly at its expires_at and releases its counters', function (): void {
    $now = now();
    test()->travelTo($now);

    ['ticketTypeId' => $ticketTypeId, 'holdId' => $holdId] = sweeperHoldFixture($this->tenantId, held: 3);

    test()->travelTo($now->copy()->addMinutes(10));

    $expired = app(ReleaseExpiredHolds::class)();

    $hold = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => Hold::query()->findOrFail($holdId));
    $inventory = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $ticketTypeId)->first(),
    );
    $event = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('tenant_id', $this->tenantId)->where('type', 'HoldExpired')->first(),
    );

    expect($expired)->toBe(1)
        ->and($hold->status)->toBe(HoldStatus::Expired)
        ->and($inventory->held)->toBe(0)
        ->and($event)->not->toBeNull()
        ->and($event->payload['hold_id'])->toBe($holdId);
});

it('expires a hold long past its expires_at', function (): void {
    $now = now();
    test()->travelTo($now);

    ['ticketTypeId' => $ticketTypeId, 'holdId' => $holdId] = sweeperHoldFixture($this->tenantId, held: 3);

    test()->travelTo($now->copy()->addDays(3));

    $expired = app(ReleaseExpiredHolds::class)();

    $hold = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => Hold::query()->findOrFail($holdId));
    $inventory = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $ticketTypeId)->first(),
    );

    expect($expired)->toBe(1)
        ->and($hold->status)->toBe(HoldStatus::Expired)
        ->and($inventory->held)->toBe(0);
});

it('is idempotent across two sweeper runs: only the first expires and records an event', function (): void {
    $now = now();
    test()->travelTo($now);

    ['holdId' => $holdId] = sweeperHoldFixture($this->tenantId, held: 3);

    test()->travelTo($now->copy()->addMinutes(11));

    $first = app(ReleaseExpiredHolds::class)();
    $second = app(ReleaseExpiredHolds::class)();

    $eventCount = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('tenant_id', $this->tenantId)->where('type', 'HoldExpired')->count(),
    );

    expect($first)->toBe(1)
        ->and($second)->toBe(0)
        ->and($eventCount)->toBe(1);
});

it('skips a hold an explicit release already claimed, recording no HoldExpired', function (): void {
    $now = now();
    test()->travelTo($now);

    ['ticketTypeId' => $ticketTypeId, 'holdId' => $holdId] = sweeperHoldFixture($this->tenantId, held: 3);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($holdId): void {
        app(ReleaseHold::class)($holdId);
    });

    test()->travelTo($now->copy()->addMinutes(11));

    $expired = app(ReleaseExpiredHolds::class)();

    $hold = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => Hold::query()->findOrFail($holdId));
    $inventory = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $ticketTypeId)->first(),
    );
    $expiredEventCount = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('tenant_id', $this->tenantId)->where('type', 'HoldExpired')->count(),
    );

    expect($expired)->toBe(0)
        ->and($hold->status)->toBe(HoldStatus::Released)
        ->and($inventory->held)->toBe(0)
        ->and($expiredEventCount)->toBe(0);
});
