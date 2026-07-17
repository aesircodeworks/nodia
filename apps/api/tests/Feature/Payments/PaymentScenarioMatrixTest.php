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
use App\Orders\Mail\OrderConfirmationMail;
use App\Orders\Models\Order;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Gateways\FakeWebhookDelivery;
use App\Payments\Models\Payment;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-08a plan, Slice 11: the scripted-scenario matrix end to end.
 * Every FakeGateway scenario (sync approve, async confirm, decline,
 * expire, duplicate webhooks) driven over HTTP, asserting order status,
 * inventory, tickets, emails, and PDFs per scenario. This is the 8a
 * portion of the Stage 8 exit line and the seed of Stage 12's smoke
 * suite.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    Mail::fake();
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
 * @return array{host: string, tenantId: string, token: string, orderId: string, holdId: string, ticketTypeId: string}
 */
function matrixFixture(): array
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
            'email' => 'matrix-buyer@example.com',
            'password' => 'password',
        ]),
    );

    $token = test()->postJson('http://'.$host.'/v1/auth/customer/token', [
        'email' => 'matrix-buyer@example.com',
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

    return [
        'host' => $host,
        'tenantId' => $tenant->id,
        'token' => $token,
        'orderId' => $orderId,
        'holdId' => $holdId,
        'ticketTypeId' => $ticketType->id,
    ];
}

function matrixInitiate(array $fixture, array $body)
{
    Auth::forgetGuards();

    return test()->postJson(
        'http://'.$fixture['host'].'/v1/storefront/orders/'.$fixture['orderId'].'/payments',
        $body,
        ['Authorization' => 'Bearer '.$fixture['token'], 'Idempotency-Key' => (string) Str::uuid7()],
    );
}

function matrixWebhook(FakeWebhookDelivery $delivery)
{
    return test()->call('POST', '/v1/webhooks/fake', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        ...$delivery->serverHeaders(),
    ], $delivery->body);
}

/**
 * @return array{order: Order, hold: Hold, inventory: TicketTypeInventory, ticketCount: int, pdfCount: int}
 */
function matrixState(array $fixture): array
{
    return app(TenantTransaction::class)->asTenant($fixture['tenantId'], fn (): array => [
        'order' => Order::query()->findOrFail($fixture['orderId']),
        'hold' => Hold::query()->findOrFail($fixture['holdId']),
        'inventory' => TicketTypeInventory::query()->where('ticket_type_id', $fixture['ticketTypeId'])->firstOrFail(),
        'ticketCount' => DB::table('tickets')->where('order_id', $fixture['orderId'])->count(),
        'pdfCount' => DB::table('media')->where('collection_name', 'ticket_pdf')->count(),
    ]);
}

describe('the scripted-scenario matrix', function (): void {
    it('sync approve: paid, committed, ticketed, one email, one PDF per ticket', function (): void {
        $fixture = matrixFixture();

        matrixInitiate($fixture, ['method' => 'card', 'details' => ['token' => 'tok_approve']])->assertStatus(201);

        $state = matrixState($fixture);

        expect($state['order']->status)->toBe(OrderStatus::Paid)
            ->and($state['hold']->status)->toBe(HoldStatus::Committed)
            ->and($state['inventory']->sold)->toBe(2)
            ->and($state['inventory']->held)->toBe(0)
            ->and($state['ticketCount'])->toBe(2)
            ->and($state['pdfCount'])->toBe(2);

        Mail::assertSentCount(1);
        Mail::assertSent(OrderConfirmationMail::class);
    });

    it('sync decline: pending, hold intact, no tickets, no email', function (): void {
        $fixture = matrixFixture();

        matrixInitiate($fixture, ['method' => 'card', 'details' => ['token' => 'tok_decline']])->assertStatus(402);

        $state = matrixState($fixture);

        expect($state['order']->status)->toBe(OrderStatus::Pending)
            ->and($state['hold']->status)->toBe(HoldStatus::Active)
            ->and($state['ticketCount'])->toBe(0)
            ->and($state['pdfCount'])->toBe(0);

        Mail::assertNothingSent();
    });

    it('async confirm: webhook drives paid with tickets, email, and PDFs', function (): void {
        $fixture = matrixFixture();

        $paymentId = matrixInitiate($fixture, ['method' => 'pix'])->assertStatus(201)->json('id');

        matrixWebhook(app(FakeGateway::class)->confirmationWebhook('fake_'.$paymentId, Money::of(250, 'USD')))->assertStatus(200);

        $state = matrixState($fixture);

        expect($state['order']->status)->toBe(OrderStatus::Paid)
            ->and($state['inventory']->sold)->toBe(2)
            ->and($state['ticketCount'])->toBe(2)
            ->and($state['pdfCount'])->toBe(2);

        Mail::assertSentCount(1);
    });

    it('async decline: failure webhook fails the order, releases the hold, no email', function (): void {
        $fixture = matrixFixture();

        $paymentId = matrixInitiate($fixture, ['method' => 'pix'])->assertStatus(201)->json('id');

        matrixWebhook(app(FakeGateway::class)->failureWebhook('fake_'.$paymentId, 'insufficient_funds'))->assertStatus(200);

        $state = matrixState($fixture);

        expect($state['order']->status)->toBe(OrderStatus::Failed)
            ->and($state['hold']->status)->toBe(HoldStatus::Released)
            ->and($state['inventory']->held)->toBe(0)
            ->and($state['inventory']->sold)->toBe(0)
            ->and($state['ticketCount'])->toBe(0);

        Mail::assertNothingSent();
    });

    it('expire: the sweeper resolves the abandoned async payment, availability recovers exactly', function (): void {
        $fixture = matrixFixture();

        matrixInitiate($fixture, ['method' => 'pix'])->assertStatus(201);

        $this->travelTo(CarbonImmutable::parse('2026-07-11T12:31:00Z'));

        test()->artisan('payments:expire')->assertSuccessful();

        $state = matrixState($fixture);

        expect($state['order']->status)->toBe(OrderStatus::Expired)
            ->and($state['hold']->status)->toBe(HoldStatus::Released)
            ->and($state['inventory']->held)->toBe(0)
            ->and($state['inventory']->sold)->toBe(0)
            ->and($state['ticketCount'])->toBe(0);

        Mail::assertNothingSent();
    });

    it('duplicate webhooks: five deliveries, one transition, one ticket batch, one email, one PDF per ticket', function (): void {
        $fixture = matrixFixture();

        $paymentId = matrixInitiate($fixture, ['method' => 'pix'])->assertStatus(201)->json('id');

        $delivery = app(FakeGateway::class)->confirmationWebhook('fake_'.$paymentId, Money::of(250, 'USD'), eventId: 'evt_matrix_storm');

        foreach (range(1, 5) as $ignored) {
            matrixWebhook($delivery)->assertStatus(200);
        }

        $state = matrixState($fixture);

        [$payment, $webhookRows] = app(TenantTransaction::class)->asPlatform(fn (): array => [
            Payment::query()->findOrFail($paymentId),
            DB::table('gateway_webhook_events')->count(),
        ]);

        expect($state['order']->status)->toBe(OrderStatus::Paid)
            ->and($payment->status)->toBe(PaymentStatus::Confirmed)
            ->and($webhookRows)->toBe(1)
            ->and($state['ticketCount'])->toBe(2)
            ->and($state['pdfCount'])->toBe(2);

        Mail::assertSentCount(1);
    });
});
