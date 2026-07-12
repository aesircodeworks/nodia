<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Capability;
use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Customer;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CreateHoldData;
use App\Models\User;
use App\Orders\Actions\ConvertHoldToOrder;
use App\Orders\Actions\MarkOrderAwaitingPayment;
use App\Orders\Actions\MarkOrderPaid;
use App\Orders\Data\CreateOrderData;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Models\Payment;
use App\Payments\Models\Refund;
use App\Support\Audit\Models\ActivityLogEntry;
use App\Support\Money\Money;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;
use Tests\Support\TenantStaff;

/*
 * Stage-08b plan, Slice 5: POST /v1/payments/{payment}/refunds with the
 * reservation guard, proportional commission derivation, Idempotency-Key
 * replay, MFA and capability gates, and the activity log.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create(['commission_bps' => 500, 'refund_commission_policy' => 'returned'])->id,
    );
    $this->otherTenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    DB::statement('truncate ledger_entries');

    foreach ([$this->tenantId, $this->otherTenantId] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            foreach (['outbox_deliveries', 'outbox_events', 'memberships', 'roles', 'media', 'refunds', 'payments', 'tickets', 'order_items', 'orders', 'hold_items', 'holds', 'customers', 'ticket_type_inventory', 'ticket_types', 'events'] as $table) {
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

/**
 * A paid order with issued tickets and a confirmed payment carrying a
 * persisted fee and commission breakdown.
 *
 * @return array{orderId: string, paymentId: string, ticketIds: list<string>, amount: int}
 */
function refundFixture(string $tenantId, int $quantity = 2): array
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $quantity): array {
        $event = Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id]);

        DB::table('ticket_type_inventory')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => $tenantId,
            'ticket_type_id' => $ticketType->id,
            'quantity' => 50,
            'held' => 0,
            'sold' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customer = Customer::factory()->create([
            'tenant_id' => $tenantId,
            'email' => sprintf('refund-buyer-%s@example.com', Str::uuid7()),
        ]);

        $holdId = app(CreateHold::class)(
            CreateHoldData::from([
                'event_id' => $event->id,
                'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => $quantity]],
            ]),
            $customer->id,
        )->id;

        $orderId = app(ConvertHoldToOrder::class)(
            CreateOrderData::from(['hold_id' => $holdId]),
            $customer->id,
        )->id;

        app(MarkOrderAwaitingPayment::class)($orderId);
        app(MarkOrderPaid::class)($orderId);

        $payment = Payment::factory()->create([
            'tenant_id' => $tenantId,
            'order_id' => $orderId,
            'status' => PaymentStatus::Confirmed,
            'money' => Money::of(10_000, 'USD'),
            'gateway_reference' => 'fake_'.Str::uuid7(),
        ]);

        DB::table('payments')->where('id', $payment->id)->update([
            'fee_amount' => 300,
            'commission_amount' => 500,
            'confirmed_at' => now(),
        ]);

        $ticketIds = DB::table('tickets')->where('order_id', $orderId)->pluck('id')->all();

        return ['orderId' => $orderId, 'paymentId' => $payment->id, 'ticketIds' => $ticketIds, 'amount' => 10_000];
    });
}

function refundHeaders(string $tenantId, ?string $idempotencyKey = null, Capability|array $capabilities = Capability::OrdersRefund): array
{
    $headers = [
        'Authorization' => 'Bearer '.TenantStaff::token($tenantId, $capabilities),
        'X-Tenant-Id' => $tenantId,
    ];

    if ($idempotencyKey !== null) {
        $headers['Idempotency-Key'] = $idempotencyKey;
    }

    return $headers;
}

