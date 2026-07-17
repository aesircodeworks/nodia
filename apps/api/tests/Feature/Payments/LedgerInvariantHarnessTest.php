<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Models\TicketTypeInventory;
use App\Payments\Actions\CreateRefund;
use App\Payments\Consumers\ProjectLedgerEntries;
use App\Payments\Data\CreateRefundData;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Gateways\FakeWebhookDelivery;
use App\Payments\Models\Payment;
use App\Payments\Models\Refund;
use App\Support\Money\Money;
use App\Support\Outbox\OutboxReplay;
use App\Support\Outbox\OutboxSweeper;
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
 * Stage-08b plan, Slice 9, exit criteria 2, 3, 6, 13: the standing
 * balance-invariant scenario harness. A seeded (reproducible) sequence
 * of purchases (sync approve, async confirm, decline, expire) and
 * refunds (full, partial, duplicate completion webhooks) is scripted
 * against both commission policies. After every ledger-affecting step
 * this asserts per-currency debits equal credits for every reference,
 * and that the tenant_net running balance matches an expectation
 * computed independently from the persisted payment and refund row
 * facts, never from the ledger projection itself. The harness closes
 * with a full outbox replay rebuild compared row for row against the
 * incrementally built ledger on the natural key. This test is meant to
 * stay in the suite permanently as a standing regression guard.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    $this->travelTo(CarbonImmutable::parse('2026-07-11T12:00:00Z'));
    $this->tenantIds = [];
});

