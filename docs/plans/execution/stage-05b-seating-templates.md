# Execution Journal: Stage 5b, Seating Templates

Durable record of execution runs for [stage-05b-seating-templates.md](../stage-05b-seating-templates.md). Append a new run header per run; never rewrite prior entries.

## Run: 2026-07-10

- Stage: 5b, Seating Templates
- Date: 2026-07-10
- Branch: `feat/api-implementation`
- Base commit: `6b2ca114af8ca5ab52b5475924d8affab577dd17`

Verified starting state: Stages 1 through 5a are Done; Stage 5a closed at `6b2ca11`. Stage 5b is Not started and no part of it exists in the codebase: no `seat_maps` or `seats` migrations, no `SeatMap` or `Seat` models, no seat map Data objects, Actions, controllers, or routes, no seat paths in `docs/openapi/openapi.yaml`, no `seat_maps.manage` case in the Stage 3 `Capability` enum, and `events` has no `seat_map_id` column. Available from prior stages: the RLS policy helper and two-tenant isolation fixture (Stages 1 and 2), capability Gates and the activity log (Stage 3), the outbox recording API (Stage 4), and `venues`, `events`, `ticket_types` with the `UpdateEvent` Action recording `EventUpdated` (Stage 5a).

Sequencing note: the stage plan requires the `events.seat_map_id` migration (its slice 6) to land before or with the DELETE endpoint's `catalog.seat_map_in_use` 409 test (its slice 5), so this run orders the event-linkage task ahead of the delete task.

### Task checklist