describe('POST /v1/payments/{payment}/refunds', function (): void {
    it('creates a pending full refund by default, reserving the refundable amount and recording RefundInitiated', function (): void {
        $fixture = refundFixture($this->tenantId);

        $response = $this->postJson(
            '/v1/payments/'.$fixture['paymentId'].'/refunds',
            ['reason' => 'event canceled'],
            refundHeaders($this->tenantId, (string) Str::uuid7()),
        );

        $response->assertStatus(201)
            ->assertConformsToOpenApi()
            ->assertJsonPath('payment_id', $fixture['paymentId'])
            ->assertJsonPath('order_id', $fixture['orderId'])
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('amount', ['amount' => 10_000, 'currency' => 'USD'])
            ->assertJsonPath('commission_amount', ['amount' => 500, 'currency' => 'USD'])
            ->assertJsonPath('reason', 'event canceled')
            ->assertJsonPath('gateway_reference', null)
            ->assertJsonPath('failure_code', null);

        [$payment, $refundEvent, $auditCount] = app(TenantTransaction::class)->asTenant($this->tenantId, fn (): array => [
            Payment::query()->findOrFail($fixture['paymentId']),
            OutboxEvent::query()->where('type', 'RefundInitiated')->where('aggregate_id', $response->json('id'))->first(),
            ActivityLogEntry::query()
                ->where('event', 'mutation')
                ->where('description', 'POST /v1/payments/'.$fixture['paymentId'].'/refunds')
                ->count(),
        ]);

        expect($payment->refunded_amount)->toBe(10_000)
            ->and($payment->refunded_commission_amount)->toBe(500)
            ->and($refundEvent)->not->toBeNull()
            ->and($refundEvent->payload['payment_id'])->toBe($fixture['paymentId'])
            ->and($refundEvent->payload['order_id'])->toBe($fixture['orderId'])
            ->and($refundEvent->payload['amount'])->toBe(['amount' => 10_000, 'currency' => 'USD'])
            ->and($auditCount)->toBeGreaterThan(0);
    });

    it('derives a proportional commission on a partial refund and persists the ticket selection', function (): void {
        $fixture = refundFixture($this->tenantId);

        $response = $this->postJson(
            '/v1/payments/'.$fixture['paymentId'].'/refunds',
            [
                'amount' => ['amount' => 2_500, 'currency' => 'USD'],
                'ticket_ids' => [$fixture['ticketIds'][0]],
            ],
            refundHeaders($this->tenantId, (string) Str::uuid7()),
        );

        // 500 commission * 2500 / 10000 = 125.
        $response->assertStatus(201)
            ->assertJsonPath('amount.amount', 2_500)
            ->assertJsonPath('commission_amount.amount', 125);

        $refund = app(TenantTransaction::class)->asTenant(
            $this->tenantId,
            fn () => Refund::query()->findOrFail($response->json('id')),
        );

        expect($refund->ticket_ids)->toBe([$fixture['ticketIds'][0]])
            ->and($refund->request_hash)->not->toBe('');
    });

    it('derives a zero commission under the retained policy', function (): void {
        app(TenantTransaction::class)->asPlatform(function (): void {
            Tenant::query()->whereKey($this->tenantId)->update(['refund_commission_policy' => 'retained']);
        });

        $fixture = refundFixture($this->tenantId);

        $this->postJson(
            '/v1/payments/'.$fixture['paymentId'].'/refunds',
            ['amount' => ['amount' => 2_500, 'currency' => 'USD']],
            refundHeaders($this->tenantId, (string) Str::uuid7()),
        )
            ->assertStatus(201)
            ->assertJsonPath('commission_amount.amount', 0);
    });

    it('requires the Idempotency-Key header', function (): void {
        $fixture = refundFixture($this->tenantId);

        $this->postJson(
            '/v1/payments/'.$fixture['paymentId'].'/refunds',
            [],
            refundHeaders($this->tenantId),
        )
            ->assertStatus(400)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'idempotency_key_missing');
    });

    it('replays the original result for the same key and body', function (): void {
        $fixture = refundFixture($this->tenantId);
        $key = (string) Str::uuid7();
        $body = ['amount' => ['amount' => 2_500, 'currency' => 'USD']];

        $first = $this->postJson('/v1/payments/'.$fixture['paymentId'].'/refunds', $body, refundHeaders($this->tenantId, $key));
        $first->assertStatus(201);

        $replay = $this->postJson('/v1/payments/'.$fixture['paymentId'].'/refunds', $body, refundHeaders($this->tenantId, $key));

        $replay->assertStatus(200);
        expect($replay->json())->toBe($first->json());

        $reserved = app(TenantTransaction::class)->asTenant(
            $this->tenantId,
            fn () => Payment::query()->findOrFail($fixture['paymentId'])->refunded_amount,
        );

        expect($reserved)->toBe(2_500);
    });

    it('rejects the same key with a different body as idempotency_key_reuse_mismatch', function (): void {
        $fixture = refundFixture($this->tenantId);
        $key = (string) Str::uuid7();

        $this->postJson('/v1/payments/'.$fixture['paymentId'].'/refunds', ['amount' => ['amount' => 2_500, 'currency' => 'USD']], refundHeaders($this->tenantId, $key))
            ->assertStatus(201);

        $this->postJson('/v1/payments/'.$fixture['paymentId'].'/refunds', ['amount' => ['amount' => 2_600, 'currency' => 'USD']], refundHeaders($this->tenantId, $key))
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'idempotency_key_reuse_mismatch');
    });

    it('rejects the same key against a different payment regardless of body', function (): void {
        $first = refundFixture($this->tenantId);
        $second = refundFixture($this->tenantId);
        $key = (string) Str::uuid7();
        $body = ['amount' => ['amount' => 2_500, 'currency' => 'USD']];

        $this->postJson('/v1/payments/'.$first['paymentId'].'/refunds', $body, refundHeaders($this->tenantId, $key))
            ->assertStatus(201);

        $this->postJson('/v1/payments/'.$second['paymentId'].'/refunds', $body, refundHeaders($this->tenantId, $key))
            ->assertStatus(409)
            ->assertJsonPath('code', 'idempotency_key_reuse_mismatch');
    });

    it('returns refund_payment_not_found for unknown and cross-tenant payment ids', function (): void {
        $foreign = refundFixture($this->otherTenantId);

        $this->postJson('/v1/payments/'.Str::uuid7().'/refunds', [], refundHeaders($this->tenantId, (string) Str::uuid7()))
            ->assertStatus(404)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'refund_payment_not_found');

        $this->postJson('/v1/payments/'.$foreign['paymentId'].'/refunds', [], refundHeaders($this->tenantId, (string) Str::uuid7()))
            ->assertStatus(404)
            ->assertJsonPath('code', 'refund_payment_not_found');
    });

    it('rejects a payment that is not confirmed with payment_not_refundable', function (): void {
        $fixture = refundFixture($this->tenantId);

        $initiated = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => Payment::factory()->create([
            'tenant_id' => $this->tenantId,
            'order_id' => $fixture['orderId'],
            'status' => PaymentStatus::Initiated,
        ]));

        $this->postJson('/v1/payments/'.$initiated->id.'/refunds', [], refundHeaders($this->tenantId, (string) Str::uuid7()))
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'payment_not_refundable');
    });

    it('rejects a refund whose order is not in a refundable status with payment_not_refundable', function (): void {
        $fixture = refundFixture($this->tenantId);

        app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($fixture): void {
            DB::table('orders')->where('id', $fixture['orderId'])->update(['status' => 'canceled']);
        });

        $this->postJson('/v1/payments/'.$fixture['paymentId'].'/refunds', [], refundHeaders($this->tenantId, (string) Str::uuid7()))
            ->assertStatus(409)
            ->assertJsonPath('code', 'payment_not_refundable');
    });

    it('rejects an amount exceeding the remaining refundable with refund_amount_exceeds_refundable', function (): void {
        $fixture = refundFixture($this->tenantId);

        $this->postJson('/v1/payments/'.$fixture['paymentId'].'/refunds', ['amount' => ['amount' => 7_500, 'currency' => 'USD']], refundHeaders($this->tenantId, (string) Str::uuid7()))
            ->assertStatus(201);

        $this->postJson('/v1/payments/'.$fixture['paymentId'].'/refunds', ['amount' => ['amount' => 5_000, 'currency' => 'USD']], refundHeaders($this->tenantId, (string) Str::uuid7()))
            ->assertStatus(422)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'refund_amount_exceeds_refundable');
    });

    it('rejects a currency differing from the payment currency with refund_currency_mismatch', function (): void {
        $fixture = refundFixture($this->tenantId);

        $this->postJson('/v1/payments/'.$fixture['paymentId'].'/refunds', ['amount' => ['amount' => 100, 'currency' => 'BRL']], refundHeaders($this->tenantId, (string) Str::uuid7()))
            ->assertStatus(422)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'refund_currency_mismatch');
    });

    it('rejects ticket ids outside the order with refund_tickets_not_in_order', function (): void {
        $fixture = refundFixture($this->tenantId);

        $this->postJson(
            '/v1/payments/'.$fixture['paymentId'].'/refunds',
            ['ticket_ids' => [Str::uuid7()->toString()]],
            refundHeaders($this->tenantId, (string) Str::uuid7()),
        )
            ->assertStatus(422)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'refund_tickets_not_in_order');
    });

    it('rejects a non-positive amount with request.validation_failed', function (): void {
        $fixture = refundFixture($this->tenantId);

        $response = $this->postJson('/v1/payments/'.$fixture['paymentId'].'/refunds', ['amount' => ['amount' => 0, 'currency' => 'USD']], refundHeaders($this->tenantId, (string) Str::uuid7()));

        $response->assertStatus(422)
            ->assertJsonPath('code', 'request.validation_failed');

        expect($response->json('errors'))->toHaveKey('amount.amount');
    });

    it('denies a bearer without the refund capability', function (): void {
        $fixture = refundFixture($this->tenantId);

        $this->postJson(
            '/v1/payments/'.$fixture['paymentId'].'/refunds',
            [],
            refundHeaders($this->tenantId, (string) Str::uuid7(), Capability::OrdersView),
        )
            ->assertStatus(403)
            ->assertJsonPath('code', 'missing_capability');
    });

    it('denies a refund-capable bearer without confirmed MFA', function (): void {
        $fixture = refundFixture($this->tenantId);

        $user = User::factory()->create();
        $token = StaffTokens::issue($user);

        app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($user): void {
            Membership::factory()->create([
                'user_id' => $user->id,
                'tenant_id' => $this->tenantId,
                'role_id' => Role::factory()->create([
                    'tenant_id' => $this->tenantId,
                    'capabilities' => [Capability::OrdersRefund->value],
                ])->id,
                'scope' => MembershipScope::Tenant,
            ]);
        });

        $this->postJson('/v1/payments/'.$fixture['paymentId'].'/refunds', [], [
            'Authorization' => 'Bearer '.$token,
            'X-Tenant-Id' => $this->tenantId,
            'Idempotency-Key' => (string) Str::uuid7(),
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'mfa_enforcement_required');
    });
});
