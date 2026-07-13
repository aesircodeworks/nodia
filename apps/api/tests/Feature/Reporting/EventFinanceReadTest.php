<?php

use App\EventCatalog\Models\Event;
use App\Identity\Capability;
use App\Models\User;
use App\Reporting\Models\EventFinance;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;
use Tests\Support\TenantStaff;

/*
 * Stage-11 plan, Slice 4 (task breakdown item 9): GET
 * /v1/reports/event-finance behind reports.view, spatie/laravel-query-
 * builder with the explicit allowlist (filter[event_id] only; the
 * endpoint's own Endpoints table row lists no sort parameter), cursor-
 * paginated with a deterministic order over event_id alone: report_
 * event_finance's own unique(tenant_id, event_id) constraint makes that
 * fully deterministic under RLS tenant scoping, mirroring
 * DailySalesReadTest's own structure for the sibling endpoint. reports.
 * view is financially privileged (Capability::isFinanciallyPrivileged,
 * stage-11 task-01 journal), so TenantStaff::token's unconditional MFA
 * confirmation is required for every bearer here.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $this->otherTenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $this->eventId = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => Event::factory()->create(['tenant_id' => $this->tenantId])->id);

    $this->withHeaders([
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::ReportsView),
        'X-Tenant-Id' => $this->tenantId,
    ]);
});

afterEach(function (): void {
    foreach ([$this->tenantId, $this->otherTenantId] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            foreach (['report_event_finance', 'events', 'memberships'] as $table) {
                DB::table($table)->where('tenant_id', $tenantId)->delete();
            }
        });
    }

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    User::query()->delete();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function seedEventFinanceRow(string $tenantId, string $eventId, array $overrides = []): EventFinance
{
    return app(TenantTransaction::class)->asTenant($tenantId, fn () => EventFinance::factory()->create([
        'tenant_id' => $tenantId,
        'event_id' => $eventId,
        ...$overrides,
    ]));
}

describe('GET /v1/reports/event-finance', function (): void {
    it('returns the wire shape: snake_case and money objects', function (): void {
        seedEventFinanceRow($this->tenantId, $this->eventId, [
            'orders_paid_count' => 4,
            'refunds_count' => 1,
            'gross' => Money::of(9_000, 'USD'),
            'gateway_fee' => Money::of(300, 'USD'),
            'platform_commission' => Money::of(500, 'USD'),
            'tenant_net' => Money::of(8_200, 'USD'),
            'refunded' => Money::of(1_000, 'USD'),
        ]);

        $response = $this->getJson('/v1/reports/event-finance');

        $response->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonPath('data.0.event_id', $this->eventId)
            ->assertJsonPath('data.0.orders_paid_count', 4)
            ->assertJsonPath('data.0.refunds_count', 1)
            ->assertJsonPath('data.0.gross', ['amount' => 9_000, 'currency' => 'USD'])
            ->assertJsonPath('data.0.gateway_fees', ['amount' => 300, 'currency' => 'USD'])
            ->assertJsonPath('data.0.platform_commission', ['amount' => 500, 'currency' => 'USD'])
            ->assertJsonPath('data.0.tenant_net', ['amount' => 8_200, 'currency' => 'USD'])
            ->assertJsonPath('data.0.refunded', ['amount' => 1_000, 'currency' => 'USD']);

        expect(array_keys($response->json('data.0')))->toBe([
            'event_id', 'orders_paid_count', 'refunds_count', 'gross',
            'gateway_fees', 'platform_commission', 'tenant_net', 'refunded',
        ]);
    });

    it('cursor-paginates in deterministic event_id order', function (): void {
        $otherEventId = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => Event::factory()->create(['tenant_id' => $this->tenantId])->id);

        $eventIds = collect([$this->eventId, $otherEventId])->sort()->values();
        seedEventFinanceRow($this->tenantId, $eventIds[0]);
        seedEventFinanceRow($this->tenantId, $eventIds[1]);

        $page = $this->getJson('/v1/reports/event-finance?per_page=1');
        $page->assertOk()->assertConformsToOpenApi();

        expect($page->json('data'))->toHaveCount(1)
            ->and($page->json('data.0.event_id'))->toBe($eventIds[0])
            ->and($page->json('meta.next_cursor'))->not->toBeNull();

        $rest = $this->getJson('/v1/reports/event-finance?per_page=1&cursor='.$page->json('meta.next_cursor'));
        expect($rest->json('data.0.event_id'))->toBe($eventIds[1]);
    });

    it('honors the event_id filter', function (): void {
        $otherEventId = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => Event::factory()->create(['tenant_id' => $this->tenantId])->id);

        seedEventFinanceRow($this->tenantId, $this->eventId);
        seedEventFinanceRow($this->tenantId, $otherEventId);

        $byEvent = $this->getJson('/v1/reports/event-finance?filter[event_id]='.$this->eventId);

        expect($byEvent->json('data'))->toHaveCount(1)
            ->and($byEvent->json('data.0.event_id'))->toBe($this->eventId);
    });

    it('rejects an unknown filter with invalid_query_parameter', function (): void {
        $this->getJson('/v1/reports/event-finance?filter[bogus]=1')
            ->assertStatus(400)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'invalid_query_parameter');
    });

    it('rejects an unknown sort with invalid_query_parameter', function (): void {
        $this->getJson('/v1/reports/event-finance?sort=gross')
            ->assertStatus(400)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'invalid_query_parameter');
    });

    it('rejects a request with no bearer', function (): void {
        $this->withoutToken()
            ->getJson('/v1/reports/event-finance', ['X-Tenant-Id' => $this->tenantId])
            ->assertUnauthorized()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'auth.unauthenticated');
    });

    it('denies a bearer without reports.view', function (): void {
        $this->withHeaders([
            'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::OrdersView),
        ])->getJson('/v1/reports/event-finance')
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'missing_capability');
    });

    it('requires the X-Tenant-Id header', function (): void {
        // Not contract-checked: mirroring DailySalesReadTest's own case,
        // this pre-existing Stage 3 400 shares no combined problem schema
        // with this path's own 400 invalid_query_parameter response.
        $this->withHeaders(['X-Tenant-Id' => ''])
            ->getJson('/v1/reports/event-finance')
            ->assertStatus(400)
            ->assertJsonPath('code', 'missing_tenant_header');
    });

    it('rejects a bearer with no membership in the asserted tenant', function (): void {
        // Not contract-checked, for the same reason as the missing-header
        // case above.
        $strangerToken = StaffTokens::issue(User::factory()->create());

        $this->withHeaders(['Authorization' => 'Bearer '.$strangerToken])
            ->getJson('/v1/reports/event-finance')
            ->assertForbidden()
            ->assertJsonPath('code', 'tenant_access_denied');
    });

    it('never leaks another tenant event finance row', function (): void {
        seedEventFinanceRow($this->tenantId, $this->eventId);

        $otherEventId = app(TenantTransaction::class)->asTenant($this->otherTenantId, fn () => Event::factory()->create(['tenant_id' => $this->otherTenantId])->id);
        seedEventFinanceRow($this->otherTenantId, $otherEventId);

        $list = $this->getJson('/v1/reports/event-finance');

        expect($list->json('data'))->toHaveCount(1)
            ->and($list->json('data.0.event_id'))->toBe($this->eventId);
    });
});
