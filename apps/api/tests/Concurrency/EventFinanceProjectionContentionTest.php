<?php

use App\EventCatalog\Models\Event;
use App\Identity\Models\Customer;
use App\Orders\Enums\OrderStatus;
use App\Orders\Models\Order;
use App\Payments\Actions\ConfirmPayment;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Models\Payment;
use App\Reporting\Jobs\ProjectEventFinance;
use App\Support\Money\Money;
use App\Support\Outbox\Jobs\ProcessOutboxDelivery;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OrderedConsumption;
use App\Support\Outbox\SubscriberRegistry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-11 plan, Slice 3 Concurrency test: N parallel workers projecting
 * N distinct PaymentConfirmed events for the same event converge to
 * exactly N increments across all four amount columns; the
 * upsert-with-increments write path (never read-then-write, master
 * plan test-first rule 2) loses no updates under real contention on the
 * single contended row.
 */

const WORKERS = 8;

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ($tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            foreach (['report_event_finance', 'outbox_deliveries', 'outbox_events', 'payments', 'orders', 'customers', 'events'] as $table) {
                DB::table($table)->where('tenant_id', $tenantId)->delete();
            }
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

/**
 * WORKERS confirmed payments against WORKERS distinct orders, all for the
 * same event: ConfirmPayment records one PaymentConfirmed each. Queue::
 * fake() keeps every delivery pending so the parallel workers below are
 * the first and only processors, the same discipline
 * tests/Concurrency/DailySalesProjectionContentionTest.php uses.
 *
 * @return array{tenantId: string, eventId: string, grossAmount: int, feeAmount: int, commissionAmount: int, eventIds: list<string>}
 */
function eventFinanceContentionFixture(): array
{
    Queue::fake();

    $grossAmount = 10_000;
    $feeAmount = 300;

    $tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create(['commission_bps' => 250, 'enabled_gateways' => ['fake']])->id,
    );

    $state = app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $grossAmount, $feeAmount): array {
        $event = Event::factory()->create(['tenant_id' => $tenantId]);

        $eventIds = [];
        $commissionAmount = null;

        for ($i = 0; $i < WORKERS; $i++) {
            $customer = Customer::factory()->create(['tenant_id' => $tenantId]);
            $order = Order::factory()->create([
                'tenant_id' => $tenantId,
                'customer_id' => $customer->id,
                'event_id' => $event->id,
                'status' => OrderStatus::Paid,
            ]);

            $payment = Payment::factory()->create([
                'tenant_id' => $tenantId,
                'order_id' => $order->id,
                'status' => PaymentStatus::Initiated,
                'money' => Money::of($grossAmount, 'USD'),
            ]);

            app(ConfirmPayment::class)($payment->id, Money::of($feeAmount, 'USD'));

            $commissionAmount = (int) Payment::query()->findOrFail($payment->id)->commission_amount;

            $eventIds[] = OutboxEvent::query()
                ->where('type', 'PaymentConfirmed')
                ->where('aggregate_id', $payment->id)
                ->firstOrFail()
                ->id;
        }

        return [
            'eventId' => $event->id,
            'commissionAmount' => $commissionAmount,
            'eventIds' => $eventIds,
        ];
    });

    return array_merge(['tenantId' => $tenantId, 'grossAmount' => $grossAmount, 'feeAmount' => $feeAmount], $state);
}

it('converges to exactly N increments across all four amount columns when N distinct PaymentConfirmed deliveries race the same row', function (): void {
    $fx = eventFinanceContentionFixture();

    expect($fx['eventIds'])->toHaveCount(WORKERS);

    ParallelRunner::runEach(...array_map(
        fn (string $eventId): callable => function () use ($eventId): bool {
            app(ProcessOutboxDelivery::class, [
                'eventId' => $eventId,
                'subscriber' => ProjectEventFinance::NAME,
            ])->handle(
                app(TenantTransaction::class),
                app(SubscriberRegistry::class),
                app(OrderedConsumption::class),
            );

            return true;
        },
        $fx['eventIds'],
    ));

    $row = app(TenantTransaction::class)->asTenant(
        $fx['tenantId'],
        fn () => DB::table('report_event_finance')->where('event_id', $fx['eventId'])->first(),
    );

    $expectedNet = ($fx['grossAmount'] - $fx['feeAmount'] - $fx['commissionAmount']) * WORKERS;

    expect((int) $row->orders_paid_count)->toBe(WORKERS)
        ->and((int) $row->gross_amount)->toBe($fx['grossAmount'] * WORKERS)
        ->and((int) $row->gateway_fee_amount)->toBe($fx['feeAmount'] * WORKERS)
        ->and((int) $row->platform_commission_amount)->toBe($fx['commissionAmount'] * WORKERS)
        ->and((int) $row->tenant_net_amount)->toBe($expectedNet);
});
