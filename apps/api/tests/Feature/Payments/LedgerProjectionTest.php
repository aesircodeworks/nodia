<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Models\TicketTypeInventory;
use App\Orders\Models\Order;
use App\Payments\Actions\ConfirmPayment;
use App\Payments\Consumers\ProjectLedgerEntries;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Models\Payment;
use App\Support\Money\Money;
use App\Support\Outbox\Jobs\ProcessOutboxDelivery;
use App\Support\Outbox\KeyedOrderedOutboxSubscriber;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OrderedConsumption;
use App\Support\Outbox\OutboxReplay;
use App\Support\Outbox\OutboxSweeper;
use App\Support\Outbox\ProjectionLock;
use App\Support\Outbox\SubscriberRegistry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-08b plan, Slice 4: the ledger projection consumes
 * PaymentConfirmed through the payload-keyed ordered helper, idempotent
 * by the (source_event_id, account) anchor, rebuildable by replay.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    $this->travelTo(CarbonImmutable::parse('2026-07-11T12:00:00Z'));

    $this->tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create(['commission_bps' => 250, 'enabled_gateways' => ['fake']])->id,
    );

    $this->orderId = app(TenantTransaction::class)->asTenant($this->tenantId, function (): string {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenantId]);
        $event = Event::factory()->create(['tenant_id' => $this->tenantId]);

        return Order::factory()->create([
            'tenant_id' => $this->tenantId,
            'customer_id' => $customer->id,
            'event_id' => $event->id,
        ])->id;
    });
});

afterEach(function (): void {
    DB::statement('truncate ledger_entries');

    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        foreach (['outbox_deliveries', 'outbox_events', 'media', 'tickets', 'order_items', 'payments', 'orders', 'hold_items', 'holds', 'customers', 'ticket_type_inventory', 'ticket_types', 'events'] as $table) {
            DB::table($table)->where('tenant_id', $this->tenantId)->delete();
        }
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });
});

function confirmedPaymentEvent(string $tenantId, string $orderId): array
{
    $payment = app(TenantTransaction::class)->asTenant($tenantId, fn () => Payment::factory()->create([
        'tenant_id' => $tenantId,
        'order_id' => $orderId,
        'status' => PaymentStatus::Initiated,
        'money' => Money::of(10_000, 'USD'),
    ]));

    app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => app(ConfirmPayment::class)($payment->id, Money::of(300, 'USD')),
    );

    $event = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => OutboxEvent::query()
            ->where('type', 'PaymentConfirmed')
            ->where('aggregate_id', $payment->id)
            ->firstOrFail(),
    );

    return [$payment->fresh(), $event];
}

function runLedgerProjection(string $eventId): void
{
    $job = new ProcessOutboxDelivery($eventId, ProjectLedgerEntries::NAME);
    $job->withFakeQueueInteractions();

    $job->handle(
        app(TenantTransaction::class),
        app(SubscriberRegistry::class),
        app(OrderedConsumption::class),
        app(ProjectionLock::class),
    );
}

/**
 * @return array<int, object>
 */
function ledgerRowsFor(string $tenantId, string $referenceId): array
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => DB::table('ledger_entries')
            ->where('reference_id', $referenceId)
            ->orderBy('account')
            ->get()
            ->all(),
    );
}

it('orders by the payment_id payload key', function (): void {
    $projection = app(SubscriberRegistry::class)->handler(ProjectLedgerEntries::NAME);

    expect($projection)->toBeInstanceOf(KeyedOrderedOutboxSubscriber::class)
        ->and($projection->orderingKeyPayloadPath())->toBe('payment_id');
});

it('projects the four balanced legs from row facts and stays idempotent under duplicate delivery', function (): void {
    [$payment, $event] = confirmedPaymentEvent($this->tenantId, $this->orderId);

    $this->travel(config()->integer('outbox.stability_window_seconds') + 1)->seconds();

    runLedgerProjection($event->id);
    runLedgerProjection($event->id);

    $rows = ledgerRowsFor($this->tenantId, $payment->id);

    // 10000 gross, 300 fee, 250 commission (250 bps): net 9450.
    expect($rows)->toHaveCount(4)
        ->and(collect($rows)->keyBy('account')->map(fn ($row) => [$row->direction, (int) $row->amount])->all())
        ->toBe([
            'gateway_fees' => ['credit', 300],
            'gateway_receivable' => ['debit', 10_000],
            'platform_commission' => ['credit', 250],
            'tenant_net' => ['credit', 9_450],
        ])
        ->and(collect($rows)->pluck('source_event_id')->unique()->all())->toBe([$event->id])
        ->and(collect($rows)->pluck('currency')->unique()->all())->toBe(['USD']);
});

