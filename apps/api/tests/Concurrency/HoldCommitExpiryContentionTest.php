<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Inventory\Actions\CommitHold;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Actions\ReleaseExpiredHolds;
use App\Inventory\Data\CommitHoldData;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Enums\HoldStatus;
use App\Inventory\Exceptions\HoldNotCommittableException;
use App\Inventory\Models\Hold;
use App\Inventory\Models\TicketTypeInventory;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;

/**
 * The commit-versus-expiry race (stage-06 plan, TDD sequencing Slice 4;
 * exit criterion 3: "a hold ends committed or expired, never both, with
 * counters consistent under either outcome; on expiry exactly one
 * HoldExpired is recorded, and on commit no Inventory event is recorded
 * ... and never a HoldExpired for the committed hold"), mirroring
 * tests/Concurrency/HoldExpiryRecoveryContentionTest.php's own
 * release-versus-sweeper race.
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
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

it('never leaves a hold both committed and expired when commit races the sweeper', function (): void {
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
        function () use ($now, $tenantId, $holdId): void {
            Date::setTestNow($now->copy()->addMinutes(11));

            try {
                app(TenantTransaction::class)->asTenant($tenantId, fn () => app(CommitHold::class)(new CommitHoldData($holdId)));
            } catch (HoldNotCommittableException) {
                // Lost the race to the sweeper; the assertions below cover this outcome.
            }
        },
        fn (): int => Date::setTestNow($now->copy()->addMinutes(11)) ?? app(ReleaseExpiredHolds::class)(),
    );

    $ticketTypeId = DB::table('hold_items')->where('hold_id', $holdId)->value('ticket_type_id');

    $hold = app(TenantTransaction::class)->asTenant($tenantId, fn () => Hold::query()->findOrFail($holdId));
    $inventory = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $ticketTypeId)->first(),
    );
    $expiredCount = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => OutboxEvent::query()->where('tenant_id', $tenantId)->where('type', 'HoldExpired')->count(),
    );
    $inventoryEventCount = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => OutboxEvent::query()->where('tenant_id', $tenantId)->count(),
    );

    expect($hold->status)->toBeIn([HoldStatus::Committed, HoldStatus::Expired])
        ->and($inventory->held)->toBe(0)
        ->and($inventory->sold + $inventory->held)->toBeLessThanOrEqual($inventory->quantity);

    if ($hold->status === HoldStatus::Committed) {
        expect($inventory->sold)->toBe(5)
            ->and($expiredCount)->toBe(0)
            // CreateHold's own HoldCreated is the only Inventory event on this path.
            ->and($inventoryEventCount)->toBe(1);
    } else {
        expect($inventory->sold)->toBe(0)
            ->and($expiredCount)->toBe(1);
    }

    Date::setTestNow();
});
