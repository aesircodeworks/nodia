# Stage 5b: Seating Templates

Implementation plan for the Seating Templates slice of Stage 5 in [api-implementation-plan.md](../api-implementation-plan.md). The master plan's "Method: the TDD loop" and "Contract pipeline" sections are binding for every slice below, as are [api-conventions.md](../api-conventions.md), [data-conventions.md](../data-conventions.md), and [event-conventions.md](../event-conventions.md).

## Scope and non-goals

### Delivers

- `seat_maps` and `seats` as reusable venue templates, exactly as system-design 6.2 and 8.2 define them: a seat map belongs to a venue and carries a name and a JSON layout; a seat belongs to a seat map and carries section, row, number, and layout coordinates.
- The `UpsertSeatMap` Action named in system-design 3.2, treating a seat map plus its seats as one document: create, full replace with seat identity preservation, and delete.
- Admin HTTP surface in the EventCatalog context: create, show, list, update, and delete seat maps, capability-gated per system-design 5.3 and audited via the activity log per system-design 14.2.
- The link that lets a seated event select a template: a nullable `seat_map_id` on `events`, validated to belong to the event's venue. This is what makes the stage exit line "seated events can be built from templates here" true; see the open questions for the system-design 8.2 gap it fills.
- Isolation coverage for both new tables, OpenAPI contract fragments, and regenerated TypeScript types.

### Non-goals, and where they land

- `event_seats` materialization on publish, per-event seat blocking, and ticket-type zoning: Stage 6 (system-design 6.2 puts materialization at publish time; the master plan assigns it to Stage 6 explicitly).
- Any hold, availability, or checkout behavior touching seats: Stage 6 and Stage 7.
- Storefront seat map rendering endpoints: there is no storefront surface in this stage. Buyers only ever see materialized `event_seats` with availability, which do not exist until Stage 6.
- Delete protection for templates referenced by materialized events (`catalog.seat_map_in_use` conflict): Stage 6, when `event_seats` rows first reference `seats.id` and the restricting foreign key ships.
- Media attachments on seat maps and search indexing: Stage 5c owns media and search.
- Seat map versioning or template duplication endpoints: not in the system design; out of scope until the design defines them.

## Dependencies

### Requires from earlier stages

- Stage 1: RFC 9457 problem+json handler with the stable code registry, the Contract suite wiring against `docs/openapi/openapi.yaml`, the real Isolation harness with its two-tenant fixture, and the Unit suite declaration.
- Stage 2: `tenants`, the `SET LOCAL app.tenant_id` transaction wrapper, the per-table RLS policy pattern, and tenant resolution middleware (`X-Tenant-Id` for admin routes per api-conventions).
- Stage 3: Passport staff authentication, memberships, capability-based Gates and Policies, and the activity log recording tenant-scoped mutations.
- Stage 4: required transitively through Stage 5a. Seat map CRUD records no outbox events, but Slice 6 mutates events through the Stage 5a `UpdateEvent` Action, which records `EventUpdated` to the outbox on every update (see Domain events).
- Stage 5a: `venues` (templates hang off them), `events` (the `seat_map_id` linkage is an additive migration against a 5a table), and `ticket_types` with `requires_seat` (system-design 8.2), which is what makes an event "seated".

### Consumed by later stages

- Stage 6 reads `seats` to materialize `event_seats` on publish (unique on `(event_id, seat_id)` per system-design 6.2), reads `events.seat_map_id` to know which template to materialize, and adds the restricting foreign key from `event_seats.seat_id` plus the `catalog.seat_map_in_use` delete conflict.
- Stage 6's publish extension treats `requires_seat` ticket types on an event without a `seat_map_id` as a publish-blocking validation failure; this stage only stores and validates the linkage.

## Data model

All three changes follow data-conventions: UUIDv7 `id` via `HasUuids`, non-null `tenant_id` on tenant-scoped tables, UTC timestamps, RLS policy in the same migration that creates each table.

### `seat_maps`

