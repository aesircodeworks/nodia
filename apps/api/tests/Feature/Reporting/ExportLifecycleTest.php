<?php

use App\Identity\Capability;
use App\Models\User;
use App\Reporting\Actions\BuildExport;
use App\Reporting\Enums\ExportStatus;
use App\Reporting\Enums\ExportType;
use App\Reporting\Models\Export;
use App\Support\Audit\Models\ActivityLogEntry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;
use Tests\Support\TenantStaff;

/*
 * Stage-11 plan, TDD sequencing Slice 8 (task breakdown item 16): the
 * export lifecycle endpoints (create, list, show, download) behind
 * reports.export, not the read-only reports.view, because export files
 * carry customer PII (system-design 14.2, stage-11 plan Endpoints).
 * reports.export is financially privileged (Capability::
 * isFinanciallyPrivileged, stage-11 task-01 journal), so TenantStaff::
 * token's unconditional MFA confirmation is required for every bearer
 * here. Queue::fake() throughout: dispatching the real
 * App\Reporting\Jobs\BuildExportJob and running a real
 * App\Reporting\Support\Export\ExportSource is exercised end to end by
 * OrdersExportTest and its siblings (T11/T12); this suite drives
 * App\Reporting\Models\Export's own claim/complete/fail transitions
 * directly wherever a completed or failed export is needed, so these
 * tests stay focused on the endpoint surface itself, not the build
 * pipeline.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    Storage::fake(config()->string('media.protected_disk'));
    Queue::fake();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $this->otherTenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $this->withHeaders([
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::ReportsExport),
        'X-Tenant-Id' => $this->tenantId,
    ]);
});

afterEach(function (): void {
    foreach ([$this->tenantId, $this->otherTenantId] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            foreach (['media', 'exports', 'memberships'] as $table) {
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
function seedExport(string $tenantId, array $overrides = []): Export
{
    $userId = app(TenantTransaction::class)->asPlatform(fn () => User::factory()->create()->id);

    return app(TenantTransaction::class)->asTenant($tenantId, fn () => Export::factory()->create([
        'tenant_id' => $tenantId,
        'requested_by_user_id' => $userId,
        ...$overrides,
    ]));
}

/**
 * Drives the same claim-then-attach-then-complete transitions
 * App\Reporting\Actions\BuildExport itself calls on success, without
 * running a real registered source (which would need real order/ticket
 * fixtures for this tenant): a two-line CSV (header plus one row) is
 * attached directly, mirroring the exact shape BuildExport produces.
 * Deliberately not a single header-only line: PHP's fileinfo mime
 * sniffing (which the export_file collection's acceptsMimeTypes(['text/
 * csv']) restriction depends on, T11's own journal) detects a lone
 * comma-separated line as text/plain, not text/csv; verified empirically
 * before writing this helper, the same way T11 verified its own
 * multi-row fixture.
 */
function completeExport(string $tenantId, string $exportId): void
{
    app(TenantTransaction::class)->asTenant($tenantId, function () use ($exportId): void {
        Export::claim($exportId);

        $export = Export::query()->findOrFail($exportId);
        $export->addMediaFromString("id,status\n1,paid\n")
            ->usingFileName('export.csv')
            ->toMediaCollection('export_file');

        Export::complete($exportId, 1);
    });
}

/**
 * Drives the same claim-then-fail conditional transitions
 * App\Reporting\Actions\BuildExport itself calls on a source failure,
 * without touching the container-bound ExportSourceRegistry singleton
 * (registering a throwing fake source there, the OrdersExportTest
 * precedent, is a per-container mutation this suite must not risk
 * leaking across the other tests in this file that rely on the real
 * `orders` source through completeExport()).
 */
function failExport(string $tenantId, string $exportId): void
{
    app(TenantTransaction::class)->asTenant($tenantId, function () use ($exportId): void {
        Export::claim($exportId);
        Export::fail($exportId, BuildExport::SOURCE_FAILURE_CODE);
    });
}

