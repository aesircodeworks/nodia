<?php

use App\EventCatalog\Models\Event;
use App\Identity\Capability;
use App\Identity\Models\Customer;
use App\Models\User;
use App\Orders\Enums\OrderStatus;
use App\Orders\Models\Order;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Enums\RefundStatus;
use App\Payments\Models\Payment;
use App\Payments\Models\Refund;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\TenantStaff;

/*
 * Stage-08b plan, Slice 8: refund show and admin list with explicit
 * allowlists, cursor-paginated ledger entries with deterministic order,
 * the per-currency balance summary, and capability denial on all three
 * surfaces.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $this->otherTenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    [$this->orderId, $this->paymentId] = app(TenantTransaction::class)->asTenant($this->tenantId, function (): array {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenantId]);
        $event = Event::factory()->create(['tenant_id' => $this->tenantId]);

        $order = Order::factory()->create([
            'tenant_id' => $this->tenantId,
            'customer_id' => $customer->id,
            'event_id' => $event->id,
            'status' => OrderStatus::Paid,
        ]);

        $payment = Payment::factory()->create([
            'tenant_id' => $this->tenantId,
            'order_id' => $order->id,
            'status' => PaymentStatus::Confirmed,
            'money' => Money::of(10_000, 'USD'),
            'gateway_reference' => 'fake_'.Str::uuid7(),
        ]);

        return [$order->id, $payment->id];
    });
});

afterEach(function (): void {
    DB::statement('truncate ledger_entries');

    foreach ([$this->tenantId, $this->otherTenantId] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            foreach (['outbox_deliveries', 'outbox_events', 'memberships', 'roles', 'refunds', 'payments', 'orders', 'customers', 'events'] as $table) {
                DB::table($table)->where('tenant_id', $tenantId)->delete();
            }
        });
    }

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKey($this->tenantId)->delete();
        Tenant::query()->whereKey($this->otherTenantId)->delete();
    });

    User::query()->delete();
});

function readHeaders(string $tenantId, Capability|array $capabilities): array
{
    return [
        'Authorization' => 'Bearer '.TenantStaff::token($tenantId, $capabilities),
        'X-Tenant-Id' => $tenantId,
    ];
}

function seededRefund(string $tenantId, string $paymentId, array $overrides = []): Refund
{
    return app(TenantTransaction::class)->asTenant($tenantId, fn () => Refund::factory()->create([
        'tenant_id' => $tenantId,
        'payment_id' => $paymentId,
        ...$overrides,
    ]));
}

function seededLedgerEntry(string $tenantId, array $overrides = []): string
{
    $id = Str::uuid7()->toString();

    app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $id, $overrides): void {
        DB::table('ledger_entries')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'account' => 'tenant_net',
            'direction' => 'credit',
            'amount' => 1_000,
            'currency' => 'USD',
            'reference_type' => 'payment',
            'reference_id' => Str::uuid7()->toString(),
            'source_event_id' => Str::uuid7()->toString(),
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    });

    return $id;
}

describe('GET /v1/refunds/{refund}', function (): void {
    it('returns the refund wire shape', function (): void {
        $refund = seededRefund($this->tenantId, $this->paymentId, ['status' => RefundStatus::Completed]);

        $this->getJson('/v1/refunds/'.$refund->id, readHeaders($this->tenantId, Capability::OrdersView))
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonPath('id', $refund->id)
            ->assertJsonPath('order_id', $this->orderId)
            ->assertJsonPath('status', 'completed');
    });

    it('returns refund_not_found for unknown and cross-tenant ids', function (): void {
        $refund = seededRefund($this->tenantId, $this->paymentId);

        $this->getJson('/v1/refunds/'.Str::uuid7(), readHeaders($this->tenantId, Capability::OrdersView))
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'refund_not_found');

        Auth::forgetGuards();

        $this->getJson('/v1/refunds/'.$refund->id, readHeaders($this->otherTenantId, Capability::OrdersView))
            ->assertNotFound()
            ->assertJsonPath('code', 'refund_not_found');
    });

    it('denies a bearer without orders.view', function (): void {
        $refund = seededRefund($this->tenantId, $this->paymentId);

        $this->getJson('/v1/refunds/'.$refund->id, readHeaders($this->tenantId, Capability::EventsView))
            ->assertStatus(403)
            ->assertJsonPath('code', 'missing_capability');
    });
});

describe('GET /v1/refunds', function (): void {
    it('cursor-paginates newest first and honors each allowed filter', function (): void {
        $first = seededRefund($this->tenantId, $this->paymentId, ['status' => RefundStatus::Completed]);
        $second = seededRefund($this->tenantId, $this->paymentId, ['status' => RefundStatus::Failed]);
        $headers = readHeaders($this->tenantId, Capability::OrdersView);

        $page = $this->getJson('/v1/refunds?per_page=1', $headers);
        $page->assertOk()->assertConformsToOpenApi();

        expect($page->json('data'))->toHaveCount(1)
            ->and($page->json('data.0.id'))->toBe($second->id)
            ->and($page->json('meta.next_cursor'))->not->toBeNull();

        $rest = $this->getJson('/v1/refunds?per_page=1&cursor='.$page->json('meta.next_cursor'), $headers);

        expect($rest->json('data.0.id'))->toBe($first->id);

        $byStatus = $this->getJson('/v1/refunds?filter[status]=failed', $headers);
        expect(array_column($byStatus->json('data'), 'id'))->toBe([$second->id]);

        $byPayment = $this->getJson('/v1/refunds?filter[payment_id]='.$this->paymentId, $headers);
        expect($byPayment->json('data'))->toHaveCount(2);

        $byOrder = $this->getJson('/v1/refunds?filter[order_id]='.$this->orderId, $headers);
        expect($byOrder->json('data'))->toHaveCount(2);

        $byOtherOrder = $this->getJson('/v1/refunds?filter[order_id]='.Str::uuid7(), $headers);
        expect($byOtherOrder->json('data'))->toHaveCount(0);
    });

    it('rejects unknown filters with invalid_query_parameter', function (): void {
        $this->getJson('/v1/refunds?filter[bogus]=1', readHeaders($this->tenantId, Capability::OrdersView))
            ->assertStatus(400)
            ->assertJsonPath('code', 'invalid_query_parameter');
    });

    it('never leaks another tenant refunds', function (): void {
        seededRefund($this->tenantId, $this->paymentId);

        [, $otherPaymentId] = app(TenantTransaction::class)->asTenant($this->otherTenantId, function (): array {
            $customer = Customer::factory()->create(['tenant_id' => $this->otherTenantId]);
            $event = Event::factory()->create(['tenant_id' => $this->otherTenantId]);

            $order = Order::factory()->create([
                'tenant_id' => $this->otherTenantId,
                'customer_id' => $customer->id,
                'event_id' => $event->id,
                'status' => OrderStatus::Paid,
            ]);

            $payment = Payment::factory()->create([
                'tenant_id' => $this->otherTenantId,
                'order_id' => $order->id,
                'status' => PaymentStatus::Confirmed,
                'money' => Money::of(10_000, 'USD'),
                'gateway_reference' => 'fake_'.Str::uuid7(),
            ]);

            return [$order->id, $payment->id];
        });
        seededRefund($this->otherTenantId, $otherPaymentId);

        $list = $this->getJson('/v1/refunds', readHeaders($this->tenantId, Capability::OrdersView));

        expect($list->json('data'))->toHaveCount(1);
    });
});

describe('GET /v1/ledger-entries', function (): void {
    it('cursor-paginates deterministically with the allowed filters', function (): void {
        $older = seededLedgerEntry($this->tenantId, ['account' => 'gateway_receivable', 'direction' => 'debit', 'created_at' => now()->subMinute()]);
        $newer = seededLedgerEntry($this->tenantId, ['account' => 'tenant_net']);
        // Seeded last so it carries the largest UUIDv7 id, but its
        // timestamp is the earliest: only a (created_at, id) order returns
        // it first, an id-only order would bury it last.
        $earliest = seededLedgerEntry($this->tenantId, ['account' => 'platform_commission', 'direction' => 'debit', 'created_at' => now()->subMinutes(2)]);
        seededLedgerEntry($this->otherTenantId);

        $headers = readHeaders($this->tenantId, Capability::LedgerView);

        $page = $this->getJson('/v1/ledger-entries?per_page=1', $headers);
        $page->assertOk()->assertConformsToOpenApi();

        expect($page->json('data'))->toHaveCount(1)
            ->and($page->json('data.0.id'))->toBe($earliest)
            ->and($page->json('meta.next_cursor'))->not->toBeNull();

        $rest = $this->getJson('/v1/ledger-entries?per_page=2&cursor='.$page->json('meta.next_cursor'), $headers);
        expect(array_column($rest->json('data'), 'id'))->toBe([$older, $newer]);

        $byAccount = $this->getJson('/v1/ledger-entries?filter[account]=tenant_net', $headers);
        expect(array_column($byAccount->json('data'), 'id'))->toBe([$newer]);

        $byWindow = $this->getJson('/v1/ledger-entries?filter[created_at_from]='.urlencode(now()->subSeconds(30)->toIso8601String()), $headers);
        expect(array_column($byWindow->json('data'), 'id'))->toBe([$newer]);
    });

    it('never leaks another tenant entries', function (): void {
        seededLedgerEntry($this->otherTenantId);

        $list = $this->getJson('/v1/ledger-entries', readHeaders($this->tenantId, Capability::LedgerView));

        expect($list->json('data'))->toHaveCount(0);
    });

    it('denies a bearer without ledger.view', function (): void {
        $this->getJson('/v1/ledger-entries', readHeaders($this->tenantId, Capability::OrdersView))
            ->assertStatus(403)
            ->assertJsonPath('code', 'missing_capability');
    });
});

describe('GET /v1/ledger-balances', function (): void {
    it('sums per currency and account with the documented sign conventions', function (): void {
        seededLedgerEntry($this->tenantId, ['account' => 'gateway_receivable', 'direction' => 'debit', 'amount' => 10_000]);
        seededLedgerEntry($this->tenantId, ['account' => 'gateway_receivable', 'direction' => 'credit', 'amount' => 2_000]);
        seededLedgerEntry($this->tenantId, ['account' => 'tenant_net', 'direction' => 'credit', 'amount' => 9_200]);
        seededLedgerEntry($this->tenantId, ['account' => 'tenant_net', 'direction' => 'debit', 'amount' => 1_700]);
        seededLedgerEntry($this->tenantId, ['account' => 'platform_commission', 'direction' => 'credit', 'amount' => 500, 'currency' => 'BRL']);

        $response = $this->getJson('/v1/ledger-balances', readHeaders($this->tenantId, Capability::LedgerView));
        $response->assertOk()->assertConformsToOpenApi();

        $balances = collect($response->json('data'))->keyBy(fn (array $row) => $row['currency'].':'.$row['account']);

        expect($balances['USD:gateway_receivable']['balance'])->toBe(['amount' => 8_000, 'currency' => 'USD'])
            ->and($balances['USD:tenant_net']['balance'])->toBe(['amount' => 7_500, 'currency' => 'USD'])
            ->and($balances['BRL:platform_commission']['balance'])->toBe(['amount' => 500, 'currency' => 'BRL']);
    });

    it('never leaks another tenant balances', function (): void {
        seededLedgerEntry($this->tenantId, ['account' => 'tenant_net', 'direction' => 'credit', 'amount' => 1_000]);
        seededLedgerEntry($this->otherTenantId, ['account' => 'tenant_net', 'direction' => 'credit', 'amount' => 50_000]);

        $response = $this->getJson('/v1/ledger-balances', readHeaders($this->tenantId, Capability::LedgerView));
        $response->assertOk();

        $balances = collect($response->json('data'))->keyBy(fn (array $row) => $row['currency'].':'.$row['account']);

        expect($balances['USD:tenant_net']['balance'])->toBe(['amount' => 1_000, 'currency' => 'USD']);
    });

    it('denies a bearer without ledger.view', function (): void {
        $this->getJson('/v1/ledger-balances', readHeaders($this->tenantId, Capability::OrdersView))
            ->assertStatus(403)
            ->assertJsonPath('code', 'missing_capability');
    });
});
