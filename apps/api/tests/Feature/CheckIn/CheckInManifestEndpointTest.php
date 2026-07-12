<?php

use App\CheckIn\Models\CheckIn;
use App\CheckIn\Models\CheckInAssignment;
use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Capability;
use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Customer;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Orders\Models\Order;
use App\Orders\Models\Ticket;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;
use Tests\Support\TenantStaff;

/*
 * Stage-09 plan, Endpoints "GET /v1/events/{event}/check-in-manifest"
 * and Slice 3: cursor-paginated manifest, filter[updated_since] delta,
 * unknown filters rejected, no PII, the scoping matrix, contract
 * conformance.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('check_in_assignments')->where('tenant_id', $this->tenantId)->delete();
        DB::table('check_ins')->where('tenant_id', $this->tenantId)->delete();
        DB::table('tickets')->where('tenant_id', $this->tenantId)->delete();
        DB::table('order_items')->where('tenant_id', $this->tenantId)->delete();
        DB::table('orders')->where('tenant_id', $this->tenantId)->delete();
        DB::table('customers')->where('tenant_id', $this->tenantId)->delete();
        DB::table('ticket_types')->where('tenant_id', $this->tenantId)->delete();
        DB::table('memberships')->where('tenant_id', $this->tenantId)->delete();
        DB::table('roles')->where('tenant_id', $this->tenantId)->delete();
        DB::table('events')->where('tenant_id', $this->tenantId)->delete();
        DB::table('tenant_domains')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );

    User::query()->delete();
});

/**
 * @return array{event_id: string, tickets: Collection<int, Ticket>}
 */
function manifestFixture(string $tenantId, int $count): array
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $count): array {
        $event = Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id]);
        $customer = Customer::factory()->create([
            'tenant_id' => $tenantId,
            'email' => Str::uuid7()->toString().'@example.com',
        ]);
        $order = Order::factory()->create([
            'tenant_id' => $tenantId,
            'customer_id' => $customer->id,
            'event_id' => $event->id,
            'hold_id' => Str::uuid7()->toString(),
        ]);

        $tickets = collect(range(1, $count))->map(fn () => Ticket::factory()->create([
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'ticket_type_id' => $ticketType->id,
            'event_id' => $event->id,
        ]));

        return ['event_id' => $event->id, 'tickets' => $tickets];
    });
}

it('returns the event tickets with status, rotation counter, and checked-in overlay, conforming to the contract', function (): void {
    $fixture = manifestFixture($this->tenantId, 2);
    $ticket = $fixture['tickets']->first();

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($fixture, $ticket): void {
        CheckIn::factory()->create([
            'tenant_id' => $this->tenantId,
            'ticket_id' => $ticket->id,
            'event_id' => $fixture['event_id'],
            'user_id' => User::factory()->create()->id,
            'scanned_at' => '2026-07-01T10:00:00Z',
        ]);
    });

    $headers = [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CheckinManage),
        'X-Tenant-Id' => $this->tenantId,
    ];

    $response = test()->getJson('/v1/events/'.$fixture['event_id'].'/check-in-manifest', $headers);
    $response->assertStatus(200)->assertConformsToOpenApi();

    $data = $response->json('data');
    expect($data)->toHaveCount(2);

    $byId = collect($data)->keyBy('ticket_id');
    expect($byId[$ticket->id]['checked_in_at'])->toBe('2026-07-01T10:00:00Z');

    $other = $fixture['tickets']->last();
    expect($byId[$other->id]['checked_in_at'])->toBeNull()
        ->and($byId[$other->id]['status'])->toBe('issued')
        ->and($byId[$other->id]['rotation_counter'])->toBe(0);
});

it('exposes no attendee PII fields in a manifest entry', function (): void {
    $fixture = manifestFixture($this->tenantId, 1);

    $headers = [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CheckinManage),
        'X-Tenant-Id' => $this->tenantId,
    ];

    $response = test()->getJson('/v1/events/'.$fixture['event_id'].'/check-in-manifest', $headers);
    $response->assertStatus(200);

    $entry = $response->json('data.0');
    expect(array_keys($entry))->toEqualCanonicalizing(['ticket_id', 'status', 'rotation_counter', 'checked_in_at']);
});

it('cursor-paginates the manifest in deterministic ticket id order', function (): void {
    $fixture = manifestFixture($this->tenantId, 3);
    $orderedIds = $fixture['tickets']->pluck('id')->sort()->values()->all();

    $headers = [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CheckinManage),
        'X-Tenant-Id' => $this->tenantId,
    ];

    $first = test()->getJson('/v1/events/'.$fixture['event_id'].'/check-in-manifest?per_page=2', $headers);
    $first->assertStatus(200)->assertConformsToOpenApi();

    $firstIds = array_column($first->json('data'), 'ticket_id');
    expect($firstIds)->toBe(array_slice($orderedIds, 0, 2));

    $nextCursor = $first->json('meta.next_cursor');
    expect($nextCursor)->not->toBeNull();

    $second = test()->getJson('/v1/events/'.$fixture['event_id'].'/check-in-manifest?per_page=2&cursor='.$nextCursor, $headers);
    $second->assertStatus(200)->assertConformsToOpenApi();

    $secondIds = array_column($second->json('data'), 'ticket_id');
    expect($secondIds)->toBe(array_slice($orderedIds, 2, 1));
});