describe('POST /v1/exports', function (): void {
    it('returns 202 with a pending export and writes the activity log entry', function (): void {
        $response = $this->postJson('/v1/exports', [
            'type' => 'orders',
            'parameters' => ['from' => '2026-07-01T00:00:00Z', 'to' => '2026-07-31T23:59:59Z'],
        ]);

        $response->assertStatus(202)
            ->assertConformsToOpenApi()
            ->assertJsonPath('type', 'orders')
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('row_count', null)
            ->assertJsonPath('failure_code', null)
            ->assertJsonPath('completed_at', null)
            ->assertJsonPath('parameters.event_id', null)
            ->assertJsonPath('parameters.from', '2026-07-01T00:00:00Z')
            ->assertJsonPath('parameters.to', '2026-07-31T23:59:59Z');

        expect(array_keys($response->json()))->toBe([
            'id', 'type', 'status', 'parameters', 'row_count', 'failure_code', 'completed_at', 'created_at',
        ]);

        // Export creation is activity-logged with the requesting staff
        // user as causer (system-design 14.2, stage-11 plan Endpoints):
        // asserted against the export's own requested_by_user_id, not a
        // separately captured bearer user, since both must name the same
        // acting staff member regardless of which helper minted the token.
        $exportId = $response->json('id');

        [$requestedByUserId, $entry] = app(TenantTransaction::class)->asTenant(
            $this->tenantId,
            fn (): array => [
                Export::query()->findOrFail($exportId)->requested_by_user_id,
                ActivityLogEntry::query()
                    ->where('event', 'mutation')
                    ->where('description', 'POST /v1/exports')
                    ->first(),
            ],
        );

        expect($entry)->not->toBeNull()
            ->and($entry->causer_type)->toBe(User::class)
            ->and($entry->causer_id)->toBe($requestedByUserId);
    });

    it('rejects an unregistered or unknown type with the standard validation code and creates nothing', function (): void {
        $response = $this->postJson('/v1/exports', ['type' => 'not_a_real_type']);

        $response->assertStatus(422)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed')
            ->assertJsonPath('errors.type.0', fn (string $message): bool => str_contains($message, 'type'));

        $count = app(TenantTransaction::class)->asTenant($this->tenantId, fn (): int => Export::query()->count());
        expect($count)->toBe(0);
    });

    it('rejects invalid parameters for the requested type with the standard validation code and creates nothing', function (): void {
        // ledger_entries prohibits event_id (App\Reporting\Support\Export\
        // Sources\LedgerEntriesExportSource::rules()).
        $response = $this->postJson('/v1/exports', [
            'type' => 'ledger_entries',
            'parameters' => ['event_id' => (string) Str::uuid7()],
        ]);

        $response->assertStatus(422)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');

        $count = app(TenantTransaction::class)->asTenant($this->tenantId, fn (): int => Export::query()->count());
        expect($count)->toBe(0);
    });

    it('rejects a request with no bearer', function (): void {
        $this->withoutToken()
            ->postJson('/v1/exports', ['type' => 'orders'], ['X-Tenant-Id' => $this->tenantId])
            ->assertUnauthorized()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'auth.unauthenticated');
    });

    it('denies a bearer without reports.export', function (): void {
        $this->withHeaders([
            'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::ReportsView),
        ])->postJson('/v1/exports', ['type' => 'orders'])
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'missing_capability');
    });

    it('requires the X-Tenant-Id header', function (): void {
        $this->withHeaders(['X-Tenant-Id' => ''])
            ->postJson('/v1/exports', ['type' => 'orders'])
            ->assertStatus(400)
            ->assertJsonPath('code', 'missing_tenant_header');
    });

    it('rejects a bearer with no membership in the asserted tenant', function (): void {
        $strangerToken = StaffTokens::issue(User::factory()->create());

        $this->withHeaders(['Authorization' => 'Bearer '.$strangerToken])
            ->postJson('/v1/exports', ['type' => 'orders'])
            ->assertForbidden()
            ->assertJsonPath('code', 'tenant_access_denied');
    });
});

describe('GET /v1/exports', function (): void {
    it('lists exports for the acting tenant, cursor-paginated in (created_at, id) order', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-07-01T00:00:00Z'));
        $earlier = seedExport($this->tenantId);
        $this->travelTo(CarbonImmutable::parse('2026-07-02T00:00:00Z'));
        $later = seedExport($this->tenantId);

        $page = $this->getJson('/v1/exports?per_page=1');
        $page->assertOk()->assertConformsToOpenApi();

        expect($page->json('data'))->toHaveCount(1)
            ->and($page->json('data.0.id'))->toBe($earlier->id)
            ->and($page->json('meta.next_cursor'))->not->toBeNull();

        $rest = $this->getJson('/v1/exports?per_page=1&cursor='.$page->json('meta.next_cursor'));
        expect($rest->json('data.0.id'))->toBe($later->id);
    });

    it('honors the type and status filters', function (): void {
        seedExport($this->tenantId, ['type' => ExportType::Orders, 'status' => ExportStatus::Pending]);
        seedExport($this->tenantId, ['type' => ExportType::Tickets, 'status' => ExportStatus::Completed]);

        $byType = $this->getJson('/v1/exports?filter[type]=orders');
        expect($byType->json('data'))->toHaveCount(1)
            ->and($byType->json('data.0.type'))->toBe('orders');

        $byStatus = $this->getJson('/v1/exports?filter[status]=completed');
        expect($byStatus->json('data'))->toHaveCount(1)
            ->and($byStatus->json('data.0.status'))->toBe('completed');
    });

    it('honors the created_at sort allowlist in both directions', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-07-01T00:00:00Z'));
        $first = seedExport($this->tenantId);
        $this->travelTo(CarbonImmutable::parse('2026-07-02T00:00:00Z'));
        $second = seedExport($this->tenantId);

        $ascending = $this->getJson('/v1/exports?sort=created_at');
        expect(array_column($ascending->json('data'), 'id'))->toBe([$first->id, $second->id]);

        $descending = $this->getJson('/v1/exports?sort=-created_at');
        expect(array_column($descending->json('data'), 'id'))->toBe([$second->id, $first->id]);
    });

    it('rejects an unknown filter with invalid_query_parameter', function (): void {
        $this->getJson('/v1/exports?filter[bogus]=1')
            ->assertStatus(400)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'invalid_query_parameter');
    });

    it('rejects an unknown sort with invalid_query_parameter', function (): void {
        $this->getJson('/v1/exports?sort=row_count')
            ->assertStatus(400)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'invalid_query_parameter');
    });

    it('never leaks another tenant export', function (): void {
        seedExport($this->tenantId);
        seedExport($this->otherTenantId);

        $list = $this->getJson('/v1/exports');
        expect($list->json('data'))->toHaveCount(1);
    });

    it('rejects a request with no bearer', function (): void {
        $this->withoutToken()
            ->getJson('/v1/exports', ['X-Tenant-Id' => $this->tenantId])
            ->assertUnauthorized()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'auth.unauthenticated');
    });

    it('denies a bearer without reports.export', function (): void {
        $this->withHeaders([
            'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::ReportsView),
        ])->getJson('/v1/exports')
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'missing_capability');
    });
});

