<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Enums\HoldStatus;
use App\Inventory\Models\Hold;
use App\Inventory\Models\TicketTypeInventory;
use App\Orders\Enums\OrderStatus;
use App\Orders\Models\Order;
use App\Payments\Actions\SweepExpiredPayments;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Gateways\FakeGatewayScenarios;
use App\Payments\Gateways\NormalizedPaymentEvent;
use App\Payments\Models\Payment;
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
 * Stage-08a plan, Slice 7: the expiry sweeper and the reconciliation
 * poller. A payment past its method window expires with PaymentExpired
 * recorded, the order expires, the hold releases, and availability
 * recovers exactly; the poller resolves an awaiting_payment order whose
 * webhook was never delivered, idempotently against webhooks.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    $this->travelTo(CarbonImmutable::parse('2026-07-11T12:00:00Z'));
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

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
 * An awaiting_payment order with an initiated async payment, built over
 * the real endpoints.
 *
 * @return array{host: string, tenantId: string, orderId: string, holdId: string, paymentId: string, ticketTypeId: string}
 */
function expiryFixture(string $method = 'pix'): array
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
            'email' => 'expiry-buyer@example.com',
            'password' => 'password',
        ]),
    );

    $token = test()->postJson('http://'.$host.'/v1/auth/customer/token', [
        'email' => 'expiry-buyer@example.com',
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
        'method' => $method,
    ], ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => (string) Str::uuid7()])->assertStatus(201)->json('id');

    return [
        'host' => $host,
        'tenantId' => $tenant->id,
        'orderId' => $orderId,
        'holdId' => $holdId,
        'paymentId' => $paymentId,
        'ticketTypeId' => $ticketType->id,
    ];
}

describe('payments:expire', function (): void {
    it('expires a payment past the pix window, expiring the order and restoring availability exactly', function (): void {
        $fixture = expiryFixture();

        $this->travelTo(CarbonImmutable::parse('2026-07-11T12:31:00Z'));

        test()->artisan('payments:expire')->assertSuccessful();

        [$payment, $order, $hold, $inventory, $expiredCount] = app(TenantTransaction::class)->asTenant($fixture['tenantId'], fn (): array => [
            Payment::query()->findOrFail($fixture['paymentId']),
            Order::query()->findOrFail($fixture['orderId']),
            Hold::query()->findOrFail($fixture['holdId']),
            TicketTypeInventory::query()->where('ticket_type_id', $fixture['ticketTypeId'])->firstOrFail(),
            OutboxEvent::query()->where('type', 'PaymentExpired')->where('aggregate_id', $fixture['paymentId'])->count(),
        ]);

        expect($payment->status)->toBe(PaymentStatus::Expired)
            ->and($expiredCount)->toBe(1)
            ->and($order->status)->toBe(OrderStatus::Expired)
            ->and($hold->status)->toBe(HoldStatus::Released)
            ->and($inventory->held)->toBe(0)
            ->and($inventory->sold)->toBe(0);
    });

    it('keeps a boleto payment alive across the shorter pix window', function (): void {
        $fixture = expiryFixture('boleto');

        $this->travelTo(CarbonImmutable::parse('2026-07-11T12:31:00Z'));

        test()->artisan('payments:expire')->assertSuccessful();

        [$payment, $order] = app(TenantTransaction::class)->asTenant($fixture['tenantId'], fn (): array => [
            Payment::query()->findOrFail($fixture['paymentId']),
            Order::query()->findOrFail($fixture['orderId']),
        ]);

        expect($payment->status)->toBe(PaymentStatus::Initiated)
            ->and($order->status)->toBe(OrderStatus::AwaitingPayment);
    });

    it('selects only initiated payments past their window', function (): void {
        $fixture = expiryFixture();

        // Still inside the pix window: nothing to sweep.
        $this->travelTo(CarbonImmutable::parse('2026-07-11T12:29:00Z'));

        expect(app(SweepExpiredPayments::class)())->toBe(0);

        $this->travelTo(CarbonImmutable::parse('2026-07-11T12:31:00Z'));

        expect(app(SweepExpiredPayments::class)())->toBe(1)
            // Terminal now: the second sweep affects zero rows.
            ->and(app(SweepExpiredPayments::class)())->toBe(0);
    });
});

