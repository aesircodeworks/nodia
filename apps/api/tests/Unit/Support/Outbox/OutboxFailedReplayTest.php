<?php

declare(strict_types=1);

use App\Support\Outbox\Enums\OutboxDeliveryStatus;
use App\Support\Outbox\Jobs\ProcessOutboxDelivery;
use App\Support\Outbox\Models\OutboxDelivery;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OutboxFailedReplay;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-12 plan Slice 5, task breakdown item 11 unit invariants:
 * OutboxFailedReplay lists only failed_jobs rows for
 * App\Support\Outbox\Jobs\ProcessOutboxDelivery (ignoring failed jobs from
 * unrelated classes), and candidates() alone never mutates anything.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    DB::table('failed_jobs')->delete();

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
 * A failed_jobs row shaped exactly like Laravel's own object-payload
 * format (Illuminate\Queue\Queue::createObjectPayload), the same shape
 * Illuminate\Queue\Console\WorkCommand's own failed-job logging produces
 * for a real worker.
 */
function insertFailedJobRow(string $displayName, string $commandInstance): string
{
    $uuid = (string) Str::uuid7();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'sync',
        'queue' => 'default',
        'payload' => json_encode([
            'uuid' => $uuid,
            'displayName' => $displayName,
            'job' => 'Illuminate\Queue\CallQueuedHandler@call',
            'maxTries' => 1,
            'data' => [
                'commandName' => $displayName,
                'command' => $commandInstance,
            ],
        ], JSON_THROW_ON_ERROR),
        'exception' => 'RuntimeException: synthetic',
        'failed_at' => now(),
    ]);

    return $uuid;
}

it('lists only failed_jobs rows for ProcessOutboxDelivery, ignoring unrelated job classes', function (): void {
    $event = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => OutboxEvent::query()->create([
        'type' => 'FixtureEvent',
        'tenant_id' => $this->tenantId,
        'aggregate_type' => 'fixture',
        'aggregate_id' => Str::uuid7()->toString(),
        'correlation_id' => 'unit-correlation',
        'occurred_at' => now(),
        'payload' => ['source' => 'unit'],
    ]));

    $matchingJob = new ProcessOutboxDelivery($event->id, 'unit_test_subscriber');
    insertFailedJobRow(ProcessOutboxDelivery::class, serialize($matchingJob));

    insertFailedJobRow('App\Payments\Jobs\ProcessGatewayWebhook', serialize(new stdClass));

    $summary = app(OutboxFailedReplay::class)->candidates();

    expect($summary->failedJobs)->toHaveCount(1);

    $only = $summary->failedJobs->first();
    $payload = json_decode((string) $only->payload, true);

    expect($payload['displayName'])->toBe(ProcessOutboxDelivery::class);
});

it('reports matched counts without reenqueuing or deleting anything when execute is false', function (): void {
    $event = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => OutboxEvent::query()->create([
        'type' => 'FixtureEvent',
        'tenant_id' => $this->tenantId,
        'aggregate_type' => 'fixture',
        'aggregate_id' => Str::uuid7()->toString(),
        'correlation_id' => 'unit-correlation-2',
        'occurred_at' => now(),
        'payload' => ['source' => 'unit'],
    ]));

    $delivery = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => OutboxDelivery::query()->create([
        'outbox_event_id' => $event->id,
        'tenant_id' => $this->tenantId,
        'subscriber' => 'unit_test_subscriber_2',
        'status' => OutboxDeliveryStatus::Pending,
    ]));

    insertFailedJobRow(
        ProcessOutboxDelivery::class,
        serialize(new ProcessOutboxDelivery($event->id, 'unit_test_subscriber_2')),
    );

    Queue::fake();

    $summary = app(OutboxFailedReplay::class)->replay('operator-unit', execute: false);

    expect($summary->failedJobs)->toHaveCount(1)
        ->and($summary->strandedDeliveries)->toHaveCount(0)
        ->and($summary->failedJobsReenqueued)->toBe(0)
        ->and($summary->strandedDeliveriesReenqueued)->toBe(0);

    Queue::assertNothingPushed();
    expect(DB::table('failed_jobs')->count())->toBe(1);

    $fresh = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxDelivery::query()->whereKey($delivery->id)->firstOrFail(),
    );

    expect($fresh->status)->toBe(OutboxDeliveryStatus::Pending);
});
