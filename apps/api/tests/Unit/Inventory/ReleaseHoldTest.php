<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Actions\ReleaseHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Enums\HoldStatus;
use App\Inventory\Exceptions\HoldInventoryReleaseFailedException;
use App\Inventory\Exceptions\HoldNotFoundException;
use App\Inventory\Exceptions\HoldNotReleasableException;
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
 * Stage-06 plan, TDD sequencing Slice 3, task breakdown item 5: ReleaseHold
 * Action unit coverage, mirroring tests/Unit/Inventory/CreateHoldTest.php's
 * own structure.
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
function releaseHoldFixture(string $tenantId, int $quantity = 10, int $held = 3): array
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

it('releases an active hold, decrements held, and records HoldReleased', function (): void {
    ['ticketTypeId' => $ticketTypeId, 'holdId' => $holdId] = releaseHoldFixture($this->tenantId, held: 3);

    app(TenantTransaction::class)->asTenant($this->tenantId, fn () => app(ReleaseHold::class)($holdId));

    $hold = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => Hold::query()->findOrFail($holdId));
    $inventory = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $ticketTypeId)->first(),
    );
    $event = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('tenant_id', $this->tenantId)->where('type', 'HoldReleased')->first(),
    );

    expect($hold->status)->toBe(HoldStatus::Released)
        ->and($inventory->held)->toBe(0)
        ->and($event)->not->toBeNull()
        ->and($event->payload['hold_id'])->toBe($holdId);
});

it('is idempotent: releasing an already-released hold is a no-op, no second event', function (): void {
    ['holdId' => $holdId] = releaseHoldFixture($this->tenantId, held: 3);

    app(TenantTransaction::class)->asTenant($this->tenantId, fn () => app(ReleaseHold::class)($holdId));
    app(TenantTransaction::class)->asTenant($this->tenantId, fn () => app(ReleaseHold::class)($holdId));

    $eventCount = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('tenant_id', $this->tenantId)->where('type', 'HoldReleased')->count(),
    );

    expect($eventCount)->toBe(1);
});

it('is idempotent: releasing an already-expired hold is a no-op, no HoldReleased', function (): void {
    ['ticketTypeId' => $ticketTypeId, 'holdId' => $holdId] = releaseHoldFixture($this->tenantId, held: 3);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($holdId): void {
        DB::table('holds')->where('id', $holdId)->update(['status' => HoldStatus::Expired->value]);
    });

    app(TenantTransaction::class)->asTenant($this->tenantId, fn () => app(ReleaseHold::class)($holdId));

    $inventory = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $ticketTypeId)->first(),
    );
    $eventCount = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('tenant_id', $this->tenantId)->where('type', 'HoldReleased')->count(),
    );

    expect($inventory->held)->toBe(3)
        ->and($eventCount)->toBe(0);
});

it('throws HoldNotReleasableException for a committed hold', function (): void {
    ['holdId' => $holdId] = releaseHoldFixture($this->tenantId, held: 3);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($holdId): void {
        DB::table('holds')->where('id', $holdId)->update(['status' => HoldStatus::Committed->value]);
    });

    $invoke = fn () => app(TenantTransaction::class)->asTenant($this->tenantId, fn () => app(ReleaseHold::class)($holdId));

    expect($invoke)->toThrow(HoldNotReleasableException::class);
});

it('throws HoldNotFoundException for an unknown hold', function (): void {
    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(ReleaseHold::class)((string) Str::uuid7()),
    );

    expect($invoke)->toThrow(HoldNotFoundException::class);
});

it('rolls back and records no HoldReleased when the counter holds fewer units than the hold', function (): void {
    ['ticketTypeId' => $ticketTypeId, 'holdId' => $holdId] = releaseHoldFixture($this->tenantId, held: 3);

    // Corrupt the counter so the held-decrement guard (held >= 3) matches no
    // row: the release must roll the whole transaction back rather than
    // recording HoldReleased against inconsistent inventory.
    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($ticketTypeId): void {
        TicketTypeInventory::query()->where('ticket_type_id', $ticketTypeId)->update(['held' => 1]);
    });

    $invoke = fn () => app(TenantTransaction::class)->asTenant($this->tenantId, fn () => app(ReleaseHold::class)($holdId));

    expect($invoke)->toThrow(HoldInventoryReleaseFailedException::class);

    $hold = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => Hold::query()->findOrFail($holdId));
    $eventCount = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('tenant_id', $this->tenantId)->where('type', 'HoldReleased')->count(),
    );

    expect($hold->status)->toBe(HoldStatus::Active)
        ->and($eventCount)->toBe(0);
});

it('rolls back the release and the outbox row together on failure', function (): void {
    ['ticketTypeId' => $ticketTypeId, 'holdId' => $holdId] = releaseHoldFixture($this->tenantId, held: 3);

    $invoke = function () use ($holdId): void {
        app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($holdId): void {
            app(ReleaseHold::class)($holdId);

            throw new RuntimeException('forced rollback');
        });
    };

    expect($invoke)->toThrow(RuntimeException::class);

    $hold = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => Hold::query()->findOrFail($holdId));
    $inventory = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $ticketTypeId)->first(),
    );

    expect($hold->status)->toBe(HoldStatus::Active)
        ->and($inventory->held)->toBe(3);
});
