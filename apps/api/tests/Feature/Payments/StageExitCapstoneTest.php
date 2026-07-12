<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Capability;
use App\Identity\Models\Customer;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Models\TicketTypeInventory;
use App\Payments\Enums\PayoutStatus;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Gateways\FakeGatewayScenarios;
use App\Payments\Gateways\FakeWebhookDelivery;
use App\Payments\Gateways\GatewaySubmerchantResult;
use App\Payments\Models\Payment;
use App\Payments\Models\Payout;
use App\Support\Money\Money;
use App\Support\Outbox\OutboxSweeper;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\TenantStaff;

/*
 * Stage-08c plan, Slice 7 (T12), exit criteria 1-11: the stage's
 * capstone loop, entirely over HTTP against the fake gateway. Publish,
 * hold, order, initiate payment, async confirmation webhook, partial
 * refund, full refund on a second order, payout webhook; the ledger
 * balance invariant is checked after every step and the final payout
 * drains the tenant net balance to zero. The scenario reruns under
 * every scripted failure mode the plan lists: a declined payment, an
 * expired payment, duplicate webhooks delivered everywhere, and a
 * failed payout that leaves the ledger untouched.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-07-11T12:00:00Z'));
    $this->tenantIds = [];
});

afterEach(function (): void {
    Cache::flush();
    DB::statement('truncate ledger_entries');

    $sentinel = config()->string('tenancy.platform_tenant_id');

    app(TenantTransaction::class)->asTenant($sentinel, function (): void {
        DB::table('gateway_webhook_events')->delete();
    });

    foreach ($this->tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            foreach (['outbox_deliveries', 'outbox_events', 'media', 'refunds', 'payments', 'payouts', 'submerchant_accounts', 'tickets', 'order_items', 'orders', 'hold_items', 'holds', 'customers', 'ticket_type_inventory', 'ticket_types', 'events', 'memberships', 'roles'] as $table) {
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
 * @return array{tenantId: string, host: string, token: string, customerId: string, ticketTypeId: string, eventId: string, staffHeaders: array<string, string>}
 */
function capstoneTenant(): array
{
    ['tenant' => $tenant, 'host' => $host] = app(TenantTransaction::class)->asPlatform(function (): array {
        $tenant = Tenant::factory()->create(['enabled_gateways' => ['fake']]);
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
            'email' => 'capstone-'.Str::uuid7().'@example.com',
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
        'staffHeaders' => [
            'Authorization' => 'Bearer '.TenantStaff::token($tenant->id, [Capability::PayoutsManage, Capability::OrdersRefund]),
            'X-Tenant-Id' => $tenant->id,
        ],
    ];
}

/**
 * Onboards the tenant's sub-merchant account over HTTP; the fake
 * gateway activates it immediately by default, so a single POST leaves
 * an active account carrying its own gateway account reference.
 */
function capstoneOnboard(array $fixture): string
{
    $reference = 'fakesm_capstone_'.$fixture['tenantId'];

    app(FakeGatewayScenarios::class)->scriptSubmerchantCreation(GatewaySubmerchantResult::active($reference));

    $response = test()->postJson('/v1/submerchant-accounts', ['gateway' => 'fake'], $fixture['staffHeaders']);

    $response->assertStatus(201)->assertJsonPath('status', 'active');

    return $response->json('gateway_account_reference');
}

/**
 * Places a two-ticket order for the fixture's event and initiates its
 * payment over HTTP through the given scenario ('sync_approve',
 * 'sync_decline', 'async_confirm', 'expire'), returning the order and
 * payment ids. A declined or expired payment carries no payment row.
 *
 * @return array{orderId: string, paymentId: ?string}
 */
function capstonePurchase(array $fixture, string $scenario): array
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
        'async_confirm', 'expire' => test()->postJson(
            'http://'.$fixture['host'].'/v1/storefront/orders/'.$orderId.'/payments',
            ['method' => 'pix'],
            ['Authorization' => 'Bearer '.$fixture['token'], 'Idempotency-Key' => (string) Str::uuid7()],
        ),
        default => throw new InvalidArgumentException('unknown scenario '.$scenario),
    };

    Auth::forgetGuards();

    $paymentId = $paymentResponse->json('id');

    if ($scenario === 'async_confirm') {
        capstoneDeliverWebhook(app(FakeGateway::class)->confirmationWebhook('fake_'.$paymentId, Money::of(300, 'USD')))
            ->assertStatus(200);
    }

    if ($scenario === 'expire') {
        $before = CarbonImmutable::now();
        test()->travel(31)->minutes();
        test()->artisan('payments:expire')->assertSuccessful();
        test()->travelTo($before);
    }

    if (! in_array($scenario, ['sync_approve', 'async_confirm'], true)) {
        return ['orderId' => $orderId, 'paymentId' => null];
    }

    $paymentId = app(TenantTransaction::class)->asTenant(
        $fixture['tenantId'],
        fn () => Payment::query()->where('order_id', $orderId)->firstOrFail()->id,
    );

    return ['orderId' => $orderId, 'paymentId' => $paymentId];
}

