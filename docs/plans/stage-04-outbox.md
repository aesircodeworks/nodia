# Stage 4: Transactional Outbox

Implementation plan for Stage 4 of [api-implementation-plan.md](../api-implementation-plan.md). The outbox is the event backbone described in [system-design.md](../system-design.md) section 9 and ADR [004](../decisions/004-transactional-outbox-with-redis-queues.md): every context after this stage records domain events from day one, and consumers are TDD-able immediately. The conventions in [event-conventions.md](../event-conventions.md), [data-conventions.md](../data-conventions.md), and [api-conventions.md](../api-conventions.md) are binding throughout.

## Scope and non-goals

Delivered by this stage:

- `outbox_events`: the append-only durable event log with the envelope from event-conventions and the bigint identity `sequence` (the sole auto-increment exception per data-conventions), retained after dispatch as the replay source (system-design 9.1).
- A recording API in `app/Support/Outbox` callable only inside a producing transaction; recording outside any transaction is a programming error and throws.
- `outbox_deliveries` per-subscriber progress tracking with `pending` and `processed` states (system-design 9.2), where marking `processed` is a conditional UPDATE checked by affected-row count.
- The after-commit dispatcher enqueuing one Horizon job per subscriber, jobs carrying only the event ID (system-design 9.2), plus the static in-code subscriber registry.
- Laravel Horizon installation and queue topology, with the failed-jobs table as the dead letter queue (system-design 9.2, 13).
- The reconciliation sweeper re-enqueuing stranded `pending` deliveries past a grace window, reading past the stability window from system-design 9.1 (sequence gaps are permanent and a lower sequence can commit after a higher one).
- The ordered-consumption helper for consumers needing per-aggregate sequence order, deferring an event whose predecessor is unprocessed (system-design 9.2); the Stage 8b ledger projection is its first real user.
- The replay primitive: rescan the outbox in `sequence` order past the stability window to rebuild a projection (system-design 9.1).
- Test fixtures for later stages: an idempotent test subscriber and Pest helpers, so every future consumer can start with the mandated failing duplicate-delivery test (api-implementation-plan, Method section).
- The identity event producers deferred from Stage 3: `UserInvited`, `UserRoleChanged`, and `CustomerRegistered` (system-design 9.3) recorded by their Actions in the producing transaction.
- The Tenancy event producers deferred from Stage 2: `TenantCreated` and `DomainVerified` (system-design 3.2) recorded by their Actions in the producing transaction, with the envelope details fixed by the Stage 2 plan (`TenantCreated` carries the sentinel platform tenant), plus the Tenancy rows the system-design 9.3 registry currently lacks, added in the same change.

Explicitly deferred:

- Real production consumers: email dispatch and PDF generation (Stage 8a), ledger projection (Stage 8b), search index refresh (Stage 5c), reporting projectors (Stage 11). This stage proves the machinery with test subscribers only.
- Producers for contexts other than Identity and Tenancy: catalog events (Stage 5a), inventory events (Stage 6), order and payment events (Stages 7 and 8), check-in events (Stage 9).
- Outbox archival to object storage and retention windows (Stage 12).
- Support-safe, audited operational commands for replaying failed deliveries (Stage 12); this stage ships the underlying replay primitive only.
- Any broker relay (Kafka or otherwise); the outbox contract is the seam if that ever changes (ADR 004).

## Dependencies

Required from earlier stages:

- Stage 1: the RFC 9457 problem handler, `Support/Money` (not used by identity payloads but available to later event payloads), the Unit suite, the real Isolation and Concurrency harnesses against PostgreSQL, the fake clock for everything TTL-based (the sweeper grace window and stability window are driven through it), and the contract suite wiring.
- Stage 2: `tenants`, the RLS bootstrap (`SET LOCAL app.tenant_id` transaction wrapper and per-table policy pattern), the sentinel platform tenant, the platform-scope cross-tenant database role (system-design 4.3), which the queue infrastructure needs to load outbox rows before tenant context is known, and the `TenantCreated` and `DomainVerified` event classes with the Tenancy Actions this stage attaches producers to.
- Stage 3: the identity models and Actions (`InviteUser`, `AssignRole`, `RegisterCustomer`) that this stage attaches producers to, and Passport-authenticated endpoints to drive them over HTTP in feature tests.
- Phase 0: the correlation ID middleware; this stage adds a request-scoped container binding so the recorder can read the current correlation ID without touching the log context (Octane-safe, never static state).

