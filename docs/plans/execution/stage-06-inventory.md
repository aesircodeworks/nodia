# Stage 6 Execution Journal: Inventory and Reserved Seating

## Run: 2026-07-11

- Stage: 6 (docs/plans/stage-06-inventory.md)
- Date: 2026-07-11
- Branch: feat/api-implementation
- Base commit: ffa8ae3d4d87321b9d497c370d170fe6a16c86ff

### Task checklist

- [x] 06-01 Counters: ticket_type_inventory migration, model, InitializeTicketTypeInventory and AdjustInventoryQuantity Actions, isolation probe (plan slice 1, task 2)
- [x] 06-02 Catalog quantity contract: additive quantity on ticket type Data objects, delegation to Inventory Actions, requires_seat rejection (plan task 3)
- [x] 06-03 GA hold creation: holds and hold_items migrations, HoldStatus, CreateHold, HoldCreated, POST and GET endpoints, GA oversell simulation green (plan slice 2, task 4)
- [x] 06-04 Release and expiry: ReleaseHold, DELETE endpoint, HoldReleased, ReleaseExpiredHolds sweeper, HoldExpired, expiry recovery simulation green (plan slice 3, tasks 5 and 6)
- [x] 06-05 Availability reads: storefront availability endpoint and admin inventory read with contracts (plan slice 3, task 7)
- [x] 06-06 ExtendHold and CommitHold internal Actions with commit-versus-expiry race (plan slice 4, task 8)
- [x] 06-07 Seat materialization on publish: event_seats migration, MaterializeEventSeats, requires_seat publish validation, seat_map_in_use extension (plan slice 5, task 9)
- [x] 06-08 Seated holds through CreateHold, ReleaseHold, CommitHold, sweeper; double-booking simulation green (plan slice 6, task 10)
- [x] 06-09 events.manage_seating capability registry addition and role wiring (plan task 10a)
- [x] 06-10 Seat management and read surfaces: storefront seats, admin seats list, admin PATCH, contracts (plan slice 7, task 11)
- [x] 06-11 Exit sweep: status table flip to done, OpenAPI consolidation check, full gate run (plan task 12, exit criteria)

### Review rounds

### Decisions and deviations

#### Task 06-01: ticket_type_inventory counters (2026-07-11)

Landed the `ticket_type_inventory` counter table and its two Actions per
plan Slice 1 task 2:

- Migration `2026_07_11_000031_create_ticket_type_inventory_table.php`:
  `id` (UUIDv7 PK) plus a unique `ticket_type_id`, `quantity`/`sold`/`held`
  integers, the four CHECK constraints
  (`ticket_type_inventory_quantity_non_negative`,
  `_sold_non_negative`, `_held_non_negative`,
  `_no_oversell` for `sold + held <= quantity`), and
  `Rls::applyTenantPolicies` in the same file.
