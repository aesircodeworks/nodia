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
use App\Payments\Actions\InitiatePayment;
use App\Payments\Data\InitiatePaymentData;
use App\Payments\Models\Payment;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;

/**
 * Stage-08a plan, Slice 4 concurrency: parallel initiations with the
 * same Idempotency-Key produce one payment row and identical results.
 * Written before InitiatePayment exists per the master plan's
 * non-negotiable rule for guarded transitions.
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

it('produces one payment row and identical results under parallel same-key initiation', function (): void {
    $tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create(['enabled_gateways' => ['fake']])->id,
    );

    $orderId = app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): string {
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
                'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
            ]),
            $customer->id,
        )->id;

        return app(ConvertHoldToOrder::class)(CreateOrderData::from(['hold_id' => $holdId]), $customer->id)->id;
    });

    $key = 'race-key-1';

    $results = ParallelRunner::run(4, function () use ($tenantId, $orderId, $key): array {
        $result = app(TenantTransaction::class)->asTenant($tenantId, function () use ($orderId, $key) {
            $customerId = Order::query()->findOrFail($orderId)->customer_id;
            $context = app(ResolveOrderForPayment::class)($orderId, $customerId);

            return app(InitiatePayment::class)(
                $context,
                InitiatePaymentData::from(['method' => 'card', 'details' => ['token' => 'tok_approve']]),
                $key,
            );
        });

        return [
            'payment_id' => $result->payment->id,
            'status' => $result->payment->status->value,
            'replayed' => $result->replayed,
        ];
    });

    $paymentCount = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Payment::query()->where('order_id', $orderId)->count(),
    );

    $ids = array_unique(array_column($results, 'payment_id'));
    $statuses = array_unique(array_column($results, 'status'));
    $fresh = array_filter($results, fn (array $result): bool => ! $result['replayed']);

    expect($paymentCount)->toBe(1)
        ->and($ids)->toHaveCount(1)
        ->and($statuses)->toBe(['confirmed'])
        ->and($fresh)->toHaveCount(1);
});