| Column                     | Type        | Notes                                                                                                  |
| -------------------------- | ----------- | ------------------------------------------------------------------------------------------------------ |
| `id`                       | uuid        | PK, UUIDv7                                                                                             |
| `tenant_id`                | uuid        | non-null, FK `tenants.id` (system-design 4.2: denormalized even though derivable via venue)            |
| `venue_id`                 | uuid        | non-null, FK `venues.id`                                                                               |
| `name`                     | string      | template name shown to staff                                                                           |
| `layout`                   | jsonb       | map-level geometry (stage position, section shapes); opaque to the API, rendered by the admin frontend |
| `created_at`, `updated_at` | timestamptz | UTC                                                                                                    |

Constraints and indexes:

- Unique `(venue_id, name)` so a venue's templates are unambiguous to staff.
- Index `(tenant_id, venue_id)` for the list endpoint.
- FK `venue_id` on delete restrict: deleting a venue with templates is refused; template deletion is explicit.
- RLS policy comparing `tenant_id` to `current_setting('app.tenant_id')`, enabled in the same migration.

### `seats`

| Column                     | Type             | Notes                                                                                                                         |
| -------------------------- | ---------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| `id`                       | uuid             | PK, UUIDv7. Stable identity: Stage 6 `event_seats.seat_id` will reference it, so upserts must preserve it for unchanged seats |
| `tenant_id`                | uuid             | non-null, FK `tenants.id`                                                                                                     |
| `seat_map_id`              | uuid             | non-null, FK `seat_maps.id`, on delete cascade (a template owns its seats)                                                    |
| `section`                  | string           | per system-design 8.2                                                                                                         |
| `row`                      | string           | per system-design 8.2; a PostgreSQL reserved word, safe because Laravel's grammar quotes identifiers                          |
| `number`                   | string           | per system-design 8.2                                                                                                         |
| `position_x`               | integer nullable | layout coordinate per system-design 6.2, in layout grid units                                                                 |
| `position_y`               | integer nullable | layout coordinate per system-design 6.2                                                                                       |
| `created_at`, `updated_at` | timestamptz      | UTC                                                                                                                           |

Constraints and indexes:

- Unique `(seat_map_id, section, row, number)`: the natural key of a seat within a template. This is the database guarantee that no upsert interleaving can produce duplicate seats.
- Index `(tenant_id)` supporting the RLS predicate; `seat_map_id` is covered by the unique index prefix.
- RLS policy in the same migration.

### `events.seat_map_id` (additive migration)

- Nullable uuid, FK `seat_maps.id` on delete restrict (once an event points at a template, the template cannot be silently deleted; until Stage 6 adds materialization-based protection this FK is the only guard).
- No RLS change: `events` already carries its policy from Stage 5a; merged migrations are never edited (data-conventions), so this is a new migration.
- Application invariant, enforced in the update-event Action: when set, the seat map must belong to the event's venue, and a virtual event (no venue) cannot set it. The invariant holds on later edits too: any change to `venue_id` or `is_virtual` while `seat_map_id` is non-null re-validates the linkage and is rejected with `catalog.seat_map_venue_mismatch` or `catalog.seat_map_virtual_event` unless the same request clears `seat_map_id`.

### Deletion semantics

- Deleting a seat map cascades to its seats at the database level. In this stage nothing else references seats, so this is safe; the events FK restricts deletion of a template an event has selected.
- Stage 6 will add `event_seats.seat_id` with on delete restrict, at which point template deletion for materialized maps fails at the database and the API maps it to the `catalog.seat_map_in_use` conflict. Nothing in this stage needs to anticipate that beyond keeping seat IDs stable.

## Domain events

No new event types; nothing consumed. Seat map CRUD records no domain events, but Slice 6's `seat_map_id` mutations run through the Stage 5a `UpdateEvent` Action and therefore record `EventUpdated`: selecting or clearing a template changes the event's sellable surface, exactly the reasoning Stage 5a uses to map ticket-type changes to the parent event's update event.