Consumed by later stages:

- Every stage from 5a onward records events through the recording API in the same transaction as the state change, without exception (event-conventions).
- Stage 8a consumers (`SendOrderConfirmation`, `GenerateTicketPdf`) subscribe via the registry and start from the duplicate-delivery test fixture.
- Stage 8b's ledger projection is the first real user of the ordered-consumption helper.
- Stage 11 projectors use the replay primitive for rebuild-equivalence tests.
- Stage 12 archival operates on the retained rows this stage accumulates.

## Data model

All timestamps UTC. No money columns in this stage. Both new tables are tenant-scoped, so both ship non-null `tenant_id` and their RLS policy in the same migration (data-conventions; ADR 003).

### outbox_events

Append-only event log per the envelope in event-conventions:

| Column                 | Type                                        | Notes                                                                                                                                                                                                                                            |
| ---------------------- | ------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| id                     | uuid, PK                                    | UUIDv7 via `HasUuids`; the event ID consumers are idempotent by                                                                                                                                                                                  |
| sequence               | bigint generated always as identity, unique | global replay order; the sole auto-increment column in the schema (data-conventions); not the primary key                                                                                                                                        |
| type                   | string, not null                            | event name from the system-design 9.3 registry, e.g. `UserInvited`                                                                                                                                                                               |
| tenant_id              | uuid, not null                              | sentinel platform tenant for platform-scope events                                                                                                                                                                                               |
| aggregate_type         | string, not null                            | e.g. `membership`, `customer`                                                                                                                                                                                                                    |
| aggregate_id           | uuid, not null                              |                                                                                                                                                                                                                                                  |
| correlation_id         | string, not null                            | propagated from the originating request; the Phase 0 middleware and api-conventions accept any opaque `X-Correlation-Id` value, so this is a string, not a uuid column; generated values (CLI and scheduled producers, absent header) are UUIDv7 |
| occurred_at            | timestamp, not null                         | UTC, set at record time                                                                                                                                                                                                                          |
| payload                | jsonb, not null                             | laravel-data payload, snake_case keys                                                                                                                                                                                                            |
| created_at, updated_at | timestamps                                  | rows are never updated or deleted by the application                                                                                                                                                                                             |

Constraints and indexes:

- Unique index on `sequence`.
- Composite index `(aggregate_type, aggregate_id, sequence)` supporting the ordered-consumption predecessor check.
- Index on `(type, sequence)` supporting replay filtered by subscribed event types.
- RLS policy comparing `tenant_id` to `current_setting('app.tenant_id')`, created in this migration.

Append-only is an application invariant (no model update or delete paths; the model has no fillable mutation surface after create) and is asserted by a unit test. Enum: none; `type` is validated against the registered event classes at record time.

### outbox_deliveries

One row per event and subscriber, created in the same producing transaction as the event row so a crash between commit and enqueue leaves a durable `pending` record for the sweeper (system-design 9.2).

| Column                 | Type                                | Notes                                                                                                                                                                                                                   |
| ---------------------- | ----------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| id                     | uuid, PK                            | UUIDv7                                                                                                                                                                                                                  |
| outbox_event_id        | uuid, not null, FK to outbox_events |                                                                                                                                                                                                                         |
| tenant_id              | uuid, not null                      | denormalized per system-design 4.2                                                                                                                                                                                      |
| subscriber             | string, not null                    | stable subscriber name from the registry, e.g. `ledger_projection`                                                                                                                                                      |
| status                 | string, not null                    | enum-backed: `pending`, `processed`                                                                                                                                                                                     |
| processed_at           | timestamp, nullable                 |                                                                                                                                                                                                                         |
| last_enqueued_at       | timestamp, nullable                 | set by dispatcher and sweeper; the sweeper grace window is measured from `coalesce(last_enqueued_at, created_at)`, so a never-enqueued row (crash between commit and enqueue) ages out of the window from creation time |
| created_at, updated_at | timestamps                          |                                                                                                                                                                                                                         |

Constraints and indexes:

- Unique on `(outbox_event_id, subscriber)`.
- Partial index on `(subscriber, created_at)` where `status = 'pending'`, named `outbox_deliveries_pending_sweep_idx` per the custom-name rule in data-conventions, supporting the sweeper scan.
- RLS policy in the same migration, same pattern as `outbox_events`.

