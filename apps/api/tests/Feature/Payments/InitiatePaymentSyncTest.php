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
use App\Payments\Enums\PaymentStatus;
use App\Payments\Gateways\FakeGatewayScenarios;
use App\Payments\Models\Payment;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-08a plan, Slice 4 (sync paths): POST
 * /v1/storefront/orders/{order}/payments with Idempotency-Key
 * semantics; sync approve pays the order in the initiating request,
 * sync decline leaves the order pending with the hold intact.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
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
 * @return array{host: string, tenantId: string, token: string, orderId: string, holdId: string}
 */
function initFixture(): array
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
            'email' => 'init-buyer@example.com',
            'password' => 'password',
        ]),
    );

    $token = test()->postJson('http://'.$host.'/v1/auth/customer/token', [
        'email' => 'init-buyer@example.com',
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

    return ['host' => $host, 'tenantId' => $tenant->id, 'token' => $token, 'orderId' => $orderId, 'holdId' => $holdId];
}

function initiatePayment(array $fixture, array $body, ?string $key = null)
{
    Auth::forgetGuards();

    $headers = ['Authorization' => 'Bearer '.$fixture['token']];

    if ($key !== null) {
        $headers['Idempotency-Key'] = $key;
    }

    return test()->postJson(
        'http://'.$fixture['host'].'/v1/storefront/orders/'.$fixture['orderId'].'/payments',
        $body,
        $headers,
    );
}

describe('POST /v1/storefront/orders/{order}/payments (sync)', function (): void {
    it('pays the order within the initiating request on sync approve', function (): void {
        $fixture = initFixture();

        $response = initiatePayment($fixture, ['method' => 'card', 'details' => ['token' => 'tok_approve']], (string) Str::uuid7());

        $response->assertStatus(201)->assertConformsToOpenApi();
        $response->assertJsonPath('status', 'confirmed')
            ->assertJsonPath('order_id', $fixture['orderId'])
            ->assertJsonPath('next_action.type', 'none');

        [$order, $payment, $ticketCount, $hold, $eventTypes] = app(TenantTransaction::class)->asTenant($fixture['tenantId'], fn (): array => [
            Order::query()->findOrFail($fixture['orderId']),
            Payment::query()->where('order_id', $fixture['orderId'])->firstOrFail(),
            DB::table('tickets')->where('order_id', $fixture['orderId'])->count(),
            Hold::query()->findOrFail($fixture['holdId']),
            OutboxEvent::query()->where('tenant_id', $fixture['tenantId'])->orderBy('sequence')->pluck('type')->all(),
        ]);

        expect($order->status)->toBe(OrderStatus::Paid)
            ->and($payment->status)->toBe(PaymentStatus::Confirmed)
            ->and($payment->fee_amount)->toBeGreaterThan(0)
            ->and($payment->gateway_reference)->toBe('fake_'.$payment->id)
            ->and($ticketCount)->toBe(2)
            ->and($hold->status)->toBe(HoldStatus::Committed)
            ->and($eventTypes)->toContain('PaymentInitiated', 'PaymentConfirmed', 'TicketIssued');
    });

    it('returns 402 payment_declined on sync decline, leaving the order pending and the hold intact', function (): void {
        $fixture = initFixture();

        $response = initiatePayment($fixture, ['method' => 'card', 'details' => ['token' => 'tok_decline']], (string) Str::uuid7());

        $response->assertStatus(402)->assertConformsToOpenApi();
        $response->assertJsonPath('code', 'payment_declined');

        [$order, $payment, $hold, $eventTypes] = app(TenantTransaction::class)->asTenant($fixture['tenantId'], fn (): array => [
            Order::query()->findOrFail($fixture['orderId']),
            Payment::query()->where('order_id', $fixture['orderId'])->firstOrFail(),
            Hold::query()->findOrFail($fixture['holdId']),
            OutboxEvent::query()->where('tenant_id', $fixture['tenantId'])->orderBy('sequence')->pluck('type')->all(),
        ]);

        expect($order->status)->toBe(OrderStatus::Pending)
            ->and($payment->status)->toBe(PaymentStatus::Failed)
            ->and($payment->failure_code)->toBe('card_declined')
            ->and($hold->status)->toBe(HoldStatus::Active)
            ->and($eventTypes)->toContain('PaymentInitiated', 'PaymentFailed');

        $retry = initiatePayment($fixture, ['method' => 'card', 'details' => ['token' => 'tok_approve']], (string) Str::uuid7());

        $retry->assertStatus(201);
        $retry->assertJsonPath('status', 'confirmed');
    });

    it('replays the original result for the same Idempotency-Key', function (): void {
        $fixture = initFixture();
        $key = (string) Str::uuid7();
        $body = ['method' => 'card', 'details' => ['token' => 'tok_approve']];

        $original = initiatePayment($fixture, $body, $key);
        $original->assertStatus(201);

        $replay = initiatePayment($fixture, $body, $key);

        $replay->assertStatus(200)->assertConformsToOpenApi();
        expect($replay->json())->toBe($original->json());

        $count = app(TenantTransaction::class)->asTenant(
            $fixture['tenantId'],
            fn () => Payment::query()->where('order_id', $fixture['orderId'])->count(),
        );
        expect($count)->toBe(1);
    });

    it('rejects reusing an Idempotency-Key against a different order', function (): void {
        $fixture = initFixture();
        $key = (string) Str::uuid7();
        $body = ['method' => 'card', 'details' => ['token' => 'tok_approve']];

        initiatePayment($fixture, $body, $key)->assertStatus(201);

        $secondHoldId = app(TenantTransaction::class)->asTenant($fixture['tenantId'], function () use ($fixture): string {
            $customer = Customer::query()->where('email', 'init-buyer@example.com')->firstOrFail();
            $eventId = DB::table('events')->where('tenant_id', $fixture['tenantId'])->value('id');
            $ticketTypeId = DB::table('ticket_types')->where('tenant_id', $fixture['tenantId'])->value('id');

            return app(CreateHold::class)(
                CreateHoldData::from([
                    'event_id' => $eventId,
                    'items' => [['ticket_type_id' => $ticketTypeId, 'quantity' => 1]],
                ]),
                $customer->id,
            )->id;
        });

        Auth::forgetGuards();

        $secondOrderId = test()->postJson('http://'.$fixture['host'].'/v1/storefront/orders', [
            'hold_id' => $secondHoldId,
        ], ['Authorization' => 'Bearer '.$fixture['token']])->assertStatus(201)->json('id');

        $response = initiatePayment(['orderId' => $secondOrderId] + $fixture, $body, $key);

        $response->assertStatus(409)->assertConformsToOpenApi();
        $response->assertJsonPath('code', 'idempotency_key_reuse_mismatch');

        $count = app(TenantTransaction::class)->asTenant(
            $fixture['tenantId'],
            fn () => Payment::query()->where('order_id', $secondOrderId)->count(),
        );
        expect($count)->toBe(0);
    });

    it('rejects the same Idempotency-Key with a different payload', function (): void {
        $fixture = initFixture();
        $key = (string) Str::uuid7();

        initiatePayment($fixture, ['method' => 'card', 'details' => ['token' => 'tok_approve']], $key)->assertStatus(201);

        $mismatch = initiatePayment($fixture, ['method' => 'card', 'details' => ['token' => 'tok_decline']], $key);

        $mismatch->assertStatus(409)->assertConformsToOpenApi();
        $mismatch->assertJsonPath('code', 'idempotency_key_reuse_mismatch');
    });

    it('requires the Idempotency-Key header', function (): void {
        $fixture = initFixture();

        $response = initiatePayment($fixture, ['method' => 'card', 'details' => ['token' => 'tok_approve']]);

        $response->assertStatus(400)->assertConformsToOpenApi();
        $response->assertJsonPath('code', 'idempotency_key_missing');
    });

    it('rejects a method outside the current offer', function (): void {
        $fixture = initFixture();

        $response = initiatePayment($fixture, ['method' => 'crypto'], (string) Str::uuid7());

        $response->assertStatus(422)->assertConformsToOpenApi();
        $response->assertJsonPath('code', 'payment_method_not_available');
    });

    it('renders order_not_payable when the order is not pending', function (): void {
        $fixture = initFixture();

        app(TenantTransaction::class)->asTenant($fixture['tenantId'], function () use ($fixture): void {
            DB::table('orders')->where('id', $fixture['orderId'])->update(['status' => OrderStatus::Expired->value]);
        });

        $response = initiatePayment($fixture, ['method' => 'card', 'details' => ['token' => 'tok_approve']], (string) Str::uuid7());

        $response->assertStatus(409)->assertConformsToOpenApi();
        $response->assertJsonPath('code', 'order_not_payable');
    });

    it('renders request.not_found for another customer\'s order', function (): void {
        $fixture = initFixture();

        app(TenantTransaction::class)->asTenant(
            $fixture['tenantId'],
            fn () => Customer::factory()->create([
                'tenant_id' => $fixture['tenantId'],
                'email' => 'other-init-buyer@example.com',
                'password' => 'password',
            ]),
        );

        $otherToken = test()->postJson('http://'.$fixture['host'].'/v1/auth/customer/token', [
            'email' => 'other-init-buyer@example.com',
            'password' => 'password',
        ])->json('access_token');

        Auth::forgetGuards();

        $response = test()->postJson(
            'http://'.$fixture['host'].'/v1/storefront/orders/'.$fixture['orderId'].'/payments',
            ['method' => 'card', 'details' => ['token' => 'tok_approve']],
            ['Authorization' => 'Bearer '.$otherToken, 'Idempotency-Key' => (string) Str::uuid7()],
        );

        $response->assertStatus(404)->assertConformsToOpenApi();
        $response->assertJsonPath('code', 'request.not_found');
    });

    it('returns 503 with Retry-After on gateway transport failure, leaving nothing behind', function (): void {
        $fixture = initFixture();
        $key = (string) Str::uuid7();

        app(FakeGatewayScenarios::class)->failNextCreate();

        $response = initiatePayment($fixture, ['method' => 'card', 'details' => ['token' => 'tok_approve']], $key);

        $response->assertStatus(503)->assertConformsToOpenApi();
        $response->assertJsonPath('code', 'gateway_unavailable');
        expect($response->headers->get('Retry-After'))->not->toBeNull();

        $count = app(TenantTransaction::class)->asTenant(
            $fixture['tenantId'],
            fn () => Payment::query()->where('order_id', $fixture['orderId'])->count(),
        );
        expect($count)->toBe(0);

        $retry = initiatePayment($fixture, ['method' => 'card', 'details' => ['token' => 'tok_approve']], $key);
        $retry->assertStatus(201);
    });
});
