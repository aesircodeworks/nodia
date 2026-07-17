<?php

use App\EventCatalog\Models\Event;
use App\Identity\Models\Customer;
use App\Orders\Actions\FindAwaitingPaymentOrders;
use App\Orders\Data\AwaitingPaymentOrderData;
use App\Orders\Enums\OrderStatus;
use App\Orders\Models\Order;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-12 plan, Slice 5, task breakdown item 12: the candidate lookup
 * App\Payments\Actions\ReconcileNamedOrders reads back through this
 * Action rather than touching the orders table directly
 * (event-conventions). Each invariant here is a bound the payments
 * :reconcile-orders command relies on to poll exactly the named orders.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-13T12:00:00Z'));

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $this->otherTenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();

    foreach ([$this->tenantId, $this->otherTenantId] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('orders')->where('tenant_id', $tenantId)->delete();
            DB::table('customers')->where('tenant_id', $tenantId)->delete();
            DB::table('events')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKey($this->tenantId)->delete();
        Tenant::query()->whereKey($this->otherTenantId)->delete();
    });
});

function awaitingPaymentOrder(string $tenantId, array $overrides = []): Order
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Order::factory()->create(array_merge([
            'tenant_id' => $tenantId,
            'customer_id' => Customer::factory()->create(['tenant_id' => $tenantId])->id,
            'event_id' => Event::factory()->create(['tenant_id' => $tenantId])->id,
            'status' => OrderStatus::AwaitingPayment,
        ], $overrides)),
    );
}

it('returns only awaiting_payment orders, excluding every other status', function (): void {
    $awaiting = awaitingPaymentOrder($this->tenantId);
    awaitingPaymentOrder($this->tenantId, ['status' => OrderStatus::Paid]);

    $find = new FindAwaitingPaymentOrders;
    $result = app(TenantTransaction::class)->asPlatform(
        fn () => $find([], null, CarbonImmutable::now()),
    );

    expect($result)->toHaveCount(1)
        ->and($result->first())->toBeInstanceOf(AwaitingPaymentOrderData::class)
        ->and($result->first()->id)->toBe($awaiting->id)
        ->and($result->first()->tenantId)->toBe($this->tenantId);
});

it('filters by the given order ID allowlist, never returning an unnamed order', function (): void {
    $named = awaitingPaymentOrder($this->tenantId);
    awaitingPaymentOrder($this->tenantId);

    $find = new FindAwaitingPaymentOrders;
    $result = app(TenantTransaction::class)->asPlatform(
        fn () => $find([$named->id], null, CarbonImmutable::now()),
    );

    expect($result)->toHaveCount(1)
        ->and($result->first()->id)->toBe($named->id);
});

it('filters by tenant when given, never returning another tenant\'s order', function (): void {
    awaitingPaymentOrder($this->tenantId);
    awaitingPaymentOrder($this->otherTenantId);

    $find = new FindAwaitingPaymentOrders;
    $result = app(TenantTransaction::class)->asPlatform(
        fn () => $find([], $this->tenantId, CarbonImmutable::now()),
    );

    expect($result)->toHaveCount(1)
        ->and($result->first()->tenantId)->toBe($this->tenantId);
});

it('excludes an order created after the before cutoff', function (): void {
    $before = awaitingPaymentOrder($this->tenantId);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-13T13:00:00Z'));
    awaitingPaymentOrder($this->tenantId);

    $find = new FindAwaitingPaymentOrders;
    $result = app(TenantTransaction::class)->asPlatform(
        fn () => $find([], $this->tenantId, CarbonImmutable::parse('2026-07-13T12:30:00Z')),
    );

    expect($result)->toHaveCount(1)
        ->and($result->first()->id)->toBe($before->id);
});

it('combines an order allowlist and a tenant filter as an intersection', function (): void {
    $named = awaitingPaymentOrder($this->tenantId);
    awaitingPaymentOrder($this->otherTenantId);

    $find = new FindAwaitingPaymentOrders;

    $result = app(TenantTransaction::class)->asPlatform(
        fn () => $find([$named->id], $this->otherTenantId, CarbonImmutable::now()),
    );

    expect($result)->toBeEmpty();
});
