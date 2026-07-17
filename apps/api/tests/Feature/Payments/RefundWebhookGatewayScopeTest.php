<?php

use App\EventCatalog\Models\Event;
use App\Identity\Models\Customer;
use App\Orders\Models\Order;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Enums\RefundStatus;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Gateways\FakeWebhookDelivery;
use App\Payments\Models\Payment;
use App\Payments\Models\Refund;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    app(TenantTransaction::class)->asTenant(
        $sentinel,
        fn () => DB::table('gateway_webhook_events')->delete(),
    );

    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ($tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('refunds')->where('tenant_id', $tenantId)->delete();
            DB::table('payments')->where('tenant_id', $tenantId)->delete();
            DB::table('orders')->where('tenant_id', $tenantId)->delete();
            DB::table('customers')->where('tenant_id', $tenantId)->delete();
            DB::table('events')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->delete(),
    );
});

/**
 * @return array{tenant_id: string, refund_id: string}
 */
function gatewayScopedRefund(string $gateway, string $refundReference): array
{
    $tenant = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create());

    return app(TenantTransaction::class)->asTenant($tenant->id, function () use ($gateway, $refundReference, $tenant): array {
        $event = Event::factory()->create(['tenant_id' => $tenant->id]);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $order = Order::factory()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'event_id' => $event->id,
        ]);
        $payment = Payment::factory()->create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'gateway' => $gateway,
            'gateway_reference' => $gateway.'_payment_reference',
            'money' => Money::of(5000, 'USD'),
            'status' => PaymentStatus::Confirmed,
        ]);

        DB::table('payments')->where('id', $payment->id)->update(['refunded_amount' => 1000]);

        $refund = Refund::factory()->create([
            'tenant_id' => $tenant->id,
            'payment_id' => $payment->id,
            'money' => Money::of(1000, 'USD'),
            'status' => RefundStatus::Processing,
            'gateway_reference' => $refundReference,
        ]);

        return ['tenant_id' => $tenant->id, 'refund_id' => $refund->id];
    });
}

function deliverGatewayScopedRefundWebhook(FakeWebhookDelivery $delivery): void
{
    test()->call('POST', '/v1/webhooks/fake', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        ...$delivery->serverHeaders(),
    ], $delivery->body)->assertOk();
}

it('resolves a refund reference only through a payment owned by the webhook gateway', function (): void {
    $sharedReference = 'shared-provider-refund-reference';
    $otherGateway = gatewayScopedRefund('pending', $sharedReference);
    $fakeGateway = gatewayScopedRefund('fake', $sharedReference);

    deliverGatewayScopedRefundWebhook(
        app(FakeGateway::class)->refundFailureWebhook($sharedReference, 'declined'),
    );

    [$otherStatus, $fakeStatus] = app(TenantTransaction::class)->asPlatform(fn (): array => [
        Refund::query()->findOrFail($otherGateway['refund_id'])->status,
        Refund::query()->findOrFail($fakeGateway['refund_id'])->status,
    ]);

    expect($otherStatus)->toBe(RefundStatus::Processing)
        ->and($fakeStatus)->toBe(RefundStatus::Failed);
});
