<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Actions\ExtendHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Data\ExtendHoldData;
use App\Inventory\Enums\HoldStatus;
use App\Inventory\Exceptions\HoldNotExtendableException;
use App\Inventory\Exceptions\HoldNotFoundException;
use App\Inventory\Models\Hold;
use App\Inventory\Models\TicketTypeInventory;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-06 plan, TDD sequencing Slice 4, task breakdown item 8: ExtendHold
 * unit coverage, mirroring tests/Unit/Inventory/ReleaseHoldTest.php's own
 * structure. Exit criterion 6: extension cannot resurrect.
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

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );
});

/**
 * @return array{ticketTypeId: string, holdId: string}
 */
function extendHoldFixture(string $tenantId, int $quantity = 10, int $held = 3): array
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

it('extends an active hold forward and never touches counters', function (): void {
    ['ticketTypeId' => $ticketTypeId, 'holdId' => $holdId] = extendHoldFixture($this->tenantId, held: 3);

    $original = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => Hold::query()->findOrFail($holdId));
    $newExpiresAt = $original->expires_at->copy()->addMinutes(20);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(ExtendHold::class)(new ExtendHoldData($holdId, CarbonImmutable::instance($newExpiresAt))),
    );

    $hold = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => Hold::query()->findOrFail($holdId));
    $inventory = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $ticketTypeId)->first(),
    );

    expect($hold->status)->toBe(HoldStatus::Active)
        ->and($hold->expires_at->equalTo($newExpiresAt))->toBeTrue()
        ->and($result->id)->toBe($holdId)
        ->and($inventory->held)->toBe(3);
});

it('refuses to shorten expires_at, leaving it untouched', function (): void {
    ['holdId' => $holdId] = extendHoldFixture($this->tenantId, held: 3);

    $original = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => Hold::query()->findOrFail($holdId));
    $shorter = $original->expires_at->copy()->subMinutes(1);

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(ExtendHold::class)(new ExtendHoldData($holdId, CarbonImmutable::instance($shorter))),
    );

    expect($invoke)->toThrow(HoldNotExtendableException::class);

    $hold = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => Hold::query()->findOrFail($holdId));

    expect($hold->expires_at->equalTo($original->expires_at))->toBeTrue();
});

it('refuses to extend an expired hold', function (): void {
    ['holdId' => $holdId] = extendHoldFixture($this->tenantId, held: 3);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($holdId): void {
        DB::table('holds')->where('id', $holdId)->update([
            'status' => HoldStatus::Expired->value,
            'expires_at' => now()->subMinute(),
        ]);
    });

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(ExtendHold::class)(new ExtendHoldData($holdId, CarbonImmutable::now()->addMinutes(10))),
    );

    expect($invoke)->toThrow(HoldNotExtendableException::class);
});

it('refuses to extend a released hold', function (): void {
    ['holdId' => $holdId] = extendHoldFixture($this->tenantId, held: 3);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($holdId): void {
        DB::table('holds')->where('id', $holdId)->update(['status' => HoldStatus::Released->value]);
    });

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(ExtendHold::class)(new ExtendHoldData($holdId, CarbonImmutable::now()->addMinutes(10))),
    );

    expect($invoke)->toThrow(HoldNotExtendableException::class);
});

it('throws HoldNotFoundException for an unknown hold', function (): void {
    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(ExtendHold::class)(new ExtendHoldData((string) Str::uuid7(), CarbonImmutable::now()->addMinutes(10))),
    );

    expect($invoke)->toThrow(HoldNotFoundException::class);
});