The `pending` to `processed` transition guards the exactly-one-effect invariant, so it is a conditional UPDATE (`... SET status = 'processed', processed_at = now() WHERE id = ? AND status = 'pending'`) checked by affected-row count; zero rows means another delivery already processed the event and the consumer skips without error (event-conventions: duplicate delivery is normal, not an error).

### Queue infrastructure tables

Horizon runs on Redis and needs no tables, but the dead letter queue does: this stage ships the `failed_jobs` migration (and `job_batches` if Horizon config requires it), adjusted to a UUID primary key per the no-auto-increment rule, following the same adjust-published-migrations approach data-conventions prescribes for third-party packages. These tables are platform infrastructure, not tenant-scoped: no `tenant_id`, no RLS policy, and the isolation suite's tenant-scoped-table check explicitly excludes them alongside framework tables like `cache`.

### Worker access pattern

Queue jobs carry only the event ID, so a worker cannot set `app.tenant_id` before reading the outbox row. The job bootstrap reads the envelope on the cross-tenant read connection established in Stage 2 (system-design 4.3), then opens the normal tenant-scoped transaction with `SET LOCAL app.tenant_id` from the envelope's `tenant_id` for all downstream work, so consumers and any Actions they call run under standard RLS. The sweeper's cross-tenant scan of `pending` deliveries uses the same connection, read-only; for each stranded delivery it then opens a tenant-scoped transaction with `SET LOCAL app.tenant_id` from the delivery row to set `last_enqueued_at` and re-enqueue. All outbox writes, from workers and the sweeper alike, therefore happen under the app role in tenant-scoped transactions, and the cross-tenant role needs only SELECT on the outbox tables. This is infrastructure use of the cross-tenant role, not a staff action; see open questions for the audit-logging boundary.

## Domain events

### Produced

The three identity events from system-design 9.3 and the two Tenancy events from system-design 3.2, recorded by their Stage 3 and Stage 2 Actions in the producing transaction. All use the envelope above; payloads are laravel-data objects with snake_case keys carrying identifiers and facts, never entity snapshots (event-conventions). Payloads deliberately exclude email addresses and names: outbox rows are retained and replayed indefinitely (system-design 9.1) while customer PII is anonymized in place (system-design 14.3), so PII in payloads would outlive erasure. Consumers needing PII load it through the owning context's Actions at consumption time.

| Event              | Recorded by                                                                      | Aggregate                          | Payload fields                                                                      |
| ------------------ | -------------------------------------------------------------------------------- | ---------------------------------- | ----------------------------------------------------------------------------------- |
| UserInvited        | InviteUser                                                                       | `membership` / membership ID       | `user_id`, `membership_id`, `tenant_id`, `role_id`, `invited_by_user_id`            |
| UserRoleChanged    | AssignRole                                                                       | `membership` / membership ID       | `membership_id`, `user_id`, `previous_role_id`, `new_role_id`, `changed_by_user_id` |
| CustomerRegistered | RegisterCustomer                                                                 | `customer` / customer ID           | `customer_id`, `is_guest`                                                           |
| TenantCreated      | CreateTenant                                                                     | `tenant` / tenant ID               | `tenant_id`, `name`, `default_locale`                                               |
| DomainVerified     | the Action settled by Stage 2's trigger decision, recorded before Stage 2 closed | `tenant_domain` / tenant domain ID | `tenant_domain_id`, `tenant_id`, `domain`                                           |

