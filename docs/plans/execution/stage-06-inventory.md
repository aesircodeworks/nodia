# Stage 6 Execution Journal: Inventory and Reserved Seating

## Run: 2026-07-11

- Stage: 6 (docs/plans/stage-06-inventory.md)
- Date: 2026-07-11
- Branch: feat/api-implementation
- Base commit: ffa8ae3d4d87321b9d497c370d170fe6a16c86ff

### Task checklist

- [x] 06-01 Counters: ticket_type_inventory migration, model, InitializeTicketTypeInventory and AdjustInventoryQuantity Actions, isolation probe (plan slice 1, task 2)
- [x] 06-02 Catalog quantity contract: additive quantity on ticket type Data objects, delegation to Inventory Actions, requires_seat rejection (plan task 3)
- [ ] 06-03 GA hold creation: holds and hold_items migrations, HoldStatus, CreateHold, HoldCreated, POST and GET endpoints, GA oversell simulation green (plan slice 2, task 4)
- [ ] 06-04 Release and expiry: ReleaseHold, DELETE endpoint, HoldReleased, ReleaseExpiredHolds sweeper, HoldExpired, expiry recovery simulation green (plan slice 3, tasks 5 and 6)
- [ ] 06-05 Availability reads: storefront availability endpoint and admin inventory read with contracts (plan slice 3, task 7)
- [ ] 06-06 ExtendHold and CommitHold internal Actions with commit-versus-expiry race (plan slice 4, task 8)
- [ ] 06-07 Seat materialization on publish: event_seats migration, MaterializeEventSeats, requires_seat publish validation, seat_map_in_use extension (plan slice 5, task 9)
- [ ] 06-08 Seated holds through CreateHold, ReleaseHold, CommitHold, sweeper; double-booking simulation green (plan slice 6, task 10)
- [ ] 06-09 events.manage_seating capability registry addition and role wiring (plan task 10a)
- [ ] 06-10 Seat management and read surfaces: storefront seats, admin seats list, admin PATCH, contracts (plan slice 7, task 11)
- [ ] 06-11 Exit sweep: status table flip to done, OpenAPI consolidation check, full gate run (plan task 12, exit criteria)

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
