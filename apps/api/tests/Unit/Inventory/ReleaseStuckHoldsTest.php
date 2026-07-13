<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Inventory\Actions\CommitHold;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Actions\ReleaseStuckHolds;
use App\Inventory\Data\CommitHoldData;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Enums\HoldStatus;
use App\Inventory\Models\Hold;
use App\Inventory\Models\TicketTypeInventory;
use App\Support\Audit\Models\ActivityLogEntry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-12 plan, Slice 5, task breakdown item 13: holds:release-stuck's
 * core Action. candidates() lists status active holds past expires_at
 * only, never a committed one; release() reuses App\Inventory\Actions
 * \ReleaseHold for the actual transition and always activity-logs.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('outbox_deliveries')->where('tenant_id', $this->tenantId)->delete();
        DB::table('outbox_events')->where('tenant_id', $this->tenantId)->delete();
        DB::table('hold_items')->where('tenant_id', $this->tenantId)->delete();
        DB::table('holds')->where('tenant_id', $this->tenantId)->delete();
        DB::table('ticket_type_inventory')->where('tenant_id', $this->tenantId)->delete();
        TicketType::query()->where('tenant_id', $this->tenantId)->delete();
        Event::query()->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        ActivityLogEntry::query()->where('event', 'holds_release_stuck_invoked')->delete();
        Tenant::query()->whereKey($this->tenantId)->delete();
    });
});

/**
 * @return array{ticketTypeId: string, holdId: string}
 */
function stuckHoldFixture(string $tenantId, int $quantity = 10, int $held = 3): array
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

it('candidates() lists an active hold past expires_at and excludes one not yet expired', function (): void {
    $now = now();
    test()->travelTo($now);

    ['holdId' => $stuckHoldId] = stuckHoldFixture($this->tenantId, held: 3);
    ['holdId' => $freshHoldId] = stuckHoldFixture($this->tenantId, held: 2);

    test()->travelTo($now->copy()->addMinutes(11));

    // Re-create the fresh hold's expiry after time travel so it is not
    // itself past due; only the first hold should ever be a candidate.
    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($freshHoldId): void {
        DB::table('holds')->where('id', $freshHoldId)->update(['expires_at' => now()->addMinutes(10)]);
    });

    $candidates = app(ReleaseStuckHolds::class)->candidates();

    expect($candidates->pluck('id')->all())->toBe([$stuckHoldId]);
});

it('candidates() never lists a committed hold, even long past its expires_at', function (): void {
    $now = now();
    test()->travelTo($now);

    ['holdId' => $holdId] = stuckHoldFixture($this->tenantId, held: 3);

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CommitHold::class)(CommitHoldData::from(['holdId' => $holdId])),
    );

    test()->travelTo($now->copy()->addMinutes(11));

    $candidates = app(ReleaseStuckHolds::class)->candidates();

    expect($candidates->pluck('id')->all())->not->toContain($holdId);
});

it('candidates() bounds by tenant when given', function (): void {
    $now = now();
    test()->travelTo($now);

    $otherTenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    ['holdId' => $ownHoldId] = stuckHoldFixture($this->tenantId, held: 3);
    ['holdId' => $otherHoldId] = stuckHoldFixture($otherTenantId, held: 3);

    test()->travelTo($now->copy()->addMinutes(11));

    $candidates = app(ReleaseStuckHolds::class)->candidates([$this->tenantId]);

    expect($candidates->pluck('id')->all())->toBe([$ownHoldId])
        ->and($candidates->pluck('id')->all())->not->toContain($otherHoldId);

    app(TenantTransaction::class)->asTenant($otherTenantId, function () use ($otherTenantId): void {
        DB::table('outbox_deliveries')->where('tenant_id', $otherTenantId)->delete();
        DB::table('outbox_events')->where('tenant_id', $otherTenantId)->delete();
        DB::table('hold_items')->where('tenant_id', $otherTenantId)->delete();
        DB::table('holds')->where('tenant_id', $otherTenantId)->delete();
        DB::table('ticket_type_inventory')->where('tenant_id', $otherTenantId)->delete();
        TicketType::query()->where('tenant_id', $otherTenantId)->delete();
        Event::query()->where('tenant_id', $otherTenantId)->delete();
    });
    app(TenantTransaction::class)->asPlatform(fn () => Tenant::query()->whereKey($otherTenantId)->delete());
});

it('release(execute: false) issues no writes yet still records an activity log entry', function (): void {
    $now = now();
    test()->travelTo($now);

    ['ticketTypeId' => $ticketTypeId, 'holdId' => $holdId] = stuckHoldFixture($this->tenantId, held: 3);

    test()->travelTo($now->copy()->addMinutes(11));

    $summary = app(ReleaseStuckHolds::class)->release('dry-run-unit', false, []);

    $hold = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => Hold::query()->findOrFail($holdId));
    $inventory = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $ticketTypeId)->first(),
    );

    expect($summary->holds->pluck('id')->all())->toContain($holdId)
        ->and($summary->released)->toBe(0)
        ->and($hold->status)->toBe(HoldStatus::Active)
        ->and($inventory->held)->toBe(3);

    $entry = app(TenantTransaction::class)->asPlatform(
        fn () => ActivityLogEntry::query()
            ->where('event', 'holds_release_stuck_invoked')
            ->where('properties->operator', 'dry-run-unit')
            ->latest('created_at')
            ->first(),
    );

    expect($entry)->not->toBeNull()
        ->and($entry->properties['execute'])->toBeFalse()
        ->and($entry->properties['holds_released'])->toBe(0);
});

it('release(execute: true) releases every matched hold through ReleaseHold and recovers availability exactly', function (): void {
    $now = now();
    test()->travelTo($now);

    ['ticketTypeId' => $ticketTypeId, 'holdId' => $holdId] = stuckHoldFixture($this->tenantId, held: 3);

    test()->travelTo($now->copy()->addMinutes(11));

    $summary = app(ReleaseStuckHolds::class)->release('execute-unit', true, []);

    $hold = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => Hold::query()->findOrFail($holdId));
    $inventory = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $ticketTypeId)->first(),
    );

    expect($summary->holds->count())->toBe(1)
        ->and($summary->released)->toBe(1)
        ->and($hold->status)->toBe(HoldStatus::Released)
        ->and($inventory->held)->toBe(0)
        ->and($inventory->quantity - $inventory->sold - $inventory->held)->toBe($inventory->quantity);

    $entry = app(TenantTransaction::class)->asPlatform(
        fn () => ActivityLogEntry::query()
            ->where('event', 'holds_release_stuck_invoked')
            ->where('properties->operator', 'execute-unit')
            ->latest('created_at')
            ->first(),
    );

    expect($entry)->not->toBeNull()
        ->and($entry->properties['execute'])->toBeTrue()
        ->and($entry->properties['holds_matched'])->toBe(1)
        ->and($entry->properties['holds_released'])->toBe(1);
});