it('narrows filter[updated_since] on both sides of the overlay', function (): void {
    $fixture = manifestFixture($this->tenantId, 2);
    $unchanged = $fixture['tickets']->first();
    $checkedInAfterFilter = $fixture['tickets']->last();

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($fixture, $unchanged, $checkedInAfterFilter): void {
        DB::table('tickets')->where('id', $unchanged->id)->update(['updated_at' => '2026-01-01T00:00:00Z']);
        DB::table('tickets')->where('id', $checkedInAfterFilter->id)->update(['updated_at' => '2026-01-01T00:00:00Z']);

        CheckIn::factory()->create([
            'tenant_id' => $this->tenantId,
            'ticket_id' => $checkedInAfterFilter->id,
            'event_id' => $fixture['event_id'],
            'user_id' => User::factory()->create()->id,
            'scanned_at' => '2026-06-01T00:00:00Z',
            'synced_at' => '2026-06-01T00:00:00Z',
        ]);
    });

    $headers = [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CheckinManage),
        'X-Tenant-Id' => $this->tenantId,
    ];

    $response = test()->getJson(
        '/v1/events/'.$fixture['event_id'].'/check-in-manifest?filter[updated_since]=2026-02-01T00:00:00Z',
        $headers,
    );
    $response->assertStatus(200)->assertConformsToOpenApi();

    expect(array_column($response->json('data'), 'ticket_id'))->toBe([$checkedInAfterFilter->id]);
});

it('rejects an unknown filter with 400 invalid_query_parameter', function (): void {
    $fixture = manifestFixture($this->tenantId, 1);

    $headers = [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CheckinManage),
        'X-Tenant-Id' => $this->tenantId,
    ];

    $response = test()->getJson('/v1/events/'.$fixture['event_id'].'/check-in-manifest?filter[bogus]=1', $headers);
    $response->assertStatus(400);
    $response->assertJsonPath('code', 'invalid_query_parameter');
});

it('rejects an invalid filter[updated_since] timestamp with a validation problem', function (): void {
    $fixture = manifestFixture($this->tenantId, 1);

    $headers = [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CheckinManage),
        'X-Tenant-Id' => $this->tenantId,
    ];

    $response = test()->getJson(
        '/v1/events/'.$fixture['event_id'].'/check-in-manifest?filter[updated_since]=not-a-date',
        $headers,
    );

    $response->assertStatus(422);
    $response->assertJsonPath('code', 'request.validation_failed');
});

it('renders event_not_found for an unknown event id', function (): void {
    $headers = [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CheckinManage),
        'X-Tenant-Id' => $this->tenantId,
    ];

    $response = test()->getJson('/v1/events/'.Str::uuid7().'/check-in-manifest', $headers);
    $response->assertStatus(404);
    $response->assertJsonPath('code', 'event_not_found');
});

describe('scoping matrix', function (): void {
    it('allows a checkin.scan caller assigned to event A but rejects them on event B', function (): void {
        $eventA = manifestFixture($this->tenantId, 1)['event_id'];
        $eventB = manifestFixture($this->tenantId, 1)['event_id'];

        $user = User::factory()->create();
        $token = StaffTokens::issue($user);
        $user->forceFill(['mfa_enabled' => true, 'mfa_confirmed_at' => now()])->save();

        app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($user, $eventA): void {
            $role = Role::factory()->create([
                'tenant_id' => $this->tenantId,
                'capabilities' => [Capability::CheckinScan->value],
            ]);
            Membership::factory()->create([
                'user_id' => $user->id,
                'tenant_id' => $this->tenantId,
                'role_id' => $role->id,
                'scope' => MembershipScope::Tenant,
            ]);
            CheckInAssignment::factory()->create([
                'tenant_id' => $this->tenantId,
                'event_id' => $eventA,
                'user_id' => $user->id,
            ]);
        });

        $headers = [
            'Authorization' => 'Bearer '.$token,
            'X-Tenant-Id' => $this->tenantId,
        ];

        test()->getJson('/v1/events/'.$eventA.'/check-in-manifest', $headers)->assertStatus(200);

        $rejected = test()->getJson('/v1/events/'.$eventB.'/check-in-manifest', $headers);
        $rejected->assertStatus(403);
        $rejected->assertJsonPath('code', 'checkin_not_assigned');
    });

    it('rejects a caller with no check-in capability at all', function (): void {
        $fixture = manifestFixture($this->tenantId, 1);

        $headers = [
            'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::OrdersView),
            'X-Tenant-Id' => $this->tenantId,
        ];

        test()->getJson('/v1/events/'.$fixture['event_id'].'/check-in-manifest', $headers)->assertStatus(403);
    });

    it('allows checkin.manage without an assignment row', function (): void {
        $fixture = manifestFixture($this->tenantId, 1);

        $headers = [
            'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CheckinManage),
            'X-Tenant-Id' => $this->tenantId,
        ];

        test()->getJson('/v1/events/'.$fixture['event_id'].'/check-in-manifest', $headers)->assertStatus(200);
    });

    it('rejects a customer bearer token', function (): void {
        ['tenant' => $tenant, 'host' => $host] = app(TenantTransaction::class)->asPlatform(function (): array {
            $tenant = Tenant::query()->whereKey($this->tenantId)->firstOrFail();
            $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

            return ['tenant' => $tenant, 'host' => $domain->domain];
        });

        $fixture = manifestFixture($tenant->id, 1);

        app(TenantTransaction::class)->asTenant($tenant->id, fn () => Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'manifest-customer@example.com',
            'password' => 'password',
        ]));

        $customerToken = test()->postJson('http://'.$host.'/v1/auth/customer/token', [
            'email' => 'manifest-customer@example.com',
            'password' => 'password',
        ])->json('access_token');

        $response = test()->getJson('/v1/events/'.$fixture['event_id'].'/check-in-manifest', [
            'Authorization' => 'Bearer '.$customerToken,
            'X-Tenant-Id' => $tenant->id,
        ]);

        $response->assertStatus(401);
    });
});
