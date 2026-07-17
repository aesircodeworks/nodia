<?php

use App\EventCatalog\Models\Event;
use App\Identity\Models\Customer;
use App\Orders\Models\Order;
use App\Payments\Actions\ConfirmPayment;
use App\Payments\Actions\ExpirePayment;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Models\Payment;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;

/**
 * The payment terminal-status race (stage-08a plan, Slice 2): a webhook
 * confirm racing the expiry sweeper on one initiated payment must end
 * in exactly one terminal status. Written before the transition Actions
 * exist per the master plan's non-negotiable rule for guarded
 * transitions.
 */
beforeEach(function (): void {
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ($tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
            DB::table('media')->where('tenant_id', $tenantId)->delete();
            DB::table('tickets')->where('tenant_id', $tenantId)->delete();
            DB::table('order_items')->where('tenant_id', $tenantId)->delete();
            DB::table('payments')->where('tenant_id', $tenantId)->delete();
            DB::table('orders')->where('tenant_id', $tenantId)->delete();
            DB::table('customers')->where('tenant_id', $tenantId)->delete();
            DB::table('events')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

it('resolves a parallel confirm and expire on one initiated payment to exactly one terminal status', function (): void {
    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $paymentId = app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): string {
        $customer = Customer::factory()->create(['tenant_id' => $tenantId]);
        $event = Event::factory()->create(['tenant_id' => $tenantId]);
        $order = Order::factory()->create([
            'tenant_id' => $tenantId,
            'customer_id' => $customer->id,
            'event_id' => $event->id,
        ]);

        return Payment::factory()->create([
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'status' => PaymentStatus::Initiated,
            'expires_at' => now()->addMinutes(30),
        ])->id;
    });

    $results = ParallelRunner::runEach(
        function () use ($tenantId, $paymentId): string {
            $confirmed = app(TenantTransaction::class)->asTenant(
                $tenantId,
                fn () => app(ConfirmPayment::class)($paymentId, Money::of(125, 'USD')),
            );

            return $confirmed !== null ? 'confirmed' : 'lost';
        },
        function () use ($tenantId, $paymentId): string {
            $expired = app(TenantTransaction::class)->asTenant(
                $tenantId,
                fn () => app(ExpirePayment::class)($paymentId),
            );

            return $expired !== null ? 'expired' : 'lost';
        },
    );

    $payment = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Payment::query()->findOrFail($paymentId),
    );

    $winners = array_values(array_filter($results, fn (string $result): bool => $result !== 'lost'));

    expect($winners)->toHaveCount(1)
        ->and($payment->status->value)->toBe($winners[0]);
});
