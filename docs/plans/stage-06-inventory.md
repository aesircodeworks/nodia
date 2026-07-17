# Stage 6: Inventory and Reserved Seating

Implementation plan for the Inventory bounded context: the oversell-proof core described in [system-design.md](../system-design.md) section 6, driven test-first by the Concurrency suite as required by the master plan ([api-implementation-plan.md](../api-implementation-plan.md), Stage 6). Every rule in [api-conventions.md](../api-conventions.md), [data-conventions.md](../data-conventions.md), and [event-conventions.md](../event-conventions.md) is binding here; this document only makes them concrete for this stage.

The stage-defining constraint, from the master plan: this stage inverts the usual TDD order fully. The parallel oversell and double-booking simulations are written and failing before any table exists, and all TTL behavior is driven through the fake clock delivered in Stage 1.

## Scope and non-goals

### Delivered by this stage

- `ticket_type_inventory` counter rows: the narrow hot table from system-design 6.3, with `quantity`, `sold`, `held`, initialized when ticket types get a quantity and adjusted only through conditional UPDATEs.
- Holds: `holds` and `hold_items`, with create, extend, release, and commit implemented as atomic conditional UPDATEs checked by affected-row count (system-design 6.1). Default TTL 10 minutes.
- Expiry enforcement twice over, per system-design 6.1 and 13: the `ReleaseExpiredHolds` scheduled sweeper, plus conversion-time validation so an expired hold is unusable even when the sweeper lags.
- Reserved seating: `event_seats` materialized on publish (system-design 6.2), one row per sellable seat per event, unique on `(event_id, seat_id)`, statuses `available`, `held`, `sold`, `blocked` moved only by conditional transitions. Per-event seat blocking and ticket-type zoning without touching venue templates. Seats materialize unzoned (Stage 5b templates carry no zoning), so admin zoning is a required post-publish, pre-on-sale step.
- The Inventory Actions other contexts consume: `CreateHold`, `ExtendHold`, `ReleaseHold`, `CommitHold`, `MaterializeEventSeats` (system-design 3.2), plus counter initialization and quantity adjustment.
- Wiring `MaterializeEventSeats` into the Catalog publish Action, completing the seated-event publish path Stage 5 left open (master plan, Stage 5 exit note), including the three Stage 5b deferrals: the publish-blocking validation for a `requires_seat` ticket type without a `seat_map_id`, the restricting foreign key from `event_seats.seat_id` to `seats.id`, and extending the `catalog.seat_map_in_use` delete conflict to materialized maps.
- Storefront hold endpoints, storefront availability read endpoints (database-backed, authoritative), and admin seat management endpoints, each with laravel-data contracts, OpenAPI paths, and generated TypeScript.
- Inventory domain events `HoldCreated`, `HoldExpired`, `HoldReleased` recorded to the Stage 4 outbox (system-design 9.3).

### Non-goals, deferred

- Converting a hold into an order, and releasing holds on order `expired`, `failed`, or `canceled`: Stage 7 (Orders calls `CommitHold` and `ReleaseHold`; this stage ships and tests the Actions).
- Promo codes and any pricing or money math at hold time: Stage 7. Holds reserve quantity and seats only; no `*_amount` columns exist in this stage's tables.
- Payment-window hold extension policy (Pix, boleto windows): Stage 8a calls `ExtendHold`; this stage ships the Action and its guard.
- Waiting-room admission tokens on the hold endpoint, per-customer purchase limits, hold-creation rate-limit tiers, and Redis-cached availability reads: Stage 10 (system-design 10). This stage's availability endpoints read PostgreSQL directly; that is correct pre-on-sale and Stage 10 layers the cache in front without changing the source of truth.
- Seat map template CRUD (`seat_maps`, `seats`): Stage 5b owns the templates; this stage only references `seat_id` values from them.
- Reporting on hold funnel metrics: Stage 11 consumes the hold events.

## Dependencies

### Required before this stage starts

