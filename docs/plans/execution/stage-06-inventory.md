# Stage 6 Execution Journal: Inventory and Reserved Seating

## Run: 2026-07-11

- Stage: 6 (docs/plans/stage-06-inventory.md)
- Date: 2026-07-11
- Branch: feat/api-implementation
- Base commit: ffa8ae3d4d87321b9d497c370d170fe6a16c86ff

### Task checklist

- [x] 06-01 Counters: ticket_type_inventory migration, model, InitializeTicketTypeInventory and AdjustInventoryQuantity Actions, isolation probe (plan slice 1, task 2)
- [ ] 06-02 Catalog quantity contract: additive quantity on ticket type Data objects, delegation to Inventory Actions, requires_seat rejection (plan task 3)
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