function capstoneDeliverWebhook(FakeWebhookDelivery $delivery)
{
    return test()->call('POST', '/v1/webhooks/fake', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_FAKE_SIGNATURE' => $delivery->headers['X-Fake-Signature'],
    ], $delivery->body);
}

function capstoneRefund(array $fixture, string $paymentId, ?array $amount = null): array
{
    $body = $amount === null ? [] : ['amount' => $amount];

    $response = test()->postJson(
        '/v1/payments/'.$paymentId.'/refunds',
        $body,
        $fixture['staffHeaders'] + ['Idempotency-Key' => (string) Str::uuid7()],
    );

    $response->assertStatus(201);

    return ['id' => $response->json('id')];
}

/**
 * Asserts every reference currently in the tenant's ledger balances
 * per currency, and that the running tenant_net balance matches the
 * independently tracked expectation.
 */
function assertCapstoneInvariants(string $tenantId, int $expectedTenantNet): void
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

function capstoneSweep(): void
{
    test()->travel(config()->integer('outbox.sweeper_grace_seconds') + 1)->seconds();
    app(OutboxSweeper::class)->sweep();
}

it('runs the full publish-through-payout loop over HTTP with balanced books at every step', function (): void {
    $fixture = capstoneTenant();
    $this->tenantIds[] = $fixture['tenantId'];

    $accountReference = capstoneOnboard($fixture);
    assertCapstoneInvariants($fixture['tenantId'], 0);

    $expectedTenantNet = 0;

    // First order: async confirmation webhook, then a partial refund.
    $first = capstonePurchase($fixture, 'async_confirm');
    capstoneSweep();

    $firstPayment = app(TenantTransaction::class)->asTenant($fixture['tenantId'], fn () => Payment::query()->findOrFail($first['paymentId']));
    $expectedTenantNet += $firstPayment->amount - $firstPayment->fee_amount - $firstPayment->commission_amount;
    assertCapstoneInvariants($fixture['tenantId'], $expectedTenantNet);

    $partialRefund = capstoneRefund($fixture, $first['paymentId'], ['amount' => 2_500, 'currency' => 'USD']);
    $refundDelivery = app(FakeGateway::class)->refundCompletionWebhook('fake_rf_'.$partialRefund['id']);
    capstoneDeliverWebhook($refundDelivery)->assertStatus(200);
    capstoneSweep();

    $freshPartialRefund = app(TenantTransaction::class)->asTenant(
        $fixture['tenantId'],
        fn () => DB::table('refunds')->where('id', $partialRefund['id'])->first(),
    );
    $expectedTenantNet -= ((int) $freshPartialRefund->amount - (int) $freshPartialRefund->commission_amount);
    assertCapstoneInvariants($fixture['tenantId'], $expectedTenantNet);

    // Second order: sync approval, then a full refund.
    $second = capstonePurchase($fixture, 'sync_approve');
    capstoneSweep();

    $secondPayment = app(TenantTransaction::class)->asTenant($fixture['tenantId'], fn () => Payment::query()->findOrFail($second['paymentId']));
    $expectedTenantNet += $secondPayment->amount - $secondPayment->fee_amount - $secondPayment->commission_amount;
    assertCapstoneInvariants($fixture['tenantId'], $expectedTenantNet);

    $fullRefund = capstoneRefund($fixture, $second['paymentId']);
    $fullRefundDelivery = app(FakeGateway::class)->refundCompletionWebhook('fake_rf_'.$fullRefund['id']);
    capstoneDeliverWebhook($fullRefundDelivery)->assertStatus(200);
    capstoneSweep();

    $freshFullRefund = app(TenantTransaction::class)->asTenant(
        $fixture['tenantId'],
        fn () => DB::table('refunds')->where('id', $fullRefund['id'])->first(),
    );
    $expectedTenantNet -= ((int) $freshFullRefund->amount - (int) $freshFullRefund->commission_amount);
    assertCapstoneInvariants($fixture['tenantId'], $expectedTenantNet);

    expect($expectedTenantNet)->toBeGreaterThan(0);

    // Payout: created then paid, draining the full accumulated tenant
    // net balance to zero, proving the payout equals the tenant's net
    // balance drawn down.
    capstoneDeliverWebhook(app(FakeGateway::class)->payoutCreatedWebhook($accountReference, 'fake_po_1', Money::of($expectedTenantNet, 'USD')))
        ->assertStatus(200);
    capstoneDeliverWebhook(app(FakeGateway::class)->payoutStatusWebhook($accountReference, 'fake_po_1', PayoutStatus::Paid))
        ->assertStatus(200);
    capstoneSweep();

    $payout = app(TenantTransaction::class)->asTenant(
        $fixture['tenantId'],
        fn () => Payout::query()->where('gateway_reference', 'fake_po_1')->firstOrFail(),
    );

    expect($payout->status)->toBe(PayoutStatus::Paid)
        ->and($payout->money)->toEqual(Money::of($expectedTenantNet, 'USD'));

    assertCapstoneInvariants($fixture['tenantId'], 0);
});