The event registry in system-design 9.3 defines the Catalog events as `EventCreated`, `EventUpdated`, `EventPublished`, `EventCanceled`; there are no seat map event types, and event-conventions makes that registry authoritative (adding a type means updating the registry in the same change). No consumer needs one either: the search index consumes catalog event lifecycle events (system-design 9.2) and seat maps are not searchable content, and Stage 6 materializes from current template state at publish time rather than by following template-change events.

Template mutations are still observable: they are tenant-scoped staff mutations, so the Stage 3 activity log records them (system-design 14.2). That is an audit concern, not a domain event.

Consequence for the suites: the outbox idempotence test pattern ("every outbox consumer starts with a failing duplicate-delivery test") has no application in this stage, since it introduces no consumers. The producer side is covered by Slice 6's feature test asserting the `EventUpdated` recording.

## Endpoints

All admin endpoints: staff bearer JWT, `X-Tenant-Id` validated against memberships (api-conventions), capabilities evaluated through a `SeatMapPolicy` (capability plus tenant context per system-design 5.3). The read and write split matches Stage 5a: the GET endpoints require the existing `events.view` capability, so view-only roles like Box Office and Finance can see templates; the mutating endpoints require `seat_maps.manage`, added to the Owner and Event Manager global role templates. Wire format snake_case JSON; every error is an RFC 9457 problem document with a stable `code`; the new codes carry the `catalog.` context prefix established by Stage 5a (`catalog.currency_mismatch`, `catalog.event_immutable`), alongside the Stage 1 registry codes (`request.validation_failed`, `request.not_found`, `auth.unauthenticated`, `auth.forbidden`). Route parameters are UUIDv7 strings. Nesting stays at one level per api-conventions: collection routes nest under the owning resource, item routes are top-level.

### `GET /v1/venues/{venue}/seat-maps`

- Lists a venue's templates without their seats. Bounded collection, page pagination in the standard paginator envelope.
- Query-builder with explicit allowlists: `filter[name]` (partial match), `sort` on `name`, `created_at` (default `-created_at`). Unknown filter, sort, or include values are rejected with a validation problem, not ignored.
- Response: paginated `SeatMapSummaryData` (`id`, `venue_id`, `name`, `seat_count`, `created_at`, `updated_at`).
- Errors: 401, 403 (`auth.forbidden`, without `events.view`), 404 for unknown or cross-tenant venue (RLS makes the row invisible, so cross-tenant is indistinguishable from missing).

### `POST /v1/venues/{venue}/seat-maps`

- Creates a template with its full seat list in one document. Request: `UpsertSeatMapData` with `name`, `layout`, and `seats` as an array of `SeatInputData` (`section`, `row`, `number`, `position_x`, `position_y`).
- Response: 201 with `SeatMapData` (`id`, `venue_id`, `name`, `layout`, `seats: SeatData[]`, timestamps); `SeatData` adds `id` to the input fields.
- Validation: non-empty `name`; each seat's `section`, `row`, `number` present; in-payload duplicate natural keys rejected with `catalog.seat_map_duplicate_seats` (422) listing the offending positions in the `errors` map; duplicate name for the venue rejected with `catalog.seat_map_name_taken` (422).
- Errors: 401, 403, 404 (venue), 422 `request.validation_failed`, 422 `catalog.seat_map_duplicate_seats`, 422 `catalog.seat_map_name_taken`.

### `GET /v1/seat-maps/{seat_map}`

- Full document: `SeatMapData` including all seats, ordered deterministically by `section`, `row`, `number`.
- Errors: 401, 403, 404.

### `PUT /v1/seat-maps/{seat_map}`

