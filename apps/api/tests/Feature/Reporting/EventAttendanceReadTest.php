<?php

use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Capability;
use App\Models\User;
use App\Reporting\Models\EventAttendance;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;
use Tests\Support\TenantStaff;

/*
 * Stage-11 plan, Slice 6 (task breakdown item 12): GET /v1/reports/attendance
 * behind reports.view, spatie/laravel-query-builder with the explicit
 * allowlist (filter[event_id] only; the endpoint's own Endpoints table row
 * lists no sort parameter), cursor-paginated with a deterministic
 * (event_id, ticket_type_id) order: report_event_attendance's own
 * unique(tenant_id, event_id, ticket_type_id) constraint makes that pair
 * fully deterministic under RLS tenant scoping, mirroring
 * EventFinanceReadTest's own structure for the sibling endpoint but with
 * a two-column order since one event has many ticket types. reports.view
 * is financially privileged (Capability::isFinanciallyPrivileged,
 * stage-11 task-01 journal), so TenantStaff::token's unconditional MFA
 * confirmation is required for every bearer here.
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
            foreach (['report_event_attendance', 'ticket_types', 'events', 'memberships'] as $table) {
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
function seedEventAttendanceRow(string $tenantId, string $eventId, string $ticketTypeId, array $overrides = []): EventAttendance
{
    return app(TenantTransaction::class)->asTenant($tenantId, fn () => EventAttendance::factory()->create([
        'tenant_id' => $tenantId,
        'event_id' => $eventId,
        'ticket_type_id' => $ticketTypeId,
        ...$overrides,
    ]));
}

describe('GET /v1/reports/attendance', function (): void {
    it('returns the wire shape: snake_case and ISO 8601 UTC timestamps', function (): void {
        seedEventAttendanceRow($this->tenantId, $this->eventId, $this->ticketTypeId, [
            'checked_in_count' => 5,
            'duplicate_scan_count' => 2,
            'first_scan_at' => '2026-07-10T09:00:00Z',
            'last_scan_at' => '2026-07-10T18:30:00Z',
        ]);

        $response = $this->getJson('/v1/reports/attendance');

        $response->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonPath('data.0.event_id', $this->eventId)
            ->assertJsonPath('data.0.ticket_type_id', $this->ticketTypeId)
            ->assertJsonPath('data.0.checked_in_count', 5)
            ->assertJsonPath('data.0.duplicate_scan_count', 2)
            ->assertJsonPath('data.0.first_scan_at', '2026-07-10T09:00:00Z')
            ->assertJsonPath('data.0.last_scan_at', '2026-07-10T18:30:00Z');

        expect(array_keys($response->json('data.0')))->toBe([
            'event_id', 'ticket_type_id', 'checked_in_count', 'duplicate_scan_count',
            'first_scan_at', 'last_scan_at',
        ]);
    });

    it('renders null scan timestamps for a cell with only duplicate scans', function (): void {
        seedEventAttendanceRow($this->tenantId, $this->eventId, $this->ticketTypeId, [
            'checked_in_count' => 0,
            'duplicate_scan_count' => 1,
            'first_scan_at' => null,
            'last_scan_at' => null,
        ]);

        $response = $this->getJson('/v1/reports/attendance');

        $response->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonPath('data.0.first_scan_at', null)
            ->assertJsonPath('data.0.last_scan_at', null);
    });

    it('cursor-paginates in deterministic (event_id, ticket_type_id) order', function (): void {
        $otherTicketType = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => TicketType::factory()->create([
            'tenant_id' => $this->tenantId,
            'event_id' => $this->eventId,
        ]));

        $ticketTypeIds = collect([$this->ticketTypeId, $otherTicketType->id])->sort()->values();
        seedEventAttendanceRow($this->tenantId, $this->eventId, $ticketTypeIds[0]);
        seedEventAttendanceRow($this->tenantId, $this->eventId, $ticketTypeIds[1]);

        $page = $this->getJson('/v1/reports/attendance?per_page=1');
        $page->assertOk()->assertConformsToOpenApi();

        expect($page->json('data'))->toHaveCount(1)
            ->and($page->json('data.0.ticket_type_id'))->toBe($ticketTypeIds[0])
            ->and($page->json('meta.next_cursor'))->not->toBeNull();

        $rest = $this->getJson('/v1/reports/attendance?per_page=1&cursor='.$page->json('meta.next_cursor'));
        expect($rest->json('data.0.ticket_type_id'))->toBe($ticketTypeIds[1]);
    });

    it('honors the event_id filter', function (): void {
        $otherEvent = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => Event::factory()->create(['tenant_id' => $this->tenantId]));
        $otherTicketType = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => TicketType::factory()->create([
            'tenant_id' => $this->tenantId,
            'event_id' => $otherEvent->id,
        ]));

        seedEventAttendanceRow($this->tenantId, $this->eventId, $this->ticketTypeId);
        seedEventAttendanceRow($this->tenantId, $otherEvent->id, $otherTicketType->id);

        $byEvent = $this->getJson('/v1/reports/attendance?filter[event_id]='.$this->eventId);

        expect($byEvent->json('data'))->toHaveCount(1)
            ->and($byEvent->json('data.0.event_id'))->toBe($this->eventId);
    });

    it('rejects an unknown filter with invalid_query_parameter', function (): void {
        $this->getJson('/v1/reports/attendance?filter[bogus]=1')
            ->assertStatus(400)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'invalid_query_parameter');
    });

    it('rejects an unknown sort with invalid_query_parameter', function (): void {
        $this->getJson('/v1/reports/attendance?sort=checked_in_count')
            ->assertStatus(400)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'invalid_query_parameter');
    });

    it('rejects a request with no bearer', function (): void {
        $this->withoutToken()
            ->getJson('/v1/reports/attendance', ['X-Tenant-Id' => $this->tenantId])
            ->assertUnauthorized()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'auth.unauthenticated');
    });

    it('denies a bearer without reports.view', function (): void {
        $this->withHeaders([
            'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::OrdersView),
        ])->getJson('/v1/reports/attendance')
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'missing_capability');
    });

    it('requires the X-Tenant-Id header', function (): void {
        // Not contract-checked: mirroring DailySalesReadTest's and
        // EventFinanceReadTest's own case, this pre-existing Stage 3 400
        // shares no combined problem schema with this path's own 400
        // invalid_query_parameter response.
        $this->withHeaders(['X-Tenant-Id' => ''])
            ->getJson('/v1/reports/attendance')
            ->assertStatus(400)
            ->assertJsonPath('code', 'missing_tenant_header');
    });

    it('rejects a bearer with no membership in the asserted tenant', function (): void {
        // Not contract-checked, for the same reason as the missing-header
        // case above.
        $strangerToken = StaffTokens::issue(User::factory()->create());

        $this->withHeaders(['Authorization' => 'Bearer '.$strangerToken])
            ->getJson('/v1/reports/attendance')
            ->assertForbidden()
            ->assertJsonPath('code', 'tenant_access_denied');
    });

    it('never leaks another tenant attendance row', function (): void {
        seedEventAttendanceRow($this->tenantId, $this->eventId, $this->ticketTypeId);

        [$otherEventId, $otherTicketTypeId] = app(TenantTransaction::class)->asTenant($this->otherTenantId, function (): array {
            $event = Event::factory()->create(['tenant_id' => $this->otherTenantId]);
            $ticketType = TicketType::factory()->create(['tenant_id' => $this->otherTenantId, 'event_id' => $event->id]);

            return [$event->id, $ticketType->id];
        });
        seedEventAttendanceRow($this->otherTenantId, $otherEventId, $otherTicketTypeId);

        $list = $this->getJson('/v1/reports/attendance');

        expect($list->json('data'))->toHaveCount(1)
            ->and($list->json('data.0.event_id'))->toBe($this->eventId);
    });
});
