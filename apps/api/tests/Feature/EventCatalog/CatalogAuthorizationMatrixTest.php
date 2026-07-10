<?php

declare(strict_types=1);

use App\Http\Middleware\RequireCapability;
use App\Identity\Capability;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\TenantStaff;

/*
 * Stage-05a plan, task breakdown item 1: the catalog Policies and Gates
 * that consume events.view, events.manage, and events.publish (already
 * seeded by Stage 3, verified in App\Identity\Capability), extended over
 * this stage's own admin endpoint table rather than the generic
 * capability probe tests/Feature/Identity/AuthorizationMatrixTest.php
 * already covers every Capability case against.
 *
 * None of Event or TicketType exist yet (task breakdown items 4 and 7
 * build them); registering the real, permanently mounted routes this
 * early would force premature OpenAPI documentation
 * (tests/Contract/RouteSpecDriftTest.php checks every registered /v1
 * route against docs/openapi/openapi.yaml with no exclusion list) ahead
 * of the contract each later task's own TDD loop owns. Every row below
 * therefore registers a probe route scoped to this test only, at the
 * exact method and path the stage plan's endpoint table names, the same
 * sanctioned mechanism AuthorizationMatrixTest already uses and
 * RouteSpecDriftTest already documents as exempt ("Probe routes are
 * registered inside the tests that use them and never reach the app's
 * route table"). This proves the capability wired to each future
 * endpoint today, through the real tenancy.admin pipeline
 * (auth:staff, ResolveTenantFromHeader, EnforceMfaCompliance,
 * RequireCapability); later tasks replace each probe's closure with a
 * real controller and Data objects without touching the capability or
 * the path.
 *
 * The four venue rows this file originally carried (task-01) were
 * removed here, in task-02, the moment Venue landed with its own
 * permanently mounted routes: a probe registered at the same method and
 * path as a real route is never reached (Laravel matches the
 * first-registered route for a given method/URI, and
 * EventCatalogServiceProvider::boot() runs ahead of any test's
 * beforeEach), so keeping the probe rows here would either silently test
 * nothing or collide with the real controller's own validation. Coverage
 * for venues' 401/403/success boundary moved to
 * tests/Feature/EventCatalog/VenueEndpointsTest.php, which asserts the
 * same three outcomes against the real routes (mirroring
 * RoleEndpointsTest's precedent), closing the "later tasks replace each
 * probe's closure with a real controller ... without touching the
 * capability or the path" plan named above: the capability and path
 * proven here carry over unchanged to the real test.
 *
 * The four "create/list/show/update event" rows this file carried through
 * task-03 were removed here, in task-04, for the identical reason: Event
 * landed with its own permanently mounted routes (task breakdown item 5),
 * so a probe at the same method and path is never reached. Coverage for
 * that boundary moved to tests/Feature/EventCatalog/EventEndpointsTest.php.
 *
 * The four ticket-type rows this file carried through task-06 were removed
 * here, in task-07 (plan task breakdown item 8), for the identical reason:
 * TicketType landed with its own permanently mounted routes. Coverage for
 * that boundary moved to
 * tests/Feature/EventCatalog/TicketTypeEndpointsTest.php. The publish/cancel
 * rows below stay probes until task breakdown item 9 lands its own real
 * routes.
 */

const CATALOG_AUTH_ID = '019797f3-0000-7000-8000-0000000000cc';

/**
 * @return array<string, array{0: string, 1: string, 2: Capability}>
 */
function catalogAdminRoutes(): array
{
    return [
        'publish event' => ['POST', '/events/{event}/publish', Capability::EventsPublish],
        'cancel event' => ['POST', '/events/{event}/cancel', Capability::EventsPublish],
    ];
}

function catalogAuthPath(string $uri): string
{
    return '/v1'.strtr($uri, [
        '{venue}' => CATALOG_AUTH_ID,
        '{event}' => CATALOG_AUTH_ID,
        '{ticket_type}' => CATALOG_AUTH_ID,
    ]);
}

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    Route::middleware('tenancy.admin')->prefix('v1')->group(function (): void {
        foreach (catalogAdminRoutes() as [$method, $uri, $capability]) {
            Route::middleware(RequireCapability::class.':'.$capability->value)
                ->match([$method], $uri, fn () => response()->noContent());
        }
    });
});

afterEach(function (): void {
    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->pluck('id')->all(),
    );

    foreach ($tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('memberships')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    User::query()->delete();
});

it('rejects an unauthenticated caller with auth.unauthenticated', function (string $method, string $uri) {
    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    test()->json($method, catalogAuthPath($uri), [], ['X-Tenant-Id' => $tenantId])
        ->assertStatus(401)
        ->assertMatchesProblemSchema()
        ->assertJsonPath('code', 'auth.unauthenticated')
        ->assertJsonPath('status', 401);
})->with('catalog admin routes');

it('rejects an authenticated caller missing the required capability with missing_capability', function (string $method, string $uri, Capability $capability) {
    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $otherCapability = $capability === Capability::EventsView ? Capability::OrdersView : Capability::EventsView;
    $token = TenantStaff::token($tenantId, $otherCapability);

    test()->json($method, catalogAuthPath($uri), [], [
        'Authorization' => 'Bearer '.$token,
        'X-Tenant-Id' => $tenantId,
    ])
        ->assertStatus(403)
        ->assertMatchesProblemSchema()
        ->assertJsonPath('code', 'missing_capability')
        ->assertJsonPath('status', 403);
})->with('catalog admin routes');

it('lets an authenticated caller holding the required capability reach the handler', function (string $method, string $uri, Capability $capability) {
    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $token = TenantStaff::token($tenantId, $capability);

    test()->json($method, catalogAuthPath($uri), [], [
        'Authorization' => 'Bearer '.$token,
        'X-Tenant-Id' => $tenantId,
    ])->assertNoContent();
})->with('catalog admin routes');

dataset('catalog admin routes', fn (): array => catalogAdminRoutes());