afterEach(function (): void {
    DB::statement('truncate ledger_entries');

    $sentinel = config()->string('tenancy.platform_tenant_id');

    app(TenantTransaction::class)->asTenant($sentinel, function (): void {
        DB::table('gateway_webhook_events')->delete();
    });

    foreach ($this->tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            foreach (['outbox_deliveries', 'outbox_events', 'media', 'refunds', 'payments', 'tickets', 'order_items', 'orders', 'hold_items', 'holds', 'customers', 'ticket_type_inventory', 'ticket_types', 'events'] as $table) {
                DB::table($table)->where('tenant_id', $tenantId)->delete();
            }
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

/**
 * A published event with a ticket type carrying enough inventory for
 * every purchase this harness run will attempt, plus a storefront
 * customer token and domain host.
 *
 * @return array{tenantId: string, host: string, token: string, ticketTypeId: string, eventId: string}
 */
function harnessTenant(string $policy, int $bps): array
{
    ['tenant' => $tenant, 'host' => $host] = app(TenantTransaction::class)->asPlatform(function () use ($policy, $bps): array {
        $tenant = Tenant::factory()->create([
            'enabled_gateways' => ['fake'],
            'commission_bps' => $bps,
            'refund_commission_policy' => $policy,
        ]);
        $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

        return ['tenant' => $tenant, 'host' => $domain->domain];
    });

    ['event' => $event, 'ticketType' => $ticketType] = app(TenantTransaction::class)->asTenant($tenant->id, function () use ($tenant): array {
        $event = Event::factory()->create(['tenant_id' => $tenant->id, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create([
            'tenant_id' => $tenant->id,
            'event_id' => $event->id,
            'price' => Money::of(5_000, 'USD'),
        ]);

        TicketTypeInventory::factory()->create([
            'tenant_id' => $tenant->id,
            'ticket_type_id' => $ticketType->id,
            'quantity' => 200,
            'held' => 0,
            'sold' => 0,
        ]);

        return ['event' => $event, 'ticketType' => $ticketType];
    });

    $customer = app(TenantTransaction::class)->asTenant(
        $tenant->id,
        fn () => Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'harness-'.Str::uuid7().'@example.com',
            'password' => 'password',
        ]),
    );

    Auth::forgetGuards();

    $token = test()->postJson('http://'.$host.'/v1/auth/customer/token', [
        'email' => $customer->email,
        'password' => 'password',
    ])->json('access_token');

    Auth::forgetGuards();

    return [
        'tenantId' => $tenant->id,
        'host' => $host,
        'token' => $token,
        'customerId' => $customer->id,
        'ticketTypeId' => $ticketType->id,
        'eventId' => $event->id,
    ];
}

/**
 * Creates a fresh paid order (two tickets) whose confirmed payment
 * carries the persisted fee/commission breakdown, through the given
 * scenario: 'sync' resolves synchronously via tok_approve, 'async'
 * resolves through a confirmation webhook.
 *
 * @return array{orderId: string, paymentId: string, ticketIds: list<string>}|null
 */
function harnessPurchase(array $fixture, string $scenario): ?array
{
    Auth::forgetGuards();

    $holdId = app(TenantTransaction::class)->asTenant(
        $fixture['tenantId'],
        fn () => app(CreateHold::class)(
            CreateHoldData::from([
                'event_id' => $fixture['eventId'],
                'items' => [['ticket_type_id' => $fixture['ticketTypeId'], 'quantity' => 2]],
            ]),
            $fixture['customerId'],
        )->id,
    );

    Auth::forgetGuards();

    $orderId = test()->postJson('http://'.$fixture['host'].'/v1/storefront/orders', [
        'hold_id' => $holdId,
    ], ['Authorization' => 'Bearer '.$fixture['token']])->assertStatus(201)->json('id');

    Auth::forgetGuards();

    $paymentResponse = match ($scenario) {
        'sync_approve' => test()->postJson(
            'http://'.$fixture['host'].'/v1/storefront/orders/'.$orderId.'/payments',
            ['method' => 'card', 'details' => ['token' => 'tok_approve']],
            ['Authorization' => 'Bearer '.$fixture['token'], 'Idempotency-Key' => (string) Str::uuid7()],
        ),
        'sync_decline' => test()->postJson(
            'http://'.$fixture['host'].'/v1/storefront/orders/'.$orderId.'/payments',
            ['method' => 'card', 'details' => ['token' => 'tok_decline']],
            ['Authorization' => 'Bearer '.$fixture['token'], 'Idempotency-Key' => (string) Str::uuid7()],
        ),
        'async_confirm', 'async_decline', 'expire' => test()->postJson(
            'http://'.$fixture['host'].'/v1/storefront/orders/'.$orderId.'/payments',
            ['method' => 'pix'],
            ['Authorization' => 'Bearer '.$fixture['token'], 'Idempotency-Key' => (string) Str::uuid7()],
        ),
        default => throw new InvalidArgumentException('unknown scenario '.$scenario),
    };

    Auth::forgetGuards();

    $paymentId = $paymentResponse->json('id');

    if ($scenario === 'async_confirm') {
        $delivery = app(FakeGateway::class)->confirmationWebhook('fake_'.$paymentId, Money::of(300, 'USD'));

        test()->call('POST', '/v1/webhooks/fake', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            ...$delivery->serverHeaders(),
        ], $delivery->body)->assertStatus(200);
    }

    if ($scenario === 'async_decline') {
        $delivery = app(FakeGateway::class)->failureWebhook('fake_'.$paymentId, 'insufficient_funds');

        test()->call('POST', '/v1/webhooks/fake', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            ...$delivery->serverHeaders(),
        ], $delivery->body)->assertStatus(200);
    }

    if ($scenario === 'expire') {
        $before = CarbonImmutable::now();
        test()->travel(31)->minutes();
        test()->artisan('payments:expire')->assertSuccessful();
        test()->travelTo($before);
    }

    if (! in_array($scenario, ['sync_approve', 'async_confirm'], true)) {
        return null;
    }

    return app(TenantTransaction::class)->asTenant($fixture['tenantId'], function () use ($orderId): array {
        $payment = Payment::query()->where('order_id', $orderId)->firstOrFail();
        $ticketIds = DB::table('tickets')->where('order_id', $orderId)->orderBy('id')->pluck('id')->all();

        return ['orderId' => $orderId, 'paymentId' => $payment->id, 'ticketIds' => $ticketIds];
    });
}

function harnessDeliverRefundWebhook(FakeWebhookDelivery $delivery)
{
    return test()->call('POST', '/v1/webhooks/fake', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        ...$delivery->serverHeaders(),
    ], $delivery->body);
}

function harnessRefund(string $tenantId, string $paymentId, ?array $amount = null): Refund
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => app(CreateRefund::class)(
            $paymentId,
            CreateRefundData::from(['amount' => $amount]),
            (string) Str::uuid7(),
        )->refund,
    );
}

/**
 * Every ledger_entries row currently persisted for the tenant, keyed by
 * the natural key from exit criterion 6.
 *
 * @return array<int, array{0: string, 1: string, 2: string, 3: int, 4: string, 5: string, 6: string, 7: string}>
 */
function harnessLedgerNaturalKeys(string $tenantId): array
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => DB::table('ledger_entries')->orderBy('id')->get()->map(fn ($row) => [
            $row->source_event_id, $row->account, $row->direction,
            (int) $row->amount, $row->currency, $row->reference_type, $row->reference_id, $row->tenant_id,
        ])->all(),
    );
}

