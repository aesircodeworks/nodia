<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Capability;
use App\Identity\Models\Customer;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CreateHoldData;
use App\Models\User;
use App\Orders\Actions\ConvertHoldToOrder;
use App\Orders\Actions\MarkOrderAwaitingPayment;
use App\Orders\Actions\MarkOrderPaid;
use App\Orders\Data\CreateOrderData;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\TenantStaff;

/*
 * Stage-07 plan, TDD sequencing Slice 6, task breakdown items 13 and
 * 14: staff order list and detail with query-builder allowlists and
 * cursor pagination, plus the resend-tickets seam with audit.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $this->otherTenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    foreach ([$this->tenantId, $this->otherTenantId] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
            DB::table('memberships')->where('tenant_id', $tenantId)->delete();
            DB::table('roles')->where('tenant_id', $tenantId)->delete();
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

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKey($this->tenantId)->delete();
        Tenant::query()->whereKey($this->otherTenantId)->delete();
    });

    User::query()->delete();
});

/**
 * Two pending orders (one per customer) and one paid order in the
 * given tenant.
 *
 * @return array{orderIds: list<string>, paidOrderId: string, eventId: string, customerIds: list<string>}
 */
function staffOrdersFixture(string $tenantId): array
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): array {
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

        $orderIds = [];
        $customerIds = [];

        for ($i = 0; $i < 3; $i++) {
            $customer = Customer::factory()->create([
                'tenant_id' => $tenantId,
                'email' => sprintf('staff-orders-%d-%s@example.com', $i, substr($tenantId, -4)),
                'name' => 'Buyer '.$i,
            ]);

            $holdId = app(CreateHold::class)(
                CreateHoldData::from([
                    'event_id' => $event->id,
                    'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
                ]),
                $customer->id,
            )->id;

            $orderIds[] = app(ConvertHoldToOrder::class)(
                CreateOrderData::from(['hold_id' => $holdId]),
                $customer->id,
            )->id;

            $customerIds[] = $customer->id;
        }

        $paidOrderId = $orderIds[2];
        app(MarkOrderAwaitingPayment::class)($paidOrderId);
        app(MarkOrderPaid::class)($paidOrderId);

        return ['orderIds' => $orderIds, 'paidOrderId' => $paidOrderId, 'eventId' => $event->id, 'customerIds' => $customerIds];
    });
}

function staffOrderHeaders(string $tenantId, Capability|array $capabilities = Capability::OrdersView): array
{
    return [
        'Authorization' => 'Bearer '.TenantStaff::token($tenantId, $capabilities),
        'X-Tenant-Id' => $tenantId,
    ];
}

describe('GET /v1/orders', function (): void {
    it('cursor-paginates deterministically and honors each allowed filter', function (): void {
        $fixture = staffOrdersFixture($this->tenantId);
        $headers = staffOrderHeaders($this->tenantId);

        $page = $this->getJson('/v1/orders?per_page=2', $headers);
        $page->assertStatus(200)->assertConformsToOpenApi();

        expect($page->json('data'))->toHaveCount(2)
            ->and($page->json('meta.next_cursor'))->not->toBeNull();

        $rest = $this->getJson('/v1/orders?per_page=2&cursor='.$page->json('meta.next_cursor'), $headers);

        $ids = array_merge(
            array_column($page->json('data'), 'id'),
            array_column($rest->json('data'), 'id'),
        );

        expect($ids)->toEqualCanonicalizing($fixture['orderIds']);

        $paid = $this->getJson('/v1/orders?filter[status]=paid', $headers);
        expect(array_column($paid->json('data'), 'id'))->toBe([$fixture['paidOrderId']]);

        $byEvent = $this->getJson('/v1/orders?filter[event_id]='.$fixture['eventId'], $headers);
        expect($byEvent->json('data'))->toHaveCount(3);

        $byCustomer = $this->getJson('/v1/orders?filter[customer_id]='.$fixture['customerIds'][0], $headers);
        expect($byCustomer->json('data'))->toHaveCount(1);

        $byRange = $this->getJson('/v1/orders?filter[created_from]='.now()->subHour()->toIso8601ZuluString().'&filter[created_to]='.now()->addHour()->toIso8601ZuluString(), $headers);
        expect($byRange->json('data'))->toHaveCount(3);

        $outOfRange = $this->getJson('/v1/orders?filter[created_to]='.now()->subHour()->toIso8601ZuluString(), $headers);
        expect($outOfRange->json('data'))->toHaveCount(0);
    });

    it('rejects unknown filter and sort parameters', function (): void {
        staffOrdersFixture($this->tenantId);
        $headers = staffOrderHeaders($this->tenantId);

        $this->getJson('/v1/orders?filter[total]=1', $headers)
            ->assertStatus(400)
            ->assertJsonPath('code', 'invalid_query_parameter');

        $this->getJson('/v1/orders?sort=total_amount', $headers)
            ->assertStatus(400)
            ->assertJsonPath('code', 'invalid_query_parameter');
    });

    it('denies staff without orders.view', function (): void {
        $this->getJson('/v1/orders', staffOrderHeaders($this->tenantId, Capability::CheckinScan))
            ->assertStatus(403)
            ->assertJsonPath('code', 'missing_capability');
    });

    it('never exposes another tenant\'s orders', function (): void {
        staffOrdersFixture($this->otherTenantId);

        $response = $this->getJson('/v1/orders', staffOrderHeaders($this->tenantId));

        $response->assertStatus(200);
        expect($response->json('data'))->toBe([]);
    });
});

