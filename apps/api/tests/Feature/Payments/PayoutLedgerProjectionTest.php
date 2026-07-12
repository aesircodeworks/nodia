<?php

use App\Payments\Actions\RecordGatewayPayout;
use App\Payments\Consumers\ProjectLedgerEntries;
use App\Payments\Enums\PayoutStatus;
use App\Payments\Events\PayoutExecuted;
use App\Payments\Gateways\NormalizedPayoutEvent;
use App\Payments\Models\Payout;
use App\Support\Money\Money;
use App\Support\Outbox\Jobs\ProcessOutboxDelivery;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OrderedConsumption;
use App\Support\Outbox\OutboxRecorder;
use App\Support\Outbox\OutboxReplay;
use App\Support\Outbox\SubscriberRegistry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-08c plan, Slice 6: ProjectLedgerEntries subscribes to
 * PayoutExecuted and appends a balanced pair (debit tenant_net, credit
 * gateway_receivable) with reference_type payout, idempotent by event
 * ID via outbox_deliveries, ordered per aggregate by outbox sequence,
 * and rebuildable identically by replay.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    $this->travelTo(CarbonImmutable::parse('2026-07-12T12:00:00Z'));

    $this->tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create(['enabled_gateways' => ['fake']])->id,
    );
});

afterEach(function (): void {
    DB::statement('truncate ledger_entries');

    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        foreach (['outbox_deliveries', 'outbox_events', 'payouts'] as $table) {
            DB::table($table)->where('tenant_id', $this->tenantId)->delete();
        }
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKey($this->tenantId)->delete();
    });
});

/**
 * @return array{0: Payout, 1: OutboxEvent}
 */
function paidPayoutEvent(string $tenantId, int $amount = 9_450, string $reference = 'fake_po_1'): array
{
    $payout = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => app(RecordGatewayPayout::class)(
            $tenantId,
            'fake',
            new NormalizedPayoutEvent('fakesm_ref', $reference, PayoutStatus::Paid, Money::of($amount, 'USD'), CarbonImmutable::now()),
        ),
    );

    $event = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => OutboxEvent::query()
            ->where('type', 'PayoutExecuted')
            ->where('aggregate_id', $payout->id)
            ->firstOrFail(),
    );

    return [$payout, $event];
}

function runPayoutLedgerProjection(string $eventId): void
{
    $job = new ProcessOutboxDelivery($eventId, ProjectLedgerEntries::NAME);
    $job->withFakeQueueInteractions();

    $job->handle(
        app(TenantTransaction::class),
        app(SubscriberRegistry::class),
        app(OrderedConsumption::class),
    );
}

/**
 * @return array<int, object>
 */
function payoutLedgerRowsFor(string $tenantId, string $payoutId): array
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => DB::table('ledger_entries')
            ->where('reference_type', 'payout')
            ->where('reference_id', $payoutId)
            ->orderBy('account')
            ->get()
            ->all(),
    );
}

it('appends exactly one balanced pair for a payout referenced by reference_type payout under duplicate delivery', function (): void {
    [$payout, $event] = paidPayoutEvent($this->tenantId);

    $this->travel(config()->integer('outbox.stability_window_seconds') + 1)->seconds();

    runPayoutLedgerProjection($event->id);
    runPayoutLedgerProjection($event->id);

    $rows = payoutLedgerRowsFor($this->tenantId, $payout->id);

    expect($rows)->toHaveCount(2)
        ->and(collect($rows)->keyBy('account')->map(fn ($row) => [$row->direction, (int) $row->amount])->all())
        ->toBe([
            'gateway_receivable' => ['credit', 9_450],
            'tenant_net' => ['debit', 9_450],
        ])
        ->and(collect($rows)->pluck('reference_type')->unique()->all())->toBe(['payout'])
        ->and(collect($rows)->pluck('source_event_id')->unique()->all())->toBe([$event->id])
        ->and(collect($rows)->pluck('currency')->unique()->all())->toBe(['USD']);
});

it('defers a same-payout successor while its predecessor delivery is unprocessed', function (): void {
    [$payoutA, $eventA] = paidPayoutEvent($this->tenantId, 5_000, 'fake_po_a');

    // A second PayoutExecuted for the same payout aggregate cannot occur
    // in production (RecordGatewayPayout records it only on the single
    // transition to paid), but the ordering mechanism must still defer
    // any successor sharing the aggregate while a predecessor delivery
    // is pending, so this records one directly to exercise that path.
    $eventB = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(OutboxRecorder::class)->record(PayoutExecuted::fromPayout($payoutA)),
    );

    $this->travel(config()->integer('outbox.stability_window_seconds') + 1)->seconds();

    $deferred = new ProcessOutboxDelivery($eventB->id, ProjectLedgerEntries::NAME);
    $deferred->withFakeQueueInteractions();
    $deferred->handle(app(TenantTransaction::class), app(SubscriberRegistry::class), app(OrderedConsumption::class));

    $deferred->assertReleased(config()->integer('outbox.ordered_defer_seconds'));

    expect(payoutLedgerRowsFor($this->tenantId, $payoutA->id))->toBeEmpty();

    runPayoutLedgerProjection($eventA->id);
    $deferred2 = new ProcessOutboxDelivery($eventB->id, ProjectLedgerEntries::NAME);
    $deferred2->withFakeQueueInteractions();
    $deferred2->handle(app(TenantTransaction::class), app(SubscriberRegistry::class), app(OrderedConsumption::class));
    $deferred2->assertNotReleased();

    expect(payoutLedgerRowsFor($this->tenantId, $payoutA->id))->toHaveCount(4);
});

it('rebuilds identical payout ledger entries from outbox replay', function (): void {
    [$payout, $event] = paidPayoutEvent($this->tenantId, 3_210, 'fake_po_replay');

    $this->travel(config()->integer('outbox.stability_window_seconds') + 1)->seconds();

    runPayoutLedgerProjection($event->id);

    $naturalKey = fn (object $row): array => [
        $row->source_event_id, $row->account, $row->direction,
        (int) $row->amount, $row->currency, $row->reference_type, $row->reference_id, $row->tenant_id,
    ];

    $incremental = collect(payoutLedgerRowsFor($this->tenantId, $payout->id))->map($naturalKey)->all();

    DB::statement('truncate ledger_entries');

    $replayed = app(OutboxReplay::class)->replay(ProjectLedgerEntries::NAME);

    $rebuilt = collect(payoutLedgerRowsFor($this->tenantId, $payout->id))->map($naturalKey)->all();

    expect($replayed)->toBeGreaterThanOrEqual(1)
        ->and($rebuilt)->toBe($incremental)
        ->and($rebuilt)->not->toBeEmpty();
});
