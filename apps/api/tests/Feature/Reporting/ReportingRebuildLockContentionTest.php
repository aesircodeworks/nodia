<?php

use App\Reporting\Jobs\ProjectDailySales;
use App\Support\Outbox\Jobs\ProcessOutboxDelivery;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OrderedConsumption;
use App\Support\Outbox\ProjectionLock;
use App\Support\Outbox\SubscriberRegistry;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-11 plan, task 13 Feature test (TDD sequencing Slice 7): a
 * projector job racing a reporting:rebuild pass on the same projection
 * serializes through App\Support\Outbox\ProjectionLock; no double-
 * applied event. Simulated with a second, independent database
 * connection holding the exclusive lock open (the same lock
 * reporting:rebuild itself takes), rather than a real rebuild run, so
 * the race is deterministic instead of timing-dependent.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = reportingRebuildTenant();
});

afterEach(function (): void {
    reportingRebuildCleanTenant($this->tenantId);
    reportingRebuildCleanGlobal();
});

it('defers the projector job while the rebuild command holds the exclusive projection lock, applying it exactly once after release', function (): void {
    $fx = reportingRebuildSalesFixture($this->tenantId);

    Queue::fake();
    $ticket = reportingRebuildIssueTicket($this->tenantId, $fx['eventId'], $fx['ticketTypeId']);

    $eventId = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'TicketIssued')->where('aggregate_id', $ticket['ticketId'])->firstOrFail()->id,
    );

    $connection = reportingRebuildRawConnection();
    reportingRebuildHoldExclusiveLock($connection, ProjectDailySales::NAME);

    try {
        $deferred = app(ProcessOutboxDelivery::class, ['eventId' => $eventId, 'subscriber' => ProjectDailySales::NAME]);
        $deferred->withFakeQueueInteractions();
        $deferred->handle(
            app(TenantTransaction::class),
            app(SubscriberRegistry::class),
            app(OrderedConsumption::class),
            app(ProjectionLock::class),
        );

        $deferred->assertReleased(config()->integer('outbox.ordered_defer_seconds'));

        expect(reportingRebuildDailySalesRows($this->tenantId))->toBeEmpty();
    } finally {
        reportingRebuildReleaseExclusiveLock($connection, ProjectDailySales::NAME);
    }

    $succeeded = app(ProcessOutboxDelivery::class, ['eventId' => $eventId, 'subscriber' => ProjectDailySales::NAME]);
    $succeeded->withFakeQueueInteractions();
    $succeeded->handle(
        app(TenantTransaction::class),
        app(SubscriberRegistry::class),
        app(OrderedConsumption::class),
        app(ProjectionLock::class),
    );

    $succeeded->assertNotReleased();

    $rows = reportingRebuildDailySalesRows($this->tenantId);

    expect($rows)->toHaveCount(1)
        ->and((int) $rows->first()['tickets_issued_count'])->toBe(1);
});
