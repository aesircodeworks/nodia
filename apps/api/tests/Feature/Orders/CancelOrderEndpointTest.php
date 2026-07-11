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
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-07 plan, TDD sequencing Slice 2, task breakdown item 5: POST
 * /v1/storefront/orders/{order}/cancel.
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
 * A tenant, an authenticated customer, and a pending order created over
 * the real conversion endpoint so the hold wiring is genuine.
 *
 * @return array{host: string, tenantId: string, token: string, orderId: string, holdId: string, ticketTypeId: string}
 */
function cancelOrderFixture(): array
{
    ['tenant' => $tenant, 'host' => $host] = app(TenantTransaction::class)->asPlatform(function (): array {
        $tenant = Tenant::factory()->create();
        $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

        return ['tenant' => $tenant, 'host' => $domain->domain];
    });

    ['event' => $event, 'ticketType' => $ticketType] = app(TenantTransaction::class)->asTenant($tenant->id, function () use ($tenant): array {
        $event = Event::factory()->create(['tenant_id' => $tenant->id, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

        TicketTypeInventory::factory()->create([
            'tenant_id' => $tenant->id,
            'ticket_type_id' => $ticketType->id,
            'quantity' => 10,
            'held' => 0,
            'sold' => 0,
        ]);

        return ['event' => $event, 'ticketType' => $ticketType];
    });

    $customer = app(TenantTransaction::class)->asTenant(
        $tenant->id,
        fn () => Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'cancel-buyer@example.com',
            'password' => 'password',
        ]),
    );

    $token = test()->postJson('http://'.$host.'/v1/auth/customer/token', [
        'email' => 'cancel-buyer@example.com',
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

    return [
        'host' => $host,
        'tenantId' => $tenant->id,
        'token' => $token,
        'orderId' => $orderId,
        'holdId' => $holdId,
        'ticketTypeId' => $ticketType->id,
    ];
}

describe('POST /v1/storefront/orders/{order}/cancel', function (): void {
    it('cancels a pending order, releases the hold, and recovers inventory exactly', function (): void {
        $fixture = cancelOrderFixture();

        $response = $this->postJson(
            'http://'.$fixture['host'].'/v1/storefront/orders/'.$fixture['orderId'].'/cancel',
            [],
            ['Authorization' => 'Bearer '.$fixture['token']],
        );

        $response->assertStatus(200)->assertConformsToOpenApi();
        $response->assertJsonPath('status', 'canceled');

        $inventory = app(TenantTransaction::class)->asTenant(
            $fixture['tenantId'],
            fn () => TicketTypeInventory::query()->where('ticket_type_id', $fixture['ticketTypeId'])->firstOrFail(),
        );
        $hold = app(TenantTransaction::class)->asTenant(
            $fixture['tenantId'],
            fn () => Hold::query()->findOrFail($fixture['holdId']),
        );

        expect($inventory->held)->toBe(0)
            ->and($inventory->sold)->toBe(0)
            ->and($hold->status)->toBe(HoldStatus::Released);
    });

    it('renders order_not_cancelable for any non-pending state', function (): void {
        $fixture = cancelOrderFixture();

        app(TenantTransaction::class)->asTenant($fixture['tenantId'], function () use ($fixture): void {
            DB::table('orders')->where('id', $fixture['orderId'])->update(['status' => OrderStatus::AwaitingPayment->value]);
        });

        $response = $this->postJson(
            'http://'.$fixture['host'].'/v1/storefront/orders/'.$fixture['orderId'].'/cancel',
            [],
            ['Authorization' => 'Bearer '.$fixture['token']],
        );

        $response->assertStatus(409)->assertConformsToOpenApi();
        $response->assertJsonPath('code', 'order_not_cancelable');
    });

    it('renders order_not_found for another customer\'s order', function (): void {
        $fixture = cancelOrderFixture();

        app(TenantTransaction::class)->asTenant(
            $fixture['tenantId'],
            fn () => Customer::factory()->create([
                'tenant_id' => $fixture['tenantId'],
                'email' => 'other-buyer@example.com',
                'password' => 'password',
            ]),
        );

        $otherToken = test()->postJson('http://'.$fixture['host'].'/v1/auth/customer/token', [
            'email' => 'other-buyer@example.com',
            'password' => 'password',
        ])->json('access_token');

        // The fixture's own conversion request cached the first buyer on
        // the customer guard; drop it so the bearer below is honored
        // (CustomerAuthenticationTest's own precedent).
        Auth::forgetGuards();

        $response = $this->postJson(
            'http://'.$fixture['host'].'/v1/storefront/orders/'.$fixture['orderId'].'/cancel',
            [],
            ['Authorization' => 'Bearer '.$otherToken],
        );

        $response->assertStatus(404)->assertConformsToOpenApi();
        $response->assertJsonPath('code', 'order_not_found');
    });

    it('renders order_not_found for an unknown order id', function (): void {
        $fixture = cancelOrderFixture();

        $response = $this->postJson(
            'http://'.$fixture['host'].'/v1/storefront/orders/'.Str::uuid7()->toString().'/cancel',
            [],
            ['Authorization' => 'Bearer '.$fixture['token']],
        );

        $response->assertStatus(404);
        $response->assertJsonPath('code', 'order_not_found');
    });
});