- Full replace with the same `UpsertSeatMapData` body. Declarative semantics inside one transaction: the Action first acquires a row lock on the `seat_maps` row (`lockForUpdate`) so concurrent replaces of the same map serialize instead of interleaving their diffs; seats are then matched to existing rows by the natural key `(section, row, number)`; matched seats keep their `id` and get coordinate updates, unmatched incoming seats are inserted, existing seats absent from the payload are deleted. `name` and `layout` are replaced. A residual unique violation on `(seat_map_id, section, row, number)` (defense in depth; unreachable once writers serialize on the lock) maps to a 409 `catalog.seat_map_conflict` problem, never a 500.
- Seat ID stability is the load-bearing property: Stage 6 references `seats.id` from `event_seats`, so an edit that only moves a seat's coordinates must not change its identity. Changing a seat's natural key is by definition a delete plus an insert.
- Errors: as create, plus 404 for the seat map itself.

### `DELETE /v1/seat-maps/{seat_map}`

- 204 on success; seats cascade.
- 409 `catalog.seat_map_in_use` when an event references the template via `seat_map_id` (the restricting FK surfaces this; the handler maps it to the problem document). Stage 6 extends the same code to materialized maps.
- Errors: 401, 403, 404, 409.

### `PATCH /v1/events/{event}` (extension of the Stage 5a endpoint)

- Adds `seat_map_id` (nullable uuid) to the existing update-event request Data object; additive contract change, no new version.
- Validation: the seat map must exist, be visible under RLS, and belong to the event's venue, else 422 `catalog.seat_map_venue_mismatch`; a virtual event rejects a non-null value with 422 `catalog.seat_map_virtual_event` (system-design 8.3 note: virtual events have no venue). The same codes guard the reverse direction: changing `venue_id` or flipping `is_virtual` on an event whose `seat_map_id` is non-null is rejected unless the request clears the link.
- Setting or clearing `seat_map_id` goes through the Stage 5a `UpdateEvent` Action and records `EventUpdated` to the outbox like every other event update.

Contract work per endpoint, per the master plan's contract pipeline: laravel-data request and response objects are the source of truth, the OpenAPI paths are added to `docs/openapi/openapi.yaml` in the same change, the Contract suite asserts recorded responses conform, and `composer types:generate` output is committed without drift.

## TDD sequencing

Ordered slices, each following the double loop from the master plan: outside feature test first, contract second, inside unit tests third, then green, refactor, regenerate, commit. Suites not listed for a slice are not applicable to it.

### Slice 1: tables under RLS

Failing tests first:

- Isolation: using the two-tenant fixture, tenant B cannot select, update, or delete tenant A's `seat_maps` rows; same matrix for `seats`; inserts with a foreign `tenant_id` fail. Written before the migration exists, per the master plan's non-negotiable ("every new tenant-scoped table starts with a failing isolation test").

Then: the migration creating `seat_maps` and `seats` with their RLS policies, models (`SeatMap`, `Seat` in `app/EventCatalog/Models`), factories. Architecture suite must stay green (models only referenced inside EventCatalog).

### Slice 2: create seat map

Failing tests first:

- Feature: POST returns 201 with the exact wire shape (snake_case, seats echoed with generated UUIDv7 ids); 422 `catalog.seat_map_duplicate_seats` for in-payload duplicates; 422 `catalog.seat_map_name_taken` for a second template with the same name on the venue; 404 for a venue of another tenant; 403 without `seat_maps.manage`; 401 unauthenticated; activity log row recorded for the mutation.
- Contract: the recorded 201 and each problem response conform to the new OpenAPI fragment.
- Unit: `UpsertSeatMap` Action rejects duplicate natural keys before touching the database; persists seats in chunked bulk inserts inside one transaction; returns the Data object with seats ordered by section, row, number.

### Slice 3: show and list

Failing tests first:

- Feature: GET item returns the full document with deterministic seat order; GET list paginates with the standard envelope, honors `filter[name]` and `sort`, and rejects an unknown filter or sort with a validation problem rather than ignoring it; `seat_count` is correct; a token without `events.view` gets 403; drafts of other concerns do not apply here but cross-tenant rows are absent.
- Contract: conformance for item, list, and rejection responses.
- Isolation: endpoint-level check that a tenant B token with a valid capability gets 404 for tenant A's seat map id (the suite covers every endpoint per system-design 18).