- [x] task-01: Failing isolation tests, `seat_maps` and `seats` migration with RLS, models, factories, `seat_maps.manage` capability wired into Owner and Event Manager role templates (plan task 1, slice 1)
- [x] task-02: `UpsertSeatMap` create path, Data objects, `SeatMapPolicy`, POST endpoint, OpenAPI fragment, generated types (plan task 2, slice 2)
- [x] task-03: GET item and GET list endpoints with query-builder allowlist and `seat_count`, contract and isolation coverage (plan task 3, slice 3)
- [ ] task-04: PUT full replace with seat identity preservation, concurrency atomicity test, `catalog.seat_map_conflict` mapping (plan task 4, slice 4)
- [ ] task-05: Additive `events.seat_map_id` migration with restricting FK, `UpdateEvent` extension with venue-match and virtual-event invariants, contract and type regeneration (plan task 6, slice 6)
- [ ] task-06: DELETE endpoint with cascade and the `catalog.seat_map_in_use` 409 path (plan task 5, slice 5; depends on task-05's migration)
- [ ] task-07: Sweep: endpoint-level isolation entries, problem `code` registry documentation, master plan status flip to Done (plan task 7)

### Review rounds

### Decisions and deviations

#### task-01, 2026-07-10

Landed the `seat_maps` and `seats` tables under RLS with capability wiring (plan task breakdown item 1, TDD sequencing Slice 1).

What landed:

- `SeatMapsIsolationTest` and `SeatsIsolationTest` (`tests/Isolation/`), written and confirmed red before the migration existed (first run failed with `Class "App\EventCatalog\Models\SeatMap" not found`), backed by new `SeatMapFixture` and `SeatFixture` two-tenant fixtures under `tests/Isolation/Support/`, mirroring `TicketTypeFixture`'s shape: one seat map and one seat per tenant, proving cross-tenant SELECT returns only the caller's own row, cross-tenant UPDATE and DELETE affect zero rows, an insert with a foreign `tenant_id` throws through `WITH CHECK`, a raw SQL query stays isolated, `nodia_platform` reads across tenants, and `nodia_platform` writes are rejected (no platform write policy on either table).
- One migration, `2026_07_10_000027_create_seat_maps_and_seats_tables.php`, creating both `seat_maps` (`venue_id` FK on delete restrict via Laravel's default `constrained()`, unique `(venue_id, name)`, index `(tenant_id, venue_id)`) and `seats` (`seat_map_id` FK `cascadeOnDelete()`, unique `(seat_map_id, section, row, number)`, index `tenant_id`), both UUIDv7-keyed, both with `Rls::applyTenantPolicies()` in the same migration. The migration docblock notes `row` is a reserved word, safe because Laravel's grammar quotes every identifier it emits.
- `App\EventCatalog\Models\SeatMap` and `App\EventCatalog\Models\Seat`, with `venue()`/`seats()` and `seatMap()` relations respectively, plus `SeatMapFactory` and `SeatFactory` (`SeatFactory` uses `fake()->unique()` on `number`, mirroring `RoleFactory`'s precedent for a natural-key column, so repeated factory calls against one seat map do not collide on the unique index without every caller overriding it).
- `Capability::SeatMapsManage = 'seat_maps.manage'` added to the registry and wired into the `Owner` and `Event Manager` entries in `SeedTemplateRoles::templates()`. `AuthorizationMatrixTest` is data-driven off `Capability::cases()` and `SeedTemplateRoles::templates()`, so it picked up the new capability automatically with no test changes needed; `CapabilityTest`'s exact-registry lock (`tests/Unit/Identity/CapabilityTest.php`) was updated to include `seat_maps.manage` in both the full-registry list and the not-financially-privileged enumeration, and its docblock/title were generalized off the stale "stage-03 initial registry" wording since the registry now grows across stages.
- `composer types:generate` regenerated `packages/api-client/src/generated/index.ts` (the `Capability` union gained `'seat_maps.manage'`) and its manifest; `pnpm --filter api-client typecheck` passes.

Test evidence:

- `tests/Isolation/SeatMapsIsolationTest.php` and `tests/Isolation/SeatsIsolationTest.php`: red before the migration (confirmed), 14/14 green after.
- Full `composer test` (Feature, Unit, Contract, Architecture, Isolation, Concurrency): 1459 tests, 1458 passed, 1 failed on first run (`Concurrency\EventLifecycleContentionTest::it reconciles a publish versus cancel race so outbox rows match the committed transitions`, asserting 409 vs 200). Re-ran that single test in isolation and it passed (4/4); this is a pre-existing timing-sensitive parallel-process test in Stage 5a's event lifecycle contention suite, unrelated to `seat_maps`/`seats` (no seat map or seat table involved), so it is recorded as a known flake rather than a regression from this task. Re-running the full suite was not repeated given the isolated confirmation; if this flake recurs in CI it needs its own investigation outside this stage's scope.
- `composer lint` (Pint): passed.
- `composer analyse` (Larastan): passed, 0 errors.

Deviations from the plan:

- None. The migration ships both tables together per the task instruction ("one migration creating both tables"), and the isolation tests were split into two files (`SeatMapsIsolationTest`/`SeatsIsolationTest`) rather than one, mirroring the existing precedent of one file per table (`VenuesIsolationTest`, `TicketTypesIsolationTest`) rather than combining them.

Committed as `ff30e74` `feat(catalog): seat_maps and seats tables under RLS with capability wiring`.

#### task-02, 2026-07-10

Landed the `UpsertSeatMap` Action's create path and `POST /v1/venues/{venue}/seat-maps` (plan task breakdown item 2, TDD sequencing Slice 2).

What landed:

- Feature test `tests/Feature/EventCatalog/SeatMapEndpointsTest.php`, written and confirmed red first (all mutation and 404 cases returned 404 before the route existed; the two already-404 cross-tenant/unknown-venue cases stayed green throughout, so they proved nothing until the rest went green): 201 with the exact snake_case wire shape, seats echoed with generated UUIDv7 ids and deterministic section/row/number order regardless of payload order, an empty-seats create, one activity_log row recorded for the mutation, 422 `catalog.seat_map_duplicate_seats` listing offending positions (`seats.0`, `seats.1`) in the errors map with no seat_maps row left behind, 422 `catalog.seat_map_name_taken` for a second template on the same venue (and the same name allowed on a different venue), 422 `request.validation_failed` for an empty name and for a seat missing a required natural-key field, 404 for a cross-tenant and an unknown venue, 401 unauthenticated, 403 without `seat_maps.manage`, 403 for a bearer with no membership in the tenant.
- Contract: OpenAPI path `POST /v1/venues/{venue}/seat-maps` plus component schemas `Seat`, `SeatInput`, `SeatMap`, `SeatMapUpsertRequest`, `SeatMapForbiddenProblem`, `SeatMapUnprocessableProblem` added to `docs/openapi/openapi.yaml` (validated by the existing `OpenApiDocumentValidityTest` and `ResponseSchemaStrictnessTest` suites); six new exercisers (`contractSeatMapTenant`, `contractSeatMapBearer`, `contractSeatMapCreatePayload` plus the 201/401/403/404/422 cases) registered in `tests/Contract/DocumentedResponseCoverageTest.php` per ADR 019, with `seat_maps` deletion added ahead of `venues` in that file's tenant cleanup (venue_id is on delete restrict).
- Unit `tests/Unit/EventCatalog/UpsertSeatMapTest.php` (7 tests, written and confirmed red first: `UpsertSeatMapData`/`UpsertSeatMap` did not exist yet), covering: duplicate natural keys rejected before any query runs (no seat_maps row created), the offending positions carried on the exception, chunked bulk insert atomicity (a failing name via the DB unique constraint leaves zero seats behind), fresh UUIDv7 ids per seat across repeated payloads, an empty seats array, and the returned `SeatMapData` ordered by section/row/number.
- Data objects: `SeatInputData` and `SeatData` (input/output shape of one seat), `UpsertSeatMapData` (request body: `name`, `layout`, `seats`), `SeatMapData` (full document response), and `SeatMapSummaryData` (list-row shape, built now per the task-02 deliverable list even though the endpoint that returns it ships in task-03). `SeatInputData` carries its own `rules()` (`section`/`row`/`number` required strings, `position_x`/`position_y` present-nullable integers) because spatie/laravel-data derives nested-collection item rules exclusively from the nested class's own properties; a parent's `rules()` cannot override them (confirmed against the current spatie/laravel-data v4 docs before writing the class, since this repo had no prior nested-request-collection precedent to copy). `UpsertSeatMapData.layout` uses `present` rather than `required` so an empty starting layout (`{}`) is accepted; `required` on an array rejects an empty one in Laravel, which would have wrongly forbidden a freshly created template with no geometry yet (caught by the `ActivityLogCoverageTest` dataset row below, not the endpoint's own feature test, which always sends a non-empty layout).
- `App\EventCatalog\Actions\UpsertSeatMap::create()`: rejects duplicate `(section, row, number)` keys in-memory first; then, in one transaction, creates the `seat_maps` row (a `UniqueConstraintViolationException` on `seat_maps_venue_id_name_unique` is translated to `SeatMapNameTakenException`, the database as the invariant guard rather than a read-then-write check, mirroring `RoleNameTakenException`'s own precedent) and bulk-inserts the seats in chunks of 500 via `Seat::query()->insert()`, then reloads the ordered `seats` relation before building `SeatMapData`.
- `App\EventCatalog\Models\SeatMap::seats()` now orders by `section`, `row`, `number` by default, so every future reader (this task's create echo, task-03's show/list) gets the same deterministic sequence without repeating the ordering at each call site.
- Two new problem codes, `catalog.seat_map_duplicate_seats` and `catalog.seat_map_name_taken` (both 422), added to the `ErrorCode` registry and its `ProblemRenderer`/`ErrorCodeTest` companions. `catalog.seat_map_duplicate_seats` needed an errors map alongside its own stable code, which neither `HasErrorCode` alone nor the framework's `ValidationException` path (always rendered as the generic `request.validation_failed`) supported; added a small, general `HasValidationErrors` interface plus an optional `ErrorCode` parameter on `ValidationProblemData::fromErrors()` (default unchanged) so `ProblemRenderer` can attach the errors map to any domain exception's own code, not just the framework's. New unit coverage for this in `tests/Unit/Problems/ProblemDataTest.php`.
- `SeatMapController::store()` and the route `POST /v1/venues/{venue}/seat-maps`, gated by a new route group keyed on `Capability::SeatMapsManage` (a third, dedicated group alongside the existing `events.manage`/`events.publish` groups, per the api-conventions capability-per-group pattern) plus `RecordActivityAudit`; a foreign or unknown venue id renders the generic `request.not_found` via the existing `VenueNotFoundException`, never a seat-map-specific code.
- `tests/Feature/Identity/ActivityLogCoverageTest.php` gained a `Stage 5b: create a seat map` dataset row (and `seat_maps` cleanup ahead of `venues`), the file that centralizes "every mutating endpoint writes exactly one activity_log row" rather than each endpoint's own feature test asserting it individually (`VenueEndpointsTest`'s own precedent).

Test evidence:

- `tests/Feature/EventCatalog/SeatMapEndpointsTest.php`: 13/13 green (confirmed red first: 11 of 13 failed on route-not-found before the route existed).
- `tests/Unit/EventCatalog/UpsertSeatMapTest.php`: 7/7 green (confirmed red first: class-not-found before the Action and Data objects existed).
- `tests/Contract/DocumentedResponseCoverageTest.php`: 197/197 green, including the six new seat-map exercisers.
- `tests/Contract/OpenApiDocumentValidityTest.php`, `tests/Contract/ResponseSchemaStrictnessTest.php`: green against the extended spec.
- `tests/Feature/Identity/ActivityLogCoverageTest.php`: 19/19 green, including the new seat-map row.
- `tests/Unit/Problems/ErrorCodeTest.php`, `tests/Unit/Problems/ProblemDataTest.php`: green with the two new codes and the new `HasValidationErrors` coverage.
- `php artisan test --testsuite=Architecture`: 37/37 green after two fixes surfaced by the first full-suite run (see Deviations).
- Full `composer test` (Feature, Unit, Contract, Architecture, Isolation, Concurrency): 1488 tests, 1488 passed on the second run (the first run had 2 architecture failures, fixed and re-run clean; no flake this time, unlike task-01's noted `EventLifecycleContentionTest` timing flake).
- `composer lint` (Pint): passed. `composer analyse` (Larastan): passed, 0 errors. `composer types:generate`: regenerated `packages/api-client/src/generated/index.ts` and its manifest (new `SeatData`, `SeatInputData`, `SeatMapData`, `SeatMapSummaryData`, `UpsertSeatMapData` types, two new `ErrorCode` union members); `pnpm --filter api-client typecheck` passed; re-running `types:generate` after the fix below produced no further drift.

Deviations from the plan:

- No standalone `SeatMapPolicy` class ships, despite being named in the task-02 deliverable list. `EventCatalogServiceProvider`'s own docblock already records the deliberate stage-03/05a precedent this stage inherits: `App\Identity\Authorization\CapabilityGate` (registered globally) plus `RequireCapability` middleware already evaluate capability and tenant context (system-design 5.3) for every other EventCatalog admin route (Venue, Event, TicketType), so a Policy class would duplicate that resolution path with nothing new to attach to. Seat maps follow the same posture; this is recorded here rather than silently dropped from the deliverable list.
- `UpsertSeatMapData.layout` validates `present` rather than `required`: Laravel's `required` rule rejects an empty array, which would have wrongly forbidden `{}` as a starting layout for a freshly created template (caught by `ActivityLogCoverageTest`'s coverage row sending `'layout' => []`, not this task's own feature test, which always sends a populated layout). Not a plan deviation in substance (the plan's Data model never says layout must be non-empty), but worth recording since it reads as a stricter interpretation of "required" than intended.
- Two Architecture suite violations surfaced only on the first full `composer test` run, after the Feature/Unit/Contract suites (run individually first per the double loop) were already green, and were fixed before this task closed rather than deferred: (1) `PresetTest`'s Laravel preset expects every `Throwable` under `App\Exceptions`; `SeatMapDuplicateSeatsException` and `SeatMapNameTakenException` needed the same `->ignoring([...])` entry every other bounded-context exception already has. (2) `TimeSourceTest` forbids constructing `Carbon`/`DateTime` instances directly; `UpsertSeatMap`'s bulk-insert timestamp used `CarbonImmutable::now()` instead of the framework clock, fixed to `Illuminate\Support\Facades\Date::now()` (mirroring `PublishEvent`/`CancelEvent`'s own precedent). Both are now covered by the passing Architecture suite; no behavior changed, only the time-source call site and the preset's exception allowlist.

Committed as `333b2a7` `feat(catalog): UpsertSeatMap create path and seat map POST endpoint`.

#### task-03, 2026-07-10

Landed `GET /v1/seat-maps/{seat_map}` and `GET /v1/venues/{venue}/seat-maps` (plan task breakdown item 3, TDD sequencing Slice 3).

What landed:

- Feature tests appended to `tests/Feature/EventCatalog/SeatMapEndpointsTest.php`, written and confirmed red first (20 of the new tests failed with 404 or 405 before the routes existed; run recorded before implementation): `GET /v1/seat-maps/{seat_map}` returns the full document with seats ordered by section/row/number regardless of insertion order, 404 `request.not_found` for a foreign tenant's seat map and for an unknown id, 401 unauthenticated, 403 `missing_capability` for a bearer holding only `seat_maps.manage` (not `events.view`), 403 `tenant_access_denied` for a bearer with no membership; `GET /v1/venues/{venue}/seat-maps` returns the standard paginator envelope of `SeatMapSummaryData` with a correct `seat_count`, scopes to the given venue only (excluding the tenant's other venues' seat maps, not just other tenants'), never returns a foreign tenant's seat maps, filters by `filter[name]` (partial match), sorts on `name`/`created_at` in both directions with `-created_at` as the default, rejects an unknown filter or sort with 400 `invalid_query_parameter` rather than ignoring it, 404 for a foreign tenant's or unknown venue, 401, 403 `missing_capability`, 403 `tenant_access_denied`. Two new test helpers, `makeSeatMapRow()` and `makeSeatRow()`, create fixture rows directly (not through the POST endpoint), mirroring `VenueEndpointsTest`'s `makeVenueRow()`.
- Contract: OpenAPI `get` operations added to the existing `/v1/venues/{venue}/seat-maps` path and a new `/v1/seat-maps/{seat_map}` path in `docs/openapi/openapi.yaml`, plus component schemas `SeatMapSummary` and `SeatMapPage` (the standard paginator envelope, mirroring `VenuePage`). `SeatMapForbiddenProblem`'s description was widened from "the seat map create endpoint" to "a seat map endpoint, reading or mutating alike" since its `tenant_access_denied`/`missing_capability`/`mfa_enforcement_required` enum is identical for both halves of the surface, mirroring `EventForbiddenProblem`'s own precedent. Eight new exercisers (`get /v1/venues/{venue}/seat-maps` 200/400/401/403/404, `get /v1/seat-maps/{seat_map}` 200/401/403/404) registered in `tests/Contract/DocumentedResponseCoverageTest.php`, backed by a new `contractSeatMap()` helper mirroring `contractVenue()`.
- Isolation: `tests/Isolation/SeatMapEndpointsIsolationTest.php`, a new endpoint-level isolation test mirroring `RoleEndpointsIsolationTest.php`'s own precedent, reusing the existing `SeatMapFixture`/`VenueFixture`/`TenantFixture` (already built in task-01). Proves a tenant B token holding a real `events.view` capability gets `request.not_found` (never `missing_capability` or `tenant_access_denied`) for both `GET /v1/seat-maps/{seat_map}` and `GET /v1/venues/{venue}/seat-maps` against tenant A's ids, confirming the RLS policies from task-01 make the rows genuinely invisible to a plain `find()`, not merely denied by a capability check.
- `App\EventCatalog\Http\Controllers\SeatMapController::index()` and `::show()`, plus a `seatMapOrFail()` private helper mirroring `venueOrFail()`; both new routes registered in the existing `events.view` `RequireCapability` group in `app/EventCatalog/Http/routes/admin.php`, matching the plan's read/write capability split. `index()` uses `QueryBuilder::for(SeatMap::query()->where('venue_id', ...)->withCount('seats'))` with `AllowedFilter::partial('name')`, `allowedSorts('name', 'created_at')`, and `defaultSort('-created_at')`, mirroring `VenueController::index()` exactly.
- `App\EventCatalog\Exceptions\SeatMapNotFoundException`, a new `HasErrorCode` exception mapping to the generic `request.not_found` code, mirroring `VenueNotFoundException`.
- `App\EventCatalog\Data\SeatMapSummaryData::fromModel()` updated to prefer the `seats_count` attribute `index()`'s own `withCount('seats')` loads (avoiding an N+1 count query per page row), falling back to a direct count query for callers that pass a model without it loaded; `App\EventCatalog\Models\SeatMap` gained a `@property-read int|null $seats_count` docblock entry for Larastan.

Test evidence:

- `tests/Feature/EventCatalog/SeatMapEndpointsTest.php`: 35/35 green (confirmed 20 of the new GET tests red first, all failing with 404 or 405 before the routes existed).
- `tests/Contract/DocumentedResponseCoverageTest.php`: 206/206 green, including the eight new seat-map GET exercisers; full `tests/Contract` suite: 211/211 green.
- `tests/Isolation/SeatMapEndpointsIsolationTest.php`: 1/1 green (10 assertions), confirmed green immediately after the routes and controller methods landed (no separate red run recorded for this file specifically, since it depends on the same routes the feature test's red run already proved absent).
- `php artisan test --testsuite=Architecture`: 37/37 green after one fix (see Deviations).
- Full `composer test` (Feature, Unit, Contract, Architecture, Isolation, Concurrency): first run 1520 tests, 1519 passed, 1 failed (`Architecture\PresetTest`, see Deviations); second run after the fix: 1520/1520 passed, 6031 assertions, no flake.
- `composer lint` (Pint): passed. `composer analyse` (Larastan): passed, 0 errors. `composer types:generate`: ran clean with no diff (no new Data objects this task; `SeatMapData`/`SeatMapSummaryData` were already generated in task-02).

Deviations from the plan:

- One Architecture suite violation surfaced only on the first full `composer test` run, after Feature/Contract/Isolation were already green individually: `PresetTest`'s Laravel preset expects every `Throwable` under `App\Exceptions`; the new `SeatMapNotFoundException` needed the same `->ignoring([...])` entry every other bounded-context `HasErrorCode` exception already has (mirroring `VenueNotFoundException`'s own entry in that same list). Fixed before this task closed; no behavior changed, only the preset's exception allowlist.
- No other deviations. The plan's task breakdown item 3 scope (GET item, GET list, query-builder allowlist, `seat_count`, contract additions) shipped as specified; the isolation coverage reuses task-01's existing fixture rather than building a new one, since the plan's own Slice 3 wording ("the suite covers every endpoint per system-design 18") did not call for a dedicated fixture.

Committed as `9887283` `feat(catalog): seat map show and list endpoints`.
