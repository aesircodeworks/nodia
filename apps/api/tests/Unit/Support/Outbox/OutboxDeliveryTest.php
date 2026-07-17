<?php

declare(strict_types=1);

use App\Support\Outbox\Enums\OutboxDeliveryStatus;
use App\Support\Outbox\Models\OutboxDelivery;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-04 plan task 6 / Slice 2 unit: the conditional pending-to-
 * processed transition admits exactly one winner by affected-row count;
 * a second mark returns false (already processed, not an error).
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
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    app()->forgetScopedInstances();
});

/**
 * @return array{event: OutboxEvent, delivery: OutboxDelivery}
 */
function pendingDeliveryFixture(string $tenantId): array
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): array {
        $event = OutboxEvent::query()->create([
            'type' => 'FixtureEvent',
            'tenant_id' => $tenantId,
            'aggregate_type' => 'fixture',
            'aggregate_id' => Str::uuid7()->toString(),
            'correlation_id' => 'unit-delivery-correlation',
            'occurred_at' => now(),
            'payload' => ['source' => 'unit-delivery'],
        ]);

        $delivery = OutboxDelivery::query()->create([
            'outbox_event_id' => $event->id,
            'tenant_id' => $tenantId,
            'subscriber' => 'unit_test_subscriber',
            'status' => OutboxDeliveryStatus::Pending,
        ]);

        return ['event' => $event, 'delivery' => $delivery];
    });
}

it('marks a pending delivery processed exactly once', function () {
    $this->freezeTime();
    $frozen = now();

    $fixture = pendingDeliveryFixture($this->tenantId);
    $delivery = $fixture['delivery'];

    $won = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => $delivery->markProcessed(),
    );

    expect($won)->toBeTrue();

    $row = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxDelivery::query()->whereKey($delivery->id)->firstOrFail(),
    );

    // timestampTz columns store second precision; compare unix seconds and
    // timezone rather than microsecond-equalTo after the round trip.
    expect($row->status)->toBe(OutboxDeliveryStatus::Processed)
        ->and($row->processed_at)->not->toBeNull()
        ->and($row->processed_at->getTimestamp())->toBe($frozen->getTimestamp())
        ->and($row->processed_at->utcOffset())->toBe(0);
});

it('returns false on a second mark without changing the first processed_at', function () {
    $this->freezeTime();
    $firstProcessedAt = now();

    $fixture = pendingDeliveryFixture($this->tenantId);
    $delivery = $fixture['delivery'];

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => $delivery->markProcessed(),
    );

    $this->travel(10)->seconds();

    $second = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        function () use ($delivery): bool {
            $fresh = OutboxDelivery::query()->whereKey($delivery->id)->firstOrFail();

            return $fresh->markProcessed();
        },
    );

    expect($second)->toBeFalse();

    $row = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxDelivery::query()->whereKey($delivery->id)->firstOrFail(),
    );

    expect($row->status)->toBe(OutboxDeliveryStatus::Processed)
        ->and($row->processed_at)->not->toBeNull()
        ->and($row->processed_at->getTimestamp())->toBe($firstProcessedAt->getTimestamp());
});
