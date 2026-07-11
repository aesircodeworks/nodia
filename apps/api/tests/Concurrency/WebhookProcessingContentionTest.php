<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Models\TicketTypeInventory;
use App\Orders\Actions\ConvertHoldToOrder;
use App\Orders\Actions\ResolveOrderForPayment;
use App\Orders\Data\CreateOrderData;
use App\Orders\Models\Order;
use App\Payments\Actions\IngestGatewayWebhook;
use App\Payments\Actions\InitiatePayment;
use App\Payments\Data\InitiatePaymentData;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Jobs\ProcessGatewayWebhook;
use App\Payments\Models\Payment;
use App\Support\Money\Money;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;

/**
 * Stage-08a plan, Slice 6 concurrency: duplicate webhooks processed in
 * parallel still produce one payment transition, one order transition,
 * one ticket batch. Written before ProcessGatewayWebhook exists per the
 * master plan's non-negotiable rule for consumers.
 */
beforeEach(function (): void {
    MigratedDatabase::ensure();
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

it('applies exactly one transition when duplicate confirmation webhooks process in parallel', function (): void {
    $tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create(['enabled_gateways' => ['fake']])->id,
    );

    $paymentId = app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): string {
        $event = Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id]);

        TicketTypeInventory::factory()->create([
            'tenant_id' => $tenantId,
            'ticket_type_id' => $ticketType->id,
            'quantity' => 100,
            'held' => 0,
            'sold' => 0,
        ]);

        $customer = Customer::factory()->create(['tenant_id' => $tenantId]);

        $holdId = app(CreateHold::class)(
            CreateHoldData::from([
                'event_id' => $event->id,
                'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
            ]),
            $customer->id,
        )->id;

        $orderId = app(ConvertHoldToOrder::class)(CreateOrderData::from(['hold_id' => $holdId]), $customer->id)->id;

        $context = app(ResolveOrderForPayment::class)($orderId, $customer->id);

        return app(InitiatePayment::class)($context, InitiatePaymentData::from(['method' => 'pix']), 'contention-key')->payment->id;
    });

    $gateway = app(FakeGateway::class);
    $fee = Money::of(250, 'USD');

    $rowIds = array_map(function (string $eventId) use ($gateway, $paymentId, $fee): string {
        $delivery = $gateway->confirmationWebhook('fake_'.$paymentId, $fee, eventId: $eventId);

        return app(IngestGatewayWebhook::class)($gateway, $delivery->body, $delivery->headers);
    }, ['evt_parallel_1', 'evt_parallel_2']);

    ParallelRunner::runEach(...array_map(
        fn (string $rowId): Closure => function () use ($rowId): bool {
            (new ProcessGatewayWebhook($rowId))->handle();

            return true;
        },
        $rowIds,
    ));

    [$confirmedCount, $paidCount, $ticketCount, $payment] = app(TenantTransaction::class)->asPlatform(fn (): array => [
        OutboxEvent::query()->where('type', 'PaymentConfirmed')->count(),
        Order::query()->where('status', 'paid')->count(),
        DB::table('tickets')->count(),
        Payment::query()->findOrFail($paymentId),
    ]);

    expect($confirmedCount)->toBe(1)
        ->and($paidCount)->toBe(1)
        ->and($ticketCount)->toBe(2)
        ->and($payment->status->value)->toBe('confirmed');
});