describe('GET /v1/orders/{order}', function (): void {
    it('composes the customer summary and tickets', function (): void {
        $fixture = staffOrdersFixture($this->tenantId);

        $response = $this->getJson('/v1/orders/'.$fixture['paidOrderId'], staffOrderHeaders($this->tenantId));

        $response->assertStatus(200)->assertConformsToOpenApi();
        $response->assertJsonPath('id', $fixture['paidOrderId'])
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('customer.id', $fixture['customerIds'][2]);

        expect($response->json('tickets'))->toHaveCount(1)
            ->and($response->json('customer'))->toHaveKeys(['id', 'email', 'name', 'created_at']);
    });

    it('renders order_not_found for a forged cross-tenant id', function (): void {
        $fixture = staffOrdersFixture($this->otherTenantId);

        $response = $this->getJson('/v1/orders/'.$fixture['paidOrderId'], staffOrderHeaders($this->tenantId));

        $response->assertStatus(404)->assertJsonPath('code', 'order_not_found');
    });
});

describe('POST /v1/orders/{order}/resend-tickets', function (): void {
    it('returns 202 for a paid order, audits, and leaves qr_rotation_counter untouched', function (): void {
        $fixture = staffOrdersFixture($this->tenantId);
        $headers = staffOrderHeaders($this->tenantId, [Capability::OrdersView, Capability::OrdersResendTickets]);

        $response = $this->postJson('/v1/orders/'.$fixture['paidOrderId'].'/resend-tickets', [], $headers);

        $response->assertStatus(202);

        $state = app(TenantTransaction::class)->asTenant($this->tenantId, fn (): array => [
            'counters' => DB::table('tickets')->where('order_id', $fixture['paidOrderId'])->pluck('qr_rotation_counter')->unique()->all(),
            'auditEntries' => DB::table('activity_log')->where('tenant_id', $this->tenantId)->count(),
        ]);

        expect($state['counters'])->toBe([0])
            ->and($state['auditEntries'])->toBeGreaterThan(0);
    });

    it('renders order_not_paid for an order without issued tickets', function (): void {
        $fixture = staffOrdersFixture($this->tenantId);
        $headers = staffOrderHeaders($this->tenantId, [Capability::OrdersView, Capability::OrdersResendTickets]);

        $response = $this->postJson('/v1/orders/'.$fixture['orderIds'][0].'/resend-tickets', [], $headers);

        $response->assertStatus(409)->assertConformsToOpenApi();
        $response->assertJsonPath('code', 'order_not_paid');
    });

    it('requires the orders.resend_tickets capability', function (): void {
        $fixture = staffOrdersFixture($this->tenantId);

        $this->postJson('/v1/orders/'.$fixture['paidOrderId'].'/resend-tickets', [], staffOrderHeaders($this->tenantId))
            ->assertStatus(403)
            ->assertJsonPath('code', 'missing_capability');
    });
});