it('defers projection inside the stability window', function (): void {
    [, $event] = confirmedPaymentEvent($this->tenantId, $this->orderId);

    runLedgerProjection($event->id);

    expect(app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DB::table('ledger_entries')->count(),
    ))->toBe(0);
});

it('ends an HTTP purchase through FakeGateway with four balanced entries referencing the payment', function (): void {
    $host = app(TenantTransaction::class)->asPlatform(
        fn () => TenantDomain::factory()->create(['tenant_id' => $this->tenantId])->domain,
    );

    ['ticketType' => $ticketType] = app(TenantTransaction::class)->asTenant($this->tenantId, function (): array {
        $event = Event::factory()->create(['tenant_id' => $this->tenantId, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $this->tenantId, 'event_id' => $event->id]);

        TicketTypeInventory::factory()->create([
            'tenant_id' => $this->tenantId,
            'ticket_type_id' => $ticketType->id,
            'quantity' => 10,
            'held' => 0,
            'sold' => 0,
        ]);

        return ['ticketType' => $ticketType, 'eventId' => $event->id];
    });

    $customer = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Customer::factory()->create([
            'tenant_id' => $this->tenantId,
            'email' => 'ledger-buyer@example.com',
            'password' => 'password',
        ]),
    );

    $token = $this->postJson('http://'.$host.'/v1/auth/customer/token', [
        'email' => 'ledger-buyer@example.com',
        'password' => 'password',
    ])->json('access_token');

    $holdId = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateHold::class)(
            CreateHoldData::from([
                'event_id' => $ticketType->event_id,
                'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
            ]),
            $customer->id,
        )->id,
    );

    $orderId = $this->postJson('http://'.$host.'/v1/storefront/orders', [
        'hold_id' => $holdId,
    ], ['Authorization' => 'Bearer '.$token])->assertStatus(201)->json('id');

    Auth::forgetGuards();

    $paymentId = $this->postJson('http://'.$host.'/v1/storefront/orders/'.$orderId.'/payments', [
        'method' => 'card',
        'details' => ['token' => 'tok_approve'],
    ], ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => (string) Str::uuid7()])
        ->assertStatus(201)
        ->json('id');

    // The synchronous delivery deferred inside the stability window; the
    // sweeper re-enqueues once past both windows, exactly as production.
    $this->travel(config()->integer('outbox.sweeper_grace_seconds') + 1)->seconds();
    app(OutboxSweeper::class)->sweep();

    $rows = ledgerRowsFor($this->tenantId, $paymentId);
    $payment = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Payment::query()->findOrFail($paymentId),
    );

    $debits = collect($rows)->where('direction', 'debit')->sum(fn ($row) => (int) $row->amount);
    $credits = collect($rows)->where('direction', 'credit')->sum(fn ($row) => (int) $row->amount);

    expect($rows)->toHaveCount(4)
        ->and($debits)->toBe($credits)
        ->and($debits)->toBe($payment->amount)
        ->and(collect($rows)->firstWhere('account', 'platform_commission'))->not->toBeNull();
});

it('rebuilds the same ledger row for row from outbox replay', function (): void {
    [$payment, $event] = confirmedPaymentEvent($this->tenantId, $this->orderId);

    $this->travel(config()->integer('outbox.stability_window_seconds') + 1)->seconds();

    runLedgerProjection($event->id);

    $naturalKey = fn (object $row): array => [
        $row->source_event_id, $row->account, $row->direction,
        (int) $row->amount, $row->currency, $row->reference_type, $row->reference_id, $row->tenant_id,
    ];

    $incremental = collect(ledgerRowsFor($this->tenantId, $payment->id))->map($naturalKey)->all();

    DB::statement('truncate ledger_entries');

    $replayed = app(OutboxReplay::class)->replay(ProjectLedgerEntries::NAME);

    $rebuilt = collect(ledgerRowsFor($this->tenantId, $payment->id))->map($naturalKey)->all();

    expect($replayed)->toBeGreaterThanOrEqual(1)
        ->and($rebuilt)->toBe($incremental)
        ->and($rebuilt)->not->toBeEmpty();
});
