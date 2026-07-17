<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Models\TicketTypeInventory;
use App\Orders\Jobs\GenerateTicketPdf;
use App\Orders\Models\Ticket;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-08a plan, Slice 10: one PDF per ticket through medialibrary's
 * ticket_pdf single-file collection, idempotent under duplicate
 * delivery because the collection replaces rather than accumulates
 * (the mandated duplicate-delivery test, written first).
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    Storage::fake(config()->string('media.protected_disk'));
    Mail::fake();
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ($tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('media')->where('tenant_id', $tenantId)->delete();
            DB::table('payments')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
            DB::table('event_signing_keys')->where('tenant_id', $tenantId)->delete();
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
 * A paid two-ticket order driven over the real sync-approve endpoint.
 *
 * @return array{tenantId: string, orderId: string}
 */
function ticketPdfFixture(): array
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
            'email' => 'pdf-buyer@example.com',
            'password' => 'password',
        ]),
    );

    $token = test()->postJson('http://'.$host.'/v1/auth/customer/token', [
        'email' => 'pdf-buyer@example.com',
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

describe('GenerateTicketPdf', function (): void {
    it('produces one non-trivial PDF per ticket on the paid path', function (): void {
        $fixture = ticketPdfFixture();

        app(TenantTransaction::class)->asTenant($fixture['tenantId'], function () use ($fixture): void {
            $tickets = Ticket::query()->where('order_id', $fixture['orderId'])->get();

            expect($tickets)->toHaveCount(2);

            foreach ($tickets as $ticket) {
                $media = $ticket->getMedia('ticket_pdf');

                expect($media)->toHaveCount(1)
                    ->and($media->first()->mime_type)->toBe('application/pdf')
                    ->and($media->first()->size)->toBeGreaterThan(500);

                $bytes = Storage::disk(config()->string('media.protected_disk'))->get($media->first()->getPathRelativeToRoot());

                expect(substr($bytes, 0, 5))->toBe('%PDF-');
            }
        });
    });

    it('converges to exactly one attachment when a TicketIssued delivery repeats', function (): void {
        $fixture = ticketPdfFixture();

        $events = app(TenantTransaction::class)->asTenant(
            $fixture['tenantId'],
            fn () => OutboxEvent::query()->where('type', 'TicketIssued')->pluck('id')->all(),
        );

        foreach ($events as $eventId) {
            processOutboxDeliveryTwice($eventId, GenerateTicketPdf::NAME);
        }

        app(TenantTransaction::class)->asTenant($fixture['tenantId'], function () use ($fixture): void {
            foreach (Ticket::query()->where('order_id', $fixture['orderId'])->get() as $ticket) {
                expect($ticket->getMedia('ticket_pdf'))->toHaveCount(1);
            }
        });
    });
});
