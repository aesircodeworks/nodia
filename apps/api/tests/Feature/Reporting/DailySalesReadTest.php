<?php

use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Capability;
use App\Models\User;
use App\Reporting\Models\DailySales;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;
use Tests\Support\TenantStaff;

/*
 * Stage-11 plan, Slice 2 (task breakdown item 6): GET /v1/reports/daily-sales
 * behind reports.view, spatie/laravel-query-builder with the explicit
 * allowlist (filter[event_id], filter[ticket_type_id], filter[from],
 * filter[to], sort in sales_date/-sales_date), cursor-paginated with a
 * deterministic (sales_date, id) order. reports.view is financially
 * privileged (Capability::isFinanciallyPrivileged, stage-11 task-01
 * journal), so TenantStaff::token's unconditional MFA confirmation is
 * required for every bearer here, matching every other financially
 * privileged read surface's own feature test posture (e.g.
 * RefundAndLedgerReadTest's ledger.view coverage).
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $this->otherTenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    [$this->eventId, $this->ticketTypeId] = app(TenantTransaction::class)->asTenant($this->tenantId, function (): array {
        $event = Event::factory()->create(['tenant_id' => $this->tenantId]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $this->tenantId, 'event_id' => $event->id]);

        return [$event->id, $ticketType->id];
    });

    $this->withHeaders([
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::ReportsView),
        'X-Tenant-Id' => $this->tenantId,
    ]);
});

afterEach(function (): void {
    foreach ([$this->tenantId, $this->otherTenantId] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            foreach (['report_daily_sales', 'ticket_types', 'events', 'memberships'] as $table) {
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
function seedDailySalesRow(string $tenantId, string $eventId, string $ticketTypeId, array $overrides = []): DailySales
{
    return app(TenantTransaction::class)->asTenant($tenantId, fn () => DailySales::factory()->create([
        'tenant_id' => $tenantId,
        'event_id' => $eventId,
        'ticket_type_id' => $ticketTypeId,
        ...$overrides,
    ]));
}

describe('GET /v1/reports/daily-sales', function (): void {
    it('returns the wire shape: snake_case, money objects, and an ISO 8601 date', function (): void {
        $row = seedDailySalesRow($this->tenantId, $this->eventId, $this->ticketTypeId, [
            'sales_date' => '2026-07-10',
            'tickets_issued_count' => 3,
            'tickets_refunded_count' => 1,
            'gross' => Money::of(9_000, 'USD'),
            'refunded' => Money::of(3_000, 'USD'),
        ]);

        $response = $this->getJson('/v1/reports/daily-sales');

        $response->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonPath('data.0.event_id', $this->eventId)
            ->assertJsonPath('data.0.ticket_type_id', $this->ticketTypeId)
            ->assertJsonPath('data.0.sales_date', '2026-07-10')
            ->assertJsonPath('data.0.tickets_issued_count', 3)
            ->assertJsonPath('data.0.tickets_refunded_count', 1)
            ->assertJsonPath('data.0.gross', ['amount' => 9_000, 'currency' => 'USD'])
            ->assertJsonPath('data.0.refunded', ['amount' => 3_000, 'currency' => 'USD']);

        expect(array_keys($response->json('data.0')))->toBe([
            'event_id', 'ticket_type_id', 'sales_date', 'tickets_issued_count',
            'tickets_refunded_count', 'gross', 'refunded',
        ]);

        expect($row)->not->toBeNull();
    });

    it('cursor-paginates in deterministic (sales_date, id) order', function (): void {
        $earlier = seedDailySalesRow($this->tenantId, $this->eventId, $this->ticketTypeId, ['sales_date' => '2026-07-01']);
        $later = seedDailySalesRow($this->tenantId, $this->eventId, $this->ticketTypeId, ['sales_date' => '2026-07-02']);

        $page = $this->getJson('/v1/reports/daily-sales?per_page=1');
        $page->assertOk()->assertConformsToOpenApi();

        expect($page->json('data'))->toHaveCount(1)
            ->and($page->json('data.0.sales_date'))->toBe('2026-07-01')
            ->and($page->json('meta.next_cursor'))->not->toBeNull();

        $rest = $this->getJson('/v1/reports/daily-sales?per_page=1&cursor='.$page->json('meta.next_cursor'));
        expect($rest->json('data.0.sales_date'))->toBe('2026-07-02');

        expect($earlier->id)->not->toBe($later->id);
    });

    it('honors the event_id, ticket_type_id, from, and to filters', function (): void {
        $otherTicketType = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => TicketType::factory()->create([
            'tenant_id' => $this->tenantId,
            'event_id' => $this->eventId,
        ]));
        $otherEvent = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => Event::factory()->create(['tenant_id' => $this->tenantId]));

        $inRange = seedDailySalesRow($this->tenantId, $this->eventId, $this->ticketTypeId, ['sales_date' => '2026-07-05']);
        seedDailySalesRow($this->tenantId, $this->eventId, $otherTicketType->id, ['sales_date' => '2026-07-05']);
        seedDailySalesRow($this->tenantId, $otherEvent->id, $this->ticketTypeId, ['sales_date' => '2026-07-05']);
        seedDailySalesRow($this->tenantId, $this->eventId, $this->ticketTypeId, ['sales_date' => '2026-06-01']);

        $byEvent = $this->getJson('/v1/reports/daily-sales?filter[event_id]='.$this->eventId);
        expect($byEvent->json('data'))->toHaveCount(3);

        $byTicketType = $this->getJson('/v1/reports/daily-sales?filter[ticket_type_id]='.$this->ticketTypeId);
        expect($byTicketType->json('data'))->toHaveCount(3);

        $byBoth = $this->getJson('/v1/reports/daily-sales?filter[event_id]='.$this->eventId.'&filter[ticket_type_id]='.$this->ticketTypeId);
        expect(array_column($byBoth->json('data'), 'sales_date'))->toBe(['2026-06-01', '2026-07-05']);

        // Both bounds are inclusive dates (the fixed OpenAPI description),
        // so from=to=2026-07-05 returns exactly the rows on that day.
        $byRange = $this->getJson('/v1/reports/daily-sales?filter[from]=2026-07-05&filter[to]=2026-07-05');
        expect($byRange->json('data'))->toHaveCount(3);

        $fromOnly = $this->getJson('/v1/reports/daily-sales?filter[from]=2026-07-01');
        expect($fromOnly->json('data'))->toHaveCount(3);

        $toOnly = $this->getJson('/v1/reports/daily-sales?filter[to]=2026-06-30');
        expect($toOnly->json('data'))->toHaveCount(1);

        expect($inRange->sales_date->toDateString())->toBe('2026-07-05');
    });

    it('honors the sales_date sort allowlist in both directions', function (): void {
        seedDailySalesRow($this->tenantId, $this->eventId, $this->ticketTypeId, ['sales_date' => '2026-07-01']);
        seedDailySalesRow($this->tenantId, $this->eventId, $this->ticketTypeId, ['sales_date' => '2026-07-03']);
        seedDailySalesRow($this->tenantId, $this->eventId, $this->ticketTypeId, ['sales_date' => '2026-07-02']);

        $ascending = $this->getJson('/v1/reports/daily-sales?sort=sales_date');
        expect(array_column($ascending->json('data'), 'sales_date'))->toBe(['2026-07-01', '2026-07-02', '2026-07-03']);

        $descending = $this->getJson('/v1/reports/daily-sales?sort=-sales_date');
        expect(array_column($descending->json('data'), 'sales_date'))->toBe(['2026-07-03', '2026-07-02', '2026-07-01']);
    });

    it('rejects an unknown filter with invalid_query_parameter', function (): void {
        $this->getJson('/v1/reports/daily-sales?filter[bogus]=1')
            ->assertStatus(400)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'invalid_query_parameter');
    });

    it('rejects an unknown sort with invalid_query_parameter', function (): void {
        $this->getJson('/v1/reports/daily-sales?sort=tickets_issued_count')
            ->assertStatus(400)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'invalid_query_parameter');
    });

    it('rejects a request with no bearer', function (): void {
        $this->withoutToken()
            ->getJson('/v1/reports/daily-sales', ['X-Tenant-Id' => $this->tenantId])
            ->assertUnauthorized()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'auth.unauthenticated');
    });

    it('denies a bearer without reports.view', function (): void {
        $this->withHeaders([
            'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::OrdersView),
        ])->getJson('/v1/reports/daily-sales')
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'missing_capability');
    });

    it('requires the X-Tenant-Id header', function (): void {
        // Not contract-checked: the endpoint documents no new codes (stage-11
        // plan, Endpoints table), and this pre-existing Stage 3 400 shares no
        // combined problem schema with this path's own 400 invalid_query_
        // parameter response, mirroring SubmerchantOnboardingTest's own
        // "requires the X-Tenant-Id header" case.
        $this->withHeaders(['X-Tenant-Id' => ''])
            ->getJson('/v1/reports/daily-sales')
            ->assertStatus(400)
            ->assertJsonPath('code', 'missing_tenant_header');
    });

    it('rejects a bearer with no membership in the asserted tenant', function (): void {
        // Not contract-checked, for the same reason as the missing-header
        // case above: this path's 403 schema documents only missing_capability
        // and mfa_enforcement_required (mirroring the ledger-entries and
        // refunds endpoints, not the combined VenueForbiddenProblem style),
        // since this endpoint introduces no new codes.
        $strangerToken = StaffTokens::issue(User::factory()->create());

        $this->withHeaders(['Authorization' => 'Bearer '.$strangerToken])
            ->getJson('/v1/reports/daily-sales')
            ->assertForbidden()
            ->assertJsonPath('code', 'tenant_access_denied');
    });

    it('never leaks another tenant daily sales row', function (): void {
        seedDailySalesRow($this->tenantId, $this->eventId, $this->ticketTypeId);

        [$otherEventId, $otherTicketTypeId] = app(TenantTransaction::class)->asTenant($this->otherTenantId, function (): array {
            $event = Event::factory()->create(['tenant_id' => $this->otherTenantId]);
            $ticketType = TicketType::factory()->create(['tenant_id' => $this->otherTenantId, 'event_id' => $event->id]);

            return [$event->id, $ticketType->id];
        });
        seedDailySalesRow($this->otherTenantId, $otherEventId, $otherTicketTypeId);

        $list = $this->getJson('/v1/reports/daily-sales');

        expect($list->json('data'))->toHaveCount(1)
            ->and($list->json('data.0.event_id'))->toBe($this->eventId);
    });
});