Envelope `tenant_id` is the membership's or customer's tenant; platform-scope memberships use the sentinel platform tenant (event-conventions). Per the Stage 2 plan, `TenantCreated` carries the sentinel platform tenant in its envelope (tenant creation runs in a platform-posture transaction, which is also what lets the outbox insert pass the table's `WITH CHECK`); `DomainVerified` carries the owning tenant. Payload Data classes live in each context's `Events/` directory and are recorded only by the owning context (event-conventions); they are internal contracts, not API shapes, and are excluded from TypeScript generation. Evolution is additive-only; a breaking change is a new event type, never a version field.

### Consumed

No production consumers exist yet. Delivery, idempotence, ordering, and replay are proven against test subscribers registered only in the test environment. Idempotence mechanism for all future consumers, fixed here: idempotent by event ID, progress recorded in `outbox_deliveries` via the conditional `pending` to `processed` UPDATE; a consumer performs its effect and marks processed inside one transaction so the effect and the progress record commit or roll back together (external side effects like email, which cannot join the transaction, additionally guard with their own idempotency key, a Stage 8a concern). The conditional UPDATE executes before the effect inside that transaction, so the row lock serializes concurrent workers and the losing worker sees zero affected rows before performing any effect; it rolls back the transaction and the job completes cleanly without retry.

### Registry

The three identity event types already appear in the system-design 9.3 catalog. The Tenancy events (`TenantCreated`, `DomainVerified`) are named in 3.2 but absent from 9.3, a gap the Stage 2 plan flags, so the change attaching their producers adds the Tenancy rows to the 9.3 catalog in the same change (event-conventions, Naming and Registry). Any test-only event types used by fixtures live under `tests/` and never enter the registry.

## Endpoints

None. This stage is infrastructure plus event recording inside existing Stage 2 and Stage 3 endpoints; it adds no routes, no laravel-data request or response objects, no OpenAPI paths, and no new problem-document codes. The operational replay and delivery-management endpoints or commands are Stage 12 scope. Horizon's bundled dashboard is gated to the local environment only via its gate; it is not part of the API surface and gets no `/v1` route.

Existing Stage 2 and Stage 3 endpoints that now record events (create tenant, domain verification, invite user, assign role, register customer) keep their contracts unchanged; their feature tests gain assertions that the outbox row exists after a 2xx response and does not exist after a failure response.

## TDD sequencing

Every slice follows the double-loop from the master plan: failing tests first, then implementation, then Larastan, Pint, and the architecture suite green. The contract suite is unaffected (no endpoints). Suites named per slice.

### Slice 1: outbox_events and the recording API

Failing tests first:

- Isolation: with the two-tenant fixture, tenant A cannot read or write tenant B's `outbox_events` rows under the app role; the cross-tenant role can read both.
- Feature (real PostgreSQL): recording inside a transaction that rolls back leaves no outbox row; recording inside a committed transaction persists exactly one row with the full envelope (the first mandated test from the master plan's Stage 4 line).
- Unit: recording outside any transaction throws; `type` not in the registered event set throws; `occurred_at` is UTC from the fake clock; `correlation_id` comes from the request-scoped binding when present and is generated (UUIDv7) when absent; payload serializes to snake_case; the model exposes no update or delete path.
- Architecture: `app/Support/Outbox` imports no context models; contexts record only their own `Events/` classes.

### Slice 2: deliveries, dispatcher, Horizon

Failing tests first:

- Feature: recording an event creates one `pending` delivery row per registered subscriber in the same transaction (rollback removes both); after commit, exactly one queue job per subscriber is on the expected queue carrying only the event ID; a rolled-back transaction enqueues nothing.
- Feature (end to end against Redis and real PostgreSQL): processing a delivery runs the test subscriber once and transitions the row to `processed`.
- Feature: duplicate delivery, running the same job twice, causes exactly one subscriber effect and the second run exits cleanly (the second mandated test).
- Concurrency: two parallel workers processing the same delivery produce exactly one effect; the conditional UPDATE admits exactly one winner by affected-row count.
- Unit: the subscriber registry rejects duplicate subscriber names; routing is static in code.

### Slice 3: reconciliation sweeper

Failing tests first:

- Feature: a delivery left `pending` with no enqueue (simulating a crash between commit and enqueue) is re-enqueued once the grace window elapses on the fake clock (the third mandated test), asserting the window is measured from `created_at` when `last_enqueued_at` is null; a `pending` delivery inside the grace window is not re-enqueued; a `processed` delivery is never re-enqueued.
- Feature: the sweeper only considers events older than the stability window, so a row whose lower-sequence sibling has not committed yet is not treated as final (system-design 9.1).
- Unit: grace and stability windows come from `config/outbox.php` and are fake-clock driven.

### Slice 4: ordered-consumption helper

Failing tests first:

- Feature: for an ordered subscriber, an event whose same-aggregate predecessor delivery is unprocessed is deferred (job released, delivery stays `pending`) and is processed after the predecessor completes (the fourth mandated test); an unprocessed event for a different aggregate does not block.
- Feature: an ordered subscriber does not process an event younger than the stability window, because a lower sequence for the same aggregate could still commit (system-design 9.1).
- Concurrency: two workers racing on out-of-order events for one aggregate apply effects in `sequence` order; the recorded effect order is asserted.

### Slice 5: replay primitive

Failing tests first:

- Feature: after recording a series of events across aggregates and processing them incrementally into a test projection, replaying from sequence zero into a fresh projection yields identical state; replay visits rows in `sequence` order and skips nothing older than the stability window; replaying twice yields the same state (consumer idempotence makes replay safe).
- Unit: replay filters by the subscriber's subscribed types and accepts a starting sequence.

### Slice 6: identity and Tenancy producers

Failing tests first:

- Feature (per Action, driving the Stage 3 endpoint over HTTP): a successful invite, role change, and customer registration each persist exactly one outbox row with the correct type, aggregate, tenant, correlation ID (echoing the request's `X-Correlation-Id`), and payload shape; a failing request (validation, authorization) records nothing; a platform-scope invite carries the sentinel platform tenant.
- Feature (per Tenancy Action, driving the Stage 2 endpoint over HTTP): a successful tenant creation persists exactly one `TenantCreated` row with the sentinel platform tenant in the envelope; the resolved `DomainVerified` trigger persists exactly one row with the owning tenant; failure paths record nothing.
- Feature: rollback coupling, forcing the Action's transaction to fail after the record call leaves no event row.
- Unit: payload Data classes serialize the exact field sets above and the identity payloads contain no email or name fields.

## Task breakdown

Ordered; each lands green through the full loop and is independently mergeable unless noted. Commit scopes: `support` for outbox infrastructure, `identity` and `tenancy` for producers, `deps` for package installs.

1. Mark Stage 4 in progress in the api-implementation-plan status table. Scope: `docs`.
2. Correlation ID container binding: request-scoped binding set by the existing middleware, readable by any service, generated on demand in CLI contexts; Octane-safe (no static state). Scope: `support`.
3. `outbox_events` migration with sequence identity column, indexes, and RLS policy, plus the `OutboxEvent` model and isolation tests (Slice 1 isolation tests first).
4. Recording API: envelope Data object, event-class contract exposing type, aggregate, tenant, and payload; `OutboxRecorder` with in-transaction enforcement and registry validation (Slice 1 feature and unit tests first). Architecture test additions.
5. Horizon and queue plumbing: install laravel/horizon, queue and supervisor config, `failed_jobs` migration adjusted to UUID PK, Horizon dashboard gated to local, isolation-suite exclusion list updated. Scope: `deps` then `support`. Mergeable alone.
6. `outbox_deliveries` migration with unique constraint, partial pending index, RLS policy, model, status enum, and the conditional processed transition (isolation and unit tests first).
7. Subscriber registry, after-commit dispatcher, and the delivery job with the cross-tenant bootstrap then tenant-scoped processing; test subscriber fixture and Pest helper for duplicate-delivery tests (Slice 2 tests first, including the concurrency race).
8. Reconciliation sweeper command, `config/outbox.php` windows, scheduler registration (Slice 3 tests first).
9. Ordered-consumption helper with predecessor check and stability-window gate (Slice 4 tests first, including the concurrency ordering race).
10. Replay primitive: support API plus `outbox:replay {subscriber} {--from-sequence=}` artisan command (Slice 5 tests first).
11. `UserInvited` producer in `InviteUser` with payload Data class (Slice 6 tests first). Scope: `identity`.
12. `UserRoleChanged` producer in `AssignRole`. Scope: `identity`.
13. `CustomerRegistered` producer in `RegisterCustomer`. Scope: `identity`.
14. `TenantCreated` producer in `CreateTenant` and `DomainVerified` producer at its resolved trigger point, with payload Data classes, plus the Tenancy rows added to the system-design 9.3 registry in the same change (Slice 6 Tenancy tests first). Scope: `tenancy`.
15. Mark Stage 4 done in the status table. Scope: `docs`.

Tasks 11 through 14 are independent of each other and can merge in any order once task 7 lands.

## Exit criteria

The stage exit line, "at-least-once delivery with idempotent consumption proven end to end against Redis and real PostgreSQL; the identity and Tenancy Actions record their events", expands to:

1. Recording an event inside a rolled-back transaction leaves no `outbox_events` or `outbox_deliveries` row; recording outside a transaction throws. Proven by Slice 1 tests in CI against real PostgreSQL.
2. A committed producing transaction results in each registered subscriber's handler running at least once, via a real Redis queue and Horizon worker, with the job carrying only the event ID.
3. Running the same delivery job twice, and racing two workers on one delivery, produces exactly one subscriber effect; the concurrency suite proves the affected-row-count guard.
4. A delivery stranded between commit and enqueue is re-enqueued by the sweeper after the grace window and never before; `processed` deliveries are never re-enqueued.
5. The ordered helper never applies same-aggregate events out of `sequence` order under parallel workers, and never processes an event younger than the stability window.
6. Replaying the outbox from sequence zero rebuilds a test projection identical to its incrementally built state, twice over.
7. Both new tables reject cross-tenant reads and writes under the app role in the isolation suite; the cross-tenant role reads both; the suite's tenant-scoped-table sweep covers them.
8. Invite user, assign role, register customer, tenant creation, and the resolved domain verification trigger over HTTP each record exactly one correctly shaped event with the request's correlation ID; failure paths record nothing; platform-scope events, `TenantCreated` included, carry the sentinel tenant; the system-design 9.3 registry gains its Tenancy rows.
9. No identity payload contains email or name fields (asserted by unit tests).
10. All six suites, Larastan, and Pint are green; no OpenAPI or generated TypeScript drift (nothing should change); the architecture suite confirms `Support/Outbox` touches no context models.
11. The status table in api-implementation-plan.md reflects the stage as done.

## Risks and open questions

- Cross-tenant role boundary. System-design 4.3 defines the cross-tenant role for platform administration with every use activity-logged. The worker bootstrap and sweeper use it as infrastructure thousands of times an hour; logging each use is noise, not audit value. Proposed reading: 4.3's audit requirement targets staff-initiated cross-tenant operations, and infrastructure reads by the queue system are exempt, with the role's grants on outbox tables limited to SELECT; this holds because all outbox writes, including the sweeper's `last_enqueued_at` updates, run in tenant-scoped transactions per the worker access pattern. If Stage 2 implemented the role with unconditional audit logging, this needs a deliberate carve-out, and the design doc should be amended to say so. Decide before task 7.
- Jobs carry only the event ID (system-design 9.2), which forces the cross-tenant read before tenant context is known. An alternative, putting `tenant_id` in the job payload, would let the worker scope immediately but diverges from the stated design and duplicates state into Redis. This plan follows the design as written; revisit only if the cross-tenant read proves problematic.
- Sequence identity versus RLS inserts. `GENERATED ALWAYS AS IDENTITY` assigns on insert under the app role with RLS enabled; the isolation harness must confirm the app role can insert (policy `WITH CHECK`) while being unable to read other tenants. A surprise here surfaces in task 3's isolation tests, which is why they run first.
- Stability and grace window defaults. Proposed defaults: stability window 5 seconds, sweeper grace 60 seconds, sweeper scheduled every minute. These are config, fake-clock tested, and tunable without migration; no production data exists to calibrate against yet.
- Delivery-row fan-out growth. One row per event per subscriber is unbounded until Stage 12 archival. Acceptable at current scale (ADR 004 explicitly defers broker-grade throughput); the partial pending index keeps the sweeper scan cheap regardless of table size.
- Ordered-helper deferral mechanics. Deferring via job release consumes Horizon attempts; a long-blocked predecessor could push successors into the failed-jobs table. Mitigation: released jobs use a backoff schedule sized against the sweeper interval, and the sweeper re-enqueues anything that falls through. The Slice 4 tests must cover exhaustion behavior; if it proves fragile, switch deferral to marking the delivery for sweeper pickup instead of job release before Stage 8b builds on it.
- `DomainVerified` trigger semantics are decided within Stage 2 (its plan owns the decision before that stage closes); task 14 attaches the producer to the settled Action and never inherits the open question. The payload contract fixed by the Stage 2 plan stays as written regardless of the answer.
- Stage 3 Action shapes are not yet built (Stage 3 has not started at time of writing). If Stage 3's `InviteUser` ends up creating the membership in a different Action than assumed, the producer attachment points in tasks 11 through 13 move with it; the payload contracts above stay fixed.
- Test-environment subscribers must never leak into production routing; the registry is environment-aware only through explicit test registration in test setup, never through config sniffing inside `app/`.