### Slice 4: update with seat identity preservation

Failing tests first:

- Feature: PUT replaces name, layout, and seat set; response reflects the replacement; validation and problem codes match create.
- Unit: given an existing map, an upsert payload that keeps a seat's natural key but changes coordinates preserves that seat's `id`; a payload omitting a seat deletes it; a payload adding a seat inserts it with a new id; the whole replacement is one transaction (a failing seat insert leaves the original document intact).
- Concurrency: two parallel PUTs against the same seat map end with exactly one payload's document fully applied, never an interleaved merge (the `lockForUpdate` on the `seat_maps` row is the serializing mechanism), and the `(seat_map_id, section, row, number)` unique constraint never raises past the API boundary as a 500 (a residual violation maps to 409 `catalog.seat_map_conflict`). This is an atomicity probe, not an invariant-counter test; the stage has no invariant-guarding status transitions (those arrive with `event_seats` in Stage 6), so the conditional-UPDATE pattern is not exercised here.

### Slice 5: delete

Failing tests first:

- Feature: DELETE returns 204 and cascades seats; deleting a template referenced by an event returns 409 `catalog.seat_map_in_use`; 404 cross-tenant.
- Contract: 204 and 409 conformance.

Note: the 409 test depends on Slice 6's linkage, and merging Slice 5 with a known-failing test would violate the master plan's definition of done. Required path: either Slices 5 and 6 share one PR with the order preserved in commits, or Slice 6's `events.seat_map_id` migration lands first so the 409 test is green when Slice 5 merges.

### Slice 6: event links a template

Failing tests first:

- Feature: PATCH event accepts `seat_map_id` for a physical event whose venue owns the map; 422 `catalog.seat_map_venue_mismatch` for a map of another venue; 422 `catalog.seat_map_virtual_event` for a virtual event; changing `venue_id` (or flipping `is_virtual`) while `seat_map_id` is set is rejected with the same codes unless the request clears the link, and succeeds when it does; clearing to null succeeds; a `seat_map_id` change records exactly one `EventUpdated` outbox row with the correct envelope; the map id appears in the event admin read shape.
- Contract: updated event request and response schemas conform; TypeScript regenerated.
- Unit: the update-event Action's invariant checks in isolation.
- Isolation: a seat map id belonging to another tenant behaves as nonexistent (422 or 404 per the validation path, asserted explicitly).

## Task breakdown

Ordered; each is independently mergeable unless noted.

1. Failing isolation tests plus the `seat_maps` and `seats` migration with RLS policies, models, factories, and the `seat_maps.manage` capability added to the Owner and Event Manager role templates. Status table in the master plan flipped to "In progress".
2. `UpsertSeatMap` Action (create path), `SeatMapData`, `SeatData`, `SeatMapSummaryData`, `UpsertSeatMapData`, `SeatInputData` Data objects, `SeatMapPolicy`, POST endpoint, OpenAPI fragment, generated types. Commit scope `catalog`.
3. GET item and GET list endpoints with the query-builder allowlist, `seat_count` aggregation, OpenAPI additions.
4. PUT endpoint completing `UpsertSeatMap` with natural-key matching, plus the concurrency atomicity test. Mergeable independently of 3.
5. DELETE endpoint with cascade; the `catalog.seat_map_in_use` 409 path lands here, and because its feature test needs task 6's migration, tasks 5 and 6 share a PR unless task 6's migration merges first.
6. Additive `events.seat_map_id` migration with restricting FK, update-event Data and Action extension, venue-match and virtual-event invariants, OpenAPI and type regeneration for the event shapes.
7. Sweep: isolation suite entries for every new endpoint, problem `code` registry entries documented, master plan status table flipped to done, roadmap untouched (reserved seating is post-MVP there; this plan is the API-surface track).

## Exit criteria