describe('GET /v1/exports/{export}', function (): void {
    it('reflects status progression on the fake clock', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-07-01T00:00:00Z'));
        $export = seedExport($this->tenantId, ['type' => ExportType::Orders]);

        $this->getJson('/v1/exports/'.$export->id)
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('completed_at', null);

        app(TenantTransaction::class)->asTenant($this->tenantId, fn () => Export::claim($export->id));

        $this->getJson('/v1/exports/'.$export->id)
            ->assertOk()
            ->assertJsonPath('status', 'processing')
            ->assertJsonPath('completed_at', null);

        $this->travelTo(CarbonImmutable::parse('2026-07-01T00:05:00Z'));
        app(TenantTransaction::class)->asTenant($this->tenantId, fn () => Export::complete($export->id, 3));

        $this->getJson('/v1/exports/'.$export->id)
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('row_count', 3)
            ->assertJsonPath('completed_at', '2026-07-01T00:05:00Z');
    });

    it('returns 404 for an unknown export id', function (): void {
        $this->getJson('/v1/exports/'.Str::uuid7())
            ->assertStatus(404)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('rejects a request with no bearer', function (): void {
        $export = seedExport($this->tenantId);

        $this->withoutToken()
            ->getJson('/v1/exports/'.$export->id, ['X-Tenant-Id' => $this->tenantId])
            ->assertUnauthorized()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'auth.unauthenticated');
    });

    it('denies a bearer without reports.export', function (): void {
        $export = seedExport($this->tenantId);

        $this->withHeaders([
            'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::ReportsView),
        ])->getJson('/v1/exports/'.$export->id)
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'missing_capability');
    });
});

describe('GET /v1/exports/{export}/download', function (): void {
    it('returns a working URL and an expiry for a completed export', function (): void {
        $export = seedExport($this->tenantId, ['type' => ExportType::Orders]);

        completeExport($this->tenantId, $export->id);

        $response = $this->getJson('/v1/exports/'.$export->id.'/download');

        $response->assertOk()->assertConformsToOpenApi();

        expect($response->json('url'))->toBeString()->not->toBeEmpty()
            ->and($response->json('expires_at'))->toBeString()->not->toBeEmpty();
    });

    it('returns 409 export_not_ready while pending', function (): void {
        $export = seedExport($this->tenantId, ['status' => ExportStatus::Pending]);

        $this->getJson('/v1/exports/'.$export->id.'/download')
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'export_not_ready');
    });

    it('returns 409 export_not_ready while processing', function (): void {
        $export = seedExport($this->tenantId, ['status' => ExportStatus::Processing]);

        $this->getJson('/v1/exports/'.$export->id.'/download')
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'export_not_ready');
    });

    it('returns 409 export_failed for a failed export', function (): void {
        $export = seedExport($this->tenantId, ['type' => ExportType::Orders]);

        failExport($this->tenantId, $export->id);

        $this->getJson('/v1/exports/'.$export->id.'/download')
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'export_failed');
    });

    it('returns 404 for an unknown export id', function (): void {
        $this->getJson('/v1/exports/'.Str::uuid7().'/download')
            ->assertStatus(404)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('rejects a request with no bearer', function (): void {
        $export = seedExport($this->tenantId);

        $this->withoutToken()
            ->getJson('/v1/exports/'.$export->id.'/download', ['X-Tenant-Id' => $this->tenantId])
            ->assertUnauthorized()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'auth.unauthenticated');
    });

    it('denies a bearer without reports.export', function (): void {
        $export = seedExport($this->tenantId);

        $this->withHeaders([
            'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::ReportsView),
        ])->getJson('/v1/exports/'.$export->id.'/download')
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'missing_capability');
    });
});
