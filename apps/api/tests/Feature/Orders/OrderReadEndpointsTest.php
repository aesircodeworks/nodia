<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Models\TicketTypeInventory;
use App\Orders\Actions\MarkOrderAwaitingPayment;
use App\Orders\Actions\MarkOrderPaid;
use App\Orders\Support\TicketQrCodec;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-07 plan, task breakdown item 8 and Slice 4 feature layer: GET
 * /v1/storefront/orders/{order} and GET
 * /v1/storefront/orders/{order}/tickets with qr_payload computed on
 * render.
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
 * @return array{host: string, tenantId: string, token: string, orderId: string}
 */
function orderReadFixture(): array
{
    ['tenant' => $tenant, 'host' => $host] = app(TenantTransaction::class)->asPlatform(function (): array {
        $tenant = Tenant::factory()->create();
        $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

        return ['tenant' => $tenant, 'host' => $domain->domain];
    });

    ['holdId' => $holdId] = app(TenantTransaction::class)->asTenant($tenant->id, function () use ($tenant): array {
        $event = Event::factory()->create(['tenant_id' => $tenant->id, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'reader@example.com',
            'password' => 'password',
        ]);

        TicketTypeInventory::factory()->create([
            'tenant_id' => $tenant->id,
            'ticket_type_id' => $ticketType->id,
            'quantity' => 10,
            'held' => 0,
            'sold' => 0,
        ]);

        $holdId = app(CreateHold::class)(
            CreateHoldData::from([
                'event_id' => $event->id,
                'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
            ]),
            $customer->id,
        )->id;

        return ['holdId' => $holdId];
    });

    $token = test()->postJson('http://'.$host.'/v1/auth/customer/token', [
        'email' => 'reader@example.com',
        'password' => 'password',
    ])->json('access_token');

    $orderId = test()->postJson('http://'.$host.'/v1/storefront/orders', [
        'hold_id' => $holdId,
        'attendee_names' => [DB::table('hold_items')->where('hold_id', $holdId)->value('ticket_type_id') => ['Ada Lovelace', 'Grace Hopper']],
    ], ['Authorization' => 'Bearer '.$token])->assertStatus(201)->json('id');

    return ['host' => $host, 'tenantId' => $tenant->id, 'token' => $token, 'orderId' => $orderId];
}

function payOrder(string $tenantId, string $orderId): void
{
    app(TenantTransaction::class)->asTenant($tenantId, function () use ($orderId): void {
        app(MarkOrderAwaitingPayment::class)($orderId);
        app(MarkOrderPaid::class)($orderId);
    });
}

describe('GET /v1/storefront/orders/{order}', function (): void {
    it('shows the customer their own order', function (): void {
        $fixture = orderReadFixture();

        $response = $this->getJson(
            'http://'.$fixture['host'].'/v1/storefront/orders/'.$fixture['orderId'],
            ['Authorization' => 'Bearer '.$fixture['token']],
        );

        $response->assertStatus(200)->assertConformsToOpenApi();
        $response->assertJsonPath('id', $fixture['orderId'])
            ->assertJsonPath('status', 'pending');
    });

    it('shows paid after the paid transition', function (): void {
        $fixture = orderReadFixture();
        payOrder($fixture['tenantId'], $fixture['orderId']);

        $response = $this->getJson(
            'http://'.$fixture['host'].'/v1/storefront/orders/'.$fixture['orderId'],
            ['Authorization' => 'Bearer '.$fixture['token']],
        );

        $response->assertStatus(200)->assertJsonPath('status', 'paid');
    });

    it('renders order_not_found for another customer', function (): void {
        $fixture = orderReadFixture();

        app(TenantTransaction::class)->asTenant(
            $fixture['tenantId'],
            fn () => Customer::factory()->create([
                'tenant_id' => $fixture['tenantId'],
                'email' => 'peeker@example.com',
                'password' => 'password',
            ]),
        );

        $otherToken = test()->postJson('http://'.$fixture['host'].'/v1/auth/customer/token', [
            'email' => 'peeker@example.com',
            'password' => 'password',
        ])->json('access_token');

        Auth::forgetGuards();

        $response = $this->getJson(
            'http://'.$fixture['host'].'/v1/storefront/orders/'.$fixture['orderId'],
            ['Authorization' => 'Bearer '.$otherToken],
        );

        $response->assertStatus(404)->assertConformsToOpenApi();
        $response->assertJsonPath('code', 'order_not_found');
    });
});

