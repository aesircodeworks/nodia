<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Inventory\Actions\CommitHold;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CommitHoldData;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Enums\HoldStatus;
use App\Inventory\Models\Hold;
use App\Inventory\Models\TicketTypeInventory;
use App\Support\Audit\Models\ActivityLogEntry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-12 plan, Slice 5, task breakdown item 13: holds:release-stuck, the
 * dry-run-by-default, --operator-required, activity-logged manual release
 * of holds stuck active past their expires_at. Named "-stuck" (not the
 * automatic sweeper's own "-expired") so the two commands never collide,
 * the same naming precedent outbox:replay-failed and
 * payments:reconcile-orders already took against their own automatic
 * siblings.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    $this->travelTo(CarbonImmutable::parse('2026-07-13T12:00:00Z'));
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
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

/**
 * @return array{tenantId: string, ticketTypeId: string, holdId: string}
 */
function releaseStuckHoldFixture(int $held = 3): array
{
    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    ['ticketTypeId' => $ticketTypeId, 'holdId' => $holdId] = app(TenantTransaction::class)->asTenant(
        $tenantId,
        function () use ($tenantId, $held): array {
            $event = Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published]);
            $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id]);

            TicketTypeInventory::factory()->create([
                'tenant_id' => $tenantId,
                'ticket_type_id' => $ticketType->id,
                'quantity' => 10,
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
        },
    );

    return ['tenantId' => $tenantId, 'ticketTypeId' => $ticketTypeId, 'holdId' => $holdId];
}

function releaseStuckHoldsActivityLogCount(): int
{
    return app(TenantTransaction::class)->asPlatform(
        fn () => DB::table('activity_log')->where('event', 'holds_release_stuck_invoked')->count(),
    );
}

it('refuses to run without --operator', function (): void {
    $before = releaseStuckHoldsActivityLogCount();

    test()->artisan('holds:release-stuck --all-tenants')->assertFailed();

    expect(releaseStuckHoldsActivityLogCount())->toBe($before);
});

it('refuses --execute unbounded, with neither --tenant nor --all-tenants', function (): void {
    $before = releaseStuckHoldsActivityLogCount();

    test()->artisan('holds:release-stuck --operator=refusal-unbounded --execute')->assertFailed();

    expect(releaseStuckHoldsActivityLogCount())->toBe($before);
});

it('dry-run listing includes an active hold past expires_at and excludes a committed one, with no side effect', function (): void {
    $fixture = releaseStuckHoldFixture(held: 3);

    $committed = releaseStuckHoldFixture(held: 2);
    app(TenantTransaction::class)->asTenant(
        $committed['tenantId'],
        fn () => app(CommitHold::class)(CommitHoldData::from(['holdId' => $committed['holdId']])),
    );

    $this->travelTo(CarbonImmutable::parse('2026-07-13T12:11:00Z'));

    test()->artisan('holds:release-stuck --operator=dry-run-operator --all-tenants')
        ->expectsOutputToContain($fixture['holdId'])
        ->assertSuccessful();

    $hold = app(TenantTransaction::class)->asTenant($fixture['tenantId'], fn () => Hold::query()->findOrFail($fixture['holdId']));
    $inventory = app(TenantTransaction::class)->asTenant(
        $fixture['tenantId'],
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $fixture['ticketTypeId'])->first(),
    );
    $committedHold = app(TenantTransaction::class)->asTenant($committed['tenantId'], fn () => Hold::query()->findOrFail($committed['holdId']));

    expect($hold->status)->toBe(HoldStatus::Active)
        ->and($inventory->held)->toBe(3)
        ->and($committedHold->status)->toBe(HoldStatus::Committed);

    $entry = app(TenantTransaction::class)->asPlatform(
        fn () => ActivityLogEntry::query()
            ->where('event', 'holds_release_stuck_invoked')
            ->where('properties->operator', 'dry-run-operator')
            ->latest('created_at')
            ->first(),
    );

    expect($entry)->not->toBeNull()
        ->and($entry->properties['execute'])->toBeFalse()
        ->and($entry->properties['holds_matched'])->toBeGreaterThanOrEqual(1)
        ->and($entry->properties['holds_released'])->toBe(0);
});

it('--execute releases the matched hold through ReleaseHold, recovering availability exactly', function (): void {
    $fixture = releaseStuckHoldFixture(held: 4);

    $this->travelTo(CarbonImmutable::parse('2026-07-13T12:11:00Z'));

    test()->artisan(
        'holds:release-stuck --operator=execute-operator --execute --tenant='.$fixture['tenantId']
    )->assertSuccessful();

    $hold = app(TenantTransaction::class)->asTenant($fixture['tenantId'], fn () => Hold::query()->findOrFail($fixture['holdId']));
    $inventory = app(TenantTransaction::class)->asTenant(
        $fixture['tenantId'],
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $fixture['ticketTypeId'])->first(),
    );

    expect($hold->status)->toBe(HoldStatus::Released)
        ->and($inventory->held)->toBe(0)
        ->and($inventory->quantity - $inventory->sold - $inventory->held)->toBe($inventory->quantity);

    $entry = app(TenantTransaction::class)->asPlatform(
        fn () => ActivityLogEntry::query()
            ->where('event', 'holds_release_stuck_invoked')
            ->where('properties->operator', 'execute-operator')
            ->latest('created_at')
            ->first(),
    );

    expect($entry)->not->toBeNull()
        ->and($entry->properties['execute'])->toBeTrue()
        ->and($entry->properties['holds_matched'])->toBe(1)
        ->and($entry->properties['holds_released'])->toBe(1);
});

it('--tenant bounds --execute to that tenant only, never releasing another tenant\'s stuck hold', function (): void {
    $named = releaseStuckHoldFixture(held: 2);
    $other = releaseStuckHoldFixture(held: 2);

    $this->travelTo(CarbonImmutable::parse('2026-07-13T12:11:00Z'));

    test()->artisan(
        'holds:release-stuck --operator=tenant-bound --execute --tenant='.$named['tenantId']
    )->assertSuccessful();

    $namedHold = app(TenantTransaction::class)->asTenant($named['tenantId'], fn () => Hold::query()->findOrFail($named['holdId']));
    $otherHold = app(TenantTransaction::class)->asTenant($other['tenantId'], fn () => Hold::query()->findOrFail($other['holdId']));

    expect($namedHold->status)->toBe(HoldStatus::Released)
        ->and($otherHold->status)->toBe(HoldStatus::Active);
});