- Stage 1: problem+json handler with the error code registry, `Support/Money` (not used in this stage's tables but required by the shared test base), the Contract suite and OpenAPI conformance gate, real Isolation and Concurrency harnesses against real PostgreSQL, and time control for TTLs. The fake clock and the parallel process runner are hard prerequisites; the stage cannot begin without them.
- Stage 2: tenant resolution middleware (`Host` for storefront, `X-Tenant-Id` for admin), the `SET LOCAL app.tenant_id` transaction wrapper, and the per-table RLS policy pattern.
- Stage 3: staff authorization (capability checks gate the admin seat management endpoints) and customer authentication (holds carry an optional `customer_id`, derived from a customer bearer token when one is presented, never from the request body).
- Stage 4: outbox recording API, dispatcher, `outbox_deliveries`, so `HoldCreated`, `HoldExpired`, `HoldReleased` are recorded in the producing transaction from day one.
- Stage 5a: `events` and `ticket_types` (with `requires_seat` and sales windows) and the publish Action this stage extends. Stage 5b: `seat_maps` and `seats` templates that materialization reads.

### Consumed by later stages

- Stage 7: `CommitHold` (inside the order-paid transaction, system-design 7.1) and `ReleaseHold` (on order abandonment and terminal failure states).
- Stage 8a: `ExtendHold` when payment initiation opens an async window (system-design 7.4); the low-inventory slow-method cutoff reads availability through an Inventory Action.
- Stage 10: wraps `CreateHold` with admission-token and purchase-limit checks and fronts the availability reads with Redis.
- Stage 11: consumes the hold lifecycle events for funnel projections.

## Data model

All four tables are tenant-scoped: non-null `tenant_id` even where derivable (system-design 4.2, data-conventions Tenancy), and each table's migration creates its single-table RLS policy comparing `tenant_id` to `current_setting('app.tenant_id')` in the same migration, or the isolation suite blocks the merge. Primary keys are `id` UUIDv7 via `HasUuids` (data-conventions Tables and Keys); the system-design 8.2 ERD draws `ticket_type_inventory` keyed by `ticket_type_id` alone, but the data conventions' UUID `id` rule governs, so the table gets `id` plus a unique `ticket_type_id`. Standard `created_at` and `updated_at` throughout. No monetary columns exist in this stage; prices stay on `ticket_types` (Stage 5a) and money enters at order time (Stage 7).

### ticket_type_inventory

The narrow counter table from system-design 6.3, kept free of metadata so hot updates do not contend with catalog reads.

| Column         | Type    | Notes                     |
| -------------- | ------- | ------------------------- |
| id             | uuid    | PK, UUIDv7                |
| tenant_id      | uuid    | non-null, RLS             |
| ticket_type_id | uuid    | non-null, unique          |
| quantity       | integer | non-null, >= 0            |
| sold           | integer | non-null, default 0, >= 0 |
| held           | integer | non-null, default 0, >= 0 |

Constraints: `unique (ticket_type_id)`; CHECK constraints `quantity >= 0`, `sold >= 0`, `held >= 0`, and `sold + held <= quantity` (custom name `ticket_type_inventory_no_oversell_idx` pattern per data-conventions applies to indexes; the check gets a descriptive name the builder supports). The conditional UPDATE is the concurrency guard; the CHECK is defense in depth that turns any future guard bug into a statement error instead of an oversell.

Indexes: the unique on `ticket_type_id` is also the lookup path; no further index needed.

The invariant `sold + held <= quantity` is system-design 6's core invariant, enforced by evaluating the guard in the same statement that mutates the row:

```sql
UPDATE ticket_type_inventory SET held = held + :n
WHERE ticket_type_id = :id AND sold + held + :n <= quantity
```

Zero affected rows means insufficient inventory; the transaction rolls back and the API returns the sold-out problem document. Quantity decreases use the same pattern (`quantity >= sold + held` after the change); increases are unconditional.

### holds

| Column      | Type        | Notes                                                                       |
| ----------- | ----------- | --------------------------------------------------------------------------- |
| id          | uuid        | PK, UUIDv7                                                                  |
| tenant_id   | uuid        | non-null, RLS                                                               |
| event_id    | uuid        | non-null                                                                    |
| customer_id | uuid        | nullable by design; Stage 7 attaches the customer at conversion (see risks) |
| status      | string      | enum-backed: `active`, `released`, `expired`, `committed`                   |
| expires_at  | timestamptz | non-null, UTC                                                               |

Indexes: `(tenant_id, status, expires_at)` for the sweeper scan; `event_id`; `customer_id` (Stage 10's per-customer limits will need it, cheap to ship now).

Status transitions, every one a conditional UPDATE checked by affected-row count:

- `active -> released` (buyer abandons, or Orders releases on terminal order states): guard `status = 'active'`.
- `active -> expired` (sweeper): guard `status = 'active' AND expires_at <= now`.
- `active -> committed` (Orders, on paid): guard `status = 'active' AND expires_at > now`. The `expires_at` check in the statement is the conversion-time validation: an expired-but-unswept hold cannot commit.
- Extension is not a status change: `UPDATE holds SET expires_at = :new WHERE id = :id AND status = 'active' AND expires_at > now() AND :new > expires_at`, so extension never resurrects an expired hold and never shortens one.

Counter and seat effects always ride in the same transaction as the hold-row transition: release and expiry decrement `held` per item and flip held seats back to `available`; commit moves `held` to `sold` per item and flips seats `held -> sold`.

### hold_items

| Column         | Type    | Notes                                     |
| -------------- | ------- | ----------------------------------------- |
| id             | uuid    | PK, UUIDv7                                |
| tenant_id      | uuid    | non-null, RLS (derivable, still required) |
| hold_id        | uuid    | non-null, FK                              |
| ticket_type_id | uuid    | non-null                                  |
| quantity       | integer | non-null, > 0                             |

Constraints: `unique (hold_id, ticket_type_id)`; CHECK `quantity > 0`. Index on `ticket_type_id`.

### event_seats

Materialized per event on publish (system-design 6.2). The unique constraint plus conditional transitions make double-booking structurally impossible.

| Column         | Type   | Notes                                                                                                                                                                           |
| -------------- | ------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| id             | uuid   | PK, UUIDv7                                                                                                                                                                      |
| tenant_id      | uuid   | non-null, RLS                                                                                                                                                                   |
| event_id       | uuid   | non-null                                                                                                                                                                        |
| seat_id        | uuid   | non-null, FK `seats.id` on delete restrict (the Stage 5b deferral: deleting a materialized template now fails at the database and Catalog maps it to `catalog.seat_map_in_use`) |
| ticket_type_id | uuid   | nullable (a blocked or unzoned seat has no type; all seats materialize unzoned)                                                                                                 |
| status         | string | enum-backed: `available`, `held`, `sold`, `blocked`                                                                                                                             |
| hold_id        | uuid   | nullable, FK `holds.id`, set while `held`                                                                                                                                       |

Constraints: `unique (event_id, seat_id)`. Indexes: `(event_id, status)` for map rendering and counts; `hold_id` for release and expiry; `(event_id, ticket_type_id)` for zone counts.

Transitions, each a single conditional UPDATE over the selected seat IDs with affected-row count compared to the requested count, rolling back on mismatch exactly as in the system-design 6.1 sequence diagram:

- `available -> held` (hold creation): `... SET status = 'held', hold_id = :hold WHERE id IN (...) AND event_id = :event AND status = 'available' AND ticket_type_id = :type`, one statement per ticket type in the selection.
- `held -> available` (release, expiry): guard `status = 'held' AND hold_id = :hold`, clearing `hold_id`.
- `held -> sold` (commit): guard `status = 'held' AND hold_id = :hold`.
- `available <-> blocked` (admin): guards `status = 'available'` and `status = 'blocked'` respectively; held or sold seats are never blockable.
- Zoning change (admin): `ticket_type_id` reassignment guarded by `status = 'available'`.

Nothing upstream carries zoning before publish: Stage 5b templates hold only section, row, number, and coordinates, so materialization creates every seat unzoned (`ticket_type_id` null, status `available`) and initializes each `requires_seat` ticket type's counter `quantity` to 0. The admin PATCH zoning operation is the only mechanism that assigns seats to types, and it is therefore a required post-publish, pre-on-sale step: an unzoned seated event legitimately reports zero availability. Seated ticket types keep their counter row in sync from then on: each zoning assignment increments the target type's `quantity` (and decrements the previous type's, when rezoning), and blocking adjusts the affected counter, all with the same conditional guard, in the same transaction as the seat UPDATE. A seated hold therefore performs both the counter UPDATE and the seat UPDATEs, as the 6.1 diagram shows, and both must succeed or the transaction rolls back.

### Migration order

Four migrations, in dependency order: `ticket_type_inventory`, `holds`, `hold_items`, `event_seats`. Each creates its RLS policy in the same file. Merged migrations are never edited; any later change is a new migration (data-conventions Migrations).

## Domain events

Produced by this stage, all three already in the system-design 9.3 registry, so no registry change is needed. Envelope per event-conventions: `id` (UUIDv7), `sequence`, `type`, non-null `tenant_id`, `aggregate_type` `hold` with `aggregate_id` the hold ID, `correlation_id` from the originating request (a synthetic correlation ID for the sweeper's scheduled runs), `occurred_at` UTC, and a laravel-data payload with snake_case keys carrying identifiers and facts, not snapshots.

| Event        | Recorded when                                   | Payload                                                                                                              |
| ------------ | ----------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| HoldCreated  | `CreateHold` commits                            | `hold_id`, `event_id`, `customer_id` (nullable), `expires_at`, `items` as `[{ticket_type_id, quantity}]`, `seat_ids` |
| HoldReleased | Explicit release (buyer or Orders) commits      | `hold_id`, `event_id`, `items`, `seat_ids`                                                                           |
| HoldExpired  | Sweeper wins the `active -> expired` transition | `hold_id`, `event_id`, `items`, `seat_ids`                                                                           |

Recording happens in the same transaction as the state change, without exception (event-conventions Envelope). Exactly-once recording falls out of the conditional transition: only the request or sweeper run whose UPDATE affected a row records the event, so a lagging sweeper racing an explicit release produces exactly one of `HoldExpired` or `HoldReleased`, never both. Payload evolution is additive only.

There is no `HoldCommitted` event: commit happens inside Stage 7's order-paid transition, and the order events (`OrderCreated`, `TicketIssued`) are the facts consumers care about. Adding one later is an additive registry change if Reporting needs it.

Consumed: none. `MaterializeEventSeats` is invoked synchronously by the Catalog publish Action inside the publish transaction, not via the outbox, because materialization must be atomic with the status flip to `published` (master plan Stage 5 exit note). Inventory therefore ships no outbox consumers and no `outbox_deliveries` subscribers in this stage; the first duplicate-delivery idempotence obligations for Inventory events fall on their Stage 11 consumers.

## Endpoints

All routes under `/v1`, snake_case JSON, laravel-data request and response objects as the single source of truth, TypeScript regenerated via `composer types:generate`, and an OpenAPI path merged with each endpoint (api-conventions). Errors are RFC 9457 problem documents; the `code` values below enter the stable registry. Storefront endpoints live under the `/v1/storefront` prefix, the routing seam Stage 5a established between the two tenant-resolution modes, and resolve the tenant from `Host`; admin endpoints require a staff bearer token plus validated `X-Tenant-Id`. Each surface gets its own OpenAPI path and its own Data objects, so the storefront and admin seat reads never share a route or a response shape.

### Storefront

**POST /v1/storefront/holds** creates a hold. Request `CreateHoldData`: `event_id`, `items` as `[{ticket_type_id, quantity}]`, optional `seat_ids`. Response 201 `HoldData`: `id`, `event_id`, `status`, `expires_at`, `items` (`HoldItemData[]`), `seat_ids`. Guest checkout means no authentication is required; the tenant comes from the host and UUIDv7 hold IDs are the capability (system-design 14.4). `customer_id` is never accepted in the request body: a client-supplied identity assertion is not trusted alone (api-conventions). It is derived from an optional customer bearer token (Stage 3 customer auth, validated against the host-resolved tenant) and stays null for anonymous guests.

Failure modes and codes:

| Condition                                                                          | Status | code                                            |
| ---------------------------------------------------------------------------------- | ------ | ----------------------------------------------- |
| Malformed body, zero or negative quantity, empty items                             | 422    | `request.validation_failed` (with `errors` map) |
| Event not published or not found for tenant                                        | 404    | `event_not_found`                               |
| Ticket type not in event                                                           | 422    | `ticket_type_not_in_event`                      |
| Outside the ticket type's sales window                                             | 409    | `sales_window_closed`                           |
| Counter guard fails (sold out at requested quantity)                               | 409    | `insufficient_inventory`                        |
| Seated type without seats, seat count not matching quantity, or seats on a GA type | 422    | `seat_selection_invalid`                        |
| Seat not available (held, sold, blocked, wrong zone, wrong event)                  | 409    | `seat_unavailable`                              |

`insufficient_inventory` and `seat_unavailable` responses identify the failing `ticket_type_id` or `seat_ids` in an extension member so the storefront can react per line.

**GET /v1/storefront/holds/{hold}** returns `HoldData` for the countdown display. 404 `hold_not_found` for unknown or cross-tenant IDs (RLS makes cross-tenant a natural 404).

**DELETE /v1/storefront/holds/{hold}** releases a hold. 204 on success and on repeated release of an already-released hold (idempotent DELETE); 409 `hold_not_releasable` when the hold is `committed`; 404 `hold_not_found` otherwise. Expired holds release as a no-op 204 (the sweeper or the release itself reconciles counters exactly once, guarded by the conditional transition).

**GET /v1/storefront/events/{event}/availability** returns `EventAvailabilityData`: per ticket type `{ticket_type_id, available, on_sale}` where `available = quantity - sold - held`. Published events only; 404 `event_not_found` otherwise. Database-backed and authoritative; Stage 10 fronts it with Redis without contract change.

**GET /v1/storefront/events/{event}/seats** returns `StorefrontEventSeatMapData` for seated events: per seat `{event_seat_id, seat_id, section, row, number, ticket_type_id, status}` with `held` and `sold` collapsed to `unavailable` on the storefront wire (buyers never need the distinction, and it avoids leaking sales pace). Seat metadata (section, row, number) is composed by calling a Catalog Action returning seat Data objects, never by joining Catalog's tables (system-design 3.1 boundary rule). 404 `event_not_found`; 409 `event_not_seated` for GA-only events.

Extension (`ExtendHold`) and commit (`CommitHold`) are internal Actions with no HTTP surface in this stage; Payments and Orders call them in-process.

### Admin

**GET /v1/events/{event}/seats** (staff token plus `X-Tenant-Id`, capability `events.manage_seating`) returns `EventSeatData[]` with full statuses including `held`, `sold`, `blocked`, and `hold_id`, via query-builder with explicit allowlists (`filter[status]`, `filter[ticket_type_id]`). The storefront seat read lives at a different path with its own Data object, so the two auth modes and response shapes never share a route.

**PATCH /v1/events/{event}/seats** applies bulk seat operations. Request `UpdateEventSeatsData`: `operations` as `[{event_seat_id, op}]` where `op` is `block`, `unblock`, or `{assign_ticket_type: uuid|null}`. All operations apply in one transaction; each is a conditional UPDATE, and any guard failure rolls back the whole batch with 409 `seat_not_modifiable` listing the offending `event_seat_ids`. Counter `quantity` adjustments for affected zones ride in the same transaction. 403 problem document when the capability is missing; 422 `request.validation_failed` for unknown ops.

Admin availability for GA (`GET /v1/ticket-types/{ticket_type}/inventory` returning `TicketTypeInventoryData` with `quantity`, `sold`, `held`) rounds out the surface so a tenant can see counters without database access.

## TDD sequencing

Per the master plan, the Concurrency suite leads. Slices are ordered; each follows the double loop (feature test, contract, unit tests, green, regenerate, commit) with scope `inventory` (`catalog` for the publish-Action wiring commit).

### Slice 0: failing simulations and probes (no production code)

Written first, all failing because nothing exists:

- Concurrency: GA oversell simulation. N parallel processes each attempt to hold k of a ticket type with quantity Q where N*k > Q; assert `sold + held <= quantity` afterward and that exactly floor(Q/k) holds succeeded. Parameterized over exact-fit, 2x, and 10x oversubscription and over mixed create-and-release interleaving.
- Concurrency: seat double-booking simulation. N parallel processes race for the same seat and for overlapping seat sets; assert at most one active hold per seat and full rollback of losers (no partial seat sets).
- Concurrency: expiry recovery simulation. Holds created, clock advanced past TTL, sweeper run concurrently with new hold creation; assert availability returns exactly to `quantity - sold` and late conversions fail.
- Isolation: probes for all four tables using the two-tenant fixture, asserting cross-tenant SELECT and UPDATE affect zero rows under RLS.

The Stage 1 harness deliberately has no pending or skip mechanism (its exit criterion is that a failing probe turns CI red and blocks merge), so these cannot land red on the mainline. They are written first, before any migration exists, and each merges in the same PR as the slice that turns it green.

### Slice 1: counters

- Feature: none yet (no endpoint).
- Unit (first): `InitializeTicketTypeInventory` creates the row with the given quantity; `AdjustInventoryQuantity` increase always succeeds, decrease below `sold + held` affects zero rows and throws the typed domain exception; direct guard tests for the `held` increment statement returning affected-row counts.
- Isolation (first): the `ticket_type_inventory` probe from slice 0 goes green.
- Implementation: migration with RLS and CHECK constraints, model, the two Actions, wiring from the Catalog ticket type create and update Actions (Catalog passes quantity through a Data object as an additive change to the Stage 5a contract; see task 3).

### Slice 2: GA hold creation

- Concurrency (first): the GA oversell simulation from slice 0 must go green here.
- Feature (first): POST /v1/storefront/holds happy path (201, wire shape, `expires_at` 10 minutes out via fake clock); every failure mode row from the endpoint table asserting status and `code`; `customer_id` populated from a customer bearer token and null for anonymous guests, with a body-supplied `customer_id` rejected as unknown input.
- Contract (first): OpenAPI path for POST /v1/storefront/holds and GET /v1/storefront/holds/{hold}; conformance assertions on the recorded responses.
- Unit (first): `CreateHold` records `HoldCreated` in the producing transaction (assert outbox row present pre-commit, absent after a forced rollback); sales window and published-event validation paths.
- Isolation (first): `holds` and `hold_items` probes green; a feature-level probe that a hold created under tenant A returns 404 under tenant B.
- Implementation: migrations, models, enum, `CreateHold`, controller, `CreateHoldData` and `HoldData`, GET endpoint.

### Slice 3: release, expiry, availability

- Unit (first): fake-clock TTL matrix for `ReleaseExpiredHolds` (not yet expired, exactly at `expires_at`, long past); exactly-one-of `HoldExpired`/`HoldReleased` when release races the sweeper; counter and seat reconciliation amounts.
- Feature (first): DELETE /v1/storefront/holds/{hold} including idempotent re-release and `hold_not_releasable`; GET availability before and after expiry showing exact recovery.
- Concurrency (first): expiry recovery simulation from slice 0 goes green.
- Contract (first): OpenAPI for DELETE and the availability endpoint.
- Implementation: `ReleaseHold`, `ReleaseExpiredHolds` console command on the scheduler, availability endpoint and Data objects, `HoldReleased` and `HoldExpired` recording.

### Slice 4: extend and commit (internal Actions)

- Unit (first): `ExtendHold` extends an active hold, refuses expired holds, never shortens; `CommitHold` moves `held` to `sold` per item in one guarded statement, refuses an expired-but-unswept hold (fake clock, sweeper deliberately not run), refuses double commit, refuses a released hold.
- Concurrency (first): parallel commit-versus-expiry race, asserting a hold commits or expires but never both, and counters end consistent either way.
- Implementation: the two Actions and their Data objects. No HTTP, no contract work.

### Slice 5: seat materialization on publish

- Unit (first): `MaterializeEventSeats` creates one `event_seats` row per template seat, unzoned (`ticket_type_id` null, status `available`), initializes each `requires_seat` ticket type's counter `quantity` to 0, is idempotent per event (re-publish attempts do not duplicate, guarded by the unique constraint), and rolls back atomically with a failed publish. Publish validation refuses an event carrying a `requires_seat` ticket type but no `seat_map_id` (the Stage 5b deferral).
- Feature (first): publishing a seated event (through the Stage 5a publish endpoint) yields materialized unzoned seats; publishing GA events materializes nothing; publishing an event with a `requires_seat` ticket type and no `seat_map_id` fails with 409 `catalog.seat_map_required`; deleting a template whose map is materialized fails with 409 `catalog.seat_map_in_use` (the restricting FK surfaces it, extending the Stage 5b conflict to materialized maps).
- Isolation (first): `event_seats` probe green.
- Implementation: migration with RLS and the on-delete-restrict FK on `seat_id`, model, enum, the Action, the publish validation, the `catalog.seat_map_in_use` mapping extension, the call from Catalog's publish Action inside its transaction.

### Slice 6: seated holds

- Concurrency (first): the double-booking simulation from slice 0 goes green, including the overlapping-set rollback cases.
- Feature (first): POST /v1/storefront/holds with seats: happy path, `seat_selection_invalid` matrix, `seat_unavailable` with offending IDs; release and expiry return seats to `available`.
- Unit (first): the per-type seat UPDATE statements' affected-row-count checks; mixed GA-plus-seated holds touch both mechanisms atomically.
- Implementation: seat handling inside `CreateHold`, `ReleaseHold`, `CommitHold`, sweeper.

### Slice 7: seat management and read surfaces

- Feature (first): storefront seats endpoint shape including the `unavailable` collapse; admin seats list with filter allowlist rejection of unknown filters; PATCH operations matrix including the all-or-nothing rollback and `seat_not_modifiable`; capability denial (403) and cross-tenant denial.
- Unit (first): block and zoning guards; counter adjustments for zone changes; blocking a held seat affects zero rows.
- Contract (first): OpenAPI for the three endpoints and the admin inventory read.
- Isolation (first): endpoint-level cross-tenant probes for the admin surface.
- Implementation: controllers, Data objects, policy check against `events.manage_seating`.

## Task breakdown

Ordered; each is a small PR, independently mergeable unless noted. Commit scope `inventory` except task 9.

1. Slice 0 simulations and isolation probes, written first but not separately mergeable: each merges with the task that turns it green (the counter probe with 2, the GA oversell simulation and hold probes with 4, the expiry recovery simulation with 6, the event_seats probe with 9, the double-booking simulation with 10), because CI has no pending mechanism and a red test blocks the build.
2. `ticket_type_inventory` migration (RLS, CHECKs), model, enum-free counter Actions, unit and isolation tests green.
3. Catalog contract change for quantity: the Stage 5a ticket type Data objects gain an additive `quantity` field (Stage 5a shipped none), the create and update Actions call the Inventory counter Actions, OpenAPI paths and generated TypeScript are updated, and `quantity` input on a `requires_seat` ticket type is rejected with `request.validation_failed` (seated counters are seeded at 0 by materialization and adjusted by zoning). Depends on 2. Scope `inventory` with the Catalog diff reviewed against the architecture suite.
4. `holds` and `hold_items` migrations, models, `HoldStatus` enum, `CreateHold` GA path, `HoldCreated`, POST and GET endpoints, contracts, TypeScript regenerated. Turns the GA oversell simulation green. Depends on 2.
5. `ReleaseHold`, DELETE endpoint, `HoldReleased`. Depends on 4.
6. `ReleaseExpiredHolds` sweeper on the scheduler, `HoldExpired`, expiry recovery simulation green. Depends on 5.
7. Availability endpoint (storefront) and admin inventory read, contracts. Depends on 4; mergeable in parallel with 5 and 6.
8. `ExtendHold` and `CommitHold` with the commit-versus-expiry concurrency test. Depends on 4.
9. `event_seats` migration (RLS, restricting FK on `seat_id`, FK on `hold_id`), `EventSeatStatus` enum, `MaterializeEventSeats`, the `requires_seat` publish validation, publish-Action wiring. Scope `catalog` for the wiring commit if split, else `inventory`. Depends on Stage 5b and on 4 (`event_seats.hold_id` references `holds`, matching the migration order); independent of 5 through 8.
10. Seated hold path through `CreateHold`, `ReleaseHold`, `CommitHold`, and the sweeper; double-booking simulation green. Depends on 8 and 9.
    10a. Capability registry addition `events.manage_seating` plus its template-role wiring in the seeders (Stage 3's initial registry does not include it); authorization matrix test extended. Scope `identity`. Mergeable alone; must land before 11.
11. Storefront seats endpoint, admin seats list, admin PATCH operations, contracts. Depends on 10 and 10a.
12. Status table flip in `docs/api-implementation-plan.md` (start at task 1, done at exit), and OpenAPI document consolidation check. With task 11.

## Exit criteria

The master plan's exit line, "no simulation configuration can oversell a ticket type or double-book a seat; availability arithmetic recovers exactly when holds expire", expands to these individually testable checks, every one automated:

1. The GA oversell simulation passes at every configured parallelism and oversubscription level, asserting `sold + held <= quantity` on every counter row after the run and exactly the expected number of successful holds.
2. The seat double-booking simulation passes: for every contested seat, at most one active hold, and every losing request rolled back completely (no counter drift, no partial seat sets).
3. The commit-versus-expiry race passes: a hold ends `committed` or `expired`, never both, with counters consistent under either outcome; on expiry exactly one `HoldExpired` is recorded, and on commit no Inventory event is recorded (there is no `HoldCommitted`) and never a `HoldExpired` for the committed hold.
4. Expiry recovery is exact: after the sweeper processes expired holds, `held` is 0 for the affected types, the availability endpoint reports `quantity - sold`, and freed seats are `available`.
5. Conversion-time validation holds: with the sweeper suspended and the fake clock past `expires_at`, `CommitHold` affects zero rows and fails with the typed exception.
6. Extension cannot resurrect: `ExtendHold` on an expired or released hold affects zero rows.
7. Every failure-mode row in the endpoint tables has a feature test asserting the status and stable `code`, and every response is validated against the merged OpenAPI paths by the Contract suite.
8. Isolation probes for `ticket_type_inventory`, `holds`, `hold_items`, and `event_seats` prove cross-tenant reads and writes fail under RLS, and endpoint-level cross-tenant requests return 404 or empty per the Stage 2 pattern.
9. The Architecture suite confirms Inventory imports no other context's models and Catalog reaches Inventory only through Actions.
10. `HoldCreated`, `HoldReleased`, and `HoldExpired` are recorded in the producing transaction (present before commit, absent after rollback) with complete envelopes.
11. Publishing a seated event materializes exactly the template's seats, all unzoned with `requires_seat` counters at 0; admin zoning assigns types and adjusts counters to match seat counts; re-publish does not duplicate; publish fails with `catalog.seat_map_required` when a `requires_seat` type exists without a `seat_map_id`.
12. `composer lint`, `composer analyse`, all six suites in `composer test`, and the TypeScript drift gate pass; generated types for the new Data objects are committed.
13. The status table row for Stage 6 reads done.

## Risks and open questions

- Nullable `customer_id` on holds: resolved. Anonymous holds are supported as designed; the system-design 8.2 ERD draws `CUSTOMER ||--o{ HOLD` without stating nullability, and nullability lets checkout start before buyer identification. Stage 7 owns attachment: its `ConvertHoldToOrder` attaches the authenticated customer inside the conversion transaction via a conditional UPDATE (set `customer_id` where it is null or already equals the caller), rejecting a hold owned by a different customer with not-found semantics. Nothing in this stage changes.
- Quantity input ownership: resolved against the Stage 5a plan, which ships ticket types with no quantity field and no availability. `quantity` lives only in `ticket_type_inventory`; task 3 is therefore an additive contract change to Stage 5a's ticket type Data objects and OpenAPI shapes, with Catalog accepting `quantity` and delegating to Inventory Actions, and rejecting it for `requires_seat` types. No backfill or column drop is needed.
- Seated counter double-accounting. Keeping `ticket_type_inventory` authoritative for seated types (seeded at 0 on materialization, adjusted on zoning, block, and rezone) means two representations of one fact. The alternative, deriving seated availability purely from `event_seats` counts, avoids drift but gives Stage 7 and Stage 8 two different commit paths. This plan keeps the counter authoritative and adds a unit test asserting counter equals seat-status counts after every seat mutation; if drift appears in practice, a reconciliation check joins the sweeper.
- Sweeper throughput. Releasing an expired hold touches the hold row, N counter rows, M seat rows, and one outbox row per hold. Batched per hold (one transaction each) it stays small; a boleto-scale backlog (Stage 8a windows up to 3 days) could make sweeps long. Cadence every minute with a per-run cap is the starting point; system-design 13 treats sweepers as backstops, so latency here only delays availability recovery, never correctness (conversion-time validation covers the gap).
- Storefront seat map payload size. Large venues mean thousands of `event_seats` rows per response. The endpoint is bounded per event, so page pagination is permitted by api-conventions, but a seat map is only useful whole; ship it unpaginated with a documented size expectation and revisit with an ETag or section-filter parameter if payloads hurt. Stage 10's caching posture will absorb the read load either way.
- Hold endpoint abuse before Stage 10. Unauthenticated hold creation is inventory denial-of-service until admission tokens and rate tiers arrive. The 10-minute TTL bounds the damage and Stage 10 owns the real controls, but if a launch precedes Stage 10, a basic per-IP throttle on POST /v1/storefront/holds should be pulled forward; note it in the Stage 10 plan.
- `events.manage_seating` capability naming. Stage 3's initial registry does not include it, so task 10a registers it in the capability enum and wires it into the template roles. The name follows the `events.*` prefix of the existing catalog capabilities; if a different name is preferred, settle it in task 10a before the admin seat endpoints land.