it('keeps the books balanced under a declined payment, an expired payment, duplicate webhooks everywhere, and a failed payout that leaves the ledger untouched', function (): void {
    $fixture = capstoneTenant();
    $this->tenantIds[] = $fixture['tenantId'];

    $accountReference = capstoneOnboard($fixture);
    assertCapstoneInvariants($fixture['tenantId'], 0);

    // Declined payment: no payment row, ledger untouched.
    capstonePurchase($fixture, 'sync_decline');
    capstoneSweep();
    assertCapstoneInvariants($fixture['tenantId'], 0);

    // Expired payment: hold released, awaiting-payment payment expired, ledger untouched.
    capstonePurchase($fixture, 'expire');
    capstoneSweep();
    assertCapstoneInvariants($fixture['tenantId'], 0);

    // A confirmed purchase whose confirmation webhook is delivered
    // twice; the duplicate must not double the ledger effect.
    $purchase = capstonePurchase($fixture, 'async_confirm');
    $confirmDelivery = app(FakeGateway::class)->confirmationWebhook('fake_'.$purchase['paymentId'], Money::of(300, 'USD'), eventId: 'evt_capstone_confirm_dup');
    capstoneDeliverWebhook($confirmDelivery)->assertStatus(200);
    capstoneSweep();

    $payment = app(TenantTransaction::class)->asTenant($fixture['tenantId'], fn () => Payment::query()->findOrFail($purchase['paymentId']));
    $expectedTenantNet = $payment->amount - $payment->fee_amount - $payment->commission_amount;
    assertCapstoneInvariants($fixture['tenantId'], $expectedTenantNet);

    capstoneDeliverWebhook($confirmDelivery)->assertStatus(200);
    capstoneSweep();
    assertCapstoneInvariants($fixture['tenantId'], $expectedTenantNet);

    // A full refund whose completion webhook is delivered twice.
    $refund = capstoneRefund($fixture, $purchase['paymentId']);
    $refundDelivery = app(FakeGateway::class)->refundCompletionWebhook('fake_rf_'.$refund['id']);
    capstoneDeliverWebhook($refundDelivery)->assertStatus(200);
    capstoneDeliverWebhook($refundDelivery)->assertStatus(200);
    capstoneSweep();

    $freshRefund = app(TenantTransaction::class)->asTenant(
        $fixture['tenantId'],
        fn () => DB::table('refunds')->where('id', $refund['id'])->first(),
    );
    $expectedTenantNet -= ((int) $freshRefund->amount - (int) $freshRefund->commission_amount);
    assertCapstoneInvariants($fixture['tenantId'], $expectedTenantNet);

    // A payout that fails: created then failed, delivered twice each,
    // records no PayoutExecuted event and leaves the ledger untouched.
    $createdDelivery = app(FakeGateway::class)->payoutCreatedWebhook($accountReference, 'fake_po_fail', Money::of(5_000, 'USD'), eventId: 'evt_capstone_payout_created_dup');
    capstoneDeliverWebhook($createdDelivery)->assertStatus(200);
    capstoneDeliverWebhook($createdDelivery)->assertStatus(200);

    $failedDelivery = app(FakeGateway::class)->payoutStatusWebhook($accountReference, 'fake_po_fail', PayoutStatus::Failed, eventId: 'evt_capstone_payout_failed_dup');
    capstoneDeliverWebhook($failedDelivery)->assertStatus(200);
    capstoneDeliverWebhook($failedDelivery)->assertStatus(200);
    capstoneSweep();

    $failedPayout = app(TenantTransaction::class)->asTenant(
        $fixture['tenantId'],
        fn () => Payout::query()->where('gateway_reference', 'fake_po_fail')->firstOrFail(),
    );

    expect($failedPayout->status)->toBe(PayoutStatus::Failed);

    $payoutExecutedCount = app(TenantTransaction::class)->asPlatform(
        fn () => DB::table('outbox_events')->where('type', 'PayoutExecuted')->where('aggregate_id', $failedPayout->id)->count(),
    );

    expect($payoutExecutedCount)->toBe(0);

    $ledgerReferencesPayoutRow = app(TenantTransaction::class)->asTenant(
        $fixture['tenantId'],
        fn () => DB::table('ledger_entries')->where('reference_type', 'payout')->where('reference_id', $failedPayout->id)->exists(),
    );

    expect($ledgerReferencesPayoutRow)->toBeFalse();

    // The failed payout leaves the tenant_net balance exactly where the
    // refund left it.
    assertCapstoneInvariants($fixture['tenantId'], $expectedTenantNet);
});
