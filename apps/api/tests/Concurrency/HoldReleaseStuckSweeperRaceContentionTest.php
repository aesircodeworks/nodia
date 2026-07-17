<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Actions\ReleaseExpiredHolds;
use App\Inventory\Actions\ReleaseStuckHolds;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Enums\HoldStatus;
use App\Inventory\Models\Hold;
use App\Inventory\Models\TicketTypeInventory;
use App\Support\Audit\Models\ActivityLogEntry;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;

/**
 * Stage-12 plan, Slice 5, task breakdown item 13: the manual
 * holds:release-stuck command (App\Inventory\Actions\ReleaseStuckHolds,
 * --execute) racing the standing Stage 6 every-minute expiry sweeper
 * (App\Inventory\Actions\ReleaseExpiredHolds) for the same stuck hold.
 * Both paths ultimately share App\Inventory\Actions\ReleaseHold's own
 * conditional active -> released UPDATE and
 * App\Inventory\Actions\Concerns\ReleasesHoldInventory's own guarded
 * counter decrement (checked by affected-row count, master plan
 * test-first rule 2), so exactly one of HoldReleased or HoldExpired is
 * ever recorded and the held counter is decremented exactly once,
 * mirroring tests/Concurrency/HoldExpiryRecoveryContentionTest.php's own
 * proof for an explicit DELETE-endpoint release racing the sweeper.
 */
beforeEach(function (): void {
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ($tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
            DB::table('hold_items')->where('tenant_id', $tenantId)->delete();
            DB::table('holds')->where('tenant_id', $tenantId)->delete();
            DB::table('ticket_type_inventory')->where('tenant_id', $tenantId)->delete();
            DB::table('ticket_types')->where('tenant_id', $tenantId)->delete();
            DB::table('events')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        ActivityLogEntry::query()->where('event', 'holds_release_stuck_invoked')->delete();
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

it('releases a stuck hold exactly once when holds:release-stuck races the expiry sweeper', function (): void {
    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $now = now();
    Date::setTestNow($now);

    $holdId = app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): string {
        $event = Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id]);

        TicketTypeInventory::factory()->create([
            'tenant_id' => $tenantId,
            'ticket_type_id' => $ticketType->id,
            'quantity' => 5,
            'held' => 0,
            'sold' => 0,
        ]);

        return app(CreateHold::class)(
            CreateHoldData::from([
                'event_id' => $event->id,
                'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 5]],
            ]),
            null,
        )->id;
    });

    Date::setTestNow($now->copy()->addMinutes(11));

    ParallelRunner::runEach(
        fn (): mixed => Date::setTestNow($now->copy()->addMinutes(11)) ?? app(ReleaseStuckHolds::class)->release(
            'race-operator',
            true,
            [$tenantId],
        ),
        fn (): int => Date::setTestNow($now->copy()->addMinutes(11)) ?? app(ReleaseExpiredHolds::class)(),
    );

    $hold = app(TenantTransaction::class)->asTenant($tenantId, fn () => Hold::query()->findOrFail($holdId));
    $inventory = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', DB::table('hold_items')->where('hold_id', $holdId)->value('ticket_type_id'))->first(),
    );
    $releasedCount = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => OutboxEvent::query()->where('tenant_id', $tenantId)->where('type', 'HoldReleased')->count(),
    );
    $expiredCount = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => OutboxEvent::query()->where('tenant_id', $tenantId)->where('type', 'HoldExpired')->count(),
    );

    expect($hold->status)->toBeIn([HoldStatus::Released, HoldStatus::Expired])
        ->and($inventory->held)->toBe(0)
        ->and($inventory->quantity - $inventory->sold - $inventory->held)->toBe($inventory->quantity)
        ->and($releasedCount + $expiredCount)->toBe(1);

    Date::setTestNow();
});
