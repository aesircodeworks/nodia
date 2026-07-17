<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Inventory\Actions\CommitHold;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CommitHoldData;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Enums\HoldStatus;
use App\Inventory\Exceptions\HoldNotCommittableException;
use App\Inventory\Exceptions\HoldNotFoundException;
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
 * Stage-06 plan, TDD sequencing Slice 4, task breakdown item 8: CommitHold
 * unit coverage. Exit criteria 3 and 5.
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
function commitHoldFixture(string $tenantId, int $quantity = 10, int $held = 3): array
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

it('commits an active hold, moving held to sold per item, and records no Inventory event', function (): void {
    ['ticketTypeId' => $ticketTypeId, 'holdId' => $holdId] = commitHoldFixture($this->tenantId, held: 3);

    app(TenantTransaction::class)->asTenant($this->tenantId, fn () => app(CommitHold::class)(new CommitHoldData($holdId)));

    $hold = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => Hold::query()->findOrFail($holdId));
    $inventory = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $ticketTypeId)->first(),
    );
    $eventCount = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('tenant_id', $this->tenantId)->count(),
    );

    expect($hold->status)->toBe(HoldStatus::Committed)
        ->and($inventory->held)->toBe(0)
        ->and($inventory->sold)->toBe(3)
        // CreateHold's own HoldCreated row is the only Inventory event expected;
        // commit records nothing further (there is no HoldCommitted).
        ->and($eventCount)->toBe(1);
});

it('refuses a double commit', function (): void {
    ['holdId' => $holdId] = commitHoldFixture($this->tenantId, held: 3);

    app(TenantTransaction::class)->asTenant($this->tenantId, fn () => app(CommitHold::class)(new CommitHoldData($holdId)));

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CommitHold::class)(new CommitHoldData($holdId)),
    );

    expect($invoke)->toThrow(HoldNotCommittableException::class);
});

it('refuses a released hold', function (): void {
    ['holdId' => $holdId] = commitHoldFixture($this->tenantId, held: 3);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($holdId): void {
        DB::table('holds')->where('id', $holdId)->update(['status' => HoldStatus::Released->value]);
    });

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CommitHold::class)(new CommitHoldData($holdId)),
    );

    expect($invoke)->toThrow(HoldNotCommittableException::class);
});

it('refuses an expired-but-unswept hold even though the sweeper has not run', function (): void {
    ['ticketTypeId' => $ticketTypeId, 'holdId' => $holdId] = commitHoldFixture($this->tenantId, held: 3);

    // Advance the fake clock past expires_at without running
    // App\Inventory\Actions\ReleaseExpiredHolds: the hold row is still
    // 'active' in the database, exercising conversion-time validation.
    $hold = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => Hold::query()->findOrFail($holdId));
    test()->travelTo($hold->expires_at->copy()->addMinute());

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CommitHold::class)(new CommitHoldData($holdId)),
    );

    expect($invoke)->toThrow(HoldNotCommittableException::class);

    $stillActive = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => Hold::query()->findOrFail($holdId));
    $inventory = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $ticketTypeId)->first(),
    );

    expect($stillActive->status)->toBe(HoldStatus::Active)
        ->and($inventory->held)->toBe(3)
        ->and($inventory->sold)->toBe(0);
});

it('throws HoldNotFoundException for an unknown hold', function (): void {
    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CommitHold::class)(new CommitHoldData((string) Str::uuid7())),
    );

    expect($invoke)->toThrow(HoldNotFoundException::class);
});

it('rolls back the commit and counter moves together on failure', function (): void {
    ['ticketTypeId' => $ticketTypeId, 'holdId' => $holdId] = commitHoldFixture($this->tenantId, held: 3);

    $invoke = function () use ($holdId): void {
        app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($holdId): void {
            app(CommitHold::class)(new CommitHoldData($holdId));

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
        ->and($inventory->held)->toBe(3)
        ->and($inventory->sold)->toBe(0);
});
