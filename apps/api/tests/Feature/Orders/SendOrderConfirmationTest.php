<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Models\TicketTypeInventory;
use App\Orders\Jobs\SendOrderConfirmation;
use App\Orders\Mail\OrderConfirmationMail;
use App\Orders\Models\Order;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-08a plan, Slice 9: one confirmation email per order. TicketIssued
 * is recorded per ticket, so a two-ticket order yields two events; the
 * confirmation_sent_at claim makes exactly one send win, and duplicate
 * delivery of any single event is a no-op (the mandated
 * duplicate-delivery test, written first).
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    Mail::fake();
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

/**
 * A paid two-ticket order driven over the real sync-approve endpoint,
 * so two TicketIssued events were recorded and delivered.
 *
 * @return array{tenantId: string, orderId: string}
 */
function confirmationFixture(string $locale = 'pt_BR'): array
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
            'email' => 'confirmation-buyer@example.com',
            'password' => 'password',
            'locale' => $locale,
        ]),
    );

    $token = test()->postJson('http://'.$host.'/v1/auth/customer/token', [
        'email' => 'confirmation-buyer@example.com',
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

    test()->postJson('http://'.$host.'/v1/storefront/orders/'.$orderId.'/payments', [
        'method' => 'card',
        'details' => ['token' => 'tok_approve'],
    ], ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => (string) Str::uuid7()])->assertStatus(201);

    return ['tenantId' => $tenant->id, 'orderId' => $orderId];
}

describe('SendOrderConfirmation', function (): void {
    it('sends exactly one localized email for a two-ticket order and claims confirmation_sent_at', function (): void {
        $fixture = confirmationFixture();

        Mail::assertSentCount(1);
        Mail::assertSent(OrderConfirmationMail::class, function (OrderConfirmationMail $mail): bool {
            return $mail->hasTo('confirmation-buyer@example.com')
                && $mail->locale === 'pt_BR'
                && $mail->ticketCount === 2;
        });

        $order = app(TenantTransaction::class)->asTenant(
            $fixture['tenantId'],
            fn () => Order::query()->findOrFail($fixture['orderId']),
        );

        expect($order->confirmation_sent_at)->not->toBeNull();
    });

    it('does not embed any QR payload in the mail', function (): void {
        confirmationFixture();

        Mail::assertSent(OrderConfirmationMail::class, function (OrderConfirmationMail $mail): bool {
            $html = $mail->render();

            return ! str_contains(strtolower($html), 'qr');
        });
    });

    it('sends nothing more when a TicketIssued delivery repeats', function (): void {
        $fixture = confirmationFixture();

        $events = app(TenantTransaction::class)->asTenant(
            $fixture['tenantId'],
            fn () => OutboxEvent::query()->where('type', 'TicketIssued')->pluck('id')->all(),
        );

        expect($events)->toHaveCount(2);

        foreach ($events as $eventId) {
            processOutboxDeliveryTwice($eventId, SendOrderConfirmation::NAME);
        }

        Mail::assertSentCount(1);
    });

    it('claims the send with a conditional UPDATE checked by affected rows', function (): void {
        $fixture = confirmationFixture();

        $eventId = app(TenantTransaction::class)->asTenant(
            $fixture['tenantId'],
            fn () => OutboxEvent::query()->where('type', 'TicketIssued')->value('id'),
        );

        // The claim is already taken; running the consumer directly against
        // the order must not send again.
        app(TenantTransaction::class)->asTenant($fixture['tenantId'], function () use ($eventId): void {
            $event = OutboxEvent::query()->findOrFail($eventId);
            app(SendOrderConfirmation::class)->handle($event);
        });

        Mail::assertSentCount(1);
    });
});
