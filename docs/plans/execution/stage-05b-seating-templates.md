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
- [ ] task-02: `UpsertSeatMap` create path, Data objects, `SeatMapPolicy`, POST endpoint, OpenAPI fragment, generated types (plan task 2, slice 2)
- [ ] task-03: GET item and GET list endpoints with query-builder allowlist and `seat_count`, contract and isolation coverage (plan task 3, slice 3)
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
