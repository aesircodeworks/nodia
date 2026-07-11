<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Models\Hold;
use App\Inventory\Models\TicketTypeInventory;
use App\Orders\Enums\OrderStatus;
use App\Orders\Models\Order;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Models\Payment;
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
 * Stage-08a plan, Slice 4 (async remainder): async initiation returns
 * next_action, moves the order to awaiting_payment, extends the hold to
 * the method's confirmation window, and the buyer polls GET
 * /v1/storefront/payments/{payment}.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    $this->travelTo(CarbonImmutable::parse('2026-07-11T12:00:00Z'));
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
function asyncInitFixture(): array
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
            'email' => 'async-buyer@example.com',
            'password' => 'password',
        ]),
    );

    $token = test()->postJson('http://'.$host.'/v1/auth/customer/token', [
        'email' => 'async-buyer@example.com',
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

function asyncInitiate(array $fixture, array $body, ?string $key = null)
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

describe('POST /v1/storefront/orders/{order}/payments (async)', function (): void {
    it('returns next_action, moves the order to awaiting_payment, and extends the hold to the pix window', function (): void {
        $fixture = asyncInitFixture();

        $response = asyncInitiate($fixture, ['method' => 'pix'], (string) Str::uuid7());

        $response->assertStatus(201)->assertConformsToOpenApi();
        $response->assertJsonPath('status', 'initiated')
            ->assertJsonPath('next_action.type', 'display_code');

        expect($response->json('next_action.code'))->not->toBeNull()
            ->and($response->json('expires_at'))->toBe('2026-07-11T12:30:00Z');

        [$order, $payment, $hold, $eventTypes] = app(TenantTransaction::class)->asTenant($fixture['tenantId'], fn (): array => [
            Order::query()->findOrFail($fixture['orderId']),
            Payment::query()->where('order_id', $fixture['orderId'])->firstOrFail(),
            Hold::query()->findOrFail($fixture['holdId']),
            OutboxEvent::query()->where('tenant_id', $fixture['tenantId'])->pluck('type')->all(),
        ]);

        expect($order->status)->toBe(OrderStatus::AwaitingPayment)
            ->and($payment->status)->toBe(PaymentStatus::Initiated)
            ->and($payment->expires_at->toIso8601String())->toBe('2026-07-11T12:30:00+00:00')
            ->and($hold->expires_at->toIso8601String())->toBe('2026-07-11T12:30:00+00:00')
            ->and($eventTypes)->toContain('PaymentInitiated');
    });

    it('extends a boleto hold to the longer window', function (): void {
        $fixture = asyncInitFixture();

        $response = asyncInitiate($fixture, ['method' => 'boleto'], (string) Str::uuid7());

        $response->assertStatus(201);
        expect($response->json('expires_at'))->toBe('2026-07-14T12:00:00Z');
    });

    it('replays an async initiation byte-identically, re-serving the code', function (): void {
        $fixture = asyncInitFixture();
        $key = (string) Str::uuid7();

        $original = asyncInitiate($fixture, ['method' => 'pix'], $key);
        $original->assertStatus(201);

        $replay = asyncInitiate($fixture, ['method' => 'pix'], $key);

        $replay->assertStatus(200)->assertConformsToOpenApi();
        expect($replay->json())->toBe($original->json());
    });
});

describe('GET /v1/storefront/payments/{payment}', function (): void {
    it('reports the payment leg and re-serves next_action', function (): void {
        $fixture = asyncInitFixture();

        $paymentId = asyncInitiate($fixture, ['method' => 'pix'], (string) Str::uuid7())->json('id');

        Auth::forgetGuards();

        $response = test()->getJson(
            'http://'.$fixture['host'].'/v1/storefront/payments/'.$paymentId,
            ['Authorization' => 'Bearer '.$fixture['token']],
        );

        $response->assertStatus(200)->assertConformsToOpenApi();
        $response->assertJsonPath('id', $paymentId)
            ->assertJsonPath('status', 'initiated')
            ->assertJsonPath('next_action.type', 'display_code');
    });

    it('renders request.not_found for another customer\'s payment', function (): void {
        $fixture = asyncInitFixture();

        $paymentId = asyncInitiate($fixture, ['method' => 'pix'], (string) Str::uuid7())->json('id');

        app(TenantTransaction::class)->asTenant(
            $fixture['tenantId'],
            fn () => Customer::factory()->create([
                'tenant_id' => $fixture['tenantId'],
                'email' => 'other-async-buyer@example.com',
                'password' => 'password',
            ]),
        );

        $otherToken = test()->postJson('http://'.$fixture['host'].'/v1/auth/customer/token', [
            'email' => 'other-async-buyer@example.com',
            'password' => 'password',
        ])->json('access_token');

        Auth::forgetGuards();

        $response = test()->getJson(
            'http://'.$fixture['host'].'/v1/storefront/payments/'.$paymentId,
            ['Authorization' => 'Bearer '.$otherToken],
        );

        $response->assertStatus(404)->assertConformsToOpenApi();
        $response->assertJsonPath('code', 'request.not_found');
    });
});