The master plan's stage exit line, "seated events can be built from templates here, but publishing them is only complete once Stage 6 adds event_seats materialization", expands to these individually testable checks:

1. A staff user with `seat_maps.manage` can create a seat map with several hundred seats on a venue over HTTP and read it back byte-identical in shape, seats deterministically ordered.
2. Updating a template preserves the `id` of every seat whose `(section, row, number)` is unchanged, verified by a unit test comparing ids across an upsert.
3. In-payload duplicate seats, duplicate template names per venue, cross-venue linkage, and virtual-event linkage each fail with their documented stable problem `code` (`catalog.seat_map_duplicate_seats`, `catalog.seat_map_name_taken`, `catalog.seat_map_venue_mismatch`, `catalog.seat_map_virtual_event`), asserted by feature tests.
4. An event on the venue can set `seat_map_id`, the mutation records `EventUpdated` through the Stage 5a Action, and together with a `requires_seat` ticket type from Stage 5a it constitutes a buildable seated event; no publish-path change ships in this stage.
5. Deleting a referenced template fails with 409 `catalog.seat_map_in_use`; deleting an unreferenced one removes it and its seats.
6. The isolation suite proves cross-tenant reads and writes fail for `seat_maps` and `seats` at both the table and the endpoint level.
7. The concurrency atomicity test shows parallel upserts of one map never produce a merged or torn document.
8. Every new endpoint's OpenAPI contract is merged, the Contract suite passes against recorded responses, and `composer types:generate` output is committed with the drift gate green.
9. All suites (`Feature`, `Unit`, `Contract`, `Architecture`, `Isolation`, `Concurrency`) pass in `composer test` and CI; Larastan and Pint clean.
10. The master plan status table marks Stage 5b done.

## Risks and open questions

- Design gap, needs a system-design update: 8.2 draws no relationship between `EVENT` and `SEAT_MAP`, yet Stage 6 materializes `event_seats` "on publish" (6.2) and must know which template to use, and this stage's exit line requires seated events to be buildable. This plan adds `events.seat_map_id` as the minimal linkage. The system-design 8.2 diagram should gain the column in the same change; if the eventual design instead wants per-ticket-type zoning at the template level or multi-map events, the nullable column remains forward-compatible. Confirm this scope decision before task 6.
- Seat identity across natural-key edits: renaming a section renames every seat's natural key, which the upsert treats as delete plus insert, producing all-new seat ids. Harmless in this stage, but after Stage 6 materialization it will make re-materialization or reconciliation semantics visible. Stage 6 owns that problem; this plan's contribution is documenting the natural-key matching rule in the OpenAPI description so the admin frontend knows renames change identity.
- Large templates: an arena map can carry tens of thousands of seats in one PUT document. Mitigations here are chunked bulk inserts inside the transaction and a validated payload ceiling (e.g. max seats per map, returned as a validation problem). If real venues exceed comfortable request sizes, a follow-up stage can add a paginated `GET /v1/seat-maps/{seat_map}/seats` and a batched write protocol; not built speculatively now.
- Coordinate representation: `position_x` and `position_y` are integers in layout grid units to keep floats off the wire and out of the schema. If the admin seat map editor needs sub-unit precision, the fix is a finer grid, not floats; flag to the frontend team before the editor is designed.
- `row` is a PostgreSQL reserved word. Laravel quotes identifiers so schema and Eloquent paths are safe, but any hand-written raw SQL in tests or future reports must quote it; worth a comment in the migration.
- Venue capacity versus seat count: the design stores `venues.capacity` (8.2) but nowhere requires templates to respect it. This plan does not validate seat count against capacity; if the product wants a warning or hard limit, that is a design decision to record first.
- No new domain event types is a deliberate reading of the 9.3 registry; Slice 6 rides the existing `EventUpdated`. If Stage 5c search or Stage 11 reporting later needs template-change signals, the addition is a new event type registered in system-design 9.3 per event-conventions, not a retrofit of this stage.
