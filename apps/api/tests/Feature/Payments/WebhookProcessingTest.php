<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Actions\ReleaseHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Enums\HoldStatus;
use App\Inventory\Models\Hold;
use App\Inventory\Models\TicketTypeInventory;
use App\Orders\Enums\OrderStatus;
use App\Orders\Models\Order;
use App\Payments\Enums\GatewayWebhookStatus;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Gateways\FakeWebhookDelivery;
use App\Payments\Jobs\ProcessGatewayWebhook;
use App\Payments\Models\Payment;
use App\Support\Audit\Models\ActivityLogEntry;
use App\Support\Money\Money;
use App\Support\Outbox\Models\OutboxEvent;
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
 * Stage-08a plan, Slice 6: the async purchase end to end over HTTP, the
 * failure webhook, duplicate deliveries, late and unmatched events, and
 * the confirm-after-hold-expiry compensating path.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    $this->travelTo(CarbonImmutable::parse('2026-07-11T12:00:00Z'));
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    // activity_log is append-only (no DELETE granted to any role), so its
    // rows accumulate for the process like ActivityLogFixture documents;
    // assertions scope by properties instead of counting globally.
    app(TenantTransaction::class)->asTenant($sentinel, function (): void {
        DB::table('gateway_webhook_events')->delete();
    });

    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ($tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('payments')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
            DB::table('media')->where('tenant_id', $tenantId)->delete();
            DB::table('tickets')->where('tenant_id', $tenantId)->delete();
            DB::table('order_items')->where('tenant_id', $tenantId)->delete();
            DB::table('orders')->where('tenant_id', $tenantId)->delete();
            DB::table('hold_items')->where('tenant_id', $tenantId)->delete();
            DB::table('holds')->where('tenant_id', $tenantId)->delete();
            DB::table('customers')->where('tenant_id', $tenantId)->delete();
            DB::table('ticket_type_inventory')->where('tenant_id', $tenantId)->delete();
            DB::table('ticket_types')->where('tenant_id', $tenantId)->delete();
            DB::table('events')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

/**
 * An awaiting_payment order with an initiated pix payment, built over
 * the real endpoints.
 *
 * @return array{host: string, tenantId: string, token: string, orderId: string, holdId: string, paymentId: string, reference: string, ticketTypeId: string}
 */
function processingFixture(): array
{
    ['tenant' => $tenant, 'host' => $host] = app(TenantTransaction::class)->asPlatform(function (): array {
        $tenant = Tenant::factory()->create(['enabled_gateways' => ['fake']]);
        $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

        return ['tenant' => $tenant, 'host' => $domain->domain];
    });

    ['event' => $event, 'ticketType' => $ticketType] = app(TenantTransaction::class)->asTenant($tenant->id, function () use ($tenant): array {
        $event = Event::factory()->create(['tenant_id' => $tenant->id, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

        TicketTypeInventory::factory()->create([
            'tenant_id' => $tenant->id,
            'ticket_type_id' => $ticketType->id,
            'quantity' => 100,
            'held' => 0,
            'sold' => 0,
        ]);

        return ['event' => $event, 'ticketType' => $ticketType];
    });

    $customer = app(TenantTransaction::class)->asTenant(
        $tenant->id,
        fn () => Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'processing-buyer@example.com',
            'password' => 'password',
        ]),
    );

    $token = test()->postJson('http://'.$host.'/v1/auth/customer/token', [
        'email' => 'processing-buyer@example.com',
        'password' => 'password',
    ])->json('access_token');

    $holdId = app(TenantTransaction::class)->asTenant(
        $tenant->id,
        fn () => app(CreateHold::class)(
            CreateHoldData::from([
                'event_id' => $event->id,
                'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
            ]),
            $customer->id,
        )->id,
    );

    $orderId = test()->postJson('http://'.$host.'/v1/storefront/orders', [
        'hold_id' => $holdId,
    ], ['Authorization' => 'Bearer '.$token])->assertStatus(201)->json('id');

    Auth::forgetGuards();

    $paymentId = test()->postJson('http://'.$host.'/v1/storefront/orders/'.$orderId.'/payments', [
        'method' => 'pix',
    ], ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => (string) Str::uuid7()])->assertStatus(201)->json('id');

    return [
        'host' => $host,
        'tenantId' => $tenant->id,
        'token' => $token,
        'orderId' => $orderId,
        'holdId' => $holdId,
        'paymentId' => $paymentId,
        'reference' => 'fake_'.$paymentId,
        'ticketTypeId' => $ticketType->id,
    ];
}

