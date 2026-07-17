<?php

declare(strict_types=1);

use App\EventCatalog\Jobs\RefreshSearchIndex;
use App\Identity\Capability;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\TenantStaff;

/*
 * Stage-05c plan, task breakdown item 10, TDD sequencing Slice 7: after
 * planting published, draft, and canceled events and running the
 * projector (RefreshSearchIndex, task-07), truncating
 * event_search_documents and running `php artisan search:rebuild`
 * reproduces row-for-row identical documents. This is the equivalence
 * test that justifies the documented deviation from the system-design
 * 9.1 outbox-replay mechanism (stage-05c plan, Domain events): search
 * documents derive entirely from current event state, so the command
 * scans published events directly instead of rescanning the outbox.
 * The command iterates every tenant and runs under the RLS regime
 * (App\Support\Tenancy\TenantTransaction::asTenant), never bypassing it.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create([
            'default_locale' => 'en',
            'supported_locales' => ['en', 'fr'],
        ])->id,
    );

    $this->otherTenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create([
            'default_locale' => 'en',
            'supported_locales' => ['en'],
        ])->id,
    );

    $this->withHeaders([
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, [Capability::EventsView, Capability::EventsManage, Capability::EventsPublish]),
        'X-Tenant-Id' => $this->tenantId,
    ]);
});

afterEach(function (): void {
    foreach ([$this->tenantId, $this->otherTenantId] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('event_search_documents')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
            DB::table('events')->where('tenant_id', $tenantId)->delete();
            DB::table('memberships')->where('tenant_id', $tenantId)->delete();
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
 * @return array<string, mixed>
 */
function searchRebuildEventPayload(array $overrides = []): array
{
    return [
        'name' => ['en' => 'Jazz Festival'],
        'description' => ['en' => 'Live music all weekend'],
        'venue_id' => null,
        'is_virtual' => true,
        'virtual_event_url' => 'https://example.test/stream',
        'start_at' => '2026-08-01T18:00:00Z',
        'end_at' => '2026-08-01T21:00:00Z',
        'timezone' => 'UTC',
        ...$overrides,
    ];
}

/**
 * id, created_at, and updated_at are excluded from the returned shape:
 * a fresh id is generated on every insert (task-06's own precedent,
 * mirrored by SearchDocumentWriter::upsert()) and the timestamps are
 * necessarily later after a rebuild, so "row-for-row identical" means
 * identical content per (event_id, locale), not identical row identity.
 *
 * @return list<array<string, mixed>>
 */
function searchRebuildDocuments(string $tenantId): array
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => DB::table('event_search_documents')
            ->where('tenant_id', $tenantId)
            ->orderBy('event_id')
            ->orderBy('locale')
            ->get()
            ->map(fn (object $row): array => collect((array) $row)->except(['id', 'created_at', 'updated_at'])->all())
            ->all(),
    );
}

it('reproduces the projector-built index row for row after a truncate and rebuild', function (): void {
    $published = $this->postJson('/v1/events', searchRebuildEventPayload(['name' => ['en' => 'Jazz Festival']]));
    $published->assertCreated();
    $publishedId = $published->json('id');
    $this->postJson("/v1/events/{$publishedId}/publish")->assertOk();

    $draft = $this->postJson('/v1/events', searchRebuildEventPayload(['name' => ['en' => 'Unpublished Gala']]));
    $draft->assertCreated();

    $canceled = $this->postJson('/v1/events', searchRebuildEventPayload(['name' => ['en' => 'Canceled Gig']]));
    $canceled->assertCreated();
    $canceledId = $canceled->json('id');
    $this->postJson("/v1/events/{$canceledId}/publish")->assertOk();
    $this->postJson("/v1/events/{$canceledId}/cancel")->assertOk();

    $otherHeaders = [
        'Authorization' => 'Bearer '.TenantStaff::token($this->otherTenantId, [Capability::EventsView, Capability::EventsManage, Capability::EventsPublish]),
        'X-Tenant-Id' => $this->otherTenantId,
    ];

    // The 'staff' auth guard caches its resolved user for the lifetime of
    // the test's container instance; without forgetting it here, the
    // request below would silently re-authenticate as the first tenant's
    // user despite the different Authorization header, then get denied
    // for lacking a membership in $this->otherTenantId (a pre-existing
    // multi-tenant testing hazard this task's test is the first to hit,
    // not a defect in the command or middleware under test).
    Auth::forgetGuards();

    $otherPublished = $this->postJson('/v1/events', searchRebuildEventPayload(['name' => ['en' => 'Other Tenant Show']]), $otherHeaders);
    $otherPublished->assertCreated();
    $otherPublishedId = $otherPublished->json('id');
    $this->postJson("/v1/events/{$otherPublishedId}/publish", [], $otherHeaders)->assertOk();

    $before = [
        $this->tenantId => searchRebuildDocuments($this->tenantId),
        $this->otherTenantId => searchRebuildDocuments($this->otherTenantId),
    ];

    expect($before[$this->tenantId])->toHaveCount(2)
        ->and($before[$this->otherTenantId])->toHaveCount(1);

    foreach ([$this->tenantId, $this->otherTenantId] as $tenantId) {
        app(TenantTransaction::class)->asTenant(
            $tenantId,
            fn () => DB::table('event_search_documents')->where('tenant_id', $tenantId)->delete(),
        );
    }

    Artisan::call('search:rebuild');

    $after = [
        $this->tenantId => searchRebuildDocuments($this->tenantId),
        $this->otherTenantId => searchRebuildDocuments($this->otherTenantId),
    ];

    expect($after[$this->tenantId])->toEqual($before[$this->tenantId])
        ->and($after[$this->otherTenantId])->toEqual($before[$this->otherTenantId]);
});

it('does not index draft or canceled events on rebuild', function (): void {
    $draft = $this->postJson('/v1/events', searchRebuildEventPayload(['name' => ['en' => 'Unpublished Gala']]));
    $draft->assertCreated();

    $canceled = $this->postJson('/v1/events', searchRebuildEventPayload(['name' => ['en' => 'Canceled Gig']]));
    $canceled->assertCreated();
    $canceledId = $canceled->json('id');
    $this->postJson("/v1/events/{$canceledId}/publish")->assertOk();
    $this->postJson("/v1/events/{$canceledId}/cancel")->assertOk();

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DB::table('event_search_documents')->where('tenant_id', $this->tenantId)->delete(),
    );

    Artisan::call('search:rebuild');

    expect(searchRebuildDocuments($this->tenantId))->toBeEmpty();
});
