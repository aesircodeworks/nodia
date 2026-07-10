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

- [ ] task-01: Failing isolation tests, `seat_maps` and `seats` migration with RLS, models, factories, `seat_maps.manage` capability wired into Owner and Event Manager role templates (plan task 1, slice 1)
- [ ] task-02: `UpsertSeatMap` create path, Data objects, `SeatMapPolicy`, POST endpoint, OpenAPI fragment, generated types (plan task 2, slice 2)
- [ ] task-03: GET item and GET list endpoints with query-builder allowlist and `seat_count`, contract and isolation coverage (plan task 3, slice 3)
- [ ] task-04: PUT full replace with seat identity preservation, concurrency atomicity test, `catalog.seat_map_conflict` mapping (plan task 4, slice 4)
- [ ] task-05: Additive `events.seat_map_id` migration with restricting FK, `UpdateEvent` extension with venue-match and virtual-event invariants, contract and type regeneration (plan task 6, slice 6)
- [ ] task-06: DELETE endpoint with cascade and the `catalog.seat_map_in_use` 409 path (plan task 5, slice 5; depends on task-05's migration)
- [ ] task-07: Sweep: endpoint-level isolation entries, problem `code` registry documentation, master plan status flip to Done (plan task 7)

### Review rounds

### Decisions and deviations