function deliverWebhook(FakeWebhookDelivery $delivery)
{
    return test()->call('POST', '/v1/webhooks/fake', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_FAKE_SIGNATURE' => $delivery->headers['X-Fake-Signature'],
    ], $delivery->body);
}

describe('webhook-driven payment processing', function (): void {
    it('drives the async purchase to paid end to end', function (): void {
        $fixture = processingFixture();
        $fee = Money::of(250, 'USD');

        deliverWebhook(app(FakeGateway::class)->confirmationWebhook($fixture['reference'], $fee))->assertStatus(200);

        [$order, $payment, $ticketCount, $hold, $inventory, $confirmedPayload, $row, $auditCount] = app(TenantTransaction::class)->asPlatform(fn (): array => [
            Order::query()->findOrFail($fixture['orderId']),
            Payment::query()->findOrFail($fixture['paymentId']),
            DB::table('tickets')->where('order_id', $fixture['orderId'])->count(),
            Hold::query()->findOrFail($fixture['holdId']),
            TicketTypeInventory::query()->where('ticket_type_id', $fixture['ticketTypeId'])->firstOrFail(),
            OutboxEvent::query()->where('type', 'PaymentConfirmed')->where('aggregate_id', $fixture['paymentId'])->firstOrFail()->payload,
            DB::table('gateway_webhook_events')->first(),
            ActivityLogEntry::query()->where('event', 'platform_role_use')->where('properties->resolved_tenant_id', $fixture['tenantId'])->count(),
        ]);

        expect($order->status)->toBe(OrderStatus::Paid)
            ->and($payment->status)->toBe(PaymentStatus::Confirmed)
            ->and($payment->fee_amount)->toBe(250)
            ->and($payment->commission_amount)->toBe(0)
            ->and($ticketCount)->toBe(2)
            ->and($hold->status)->toBe(HoldStatus::Committed)
            ->and($inventory->sold)->toBe(2)
            ->and($inventory->held)->toBe(0)
            ->and($confirmedPayload['fee'])->toBe(['amount' => 250, 'currency' => 'USD'])
            ->and($row->status)->toBe('processed')
            ->and($auditCount)->toBeGreaterThan(0);
    });

    it('drives awaiting_payment to failed and releases the hold on a failure webhook', function (): void {
        $fixture = processingFixture();

        deliverWebhook(app(FakeGateway::class)->failureWebhook($fixture['reference'], 'insufficient_funds'))->assertStatus(200);

        [$order, $payment, $hold, $inventory] = app(TenantTransaction::class)->asTenant($fixture['tenantId'], fn (): array => [
            Order::query()->findOrFail($fixture['orderId']),
            Payment::query()->findOrFail($fixture['paymentId']),
            Hold::query()->findOrFail($fixture['holdId']),
            TicketTypeInventory::query()->where('ticket_type_id', $fixture['ticketTypeId'])->firstOrFail(),
        ]);

        expect($order->status)->toBe(OrderStatus::Failed)
            ->and($payment->status)->toBe(PaymentStatus::Failed)
            ->and($payment->failure_code)->toBe('insufficient_funds')
            ->and($hold->status)->toBe(HoldStatus::Released)
            ->and($inventory->held)->toBe(0)
            ->and($inventory->sold)->toBe(0);
    });

    it('produces one transition, one ticket batch under five duplicate deliveries and a re-run job', function (): void {
        $fixture = processingFixture();
        $fee = Money::of(250, 'USD');

        $delivery = app(FakeGateway::class)->confirmationWebhook($fixture['reference'], $fee, eventId: 'evt_storm');

        foreach (range(1, 5) as $ignored) {
            deliverWebhook($delivery)->assertStatus(200);
        }

        $rowId = app(TenantTransaction::class)->asPlatform(
            fn () => DB::table('gateway_webhook_events')->value('id'),
        );

        (new ProcessGatewayWebhook((string) $rowId))->handle();
        (new ProcessGatewayWebhook((string) $rowId))->handle();

        [$rowCount, $ticketCount, $confirmedCount, $paidOrders] = app(TenantTransaction::class)->asPlatform(fn (): array => [
            DB::table('gateway_webhook_events')->count(),
            DB::table('tickets')->where('order_id', $fixture['orderId'])->count(),
            OutboxEvent::query()->where('type', 'PaymentConfirmed')->where('aggregate_id', $fixture['paymentId'])->count(),
            Order::query()->whereKey($fixture['orderId'])->where('status', OrderStatus::Paid)->count(),
        ]);

        expect($rowCount)->toBe(1)
            ->and($ticketCount)->toBe(2)
            ->and($confirmedCount)->toBe(1)
            ->and($paidOrders)->toBe(1);
    });

    it('ignores a confirm arriving past the window with the sweeper lagging', function (): void {
        $fixture = processingFixture();

        $this->travelTo(CarbonImmutable::parse('2026-07-11T12:31:00Z'));

        deliverWebhook(app(FakeGateway::class)->confirmationWebhook($fixture['reference'], Money::of(250, 'USD')))->assertStatus(200);

        [$payment, $order, $row] = app(TenantTransaction::class)->asPlatform(fn (): array => [
            Payment::query()->findOrFail($fixture['paymentId']),
            Order::query()->findOrFail($fixture['orderId']),
            DB::table('gateway_webhook_events')->first(),
        ]);

        expect($payment->status)->toBe(PaymentStatus::Initiated)
            ->and($order->status)->toBe(OrderStatus::AwaitingPayment)
            ->and($row->status)->toBe(GatewayWebhookStatus::Ignored->value);
    });

    it('marks an unmatched gateway reference ignored', function (): void {
        processingFixture();

        deliverWebhook(app(FakeGateway::class)->confirmationWebhook('fake_'.Str::uuid7(), Money::of(250, 'USD')))->assertStatus(200);

        $row = app(TenantTransaction::class)->asPlatform(
            fn () => DB::table('gateway_webhook_events')->orderByDesc('received_at')->first(),
        );

        expect($row->status)->toBe(GatewayWebhookStatus::Ignored->value);
    });

    it('takes the compensating path when the confirmed payment finds a dead hold', function (): void {
        $fixture = processingFixture();

        app(TenantTransaction::class)->asTenant(
            $fixture['tenantId'],
            fn () => app(ReleaseHold::class)($fixture['holdId']),
        );

        deliverWebhook(app(FakeGateway::class)->confirmationWebhook($fixture['reference'], Money::of(250, 'USD')))->assertStatus(200);

        [$order, $payment, $ticketCount, $mismatchAudits] = app(TenantTransaction::class)->asPlatform(fn (): array => [
            Order::query()->findOrFail($fixture['orderId']),
            Payment::query()->findOrFail($fixture['paymentId']),
            DB::table('tickets')->where('order_id', $fixture['orderId'])->count(),
            ActivityLogEntry::query()->where('event', 'payment_confirmed_after_hold_expired')->where('properties->order_id', $fixture['orderId'])->count(),
        ]);

        expect($order->status)->toBe(OrderStatus::Expired)
            ->and($payment->status)->toBe(PaymentStatus::Confirmed)
            ->and($ticketCount)->toBe(0)
            ->and($mismatchAudits)->toBe(1);
    });
});