- `App\Inventory\Models\TicketTypeInventory` (new `Inventory` bounded
  context: `Models`, `Actions`, `Exceptions`), `HasUuids`, no relations
  (Inventory never reaches into Catalog's `TicketType` model directly).
- `App\Inventory\Actions\InitializeTicketTypeInventory`: creates the
  counter row given `tenant_id`, `ticket_type_id`, `quantity`, seeding
  `held`/`sold` at 0 explicitly (Eloquent's `create()` does not read back
  server-side DEFAULTs onto the in-memory model, so leaving them off the
  payload left `held`/`sold` null on the returned instance until a
  `fresh()` call; passing them explicitly is simpler and matches every
  other counter caller's expectation of a fully-populated object back).
- `App\Inventory\Actions\AdjustInventoryQuantity`: increase (`$delta >=
  0`) is an unconditional `UPDATE quantity = quantity + delta`; decrease
  adds `WHERE sold + held <= quantity + delta` to the same statement,
  checked by affected-row count, never read-then-write. Zero affected
  rows throws the new `App\Inventory\Exceptions\
  InsufficientInventoryException` (`ErrorCode::InsufficientInventory`,
  wire code `insufficient_inventory`, 409, matching the plan's endpoint
  table for the future hold-creation guard reusing this same shape).
- Isolation: `tests/Isolation/Support/TicketTypeInventoryFixture.php`
  (builds on `TicketTypeFixture`) and
  `tests/Isolation/TicketTypeInventoryIsolationTest.php`, mirroring
  `TicketTypesIsolationTest`'s shape (own-tenant read, cross-tenant
  update/delete affect zero rows, `WITH CHECK` on foreign `tenant_id`,
  the three independently-reachable CHECK constraints plus a
  `pg_constraint` existence check for `quantity_non_negative` since a
  negative `quantity` alongside non-negative `sold`/`held` always also
  trips `no_oversell`, making that one constraint unreachable in
  isolation from the other three; documented inline in the test).
  Two extra tests exercise the plan's own SQL shape for the held-increment
  guard directly (`UPDATE ... SET held = held + ? WHERE ticket_type_id =
  ? AND sold + held + ? <= quantity`) via `DB::update`, proving the
  affected-row-count pattern task 4's `CreateHold` will reuse, without
  introducing that Action ahead of its own task.
- Unit: `tests/Unit/Inventory/InitializeTicketTypeInventoryTest.php` and
  `AdjustInventoryQuantityTest.php`, mirroring
  `tests/Unit/EventCatalog/CreateTicketTypeTest.php`'s
  `TenantTransaction::asTenant()` structure: creates the row with the
  given quantity; increase always succeeds; a decrease that still covers
  `sold + held` applies; a decrease below `sold + held` throws
  `InsufficientInventoryException` and leaves `quantity` unchanged.
- `tests/Architecture/PresetTest.php`: added `App\Inventory\Models` and
  `InsufficientInventoryException::class` to the preset's ignore list
  (every other context's `Models` namespace and `HasErrorCode` exception
  is already listed there the same way).
  `tests/Architecture/ContextBoundariesTest.php` already had `Inventory`
  in its `$contexts` array from the stage-06 plan's own scaffolding, so
  no change was needed there.
- `ProblemRenderer::detailFor()` and `tests/Unit/Problems/
  ErrorCodeTest.php`'s registry snapshot both updated for the new
  `ErrorCode::InsufficientInventory` case (Larastan's match-exhaustiveness
  check caught the renderer miss).
- `composer types:generate` run; `packages/api-client/src/generated/
  index.ts` and `typescript-transformer-manifest.json` regenerated for
  the new `ErrorCode` case (no new Data class this task; the counter
  table has no laravel-data object yet, that arrives with task 3's
  Catalog quantity contract).

Test evidence (run from `apps/api`):

- `php artisan test --filter="InitializeTicketTypeInventoryTest|AdjustInventoryQuantityTest|TicketTypeInventoryIsolationTest"`: 17 passed, 34 assertions.
- `php artisan test --testsuite=Architecture`: 38 passed, 94 assertions.
- `php artisan test --testsuite=Isolation --testsuite=Unit`: 557 passed, 1331 assertions.
- `php artisan test --testsuite=Feature --filter="Problem|ErrorCode"`: 81 passed, 492 assertions (confirms the `ErrorCode` registry change did not regress the problem-document renderer's own suite).
- `composer analyse`: passed (0 errors).
- `composer lint`: passed.

No deviations from the plan beyond the two documented above (explicit
`held`/`sold` seeding on create, and the `pg_constraint`-existence
substitute for the unreachable-in-isolation `quantity_non_negative`
insert test).

#### Task 06-02: Catalog quantity contract change (2026-07-11)

Landed the additive `quantity` field on the Stage 5a ticket type Data
objects and wired both Catalog Actions to Inventory, per plan task 3 and
the Risks note "Quantity input ownership":

- `App\EventCatalog\Data\CreateTicketTypeData` and `UpdateTicketTypeData`
  gain `public int|Optional $quantity` with rule `['sometimes',
  'integer', 'min:0']`. `quantity` never lands on `ticket_types` or on
  `TicketTypeData`'s response shape (unchanged); it is read only by the
  two Catalog Actions and handed to Inventory.
- New `App\Inventory\Actions\SetTicketTypeQuantity`: locks the counter
  row (`lockForUpdate`), seeds one via `InitializeTicketTypeInventory` if
  none exists yet, otherwise computes the delta against the locked
  quantity and calls `AdjustInventoryQuantity`, so Catalog never reads or
  writes `App\Inventory\Models\TicketTypeInventory` directly. Chosen over
  making `AdjustInventoryQuantity` itself accept an absolute value
  because that Action's contract (delta, guarded decrease) is already
  proven by task 06-01's tests and is reused unchanged by the future
  hold-creation guard; `SetTicketTypeQuantity` is Catalog's translation
  layer, not a new primitive.
- `CreateTicketType`: resolves effective `requiresSeat` (existing
  Optional-default-false pattern), throws `Illuminate\Validation\
  ValidationException` on `quantity` given with `requires_seat` true
  (renders as the generic `request.validation_failed`, matching
  `ProblemRenderer`'s existing handling for every `ValidationException`),
  otherwise calls `SetTicketTypeQuantity` with the given quantity or `0`
  when omitted. Seated types get no counter row here; task 9's
  `MaterializeEventSeats` seeds theirs at publish.
- `UpdateTicketType`: resolves effective `requiresSeat` as the given
  value or, when the payload omits `requires_seat`, the ticket type's
  persisted value (a Data class cannot read the model, so this re-check
  could not live in `UpdateTicketTypeData`, mirroring
  `ValidatesTicketTypeInvariants`'s own documented limitation for the
  sales-window pair). Same `quantity`+`requires_seat` guard, evaluated
  before `$ticketType->update()` so a request that fails the guard leaves
  no partial write. When `quantity` is given and the effective type is
  GA, calls `SetTicketTypeQuantity` after the model update.
- OpenAPI: `TicketTypeCreateRequest`/`TicketTypeUpdateRequest` gain
  `quantity` (integer, `minimum: 0`); a new
  `TicketTypeUpdateConflictProblem` schema covers the PATCH endpoint's
  409, now either `catalog.event_immutable` or `insufficient_inventory`;
  both endpoints' 422 descriptions mention the new quantity failure
  modes. `composer types:generate` run; `packages/api-client/src/generated/
  index.ts` and the manifest regenerated with `quantity?: number` on both
  request types.
- Test-first, per the master plan double loop: added the requires_seat
  rejection, quantity-seeding, and quantity-update feature tests to
  `tests/Feature/EventCatalog/TicketTypeEndpointsTest.php` before writing
  the Data/Action changes, watched them fail (`Undefined property
  $quantity`, then 500s once the field existed but nothing consumed it),
  then implemented. Same order for the unit suites below.
- Unit: extended `tests/Unit/EventCatalog/CreateTicketTypeTest.php` and
  `UpdateTicketTypeTest.php` with quantity-seeding, zero-default,
  seated-rejection, and (update-only) existing-row-adjustment and
  insufficient-inventory cases; new
  `tests/Unit/Inventory/SetTicketTypeQuantityTest.php` mirrors
  `InitializeTicketTypeInventoryTest.php`'s structure for the new Action
  directly (create-when-absent, increase, guarded decrease, no-op).
- Cleanup fix, not itself part of task 3's contract but required for
  every affected suite to pass once `ticket_type_inventory` rows started
  being created: every Feature and Unit test file whose `afterEach`
  deletes `ticket_types` for a tenant needed a `ticket_type_inventory`
  delete first (the new table's FK to `ticket_types.id` has no cascade).
  Touched `tests/Feature/EventCatalog/{TicketTypeEndpointsTest,
  TicketTypeEventUpdatedOutboxTest, EventEndpointsTest,
  StorefrontEventEndpointsTest, CatalogPublishSequenceTest}.php`,
  `tests/Feature/Identity/ActivityLogCoverageTest.php`, and
  `tests/Unit/EventCatalog/{CreateTicketTypeTest,UpdateTicketTypeTest}.php`.
  Left uncaught this surfaced only as cascading failures several files
  later in a full-suite run (an aborted `afterEach` from an FK violation
  skips the platform-tenant delete that follows it, so the orphaned
  tenant and its rows then broke unrelated tests, e.g.
  `tests/Unit/Tenancy/CreateTenantTest.php`'s tenant-count assertions);
  traced by bisecting with `git stash` against the same polluted database
  to confirm the baseline was clean and the diff was the cause.

Test evidence (run from `apps/api`):

- `php artisan test --filter="TicketTypeEndpointsTest"`: 44 passed, 192 assertions.
- `php artisan test --filter="TicketTypeEventUpdatedOutboxTest"`: included above, passing.
- `php artisan test --filter="CreateTicketTypeTest|UpdateTicketTypeTest|SetTicketTypeQuantityTest|InitializeTicketTypeInventoryTest|AdjustInventoryQuantityTest"`: 71 passed, 256 assertions.
- `php artisan test --testsuite=Architecture`: 38 passed, 94 assertions (Catalog reaches Inventory only through `SetTicketTypeQuantity`/`InitializeTicketTypeInventory`, no direct model or table access; the boundary and preset suites both pass unchanged).
- `php artisan test --testsuite=Feature --filter="EventCatalog|Identity"`: 517 passed, 2517 assertions.
- `php artisan test --testsuite=Unit`: 557 passed, 1331 assertions (unchanged count from task 06-01, confirming the cleanup fix above did not add or drop any Unit test).
- `php artisan test --testsuite=Isolation`: 189 passed, 413 assertions.
- `composer analyse`: passed (0 errors).
- `composer lint`: passed.

No deviations from the plan beyond the two documented above (the
cleanup-fix scope and the `SetTicketTypeQuantity`-vs-absolute-
`AdjustInventoryQuantity` design choice).

#### Task 06-03: GA hold creation with oversell simulation (2026-07-11)

Landed Slice 2's GA path (stage-06 plan, task breakdown item 4): the
`holds`/`hold_items` tables, `HoldStatus`, `CreateHold`, and
`POST`/`GET /v1/storefront/holds`, turning the GA oversell simulation
from slice 0 green.

- `holds` (id, tenant_id, event_id, customer_id nullable, status,
  expires_at) and `hold_items` (id, tenant_id, hold_id, ticket_type_id,
  quantity with a `> 0` CHECK) migrations, each with RLS in the same
  file, `event_id`/`customer_id`/`ticket_type_id`/`hold_id` all real FKs.
  `App\Inventory\Models\{Hold,HoldItem}`, `App\Inventory\Enums\HoldStatus`
  (`active`, `released`, `expired`, `committed`), and their factories.
- `App\Inventory\Actions\CreateHold`: validates the request against
  Catalog facts through a new read-only seam,
  `App\EventCatalog\Actions\ResolveEventForHold` (returning
  `#[Hidden]` `HoldableEventData`/`HoldableTicketTypeData` Data objects),
  never by querying `Event`/`TicketType` directly (the architecture
  boundary rule), the same pattern `CreateTicketType` already
  established for the Inventory-ward direction. Per item: event
  published lookup (`event_not_found`), ticket-type membership
  (`ticket_type_not_in_event`), sales-window check against
  `Illuminate\Support\Facades\Date::now()` (`sales_window_closed`),
  then the held-increment conditional UPDATE (`sold + held + n <=
  quantity`, checked by affected-row count, throwing
  `InsufficientHoldInventoryException` with a `ticket_type_id`
  extension member on zero rows). TTL is a 10-minute constant. Runs
  entirely inside the ambient request transaction
  `ResolveTenantFromHost` already opened, so the hold row, its items,
  the counter guard, and the `HoldCreated` outbox row commit or roll
  back together.
- `App\Inventory\Http\Controllers\HoldController`: `customer_id` is
  never a `CreateHoldData` property (a client-supplied identity
  assertion is not trusted alone), so a body-supplied value is simply
  ignored; the controller derives it from `$request->user('customer')`
  (optional, no `auth:customer` middleware on either route), null for
  guests. `GET` renders `hold_not_found` for an unknown or
  cross-tenant id (RLS makes cross-tenant a natural miss). New
  `App\Inventory\InventoryServiceProvider` mounts the two routes under
  `tenancy.storefront` and registers `HoldCreated` in the outbox event
  type registry.
- Four new stable error codes (`event_not_found` 404,
  `ticket_type_not_in_event` 422, `sales_window_closed` 409,
  `hold_not_found` 404) added to the `ErrorCode` registry;
  `insufficient_inventory` (409) is reused for the hold path via a new
  `InsufficientHoldInventoryException` distinct from the existing
  quantity-adjustment one so the PATCH ticket-type endpoint's existing
  conflict schema (no `errors` member) stays unchanged.
- OpenAPI: `POST /v1/storefront/holds` and
  `GET /v1/storefront/holds/{hold}` paths, `Hold`/`HoldItem`/
  `HoldItemInput`/`CreateHoldRequest` schemas, and four new problem
  schemas (`HoldEventNotFoundProblem`, `HoldNotFoundProblem`,
  `HoldCreateUnprocessableProblem`, `HoldCreateConflictProblem`).
  `composer types:generate` run; `packages/api-client/src/generated/
  index.ts` and the manifest regenerated with `CreateHoldData`,
  `HoldData`, `HoldItemData`, `HoldItemInputData`, `HoldStatus`, and the
  four new `ErrorCode` members.
- Test-first per the master plan double loop: the GA oversell
  concurrency simulation (`tests/Concurrency/GaHoldContentionTest.php`,
  exact-fit/2x/10x oversubscription plus a multi-unit-per-request case,
  driven through the real HTTP kernel in forked workers against
  `POST /v1/storefront/holds`) was written and run failing before the
  migrations existed, then turned green by the implementation, per
  slice 0's rule that a failing probe cannot land on `main` so it
  merges with the task that turns it green. Isolation probes for
  `holds` and `hold_items` (`tests/Isolation/{HoldsIsolationTest,
  HoldItemsIsolationTest}.php`) went the same route. Unit
  (`tests/Unit/Inventory/CreateHoldTest.php`, including a rollback
  probe: `HoldCreated` present via a mid-transaction `OutboxEvent`
  query, then absent and the hold row gone after a forced
  `RuntimeException` rolls the surrounding `TenantTransaction::asTenant`
  back) and feature
  (`tests/Feature/Inventory/HoldEndpointsTest.php`, every failure-mode
  row from the endpoint table, `expires_at` asserted via `travelTo`,
  customer-token derivation, guest null, and a feature-level
  cross-tenant 404 probe) suites were written before the Action and
  controller existed and watched fail on the missing classes.
- `tests/Contract/DocumentedResponseCoverageTest.php` gained
  `contractHoldTenant()`/`contractHoldFixture()` helpers and six
  exercisers (one per newly documented `method path status` triple),
  required by that suite's own coverage gate; `tests/Architecture/
  PresetTest.php` gained the new context's ignore-list entries
  (`App\Inventory\Http\Controllers`, `InventoryServiceProvider`,
  `HoldStatus`, the five new exception classes) the Laravel preset
  needs for a fresh bounded context, mirroring every earlier context's
  own entries.

Test evidence (run from `apps/api`):

- `php artisan test --filter="CreateHoldTest|HoldEndpointsTest|HoldsIsolationTest|HoldItemsIsolationTest|GaHoldContentionTest|ErrorCodeTest"`: 103 passed, 384 assertions.
- `php artisan test --testsuite=Architecture`: 38 passed, 94 assertions.
- `php artisan test --testsuite=Isolation`: 201 passed, 433 assertions.
- `php artisan test --testsuite=Unit`: 587 passed, 1407 assertions.
- `php artisan test --testsuite=Feature`: 732 passed, 3622 assertions.
- `php artisan test --testsuite=Contract`: 247 passed, 1575 assertions (one flaky faker-email-collision failure on a first run, in an unrelated pre-existing exerciser, gone on rerun against a clean database).
- `composer analyse`: passed (0 errors).
- `composer lint`: passed (Pint auto-fixed one import order in the new unit test file).

Deviations from the plan, both scope-narrowing and both intentional
given task 06-03's own instructions restrict this task to the GA path:

- Seated ticket types are out of scope: `CreateHold` has no
  `seat_ids` input yet and no `requires_seat` special-case. A
  `requires_seat` ticket type simply falls through to the same
  held-increment guard as GA, which stays safe because a later task's
  `MaterializeEventSeats` seeds every seated counter's `quantity` at 0
  until zoning assigns seats, so an unmaterialized or unzoned seated
  type always fails `insufficient_inventory` rather than overselling.
  `HoldData`/`HoldCreatedPayload` ship `seat_ids` as an always-empty
  array now so the response and payload envelopes do not change shape
  once a later task adds seat handling.
- The plan's GA oversell simulation description also asks for "mixed
  create-and-release interleaving"; `ReleaseHold` does not exist yet
  (task breakdown item 5), so `GaHoldContentionTest` covers only the
  create-side oversubscription matrix (exact-fit, 2x, 10x, and a
  multi-unit-per-request case) and defers the release interleaving
  case to the task that ships `ReleaseHold`.

#### Task 06-04: Release, expiry sweeper, hold lifecycle events (2026-07-11)

Landed Slice 3, task breakdown items 5 and 6: explicit hold release,
the expiry sweeper, and the `HoldReleased`/`HoldExpired` outbox events,
turning the exactly-one-of race and the expiry-recovery simulation
green.

- `App\Inventory\Actions\ReleaseHold` (DELETE /v1/storefront/holds/{hold}):
  the active -> released transition is a conditional UPDATE checked by
  affected-row count, never read-then-write. A hold found already
  released or expired is an idempotent no-op (204, no second event); a
  committed hold throws `HoldNotReleasableException` (409
  `hold_not_releasable`, new `ErrorCode` member); an unknown or
  cross-tenant id throws the existing `HoldNotFoundException` (404,
  RLS makes cross-tenant a natural miss). Losing the transition race
  (the sweeper or another release request won it first) re-checks the
  current status rather than erroring: committed still refuses, anything
  else is the same idempotent no-op.
- `App\Inventory\Actions\ReleaseExpiredHolds`: the sweeper. Candidate
  discovery is a cross-tenant platform SELECT of active holds past
  `expires_at` (mirrors `App\Support\Outbox\OutboxSweeper`'s own
  precedent), but the active -> expired conditional UPDATE, counter
  release, and `HoldExpired` recording for each candidate run inside
  that hold's own tenant transaction, one hold at a time. A hold an
  explicit release already claimed makes the sweeper's own UPDATE
  affect zero rows, so it is simply skipped: this conditional-UPDATE
  pairing is what guarantees exactly one of `HoldReleased`/`HoldExpired`
  is ever recorded per hold, never both, never zero. Wired onto the
  scheduler as `holds:release-expired`
  (`App\Console\Commands\ReleaseExpiredHoldsCommand`), every minute
  (`bootstrap/app.php`), mirroring `outbox:sweep`'s own precedent.
- Counter reconciliation (`held = held - n WHERE held >= n`, the mirror
  image of `CreateHold::claim`'s held-increment guard) is shared by both
  Actions through a new `App\Inventory\Actions\Concerns\
  ReleasesHoldInventory` trait rather than duplicated, since the two
  Actions differ only in how they win their own conditional transition,
  not in what happens once they have.
- `HoldReleased` and `HoldExpired` events, each carrying `hold_id`,
  `event_id`, `items`, `seat_ids` (always empty in this task, same as
  `HoldCreated`'s own precedent) per the Domain events table.
  `HoldCreatedItemPayload` renamed to `HoldItemPayload` and shared by
  all three payload classes rather than three near-identical item
  classes. Both event types registered in
  `InventoryServiceProvider::boot()` alongside `HoldCreated`.
- OpenAPI: `DELETE /v1/storefront/holds/{hold}` (204/404/409) and the
  new `HoldNotReleasableProblem` schema. `composer types:generate` run
  (the new `ErrorCode::HoldNotReleasable` member is `#[TypeScript]`);
  `packages/api-client/src/generated/index.ts` and the manifest
  regenerated.
- Test-first per the master plan double loop: `tests/Unit/Inventory/
  {ReleaseHoldTest,ReleaseExpiredHoldsTest}.php` (the fake-clock TTL
  matrix: well before `expires_at` untouched, exactly at `expires_at`
  expires, long past expires, a second sweeper run is a no-op, a
  sweeper run skips a hold an explicit release already claimed;
  idempotent re-release with no second event; a rollback probe mirroring
  `CreateHoldTest`'s own) were written and watched fail on the missing
  `ReleaseHold`/`ReleaseExpiredHolds` classes before either existed.
  `tests/Concurrency/HoldExpiryRecoveryContentionTest.php` adds two
  simulations: an explicit release racing the sweeper for the same hold
  (`ParallelRunner::runEach`, asserting the hold ends in exactly one
  terminal status and exactly one of `HoldReleased`/`HoldExpired` is
  recorded), and the expiry-recovery simulation itself (a stale
  eight-unit hold, the sweeper racing three new three-unit hold
  requests through the real HTTP kernel; the invariant asserted is
  interleaving-independent: `sold + held <= quantity` always holds, and
  once every worker has joined, `held` exactly equals `3 *
  successCount`, i.e. availability recovers to exactly `quantity - sold`
  for whatever surviving holds remain, not a fixed success count, since
  the new-hold workers legitimately see `insufficient_inventory` for
  any of them that lands before the sweeper's own commit frees the
  stale hold's units). `tests/Feature/Inventory/HoldEndpointsTest.php`
  gained a `DELETE /v1/storefront/holds/{hold}` describe block covering
  every endpoint-table row (idempotent 204, `hold_not_releasable` 409,
  `hold_not_found` 404 for unknown and cross-tenant, and an
  expiry-then-release no-op showing availability recovers exactly to
  `quantity`).
- `tests/Contract/DocumentedResponseCoverageTest.php` gained 404 and 409
  exercisers for the new DELETE path; no 204 exerciser was added, since
  `OpenApiSpec::documentedResponseSchemas()` only indexes responses that
  carry a `content` block and a 204 carries none, so a 204 exerciser
  would never match a documented triple (this mirrors the existing
  204-DELETE endpoints elsewhere in the suite, e.g. seat maps, roles,
  memberships, none of which have one either); the feature suite's own
  DELETE describe block is the 204 shape's coverage instead.
  `tests/Architecture/PresetTest.php` gained
  `HoldNotReleasableException::class` in the ignore-list (implements
  `Throwable`, same as every other `HasErrorCode` exception already
  there). `tests/Unit/Problems/ErrorCodeTest.php` gained the new
  `hold_not_releasable` registry and status/title/type-slug rows.

Test evidence (run from `apps/api`):

- `php artisan test --filter="ReleaseHoldTest|ReleaseExpiredHoldsTest|HoldExpiryRecoveryContentionTest|HoldEndpointsTest|CreateHoldTest|GaHoldContentionTest|ErrorCodeTest"`: 48 passed, 184 assertions (plus a further combined run including `ErrorCodeTest` at 97 passed, 385 assertions once the registry fixture was updated).
- `php artisan test --testsuite=Unit,Contract,Isolation,Architecture,Concurrency` (combined): 1109 passed, 3656 assertions.
- `php artisan test --testsuite=Feature`: 738 passed, 3642 assertions on a clean rerun; an earlier run in the same session showed 12 failures, all in `tests/Feature/Tenancy/*`, none touching Inventory, and gone on rerun in isolation (`php artisan test --filter=Tenancy --testsuite=Feature`: 143 passed) and on a full clean rerun, confirming pre-existing cross-test pollution rather than a regression from this task.
- `HoldExpiryRecoveryContentionTest` run three additional times back to back with no failures, confirming the race and recovery assertions are not flaky under the chosen invariants.
- `composer analyse`: passed (0 errors) after fixing two findings the first run surfaced: `ProblemRenderer::detailFor()`'s match needed the new `ErrorCode::HoldNotReleasable` arm, and `ReleaseExpiredHolds::findCandidates()` was typed to return `object{id, tenant_id}` but a `DB::table('holds')->get()` actually returns `stdClass` rows under Larastan's type inference; switched the query to `Hold::query()->select(...)->get()` so the return type is a real `Collection<int, Hold>`.
- `composer lint`: passed (Pint auto-fixed import ordering and a redundant closure-parameter import in the two new test files on first run).
- `composer types:generate`: run; `packages/api-client/src/generated/index.ts` and the manifest regenerated with the new `HoldNotReleasable` `ErrorCode` member.

No deviations from the plan beyond the two documented above (the 204
exerciser omission from the contract coverage gate, and the
`findCandidates()` typing fix), both mechanical consequences of the
codebase's existing conventions rather than scope changes.

#### Task 06-05: Availability reads, storefront and admin (2026-07-11)

Landed Slice 3, task breakdown item 7: the database-backed storefront
availability read and the admin ticket-type inventory read.

- `GET /v1/storefront/events/{event}/availability`
  (`App\Inventory\Http\Controllers\AvailabilityController`,
  `App\Inventory\Actions\GetEventAvailability`): reuses
  `App\EventCatalog\Actions\ResolveEventForHold`, the same read-only
  cross-context seam `CreateHold` already depends on, so Inventory never
  touches `App\EventCatalog\Models\Event` or `TicketType` directly
  (system-design 3.1 boundary rule); a nonexistent or unpublished event
  id renders the same `event_not_found` code either way, reusing
  `App\Inventory\Exceptions\HoldEventNotFoundException` rather than
  minting a duplicate exception, since the code, status, and unpublished-
  existence posture are identical. Returns `EventAvailabilityData`: per
  ticket type `{ticket_type_id, available, on_sale}`, `available =
  quantity - sold - held` (defensively floored at 0, though the
  `ticket_type_inventory_no_oversell` CHECK constraint makes a negative
  value unreachable in practice), `on_sale` true when `now` falls inside
  the ticket type's own `sales_start`/`sales_end` window (inclusive at
  both bounds, mirroring `CreateHold::assertHoldable`'s own comparison
  operators). Database-backed and authoritative per the plan; a later
  stage fronts this with a cache without changing the contract.
- `GET /v1/ticket-types/{ticket_type}/inventory`
  (`App\Inventory\Http\Controllers\TicketTypeInventoryController`, new
  `app/Inventory/Http/routes/admin.php` mounted under `tenancy.admin` in
  `InventoryServiceProvider`, gated by the `events.view` capability like
  `TicketTypeController`'s own read routes): returns
  `TicketTypeInventoryData` (`quantity`, `sold`, `held`) read directly
  off the tenant-scoped `ticket_type_inventory` row, no cross-context
  reach needed since Inventory already owns that table. A missing row
  (nonexistent or foreign-tenant ticket type; every real ticket type
  always has one, seeded by `InitializeTicketTypeInventory` at creation)
  throws the new `App\Inventory\Exceptions\TicketTypeInventoryNotFoundException`,
  mapped to the generic `request.not_found` code, mirroring
  `App\EventCatalog\Exceptions\TicketTypeNotFoundException`'s own
  precedent so existence never leaks across tenants.
- OpenAPI: `GET /v1/storefront/events/{event}/availability` (200/404,
  the 404 sharing the existing `HoldEventNotFoundProblem` schema rather
  than a duplicate, since status and code are identical to the hold
  endpoint's own) and `GET /v1/ticket-types/{ticket_type}/inventory`
  (200/401/403/404, reusing `TicketTypeForbiddenProblem` and
  `NotFoundProblem` per `getTicketType`'s own precedent), plus the new
  `EventAvailability`, `TicketTypeAvailability`, and `TicketTypeInventory`
  schemas. `composer types:generate` run;
  `packages/api-client/src/generated/index.ts` and the manifest
  regenerated with `EventAvailabilityData`, `TicketTypeAvailabilityData`,
  and `TicketTypeInventoryData`.
- Test-first per the master plan double loop:
  `tests/Feature/Inventory/AvailabilityEndpointsTest.php` (written and
  watched fail on the missing routes before either controller existed)
  covers the arithmetic and `on_sale` shape, the unknown/draft-event
  404, the admin staff-bearer-plus-X-Tenant-Id 200, and unknown/cross-
  tenant ticket type 404s, plus an expiry-recovery case reusing
  `ReleaseExpiredHolds` to show availability returns to `quantity`
  exactly. `tests/Unit/Inventory/GetEventAvailabilityTest.php` isolates
  `GetEventAvailability`'s own invariants: the arithmetic, the
  sales-window boundary inclusive at both `sales_start` and `sales_end`,
  a fully-exhausted-inventory zero case, and both `HoldEventNotFoundException`
  branches (unknown, draft). `tests/Contract/DocumentedResponseCoverageTest.php`
  gained exercisers for both new paths' documented response triples
  (200/404 for availability; 200/401/403/404 for the admin inventory
  read). `tests/Architecture/PresetTest.php` gained
  `TicketTypeInventoryNotFoundException::class` in the ignore-list
  (implements `Throwable`, same as every other `HasErrorCode` exception
  already there).

Test evidence (run from `apps/api`):

- `php artisan test --filter=AvailabilityEndpointsTest`: 8 passed, 31
  assertions.
- `php artisan test --filter=GetEventAvailabilityTest`: 6 passed, 7
  assertions.
- `php artisan test --filter=DocumentedResponseCoverageTest`: 250
  passed, 1245 assertions.
- `php artisan test --testsuite=Architecture`: 38 passed, 94 assertions.
- `php artisan test --testsuite=Unit,Contract,Isolation,Architecture,Concurrency`
  (combined): 1121 passed, 3706 assertions.
- `php artisan test --testsuite=Feature`: 746 passed, 3673 assertions.
- `composer analyse`: passed (0 errors).
- `composer lint`: passed, no changes needed.
- `composer types:generate`: run;
  `packages/api-client/src/generated/index.ts` and the manifest
  regenerated.

No deviations from the plan. One design choice not spelled out in the
plan text: the availability 404 problem document reuses
`HoldEventNotFoundProblem`/`HoldEventNotFoundException` verbatim rather
than introducing an availability-specific pair, since the status, code,
and unpublished-existence posture are identical to the hold-creation
endpoint's own `event_not_found` case; this keeps one source of truth
for that shape rather than two schemas that would need to stay in sync
by hand.

#### Task 06-06: ExtendHold and CommitHold internal Actions (2026-07-11)

Landed Slice 4, task breakdown item 8: the two internal-only Actions
Stage 8a (payment-window extension) and Stage 7 (order-paid commit)
will call in-process. No HTTP surface, no OpenAPI, no request-facing
Data objects, per the plan.

- `App\Inventory\Actions\ExtendHold` (`App\Inventory\Data\ExtendHoldData`
  input, holdId plus a `CarbonImmutable` `expiresAt`): a single
  conditional UPDATE, `status = 'active' AND expires_at > now() AND
  :new > expires_at`, checked by affected-row count exactly as
  system-design 6.1 specifies, so extension can never resurrect an
  expired or released hold and never shortens `expires_at`. Zero
  affected rows throws the new `HoldNotExtendableException`
  (`hold_not_extendable`, 409); an unknown hold id throws the existing
  `HoldNotFoundException` first.
- `App\Inventory\Actions\CommitHold` (`App\Inventory\Data\CommitHoldData`
  input, holdId only): the hold's own active -> committed transition is
  a conditional UPDATE guarded by `status = 'active' AND expires_at >
  now()`, so an expired-but-unswept hold, a double commit, and a
  released hold all fail this one guard with the same
  `HoldNotCommittableException` (`hold_not_committable`, 409). Each
  item's held -> sold move is its own guarded conditional UPDATE
  (`held >= quantity`), so a failure partway rolls the whole commit
  back with the caller's transaction. No outbox event is recorded:
  there is no `HoldCommitted` event per the plan's exit criteria; the
  commit rides inside whatever transaction and event the caller (Stage
  7's order-paid path) owns.
- Two new `ErrorCode` members, `HoldNotExtendable` and
  `HoldNotCommittable`, both 409: required even though these Actions
  have no HTTP surface in this stage, because the global exception
  handler renders any `HasErrorCode` exception regardless of which
  endpoint raised it, and a future caller (Stage 7, Stage 8a) may let
  either exception bubble to its own HTTP response.
- `tests/Architecture/PresetTest.php`: added the two new exception
  classes to the existing allowlist of domain exceptions permitted to
  implement `Throwable`, mirroring the other Inventory exceptions
  already listed there.

Test evidence (all from `apps/api`):

- `php artisan test --filter=ExtendHoldTest`: 5 passed, 9 assertions.
- `php artisan test --filter=CommitHoldTest`: 6 passed, 15 assertions.
- `php artisan test --filter=HoldCommitExpiryContentionTest`: 1 passed,
  6 assertions; repeated 5 times to check for race flakiness, stable
  every run.
- `php artisan test --testsuite=Unit --filter=Inventory`: 55 passed,
  129 assertions.
- `php artisan test --testsuite=Concurrency`: 23 passed, 105
  assertions.
- `php artisan test --testsuite=Isolation --filter=Hold`: 12 passed,
  20 assertions (no new tenant-scoped table in this task, so no new
  probe needed).
- `php artisan test --testsuite=Architecture`: 38 passed, 94
  assertions (green only after the PresetTest allowlist update above).
- `php artisan test --testsuite=Feature --filter=Inventory`: 30
  passed, 115 assertions.
- `./vendor/bin/pint --test` on all changed files: passed (one
  auto-fix applied to `ExtendHoldTest.php`'s import ordering before
  the final run).
- `./vendor/bin/phpstan analyse --memory-limit=1G` on all changed
  files: passed, 0 errors.
- `composer types:generate`: run; `packages/api-client/src/generated/index.ts`
  and the manifest regenerated with the two new `ErrorCode` members.

Commit: `9970af3` (`feat(inventory): ExtendHold and CommitHold internal
Actions`).

No deviations from the plan beyond the PresetTest allowlist addition,
a mechanical consequence of adding new domain exceptions under the
codebase's existing architecture rule.

#### Task 06-07: event_seats materialization on publish (2026-07-11)

Landed Slice 5, task breakdown item 9: the three Stage 5b deferrals
(publish-blocking validation for a requires_seat ticket type without a
seat_map_id, the restricting FK from event_seats.seat_id to seats.id,
and extending catalog.seat_map_in_use to materialized maps), plus
MaterializeEventSeats and its wiring into Catalog's publish Action.

- `event_seats` migration (RLS, `unique(event_id, seat_id)`, restricting
  FK on `seat_id` to `seats.id`, FK on `hold_id` to `holds.id`, indexes
  on `(event_id, status)`, `hold_id`, and `(event_id, ticket_type_id)`),
  `App\Inventory\Enums\EventSeatStatus`, `App\Inventory\Models\EventSeat`.
- `App\Inventory\Actions\MaterializeEventSeats`: takes tenant id, event
  id, and the seat ids and requires_seat ticket type ids Catalog already
  resolved (Inventory never reads App\EventCatalog's seats or
  ticket_types tables directly), wraps the whole call in `DB::transaction`
  so a mid-way failure (an unresolvable seat id in the unit suite's
  probe) rolls back every event_seats row and every counter seed
  together. `insertOrIgnore` against the `unique(event_id, seat_id)`
  constraint makes seat materialization idempotent; each requires_seat
  ticket type's counter is seeded via the existing
  `InitializeTicketTypeInventory` only when no counter row exists yet,
  making the counter seed idempotent too.
- `App\EventCatalog\Exceptions\SeatMapRequiredException`
  (`catalog.seat_map_required`, 409) and the publish validation in
  `App\EventCatalog\Actions\PublishEvent`: before the conditional
  UPDATE, an event with at least one requires_seat ticket type and no
  seat_map_id is refused. This is a shape validation on the event's own
  data, not a concurrency guard, so a plain read-then-throw is correct
  here per CLAUDE.md's read-then-write rule (which targets invariant-
  guarding state transitions). After a successful publish of a seated
  event, PublishEvent resolves the template's seat ids via
  App\EventCatalog\Models\Seat and calls MaterializeEventSeats inside
  the same transaction the whole admin request already runs in; a GA
  event (`seat_map_id` null) calls nothing.
- `App\EventCatalog\Actions\DeleteSeatMap` now catches both
  `events_seat_map_id_foreign` (Stage 5b's own case) and
  `event_seats_seat_id_foreign` (this task's: seats cascade on
  seat_maps delete, but event_seats.seat_id restricts, so the cascade
  itself fails), mapping either to the existing `SeatMapInUseException`.
- OpenAPI: `EventNotPublishableProblem`'s `code` enum gained
  `catalog.seat_map_required` alongside `catalog.event_not_publishable`
  (same 409 status, one schema, mirroring the existing
  `catalog.event_immutable`/`insufficient_inventory` combined-enum
  precedent) rather than a second schema; the publish path's 409
  description and the seat-map DELETE path's own description (already
  anticipating this task from Stage 5b) were updated in text only.
  `composer types:generate` run; `packages/api-client/src/generated/`
  regenerated with `EventSeatStatus` and the new `ErrorCode` member.
- Test-first per the master plan double loop:
  `tests/Unit/Inventory/MaterializeEventSeatsTest.php` (one row per
  template seat unzoned/available, counter seeded at 0, idempotent
  re-materialization, atomic rollback on a bogus seat id) written and
  watched fail before the Action existed.
  `tests/Isolation/EventSeatsIsolationTest.php` plus
  `tests/Isolation/Support/EventSeatFixture.php` (built on SeatFixture,
  creating its event directly rather than through EventFixture::seed()
  to avoid double-seeding TenantFixture, mirroring HoldItemFixture's own
  precedent) prove the standard tenant-isolation matrix for the new
  table. `tests/Feature/EventCatalog/SeatMaterializationEndpointsTest.php`
  covers all four feature-test requirements through the Stage 5a publish
  endpoint: a seated event materializes unzoned seats, a GA event
  materializes nothing, a requires_seat type with no seat_map_id gets
  409 catalog.seat_map_required, and deleting a materialized template's
  seat map gets 409 catalog.seat_map_in_use via the restricting FK.

Test evidence (all from `apps/api`):

- `php artisan test --filter="MaterializeEventSeatsTest|EventSeatsIsolationTest|SeatMaterializationEndpointsTest|ErrorCodeTest"`:
  79 passed, 317 assertions.
- `php artisan test --filter="MaterializeEventSeatsTest|EventSeatsIsolationTest|SeatMaterializationEndpointsTest|CatalogPublishSequenceTest|SeatMapEndpointsTest|TicketTypeEndpointsTest|EventLifecycleContentionTest|ErrorCodeTest"`:
  178 passed, 796 assertions (proves the extension does not regress the
  Stage 5a/5b publish, seat-map, or ticket-type suites).
- `php artisan test --testsuite=Architecture`: 38 passed, 94 assertions
  (green only after adding `SeatMapRequiredException` and
  `EventSeatStatus` to `tests/Architecture/PresetTest.php`'s allowlist).
- `php artisan test --testsuite=Unit,Contract,Isolation,Architecture,Concurrency`
  (combined): 1145 passed, 3772 assertions, run twice (once before,
  once after the `composer analyse`/`composer lint` fixes below) with
  identical results.
- `php artisan test --testsuite=Feature`: 750 passed, 3710 assertions.
- `composer analyse`: failed on first run (phpstan's exhaustive match
  check on `ProblemRenderer::detailFor()` caught not only the new
  `CatalogSeatMapRequired` arm this task added but two pre-existing
  gaps from Stage 6 task 06-06, `HoldNotExtendable` and
  `HoldNotCommittable`, whose `ErrorCode` members were never added to
  `detailFor()`'s match or to `ErrorCodeTest`'s known-codes array; both
  are fixed here since phpstan and `ErrorCodeTest` now both catch the
  gap directly). Passed (0 errors) after adding all three arms.
- `composer lint`: failed on the two new test files (import ordering,
  an unused import); passed after `pint` auto-fixed them.
- `composer types:generate`: run; `packages/api-client/src/generated/index.ts`
  and the manifest regenerated with `EventSeatStatus` and
  `catalog.seat_map_required`.

Commit: `3d02711` (`feat(catalog): materialize event_seats on publish`),
scope `catalog` per the plan's own instruction for the wiring commit,
covering the Inventory-side Action and models too since splitting them
into a second `inventory`-scoped commit would have left one of the two
commits red (PublishEvent and MaterializeEventSeats are tested together
through the Slice 5 feature suite).

Deviations from the plan: none beyond the two pre-existing ErrorCode
gaps fixed above, which were mechanical consequences of phpstan's
exhaustive-match check rather than new scope.

#### Task 06-08: Seated holds and double-booking simulation (2026-07-11)

Landed Slice 6, task breakdown item 10: seat handling inside `CreateHold`,
`ReleaseHold`, `CommitHold`, and the expiry sweeper, plus the seat
double-booking concurrency simulation.

- `seat_ids` on `CreateHoldData` is a flat, top-level list (Endpoints:
  "items as [{ticket_type_id, quantity}], optional seat_ids"), `Optional`
  so an all-GA request omits it entirely. `CreateHold` partitions it
  positionally across the `requires_seat` items in request order (each
  such item's own quantity claims the next slice): a structural check
  (`partitionSeatIds`) validates only that the partition adds up (every
  `requires_seat` item gets exactly `quantity` seat ids, no GA item gets
  any, no duplicates), using nothing but the request and Catalog's
  `requires_seat` flags, before any `event_seats` row is read. This is a
  deliberate, self-authored resolution of the endpoint table's own
  ambiguity between the structural `seat_selection_invalid` (422) failure
  mode and the per-seat `seat_unavailable` (409) one ("wrong zone" is
  explicitly listed under the latter): `seat_selection_invalid` is
  therefore pure arithmetic against the request shape, and
  `seat_unavailable` is everything the per-item conditional UPDATE can
  still refuse (held, sold, blocked, wrong zone, wrong event, or an
  unknown seat id).
- `CreateHold::claimSeats`: one conditional UPDATE per `requires_seat`
  item (`available -> held`, guarded by `event_id`, `status`, and
  `ticket_type_id`, matching the plan's own "one statement per ticket
  type in the selection"), affected-row count compared to the requested
  slice's count, never a read-then-write existence check. A
  `requires_seat` item still runs through the existing GA `claim()`
  counter guard too, in the same transaction, so a seated hold performs
  both the counter UPDATE and the seat UPDATEs and both must succeed or
  roll back together (event_seats section). The offending seat ids for
  `SeatUnavailableException`'s errors extension are read back by
  elimination (which of the requested ids now show `hold_id` = this
  hold), still inside the same transaction, never from stale data.
- `App\Inventory\Actions\Concerns\ReleasesHoldInventory::
  releaseHeldInventory` now also flips this hold's own held seats back to
  `available` (clearing `hold_id`) and returns the freed seat ids, shared
  by `ReleaseHold` and `ReleaseExpiredHolds` exactly as the counter
  decrement already was; `CommitHold` gained the parallel `held -> sold`
  seat flip. All three read this hold's own `hold_id`-scoped seat rows
  before writing them, which the docblocks argue is safe despite the
  read-then-write rule: `hold_id` already made those rows exclusive to
  the single winner of the row's own conditional transition, so no other
  process can contend for them.
- `HoldData::fromModel`, `HoldCreatedPayload::fromHold`,
  `HoldReleasedPayload::fromHold`, and `HoldExpiredPayload::fromHold` all
  gained an explicit `$seatIds` parameter (default `[]`, so every
  existing GA-only caller is unaffected); `Hold` gained an `eventSeats()`
  `HasMany` relation (same-context, unlike the plain-FK cross-context
  columns) so `HoldController::show` and the three Actions can pass real
  seat ids through instead of always shipping the empty array Slice 2
  shipped as a placeholder.
- Two new stable codes: `seat_selection_invalid` (422,
  `SeatSelectionInvalidException`) and `seat_unavailable` (409,
  `SeatUnavailableException implements HasValidationErrors`, `errors.
  seat_ids`). OpenAPI: `POST /v1/storefront/holds`'s 422/409 responses,
  `CreateHoldRequest.seat_ids`, `Hold`'s description, and both
  `HoldCreateUnprocessableProblem`/`HoldCreateConflictProblem` oneOf
  branches extended (no new schemas, matching the existing combined-enum
  precedent from earlier stage-06 tasks). `composer types:generate` run;
  `packages/api-client/src/generated/` regenerated with
  `CreateHoldData.seat_ids` and the two new `ErrorCode` members.
- Test-first per the master plan double loop:
  `tests/Concurrency/SeatDoubleBookingContentionTest.php` (two
  simulations, driven through the real HTTP kernel in forked workers: 8
  workers racing one contested seat, asserting exactly one 201 and every
  seat_unavailable/insufficient_inventory loser fully rolled back with no
  counter drift; four workers each requesting an overlapping pair from a
  4-seat pool, asserting held-seat count equals `2 * successCount` and
  the counter matches the seat table exactly) was written and watched
  fail on the missing `seat_ids` request field before `CreateHold` read
  it. `tests/Unit/Inventory/CreateHoldSeatsTest.php` (per-type seat
  UPDATE affected-row-count checks; a mixed GA-plus-seated hold touching
  both mechanisms atomically; the full `seat_selection_invalid` matrix:
  missing seats, count mismatch, seats on a GA item, duplicates; the
  `seat_unavailable` cases: already-held seat naming the exact offender
  and rolling the counter back, wrong event, unknown id) and feature
  coverage appended to `tests/Feature/Inventory/HoldEndpointsTest.php`
  (seated-hold happy path, mixed hold, the `seat_selection_invalid` and
  `seat_unavailable` matrix through the real endpoint, release and
  expiry returning seats to `available`) were written and watched fail
  on the missing seat classes and fields before they existed.

Test evidence (all from `apps/api`):

- `php artisan test --filter="CreateHoldSeatsTest|SeatDoubleBookingContentionTest|HoldEndpointsTest|CreateHoldTest|ReleaseHoldTest|ReleaseExpiredHoldsTest|MaterializeEventSeatsTest|ErrorCodeTest"`:
  164 passed, 674 assertions.
- `php artisan test --testsuite=Architecture`: 38 passed, 94 assertions.
- `php artisan test --testsuite=Unit,Feature,Isolation,Architecture,Concurrency`
  (combined): 1663 tests, 1662 passed; the one failure
  (`StaffAuthenticationTest`'s `expires_in` assertion, off by one second)
  is a pre-existing clock-drift flake unrelated to this task's scope,
  confirmed green on an isolated rerun.
- `php artisan test --testsuite=Contract`: 255 passed, 1629 assertions
  (proves every new and changed OpenAPI schema, including the extended
  `HoldCreateUnprocessableProblem`/`HoldCreateConflictProblem` oneOf
  branches and `CreateHoldRequest.seat_ids`, matches the implementation).
- `composer analyse`: passed (0 errors).
- `composer lint`: passed.
- `composer types:generate`: run; `packages/api-client/src/generated/`
  regenerated.

Deviations from the plan: the endpoint table's `seat_ids` shape is
stated only loosely ("items as [{ticket_type_id, quantity}], optional
seat_ids"); this task resolves it as a flat, top-level, request-order
positional partition across `requires_seat` items rather than a
per-item nested field, and documents that choice in `CreateHoldData`'s
own docblock and the OpenAPI description, since no prior stage-06 task
committed to either shape.

## Task 06-09: events.manage_seating capability (2026-07-11 07:56 UTC)

Landed the `events.manage_seating` capability registry addition and its
template-role wiring (Task breakdown item 10a), ahead of the admin seats
endpoints task 11 that depends on it.

- `App\Identity\Capability`: added `EventsManageSeating = 'events.manage_seating'`,
  appended after `SeatMapsManage` (the existing `events.*`-prefixed
  precedent, per the plan's Risks / capability naming note).
- `App\Identity\Actions\SeedTemplateRoles::templates`: granted the new
  capability to `Owner` and `Event Manager`, mirroring exactly which
  templates already carry `seat_maps.manage` (the closest existing
  seating-shaped capability), leaving `Box Office`, `Finance`, and
  `Check-in Agent` without it so the authorization matrix proves denial
  for those templates.
- No Data class, controller, or OpenAPI path changed: the capability
  registry is not itself an OpenAPI schema (only referenced in prose in
  existing paths), and `Tests\Feature\Identity\AuthorizationMatrixTest`
  is already data-driven off `Capability::cases()` and
  `SeedTemplateRoles::templates()`, so it exercises every template role
  against the new capability, including denial, with no test-code
  changes required beyond what's listed below.
- Test-first per the master plan double loop:
  `tests/Unit/Identity/CapabilityTest.php`'s registry-lock-down test was
  extended with `'events.manage_seating'` in the expected values list
  (and the new case added to the "not financially privileged" dataset)
  before the enum case existed, watched fail
  (`Pest\Exceptions\DatasetMissing`, since referencing the
  not-yet-defined `Capability::EventsManageSeating` case broke file
  compilation), then the enum case and the template-role wiring were
  added until the assertions passed.

Test evidence (all from `apps/api`):

- `php artisan test --filter="CapabilityTest|SeedTemplateRolesTest|AuthorizationMatrixTest"`:
  91 passed, 525 assertions (includes every `Capability::cases()` x
  template-role combination, home and foreign tenant, for the new
  capability).
- `php artisan test --testsuite=Unit,Feature --filter=Identity`: 400
  passed, 1572 assertions.
- `php artisan test --testsuite=Architecture`: 38 passed, 94 assertions.

No Data class changed, so `composer types:generate` was not run.

Deviations from the plan: none. Template-role assignment (`Owner` and
`Event Manager` only) was not specified by the plan text itself; this
task resolves it by following the existing `seat_maps.manage` grant
pattern, since both capabilities gate seating-adjacent admin operations
and no prior stage-06 task committed to a different split.

## Task 06-10: Seat management and read surfaces (2026-07-11 08:17 UTC)

Landed the three Slice 7 endpoints (task breakdown item 11): the
storefront seat map read, the admin seats list, and the admin PATCH bulk
seat operations.

- `App\EventCatalog\Actions\ResolveSeatsById`: the read-only Catalog seam
  that composes seat metadata (section, row, number) from `SeatData` by
  id, used by both the storefront read and (indirectly, via the same
  `event_seats` rows) the admin read, so seat metadata is never joined
  from Inventory's own queries (system-design 3.1 boundary rule).
- `App\Inventory\Actions\GetStorefrontEventSeats`: reuses
  `ResolveEventForHold` (the same published-event seam `CreateHold` and
  `GetEventAvailability` already depend on) for the event lookup and
  `event_not_found`; throws the new `EventNotSeatedException` when the
  event resolves but its own `event_seats` table has no rows for it,
  the reliable Inventory-only signal for "GA-only" without asking
  Catalog for `seat_map_id` directly. Collapses `held`/`sold` to
  `unavailable` on `StorefrontEventSeatData`, sorts by
  (section, row, number) in PHP since ordering by Catalog's own columns
  would require a join.
- `App\Inventory\Actions\UpdateEventSeats`: every operation in a PATCH
  batch runs inside one transaction as its own conditional UPDATE
  (`block`: `available -> blocked`; `unblock`: `blocked -> available`;
  `assign_ticket_type`: guarded on `available`, rezones or unzones),
  checked by affected-row count; a `lockForUpdate()` read precedes each
  guarded UPDATE only to capture the seat's prior `ticket_type_id` for
  the paired `AdjustInventoryQuantity` counter call (mirroring
  `SetTicketTypeQuantity`'s own `lockForUpdate` precedent), never as the
  eligibility guard itself. Every operation still runs even after one
  fails, so `SeatNotModifiableException` reports every offending
  `event_seat_id` together; the whole transaction, including any counter
  adjustments already applied earlier in the batch, rolls back if the
  offending list is non-empty. `assign_ticket_type` also rejects a
  `ticket_type_id` that does not belong to the same event or is not
  `requires_seat`, folded into the same offending-seat outcome rather
  than a separate 422, since it is still a per-seat guard failure.
- `App\Inventory\Http\Controllers\StorefrontEventSeatController` and
  `EventSeatController` (admin), the latter gated by
  `events.manage_seating` (task 06-09) via the existing
  `RequireCapability` middleware pattern, mirroring
  `TicketTypeInventoryController`'s own `events.view`-gated precedent.
  The admin list uses `QueryBuilder` with an explicit
  `filter[status]`/`filter[ticket_type_id]` allowlist and page
  pagination, mirroring `EventController::index`.
- Two new stable codes: `event_not_seated` (409,
  `EventNotSeatedException`) and `seat_not_modifiable` (409,
  `SeatNotModifiableException implements HasValidationErrors`,
  `errors.event_seat_ids`). New Data objects:
  `StorefrontEventSeatData`/`StorefrontEventSeatMapData`,
  `EventSeatData`/`EventSeatBatchData` (the admin PATCH response, see
  deviation below), `UpdateEventSeatOperationData`/`UpdateEventSeatsData`.
  OpenAPI: the three new paths plus
  `StorefrontEventSeat(Map)`, `EventSeat`, `EventSeatPage`,
  `EventSeatBatch`, `UpdateEventSeatOperation`/`UpdateEventSeatsRequest`,
  and `EventNotSeatedProblem`/`EventSeatForbiddenProblem`/
  `SeatNotModifiableProblem`. `composer types:generate` run;
  `packages/api-client/src/generated/` regenerated.
- Test-first per the master plan double loop:
  `tests/Feature/Inventory/EventSeatEndpointsTest.php` (storefront read
  including the `unavailable` collapse, `event_not_found`,
  `event_not_seated`; admin list including the filter allowlist
  rejection, capability denial, cross-tenant `event_not_found`; PATCH
  operations matrix including the counter-adjustment happy path,
  all-or-nothing rollback with the offending `event_seat_ids` listed,
  unknown-op 422, capability denial, cross-tenant `event_not_found`) and
  `tests/Unit/Inventory/UpdateEventSeatsTest.php` (block/unblock/rezone/
  unzone counter arithmetic, "blocking a held seat affects zero rows",
  rejecting a non-`requires_seat` or foreign-event ticket type) were
  written and watched fail on the missing routes, Actions, and
  exceptions before they existed.

Test evidence (all from `apps/api`):

- `php artisan test --filter="EventSeatEndpointsTest|UpdateEventSeatsTest"`:
  20 passed, 76 assertions.
- `php artisan test --testsuite=Architecture`: 38 passed, 94 assertions
  (after adding the two new exceptions to `PresetTest`'s ignore list,
  the same treatment every other `HasErrorCode` exception in
  `App\Inventory\Exceptions` already has).
- `php artisan test --testsuite=Contract`: 269 passed, 1719 assertions
  (after registering exercisers for all three endpoints' documented
  responses, including a new `contractSeatedEvent` fixture, and
  extending the suite's own tenant-teardown ordering for `event_seats`).
- `php artisan test --testsuite=Unit,Feature,Isolation,Architecture --filter="Inventory|EventCatalog|Identity"`:
  974 passed, 3676 assertions.
- `vendor/bin/phpstan analyse app/Inventory app/EventCatalog/Actions/ResolveSeatsById.php`
  (isolated run, `--memory-limit=1G`; the default 128M crashes on this
  machine regardless of this task's changes): 0 errors, after fixing one
  genuine `nullsafe.neverNull` finding in `EventSeatController::eventIdOrFail`.
- `vendor/bin/pint` (targeted paths): fixed import ordering in two new
  test files; clean on rerun.
- `composer types:generate`: run; `packages/api-client/src/generated/`
  regenerated.

Deviations from the plan: two, both self-authored resolutions of shapes
the endpoint table states only loosely, following the precedent task
06-08 already set for `CreateHoldData.seat_ids`:
1. The PATCH request's polymorphic `op` shape (`block`, `unblock`, or
   `{assign_ticket_type: uuid|null}`) is flattened to two fields on
   `UpdateEventSeatOperationData`, `op` (a string enum) plus
   `ticket_type_id` (used only when `op` is `assign_ticket_type`,
   ignored otherwise), since a nested discriminated union has no direct
   laravel-data representation.
2. The PATCH response, unspecified by the endpoint table beyond
   "`EventSeatData[]`" prose, is object-wrapped as `EventSeatBatchData
   { seats: EventSeatData[] }` rather than shipped as a bare JSON array:
   `tests/Contract/ResponseSchemaStrictnessTest.php` (ADR 019) requires
   every documented response schema to declare
   `additionalProperties`/`unevaluatedProperties: false`, which a
   top-level `type: array` schema cannot express, so a bare-array
   response would be permanently unable to satisfy that gate.

#### Task 06-11: Exit sweep and status flip (2026-07-11)

Verified every exit criterion (plan task 12, exit criteria 1-13) against
the tree as landed by tasks 06-01 through 06-10; no new production code
was needed beyond committing two files that task 06-10 had left
unstaged (`ProblemRenderer::detailFor` and `ErrorCodeTest`'s
`event_not_seated`/`seat_not_modifiable` cases, both already exercised
by `EventSeatEndpointsTest` and `ErrorCodeTest` but only present in the
working tree, not in commit 96a959e). Flipped the Stage 6 row in
`docs/api-implementation-plan.md`'s status table to Done. Confirmed the
OpenAPI document is a single merged file with no external `$ref`s
(`grep -c '\$ref.*\.yaml' docs/openapi/openapi.yaml` is 0) and that its
structural validity and route/response coverage are already asserted
by `tests/Contract/OpenApiDocumentValidityTest.php`,
`DocumentedResponseCoverageTest.php`, and `RouteSpecDriftTest.php`,
which ran clean as part of the Contract suite below; no separate
consolidation tool exists in this repo, so the check is this suite plus
the `$ref` grep.

Test evidence (all from `apps/api`):

- `composer lint`: Pint, passed, 0 issues.
- `composer analyse`: Larastan, passed, 0 errors.
- `composer test` (all six suites: Unit, Feature, Isolation,
  Concurrency, Architecture, Contract): 1961 passed, 7788 assertions.
- `php artisan test --testsuite=Concurrency`: 25 passed, 127 assertions
  (GA oversell at every configured parallelism/oversubscription level,
  seat double-booking, commit-versus-expiry race, all green; exit
  criteria 1-3).
- `php artisan test --testsuite=Isolation`: 208 passed, 445 assertions
  (cross-tenant probes for `ticket_type_inventory`, `holds`,
  `hold_items`, and `event_seats` all present and green; exit
  criterion 8).
- `php artisan test --testsuite=Architecture`: 38 passed, 94 assertions
  (Inventory imports no other context's models, Catalog reaches
  Inventory only through Actions; exit criterion 9).
- `composer types:generate`: run; `git status` on
  `packages/api-client/src/generated/` is clean, confirming no drift
  (exit criterion 12's TypeScript gate).

Exit criteria 4-7, 10, and 11 (expiry recovery exactness, conversion-time
and extension guards, failure-mode/code coverage via the Contract
suite, outbox recording proofs, and seat materialization/zoning
correctness) are covered by the feature and unit tests landed in tasks
06-03 through 06-10 and re-verified green by this run's full `composer
test` pass; no gaps found.

Commit: 96a959e (task 06-10, prior) plus this task's own commit
completing the exit sweep (status flip, journal, and the two files
task 06-10 left uncommitted). No production behavior changed beyond
committing the already-implemented and already-tested
`ErrorCode::EventNotSeated`/`SeatNotModifiable` problem-detail wiring.

Deviation: none from the plan's method. The one deviation from a clean
handoff is procedural, not substantive: task 06-10's commit omitted two
files it had already written and tested; this task committed them
rather than re-doing the work, since the tests that depend on them were
already green in the working tree and the omission would otherwise
regress `composer test` for anyone starting from a clean checkout of
commit 96a959e.

## Gate

Sat Jul 11 05:51:08 -03 2026

Full quality gates re-run after Stage 6 implementation work, all green:

- `composer -d apps/api run lint` (Pint): passed
- `composer -d apps/api run analyse` (PHPStan/Larastan): passed, 0 errors
- `composer -d apps/api run test` (Pest): 1961 passed, 7788 assertions
- `composer -d apps/api run types:generate` + `git status --short packages/api-client/src/generated`: no contract drift
- `pnpm typecheck`: skipped (no TypeScript changed)

## Review round 1

2026-07-11 05:56:56 -03

Codex review of the stage-06 diff surfaced two important findings; both
were fixed test-first.

1. important, apps/api/app/Inventory/Actions/Concerns/ReleasesHoldInventory.php:78
   The guarded `held` decrement ignored its affected-row count, so a
   missing or under-count counter row still let the hold transition and
   its HoldReleased/HoldExpired event record, leaving inventory
   permanently inconsistent (violates the guarded-transition
   affected-row-check convention).
   Fixed: `releaseHeldQuantity` now captures the affected-row count and
   throws a new App\Inventory\Exceptions\HoldInventoryReleaseFailedException
   (HasErrorCode -> server.internal_error, 500) when it is zero, so the
   surrounding release or expiry transaction rolls back instead of
   recording an event against broken inventory. Added a failing-first
   test to tests/Unit/Inventory/ReleaseHoldTest.php ("rolls back and
   records no HoldReleased when the counter holds fewer units than the
   hold").

2. important, apps/api/app/EventCatalog/Actions/UpdateTicketType.php:83
   Changing an existing GA ticket type to requires_seat=true left its GA
   inventory counter (and its absolute quantity) intact; because
   MaterializeEventSeats only seeds a zero counter when none exists, the
   converted seated type kept its stale GA quantity and reported wrong
   availability.
   Fixed: UpdateTicketType now captures the pre-update requires_seat
   value and, on a GA -> seated transition, calls SetTicketTypeQuantity
   with 0 to reset the counter, matching MaterializeEventSeats' zero
   seed; the conditional decrease also guards against zeroing out already
   sold or held units. Added a failing-first test to
   tests/Unit/EventCatalog/UpdateTicketTypeTest.php ("resets the counter
   quantity to zero when converting a GA ticket type to requires_seat").

Verification: `php artisan test` for the touched Unit/Feature/Concurrency
files (ReleaseHoldTest, ReleaseExpiredHoldsTest, UpdateTicketTypeTest,
MaterializeEventSeatsTest, TicketTypeEndpointsTest,
HoldExpiryRecoveryContentionTest) all green; `composer -d apps/api run
lint` passed. No findings declined.

## Review round 2 (Sat Jul 11 06:02:29 -03 2026)

Codex stage-06 round 2 raised two important findings; both fixed.

1. important, apps/api/app/EventCatalog/Actions/CreateTicketType.php:46
   Creating a requires_seat ticket type on an already-published event
   skipped inventory initialization (MaterializeEventSeats only seeds
   counters at publish time), so the new seated type had no
   ticket_type_inventory row: zoning could not adjust its quantity and
   availability/holds treated it as permanently unavailable.
   Fixed: CreateTicketType now injects InitializeTicketTypeInventory and,
   for a seated type added to a Published event, seeds a zero-quantity
   counter row (mirroring MaterializeEventSeats' seed) for zoning to
   adjust. Draft events still defer to publish-time materialization.
   Added a failing-first test to
   tests/Unit/EventCatalog/CreateTicketTypeTest.php ("seeds a
   zero-quantity counter row for a requires_seat ticket type added to a
   published event").

2. important, apps/api/app/Inventory/Actions/Concerns/ReleasesHoldInventory.php:48
   (and apps/api/app/Inventory/Actions/CommitHold.php:76-90)
   The guarded held->available (release/expiry) and held->sold (commit)
   seat UPDATEs ignored the affected-row count, so a partial or missing
   seat transition would be treated as successful while the
   release/commit events and counter changes still proceeded, violating
   the convention that conditional UPDATEs are checked by affected-row
   count.
   Fixed: both seat flips now capture the affected-row count and throw
   when it does not equal the number of seat ids selected under the held
   guard (releaseHeldSeats throws HoldInventoryReleaseFailedException via
   a new forSeats factory; commitSeats throws HoldNotCommittableException),
   rolling the surrounding transaction back rather than proceeding on an
   inconsistent seat set. This is a defensive guard on a race-only path
   (seats are scoped by hold_id, exclusive to the single winner of the
   hold's own conditional transition), so no new dedicated failing test
   was added; the existing release/commit unit and concurrency suites
   still cover the happy path and stay green.

Verification: `php artisan test --filter='Inventory|TicketType'` (234
tests) green, plus the targeted
CreateTicketType|CommitHold|ReleaseHold|ReleaseExpiredHolds filter (27
tests) green; `composer -d apps/api run lint` and `composer -d apps/api
run analyse` both passed. No findings declined.