describe('payments:reconcile', function (): void {
    it('resolves an awaiting_payment order whose webhook was never delivered', function (): void {
        $fixture = expiryFixture();

        app(FakeGatewayScenarios::class)->scriptQueryResult(
            'fake_'.$fixture['paymentId'],
            NormalizedPaymentEvent::confirmed('fake_'.$fixture['paymentId'], Money::of(250, 'USD')),
        );

        $this->travelTo(CarbonImmutable::parse('2026-07-11T12:10:00Z'));

        test()->artisan('payments:reconcile')->assertSuccessful();

        [$payment, $order, $ticketCount, $confirmedCount] = app(TenantTransaction::class)->asTenant($fixture['tenantId'], fn (): array => [
            Payment::query()->findOrFail($fixture['paymentId']),
            Order::query()->findOrFail($fixture['orderId']),
            DB::table('tickets')->where('order_id', $fixture['orderId'])->count(),
            OutboxEvent::query()->where('type', 'PaymentConfirmed')->where('aggregate_id', $fixture['paymentId'])->count(),
        ]);

        expect($payment->status)->toBe(PaymentStatus::Confirmed)
            ->and($payment->fee_amount)->toBe(250)
            ->and($order->status)->toBe(OrderStatus::Paid)
            ->and($ticketCount)->toBe(2)
            ->and($confirmedCount)->toBe(1);

        // Poller and webhook double-processing is a zero-row no-op.
        test()->artisan('payments:reconcile')->assertSuccessful();

        $confirmedAfterRerun = app(TenantTransaction::class)->asTenant(
            $fixture['tenantId'],
            fn () => OutboxEvent::query()->where('type', 'PaymentConfirmed')->where('aggregate_id', $fixture['paymentId'])->count(),
        );

        expect($confirmedAfterRerun)->toBe(1);
    });

    it('applies a gateway-side failure, failing the order and releasing the hold', function (): void {
        $fixture = expiryFixture();

        app(FakeGatewayScenarios::class)->scriptQueryResult(
            'fake_'.$fixture['paymentId'],
            NormalizedPaymentEvent::failed('fake_'.$fixture['paymentId'], 'expired_at_gateway'),
        );

        $this->travelTo(CarbonImmutable::parse('2026-07-11T12:10:00Z'));

        test()->artisan('payments:reconcile')->assertSuccessful();

        [$payment, $order, $hold] = app(TenantTransaction::class)->asTenant($fixture['tenantId'], fn (): array => [
            Payment::query()->findOrFail($fixture['paymentId']),
            Order::query()->findOrFail($fixture['orderId']),
            Hold::query()->findOrFail($fixture['holdId']),
        ]);

        expect($payment->status)->toBe(PaymentStatus::Failed)
            ->and($payment->failure_code)->toBe('expired_at_gateway')
            ->and($order->status)->toBe(OrderStatus::Failed)
            ->and($hold->status)->toBe(HoldStatus::Released);
    });

    it('leaves fresh payments inside the grace period alone', function (): void {
        $fixture = expiryFixture();

        app(FakeGatewayScenarios::class)->scriptQueryResult(
            'fake_'.$fixture['paymentId'],
            NormalizedPaymentEvent::confirmed('fake_'.$fixture['paymentId'], Money::of(250, 'USD')),
        );

        test()->artisan('payments:reconcile')->assertSuccessful();

        $payment = app(TenantTransaction::class)->asTenant(
            $fixture['tenantId'],
            fn () => Payment::query()->findOrFail($fixture['paymentId']),
        );

        expect($payment->status)->toBe(PaymentStatus::Initiated);
    });
});