describe('GET /v1/storefront/orders/{order}/tickets', function (): void {
    it('returns an empty list before the order is paid', function (): void {
        $fixture = orderReadFixture();

        $response = $this->getJson(
            'http://'.$fixture['host'].'/v1/storefront/orders/'.$fixture['orderId'].'/tickets',
            ['Authorization' => 'Bearer '.$fixture['token']],
        );

        $response->assertStatus(200)->assertConformsToOpenApi();
        expect($response->json('data'))->toBe([]);
    });

    it('returns the tickets with verifying qr payloads after paid', function (): void {
        $fixture = orderReadFixture();
        payOrder($fixture['tenantId'], $fixture['orderId']);

        $response = $this->getJson(
            'http://'.$fixture['host'].'/v1/storefront/orders/'.$fixture['orderId'].'/tickets',
            ['Authorization' => 'Bearer '.$fixture['token']],
        );

        $response->assertStatus(200)->assertConformsToOpenApi();

        $tickets = $response->json('data');

        expect($tickets)->toHaveCount(2)
            ->and(array_column($tickets, 'attendee_name'))->toEqualCanonicalizing(['Ada Lovelace', 'Grace Hopper'])
            ->and(array_column($tickets, 'status'))->toBe(['issued', 'issued']);

        $codec = app(TicketQrCodec::class);

        foreach ($tickets as $ticket) {
            $verified = app(TenantTransaction::class)->asTenant(
                $fixture['tenantId'],
                fn () => $codec->verify($ticket['qr_payload']),
            );

            expect($verified->valid)->toBeTrue()
                ->and($verified->ticketId)->toBe($ticket['id']);
        }
    });

    it('renders fresh payloads after a rotation bump, and the old ones stop verifying', function (): void {
        $fixture = orderReadFixture();
        payOrder($fixture['tenantId'], $fixture['orderId']);

        $before = $this->getJson(
            'http://'.$fixture['host'].'/v1/storefront/orders/'.$fixture['orderId'].'/tickets',
            ['Authorization' => 'Bearer '.$fixture['token']],
        )->json('data');

        app(TenantTransaction::class)->asTenant($fixture['tenantId'], function () use ($fixture): void {
            DB::table('tickets')->where('order_id', $fixture['orderId'])->increment('qr_rotation_counter');
        });

        $after = $this->getJson(
            'http://'.$fixture['host'].'/v1/storefront/orders/'.$fixture['orderId'].'/tickets',
            ['Authorization' => 'Bearer '.$fixture['token']],
        )->json('data');

        $codec = app(TicketQrCodec::class);

        expect(array_column($after, 'qr_payload'))->not->toBe(array_column($before, 'qr_payload'));

        foreach ($before as $ticket) {
            $stale = app(TenantTransaction::class)->asTenant(
                $fixture['tenantId'],
                fn () => $codec->verify($ticket['qr_payload']),
            );

            expect($stale->valid)->toBeFalse();
        }
    });

    it('renders order_not_found for an unknown order', function (): void {
        $fixture = orderReadFixture();

        $response = $this->getJson(
            'http://'.$fixture['host'].'/v1/storefront/orders/'.Str::uuid7()->toString().'/tickets',
            ['Authorization' => 'Bearer '.$fixture['token']],
        );

        $response->assertStatus(404)->assertConformsToOpenApi();
        $response->assertJsonPath('code', 'order_not_found');
    });
});