/**
 * Asserts that within the tenant's current ledger state every reference
 * (payment or refund) that has any rows is balanced per currency, and
 * that the tenant_net running balance matches the independently tracked
 * expectation built from persisted payment/refund row facts.
 */
function assertHarnessInvariants(string $tenantId, int $expectedTenantNet): void
{
    $rows = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => DB::table('ledger_entries')->get(),
    );

    $byReferenceAndCurrency = $rows->groupBy(fn ($row) => $row->reference_type.'|'.$row->reference_id.'|'.$row->currency);

    foreach ($byReferenceAndCurrency as $group) {
        $debits = $group->where('direction', 'debit')->sum(fn ($row) => (int) $row->amount);
        $credits = $group->where('direction', 'credit')->sum(fn ($row) => (int) $row->amount);

        expect($debits)->toBe($credits);
    }

    $tenantNetRows = $rows->where('account', 'tenant_net');
    $tenantNetBalance = $tenantNetRows->where('direction', 'credit')->sum(fn ($row) => (int) $row->amount)
        - $tenantNetRows->where('direction', 'debit')->sum(fn ($row) => (int) $row->amount);

    expect($tenantNetBalance)->toBe($expectedTenantNet);
}

it('holds per-reference balance and the tenant_net running total across a scripted purchase-and-refund sequence, and replays row for row', function (): void {
    mt_srand(80211092);

    $purchaseScenarios = ['sync_approve', 'sync_decline', 'async_confirm', 'async_decline', 'expire'];

    foreach ([['policy' => 'returned', 'bps' => 250], ['policy' => 'retained', 'bps' => 500]] as $config) {
        $fixture = harnessTenant($config['policy'], $config['bps']);
        $this->tenantIds[] = $fixture['tenantId'];

        $expectedTenantNet = 0;
        $confirmedPayments = [];

        for ($i = 0; $i < 6; $i++) {
            $scenario = $purchaseScenarios[mt_rand(0, count($purchaseScenarios) - 1)];
            $purchase = harnessPurchase($fixture, $scenario);

            if ($purchase === null) {
                continue;
            }

            $this->travel(config()->integer('outbox.sweeper_grace_seconds') + 1)->seconds();
            app(OutboxSweeper::class)->sweep();

            $payment = app(TenantTransaction::class)->asTenant(
                $fixture['tenantId'],
                fn () => Payment::query()->findOrFail($purchase['paymentId']),
            );

            $expectedTenantNet += $payment->amount - $payment->fee_amount - $payment->commission_amount;

            assertHarnessInvariants($fixture['tenantId'], $expectedTenantNet);

            $confirmedPayments[] = $purchase;
        }

        foreach ($confirmedPayments as $purchase) {
            $refundMode = mt_rand(0, 2);

            if ($refundMode === 0) {
                continue;
            }

            $refunds = $refundMode === 1
                ? [null]
                : [['amount' => 3_000, 'currency' => 'USD']];

            foreach ($refunds as $amount) {
                $refund = harnessRefund($fixture['tenantId'], $purchase['paymentId'], $amount);

                $delivery = app(FakeGateway::class)->refundCompletionWebhook('fake_rf_'.$refund->id);

                harnessDeliverRefundWebhook($delivery)->assertStatus(200);

                if (mt_rand(0, 1) === 1) {
                    // Duplicate completion webhook: exactly one outcome, no
                    // change to the running expectation.
                    harnessDeliverRefundWebhook($delivery)->assertStatus(200);
                }

                $this->travel(config()->integer('outbox.sweeper_grace_seconds') + 1)->seconds();
                app(OutboxSweeper::class)->sweep();

                $fresh = app(TenantTransaction::class)->asTenant(
                    $fixture['tenantId'],
                    fn () => Refund::query()->findOrFail($refund->id),
                );

                $returnedCommission = $config['policy'] === 'retained' ? 0 : $fresh->commission_amount;
                $expectedTenantNet -= ($fresh->amount - $returnedCommission);

                assertHarnessInvariants($fixture['tenantId'], $expectedTenantNet);
            }
        }

        $incremental = harnessLedgerNaturalKeys($fixture['tenantId']);

        DB::statement('truncate ledger_entries');

        app(OutboxReplay::class)->replay(ProjectLedgerEntries::NAME);

        $rebuilt = harnessLedgerNaturalKeys($fixture['tenantId']);

        expect($rebuilt)->toEqualCanonicalizing($incremental)
            ->and($rebuilt)->not->toBeEmpty();

        assertHarnessInvariants($fixture['tenantId'], $expectedTenantNet);
    }
});
