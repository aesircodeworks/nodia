# Stage 11: Reporting and Exports

Implementation plan for Stage 11 of [api-implementation-plan.md](../api-implementation-plan.md). The Reporting context is the read side of the platform: pre-aggregated projections built from outbox events, dashboard read endpoints, and file exports, per [system-design.md](../system-design.md) sections 3.1 (Reporting context), 9.2 (reporting aggregates as an outbox consumer), and 17 (reporting reads run against replicas and pre-aggregated projections, never against the primary during sales). The conventions in [api-conventions.md](../api-conventions.md), [data-conventions.md](../data-conventions.md), and [event-conventions.md](../event-conventions.md) are binding throughout, as are ADRs [003](../decisions/003-postgres-rls-for-tenant-isolation.md) (RLS), [004](../decisions/004-transactional-outbox-with-redis-queues.md) (outbox), [005](../decisions/005-uuidv7-identifiers.md) (UUIDv7), [013](../decisions/013-laravel-data-for-dtos.md) (laravel-data), [014](../decisions/014-spatie-utility-packages.md) (query-builder, medialibrary), and [018](../decisions/018-integer-minor-units-for-money.md) (money).

## Scope and non-goals

Delivered by this stage:

- Three pre-aggregated read models in the Reporting context (system-design 3.2: `Reporting/Models` holds read models, `Reporting/Jobs` holds projectors per subscribed event type): daily sales per event and ticket type, per-event finance (gross, gateway fee, platform commission, tenant net, refunds, per system-design 7.3), and per-event attendance from check-in events.
- Projectors as outbox consumers, one per subscribed event type, idempotent by event ID through the Stage 4 delivery mechanism, with all projection mutations expressed as commutative atomic increments so unordered delivery and replay converge to the same state (system-design 9.2: Horizon workers provide no ordering guarantee).
- Dashboard read endpoints over the projections: daily sales, event finance, attendance, all capability-gated admin endpoints reading only Reporting-owned tables.
- `BuildExport` (system-design 3.2: `Reporting/Actions`) producing CSV files from cursor-paginated sources exposed as Actions by the owning contexts, stored through medialibrary on object storage, with a lifecycle endpoint surface: create, list, show, download via expiring URL.
- Rebuild tooling: a `reporting:rebuild` artisan command reconstructing any projection from the Stage 4 replay primitive, with a verify mode proving the replayed state matches the incrementally built state (the master plan's rebuild-equivalence requirement).
- The `reports.view` and `reports.export` capabilities added to the global role templates (system-design 5.3; Finance and Owner templates gain both, Event Manager gains `reports.view`).

Explicitly deferred:

- Retention windows for export files and outbox archival: Stage 12 (system-design 14.3, 9.1).
- The GDPR and LGPD data subject export: Stage 12; it is a compliance flow owned by Identity, not a reporting export (system-design 14.3).
- Support-safe, audited operational commands beyond `reporting:rebuild` (replay failed deliveries, reconcile payments): Stage 12.
- Platform-level cross-tenant aggregate analytics (system-design 4.3): out of scope for the entire plan; all Stage 11 read models and endpoints are tenant-scoped. PostHog product analytics are a frontend concern (system-design 15.4).
- Read replica routing: the endpoints read only projection tables, so pointing reads at a replica is deployment configuration (system-design 17, 16.3); no replica is provisioned in this stage and no code may assume one.
- Time-series attendance (scan rate over time) and any dashboard depth beyond the three read models; add under product pressure per roadmap Post-MVP item 8.
- Queue analytics for the waiting room (roadmap Post-MVP item 7).

## Dependencies

Required from earlier stages:

- Stage 1: RFC 9457 problem handler and code registry, `Support/Money` with the `{amount, currency}` wire transformers, contract suite wiring, Isolation and Concurrency harnesses against real PostgreSQL, fake clock.
- Stage 2: `tenants`, the RLS bootstrap and per-table policy pattern, the cross-tenant worker bootstrap pattern from Stage 4's worker access section.
- Stage 3: staff authentication, `X-Tenant-Id` membership validation, capability-based policies (the two new capabilities slot into the existing RBAC), activity log for export creation.
- Stage 4: the entire outbox machinery: delivery jobs carrying only the event ID, `outbox_deliveries` conditional processed transition (the idempotence mechanism fixed there), the duplicate-delivery Pest fixture, and the replay primitive that `reporting:rebuild` wraps.
- Stage 5a: `events` and `ticket_types` exist as aggregates the read models reference by ID; catalog Actions to resolve names for export rows.
- Stage 7: `OrderCreated` and `TicketIssued` events and the Orders Actions the sales projector and the orders and tickets export sources call.
- Stage 8a and 8b: `PaymentConfirmed` carrying `amount` in its payload with `fee_amount` and `commission_amount` persisted on the payment row in the same confirmation transaction; `RefundCompleted` carrying its full money breakdown (`amount`, `commission_amount`, `commission_policy`) in the payload; and `TicketRefunded`, whose event class Stage 7 defines but whose producer (the Orders `MarkTicketsRefunded` Action) lands in Stage 8b, so the `ProjectDailySales` refund path in task 5 is gated on Stage 8b merged, not just Stage 7. The finance projector reads amounts from these payloads and the payment row facts through a Payments Action over `payments`; it never reads `ledger_entries`, which are built asynchronously by the Stage 8b ledger projection consuming the same events with no ordering guarantee across consumers (system-design 9.2).
- Stage 9: `TicketCheckedIn` and `DuplicateScanDetected` events for the attendance projection. The master plan allows Stages 9, 10, and 11 to run in parallel once Stage 8 merges; if this stage starts before Stage 9 lands, the attendance slice (slices 5 and 6, tasks 10 through 12) is gated on Stage 9's events and everything else proceeds.

Consumed by later stages and consumers:

- Stage 12's security sweep asserts isolation and contract coverage over this stage's endpoints; its retention work operates on the export files this stage accumulates; its smoke suite may assert dashboard figures after the purchase flow.
- The admin portal consumes the dashboard and export endpoints through `packages/api-client` types generated from this stage's Data classes.

## Data model

All tables are tenant-scoped: non-null `tenant_id`, UUIDv7 `id` via `HasUuids`, UTC timestamps, and a single-table RLS policy comparing `tenant_id` to `current_setting('app.tenant_id')` created in the same migration as the table (data-conventions; ADR 003). All money columns are integer minor units in `*_amount` columns paired with a `currency` column on the same row; per system-design 12 the currency is constrained in practice to the tenant's settlement currency, but the column is stored per row regardless. Projection tables use the explicit `report_` prefix so read models are visually distinct from source-of-truth tables; models set `$table` explicitly.

### report_daily_sales

One row per tenant, event, ticket type, and UTC calendar day with sales activity.

| Column | Type | Notes |
| --- | --- | --- |
| id | uuid, PK | UUIDv7 |
| tenant_id | uuid, not null | |
| event_id | uuid, not null | no FK across context boundaries is implied by architecture rules, but the FK constraint itself is a database concern and is allowed; index required |
| ticket_type_id | uuid, not null | |
| sales_date | date, not null | UTC calendar day of `occurred_at` (see risks) |
| tickets_issued_count | integer, not null, default 0 | |
| tickets_refunded_count | integer, not null, default 0 | |
| gross_amount | bigint, not null, default 0 | face value of issued tickets, minor units; see the money semantics note |
| refunded_amount | bigint, not null, default 0 | face value of refunded tickets; see the money semantics note |
| currency | string, not null | |
| created_at, updated_at | timestamps | |

Constraints and indexes: unique on `(tenant_id, event_id, ticket_type_id, sales_date)` (the upsert conflict target); index on `(tenant_id, sales_date)` for date-range dashboard queries. RLS policy in the same migration.

Money semantics: both money columns are pre-discount face value, the ticket type's list price at issue time, allocated per ticket. Order-level promo discounts stay on the order (system-design 8.3: `discount_amount` is an order column) and are not distributed across tickets, so daily-sales gross is intentionally distinct from the finance projection's gross, which reflects the actual charge; the Data class documentation and the admin portal must label it as face value. `refunded_amount` follows the same rule, the face value of each voided ticket, because refund money is payment-level and no per-ticket refunded amount exists anywhere in the system. Because neither the `TicketIssued` payload (no price) nor the `TicketRefunded` payload (`ticket_id`, `order_id`, `refund_id` only) carries these facts, the projector resolves ticket ID to `event_id`, `ticket_type_id`, and list price at issue through a bulk lookup Orders Action that task 5 requires.

All writes are `INSERT ... ON CONFLICT DO UPDATE` with additive increments evaluated in the statement (`gross_amount = report_daily_sales.gross_amount + excluded.gross_amount`), never read-then-write, so parallel projector workers cannot lose updates.

### report_event_finance

One row per tenant and event, mirroring the ledger's per-event totals (system-design 7.3: gross charge, gateway fee, platform commission, tenant net).

| Column | Type | Notes |
| --- | --- | --- |
| id | uuid, PK | UUIDv7 |
| tenant_id | uuid, not null | |
| event_id | uuid, not null, unique with tenant_id | |
| orders_paid_count | integer, not null, default 0 | |
| refunds_count | integer, not null, default 0 | |
| gross_amount | bigint, not null, default 0 | |
| gateway_fee_amount | bigint, not null, default 0 | |
| platform_commission_amount | bigint, not null, default 0 | |
| tenant_net_amount | bigint, not null, default 0 | |
| refunded_amount | bigint, not null, default 0 | |
| currency | string, not null | |
| created_at, updated_at | timestamps | |

Constraints and indexes: unique on `(tenant_id, event_id)`. Same upsert-with-increments write pattern, RLS policy in the same migration. Payment rows take gross from the `PaymentConfirmed` payload and fee and commission from the payment row facts (`fee_amount`, `commission_amount`, persisted at confirmation time per Stage 8b), with net as gross minus fee minus commission. Refund rows apply signed deltas to the commission and net columns from the `RefundCompleted` payload fields (`amount`, `commission_amount`, `commission_policy`), whose commission math was resolved at refund creation (Stage 8b); the projector applies recorded facts, it never recomputes commission from mutable tenant configuration and it never reads `ledger_entries`.

### report_event_attendance

One row per tenant, event, and ticket type.

| Column | Type | Notes |
| --- | --- | --- |
| id | uuid, PK | UUIDv7 |
| tenant_id | uuid, not null | |
| event_id | uuid, not null | |
| ticket_type_id | uuid, not null | |
| checked_in_count | integer, not null, default 0 | |
| duplicate_scan_count | integer, not null, default 0 | |
| first_scan_at | timestamp, nullable | monotonic min, set via `LEAST` in the upsert |
| last_scan_at | timestamp, nullable | monotonic max, set via `GREATEST` in the upsert |
| created_at, updated_at | timestamps | |

Constraints and indexes: unique on `(tenant_id, event_id, ticket_type_id)`. No money columns. Same upsert pattern, RLS policy in the same migration. `LEAST` and `GREATEST` keep the timestamp columns commutative so replay order cannot change them.

### exports

The export lifecycle record. The generated file is a medialibrary attachment on this model (data-conventions: file attachments go through medialibrary's `media` table, no bespoke path columns).

| Column | Type | Notes |
| --- | --- | --- |
| id | uuid, PK | UUIDv7 |
| tenant_id | uuid, not null | |
| type | string, not null | enum-backed: `orders`, `tickets`, `ledger_entries`, `check_ins`; creation accepts only types with a registered `ExportSource` (task 16) |
| status | string, not null | enum-backed: `pending`, `processing`, `completed`, `failed` |
| parameters | jsonb, not null | validated per type: `event_id`, `from`, `to` |
| requested_by_user_id | uuid, not null | the staff user; export creation is activity-logged |
| row_count | integer, nullable | set on completion |
| completed_at | timestamp, nullable | |
| failure_code | string, nullable | stable code surfaced on the resource, never free text alone |
| created_at, updated_at | timestamps | |

Constraints and indexes: index on `(tenant_id, created_at)` for the cursor-paginated list. RLS policy in the same migration.

The `pending` to `processing` claim guards the exactly-one-worker invariant, so it is a conditional UPDATE (`SET status = 'processing' WHERE id = ? AND status = 'pending'`) checked by affected-row count; zero rows means another worker owns the export and the job exits cleanly. `processing` to `completed` and `processing` to `failed` follow the same pattern. Read-then-write transitions are forbidden here (data-conventions).

## Domain events

### Produced

None. Reporting is a pure consumer; it records no domain events and the system-design 9.3 registry is unchanged by this stage. If a future need arises (for example `ExportCompleted` driving a notification), it is added to the registry in that change, not now.

### Consumed

All consumption uses the Stage 4 mechanism verbatim: queue jobs carry only the event ID, the worker bootstrap loads the envelope on the cross-tenant connection then opens a tenant-scoped transaction from the envelope's `tenant_id`, the projection upsert and the `outbox_deliveries` conditional `pending` to `processed` UPDATE commit in one transaction, so duplicate delivery causes exactly one effect (event-conventions: duplicate delivery is normal, not an error). Projectors never touch another context's tables; where a payload lacks a fact the projection needs (ticket price and type, event ID for a payment, the payment's fee and commission), the projector calls the owning context's Actions (event-conventions: consumers needing more load it through the owning context's Actions). No projector reads `ledger_entries`: the ledger is itself an outbox projection of `PaymentConfirmed` and `RefundCompleted`, and system-design 9.2 gives no ordering guarantee across consumers, so a projector running first would find zero ledger rows and bake wrong figures in while marking its delivery processed.

No projector uses the ordered-consumption helper: every projection mutation is a commutative increment or a `LEAST`/`GREATEST` bound, so cross-event ordering cannot change the converged state. This is a deliberate design constraint on all three projectors and is asserted by the rebuild-equivalence tests, which replay in sequence order and must match state built in arbitrary delivery order.

| Event | Owner | Projector | Effect |
| --- | --- | --- | --- |
| TicketIssued | Orders | ProjectDailySales | increment `tickets_issued_count` and `gross_amount` (face value via the Orders bulk lookup Action) for the ticket's event, ticket type, and UTC day |
| TicketRefunded | Orders | ProjectDailySales | increment `tickets_refunded_count` and `refunded_amount`, resolving event, ticket type, and face value through the Orders bulk lookup Action (the payload carries only `ticket_id`, `order_id`, `refund_id`) |
| PaymentConfirmed | Payments | ProjectEventFinance | increment `orders_paid_count`; gross from the payload `amount`, fee and commission from the payment row facts via a Payments Action, net as gross minus fee minus commission |
| RefundCompleted | Payments | ProjectEventFinance | increment `refunds_count` and `refunded_amount`; signed commission and net deltas from the payload `amount`, `commission_amount`, and `commission_policy` |
| TicketCheckedIn | CheckIn | ProjectEventAttendance | increment `checked_in_count`, update `first_scan_at` and `last_scan_at` |
| DuplicateScanDetected | CheckIn | ProjectEventAttendance | increment `duplicate_scan_count` |

System-design 9.2 already routes `TicketIssued`, `PaymentConfirmed`, `RefundCompleted`, and the check-in events to reporting. Subscribing reporting to `TicketRefunded` is an addition to the static in-code routing only; `TicketRefunded` is already in the 9.3 registry, so no registry change is needed. `OrderCreated` is deliberately not consumed: pending orders are not sales.

## Endpoints

All endpoints are admin endpoints: bearer staff JWT, `X-Tenant-Id` validated against memberships (api-conventions), capability-gated through Reporting policies. Dashboard endpoints require `reports.view`; every `/v1/exports` route requires `reports.export` because export files contain customer PII. List endpoints use spatie/laravel-query-builder with explicit allowlists; unknown filter, sort, or include values are rejected with the standard validation problem code from the Stage 1 registry (api-conventions). All money on the wire is `{amount, currency}` via the `Support/Money` transformers. Auth and tenant-context failures use the problem codes established in Stages 2 and 3; only codes new in this stage are listed. Every endpoint ships its OpenAPI path in `docs/openapi/openapi.yaml` in the same change, and regenerated TypeScript lands in `packages/api-client` without drift.

| Method and path | Purpose | Request | Response | New error codes |
| --- | --- | --- | --- | --- |
| GET `/v1/reports/daily-sales` | daily sales rows | query: `filter[event_id]`, `filter[ticket_type_id]`, `filter[from]`, `filter[to]` (dates), `sort` allowlist `sales_date`, `-sales_date` | cursor-paginated envelope of `DailySalesData` (deterministic order: `sales_date`, `id`) | none |
| GET `/v1/reports/event-finance` | per-event finance summaries | query: `filter[event_id]` | cursor-paginated envelope of `EventFinanceData` | none |
| GET `/v1/reports/attendance` | per-event attendance | query: `filter[event_id]` | cursor-paginated envelope of `EventAttendanceData` | none |
| POST `/v1/exports` | request an export | `CreateExportData`: `type`, `parameters` (`event_id` nullable, `from` and `to` nullable dates) | 202 with `ExportData` | validation failures use the standard registry code |
| GET `/v1/exports` | list exports | query: `filter[type]`, `filter[status]`, `sort` allowlist `-created_at`, `created_at` | cursor-paginated envelope of `ExportData` | none |
| GET `/v1/exports/{export}` | export status | | `ExportData` | 404 (standard) when unknown or cross-tenant, indistinguishable under RLS |
| GET `/v1/exports/{export}/download` | fetch the file | | `ExportDownloadData`: `url` (expiring signed object-storage URL), `expires_at` | 409 `export_not_ready` while `pending` or `processing`; 409 `export_failed` when `failed` |

laravel-data objects (all in `app/Reporting/Data`, snake_case on the wire, exported to TypeScript):

- `DailySalesData`: `event_id`, `ticket_type_id`, `sales_date`, `tickets_issued_count`, `tickets_refunded_count`, `gross` (money), `refunded` (money).
- `EventFinanceData`: `event_id`, `orders_paid_count`, `refunds_count`, `gross`, `gateway_fees`, `platform_commission`, `tenant_net`, `refunded` (all money).
- `EventAttendanceData`: `event_id`, `ticket_type_id`, `checked_in_count`, `duplicate_scan_count`, `first_scan_at`, `last_scan_at`.
- `CreateExportData` (request), `ExportParametersData`, `ExportData` (`id`, `type`, `status`, `parameters`, `row_count`, `failure_code`, `completed_at`, `created_at`), `ExportDownloadData`.

POST `/v1/exports` does not require an `Idempotency-Key`: the header is mandated only for payment and refund creation (api-conventions), and a duplicate export is wasteful, not incorrect. Export creation is written to the activity log with the requesting user as causer (system-design 14.2: all staff actions).

`reporting:rebuild {projection} {--tenant=} {--verify}` is an artisan command, not an endpoint: `--verify` replays into in-memory aggregates and reports drift against the live table without writing; without it, the command deletes the projection's rows for the given tenant (or all tenants) and replays inside a transaction. It takes a per-projection advisory lock that the projector jobs also take shared, so a rebuild cannot interleave with live deliveries (see risks).

## TDD sequencing

Every slice follows the master plan's double loop: outside feature test first, contract next, unit tests driving the inside, then green, refactor, `composer types:generate`, commit. The two test-first mandates that name this stage directly: every projector starts with a failing duplicate-delivery test (mandate three of the master plan Method section), and the stage's own mandate, projection idempotence under duplicate delivery plus rebuild equivalence. Every new table starts with a failing isolation test.

### Slice 1: sales projection

Failing tests first:

- Isolation: tenant A cannot read or write tenant B's `report_daily_sales` rows under the app role; the tenant-scoped-table sweep covers the new table.
- Feature (Redis and real PostgreSQL): a `TicketIssued` event delivered through the real pipeline produces exactly one row with correct counts, amounts, currency, and UTC `sales_date`; a second ticket on the same day increments the same row; `TicketRefunded` increments refund columns.
- Feature: duplicate delivery of the same event ID causes exactly one increment (the mandated test, using the Stage 4 fixture).
- Concurrency: N parallel workers projecting N distinct events for the same `(event, ticket_type, day)` cell converge to exactly N increments; no lost updates through the upsert.
- Unit: UTC day bucketing from `occurred_at` via the fake clock, including a boundary instant; payload-to-increment mapping; money stays in minor units end to end.

### Slice 2: daily sales endpoint

Failing tests first:

- Feature: 200 with the paginator envelope and correct wire shape (money as `{amount, currency}`, snake_case, ISO 8601); filter matrix for event, ticket type, and date range; unknown filter and sort values rejected with the standard validation code; 401 without a token; 403 without `reports.view`; wrong or missing `X-Tenant-Id` per the Stage 3 codes.
- Contract: response conforms to the new OpenAPI path; drift gate green after `types:generate`.
- Isolation: the endpoint returns only the acting tenant's rows with two tenants seeded.

### Slice 3: finance projection

Failing tests first:

- Isolation: `report_event_finance` cross-tenant denial.
- Feature: `PaymentConfirmed` through the pipeline produces one finance row whose four amount columns equal the payload `amount` plus the payment row's `fee_amount` and `commission_amount` loaded through the Payments Action; `RefundCompleted` applies the signed deltas from its payload; commission-retained versus commission-returned refunds (the Stage 8b per-tenant policy flag) both project correctly because the amounts were fixed when the refund was created.
- Feature: the projector converges to the correct figures when it runs before the Stage 8b ledger projection has processed the same event, proving it depends on no `ledger_entries` rows (system-design 9.2: no ordering across consumers).
- Feature: duplicate delivery of each event type causes exactly one effect.
- Concurrency: N parallel workers projecting N distinct `PaymentConfirmed` events for the same event converge to exactly N increments across all four amount columns; no lost updates on the single contended row.
- Unit: the projector performs no commission arithmetic of its own; a balance assertion that `gross_amount - gateway_fee_amount - platform_commission_amount = tenant_net_amount` holds after every simulated sequence.

### Slice 4: finance endpoint

Same pattern as slice 2 for GET `/v1/reports/event-finance`: feature (shape, filters, authz), contract, isolation.

### Slice 5: attendance projection (gated on Stage 9)

Failing tests first:

- Isolation: `report_event_attendance` cross-tenant denial.
- Feature: `TicketCheckedIn` increments the count and sets both scan timestamps; `DuplicateScanDetected` increments only the duplicate count; out-of-order delivery of two scans leaves `first_scan_at` at the earlier and `last_scan_at` at the later instant.
- Feature: duplicate delivery causes exactly one increment.
- Concurrency: parallel scans for one event and ticket type lose no counts and keep the timestamp bounds correct.

### Slice 6: attendance endpoint

Same pattern as slice 2 for GET `/v1/reports/attendance`.

### Slice 7: rebuild tooling

Failing tests first:

- Feature (the stage's rebuild-equivalence mandate): seed a mixed event stream (issues, refunds, payments, refunds completed, check-ins) across two tenants, process it incrementally in shuffled delivery order, run `reporting:rebuild` for each projection, and assert the rebuilt tables are row-for-row identical to a snapshot of the incremental state; run the rebuild twice and assert the same state again.
- Feature: `--verify` reports zero drift on a healthy projection and nonzero drift after a row is manually corrupted, without writing.
- Feature: `--tenant=` rebuilds only that tenant's rows; the other tenant's rows are untouched (and RLS-protected).
- Feature: a projector job and a rebuild racing on the same projection serialize through the advisory lock; no double-applied event.
- Unit: rebuild reads through the Stage 4 replay primitive in sequence order and respects the stability window.

### Slice 8: exports

Failing tests first:

- Isolation: `exports` cross-tenant denial, including that tenant A cannot fetch or download tenant B's export by ID (404).
- Feature: POST returns 202 with a `pending` `ExportData` and writes the activity log entry; invalid `type` or parameters fail with the standard validation code and create nothing, including a `type` whose `ExportSource` is not yet registered (`check_ins` before task 11 lands), so no export can be created that has no source to build it; GET list paginates by cursor with the filter allowlist; GET show reflects status progression on the fake clock.
- Feature (end to end): a completed `orders` export contains exactly the expected CSV rows for the tenant and parameter window, built through the owning context's cursor-paginated source Action, with money rendered as minor units plus currency columns, never floats; `row_count` and `completed_at` are set.
- Feature: download of a completed export returns a URL that serves the file and an `expires_at`; download while `pending` or `processing` returns 409 `export_not_ready`; download of a `failed` export returns 409 `export_failed`; a failed source marks the export `failed` with a stable `failure_code`.
- Concurrency: two workers claiming one `pending` export admit exactly one through the conditional UPDATE; the loser exits with no side effects and no duplicate file.
- Contract: all four export endpoints conform to their OpenAPI paths.
- Unit: each `ExportSource` maps Data objects to CSV columns; the writer streams in cursor pages and holds at most one page in memory.
- Architecture: Reporting imports no other context's models; export sources call Actions only.

## Task breakdown

Ordered; each task lands green through the full loop and is independently mergeable unless noted. Commit scope: `reporting`, except where noted.

1. Mark Stage 11 in progress in the api-implementation-plan status table. Scope: `docs`.
2. Add `reports.view` and `reports.export` to the global role template seeds and capability registry. Scope: `identity`. Mergeable alone.
3. Reporting context scaffolding: `ReportingServiceProvider`, route group, policy stubs, architecture-suite coverage of the new context. Small, merged with task 4 if trivial.
4. `report_daily_sales` migration with unique constraint, indexes, and RLS policy, plus model and isolation tests (slice 1 isolation tests first).
5. `ProjectDailySales` projector subscribed to `TicketIssued` and `TicketRefunded`, upsert-with-increments write path (slice 1 feature, duplicate-delivery, concurrency, and unit tests first). Requires the Orders bulk lookup Action (ticket ID to `event_id`, `ticket_type_id`, list price at issue; scope `orders`); the `TicketRefunded` path is gated on Stage 8b merged, which ships that event's producer. Adds the reporting subscriptions to the static registry.
6. GET `/v1/reports/daily-sales`: Data classes, controller, policy, OpenAPI path, generated TypeScript (slice 2 tests first).
7. `report_event_finance` migration with RLS and isolation tests (slice 3 isolation tests first).
8. `ProjectEventFinance` subscribed to `PaymentConfirmed` and `RefundCompleted`, payment row facts loaded through the Payments Action, refund deltas taken from the payload (slice 3 tests first, including the concurrency and runs-before-the-ledger-projection tests).
9. GET `/v1/reports/event-finance` (slice 4 tests first).
10. `report_event_attendance` migration with RLS and isolation tests (slice 5 isolation tests first). Gated on Stage 9 merged.
11. `ProjectEventAttendance` subscribed to `TicketCheckedIn` and `DuplicateScanDetected` (slice 5 tests first). Gated on Stage 9.
12. GET `/v1/reports/attendance` (slice 6 tests first). Gated on Stage 9.
13. `reporting:rebuild` command with `--tenant` and `--verify`, advisory lock shared with projector jobs, rebuild-equivalence tests (slice 7 tests first). Requires tasks 5 and 8; extends to attendance when task 11 lands.
14. `exports` migration with RLS policy, model, type and status enums, conditional claim transition, and isolation tests (slice 8 isolation and concurrency-claim tests first).
15. `ExportSource` interface, CSV writer, `BuildExport` action and job, and the `orders`, `tickets`, and `ledger_entries` sources calling Orders and Payments Actions (slice 8 end-to-end and unit tests first). The `check_ins` source lands with or after task 11.
16. Export endpoints: POST create (202), GET list, GET show, GET download with expiring URL and the `export_not_ready` and `export_failed` codes registered in the problem-code registry (slice 8 feature and contract tests first). POST validates `type` against the registered `ExportSource`s, not the raw enum, so `check_ins` is rejected with the standard validation code until task 15's gated source lands; the OpenAPI description states the accepted types are deployment-dependent on registered sources.
17. Mark Stage 11 done in the status table; confirm the roadmap Implementation Status table needs no change (this plan tracks the API stage, the roadmap tracks product phases). Scope: `docs`.

Tasks 4 through 6, 7 through 9, and 10 through 12 are three independent verticals once tasks 2 and 3 land; they can proceed in parallel or in any order. Tasks 14 through 16 depend only on task 3 plus the source contexts and can proceed in parallel with the projection verticals.

## Exit criteria

The stage's goal line, "projections and the read surface for dashboards and finance", with its mandated tests, expands to:

1. Duplicate delivery of any subscribed event (all six types) causes exactly one projection effect, proven per projector with the Stage 4 fixture against Redis and real PostgreSQL.
2. Parallel projector workers targeting the same aggregate row lose no increments and keep `first_scan_at` and `last_scan_at` bounds correct; the concurrency suite proves the upsert-with-increments path.
3. For every projection, `reporting:rebuild` from outbox replay produces state row-for-row identical to the incrementally built state regardless of original delivery order, and rebuilding twice yields the same state; `--verify` detects a deliberately corrupted row.
4. A rebuild racing live projector jobs serializes through the advisory lock with no double-applied or skipped event.
5. The finance projection satisfies `gross - gateway_fee - platform_commission = tenant_net` after every simulated purchase and refund sequence, under both commission policies, without performing commission math itself.
6. All three dashboard endpoints return cursor-paginated envelopes with money as `{amount, currency}` objects, reject unknown filters and sorts, deny without `reports.view`, and are contract-checked against `docs/openapi/openapi.yaml`.
7. The export lifecycle runs end to end: 202 on create, exactly one worker claims each export via affected-row count, sources iterate by cursor pagination through owning-context Actions only, the completed CSV matches the parameter window, download returns an expiring URL, `export_not_ready` and `export_failed` render as RFC 9457 problems with those stable codes, and creation is activity-logged.
8. All four new tables reject cross-tenant reads and writes in the isolation suite, and the tenant-scoped-table sweep covers them; export fetch and download are cross-tenant-denied at the endpoint level.
9. `reports.view` and `reports.export` gate every route in this stage and appear in the correct global role templates, covered by the authorization matrix.
10. The architecture suite proves Reporting imports no other context's models and reaches other contexts through Actions and events only.
11. All six suites, Larastan, and Pint are green; generated TypeScript and the OpenAPI document are committed without drift.
12. The status table in api-implementation-plan.md reflects the stage as done.

## Risks and open questions

- Consumed payload shapes are owned by Stages 7, 8, and 9. This plan assumes payloads carry identifiers plus the facts of the event and resolves everything else through owning-context Actions (event-conventions), so the projectors are insulated from payload detail. The known gaps are already accounted for above: `TicketIssued` carries no price and `TicketRefunded` carries only `ticket_id`, `order_id`, `refund_id`, so task 5 requires the Orders bulk lookup Action first. Verify the six payloads against the merged stages at task 5, 8, and 11 start; for `TicketRefunded` the authoritative shape is Stage 8b, which ships its producer, so that check is against Stage 8b merged.
- UTC date bucketing. `sales_date` is the UTC day of `occurred_at`, while dashboards display in the event's timezone (ui-conventions), so an event-local day can straddle two buckets. Bucketing by event timezone would bake display logic into stored data and break when a tenant edits the timezone. Decision: store UTC buckets; the admin portal aggregates or annotates. Revisit only on real tenant complaints, and note that rebucketing is cheap because any scheme can be rebuilt from replay.
- Rebuild versus live deliveries. Delete-and-replay inside a transaction with a per-projection advisory lock is simple but blocks projector jobs for the duration of the replay. Fine at current volumes; if replay time grows, switch to building into a shadow state and swapping. The Stage 4 stability window also means a rebuild never sees the newest few seconds; the equivalence test must freeze the stream (fake clock past the window) before comparing.
- Deferral behavior under the advisory lock: projector jobs that cannot take the shared lock must release with backoff, not fail; this inherits the Stage 4 open question about job-release attempts and the sweeper backstop. Reuse whatever resolution Stage 4 landed on.
- Adding reporting subscriptions to already-flowing events (`TicketIssued` and the rest ship in Stages 7 through 9, before this stage) means events recorded before the subscriber existed have no `outbox_deliveries` rows for it. The projections therefore start empty and must be backfilled by `reporting:rebuild` at deploy time; the runbook note for task 17 must say so, and the rebuild-equivalence test already proves the backfill is correct.
- Export PII. Orders and tickets exports contain customer names and emails; files sit on object storage until Stage 12 retention lands. Mitigations now: `reports.export` as a distinct capability, expiring download URLs, activity-logged creation, and exports of anonymized customers naturally reflect the anonymized values because sources read current state. An anonymization request after an export was generated cannot reach into the existing file; that residual risk is accepted until Stage 12's retention window bounds it, and the window must be documented there.
- Export size and worker limits. A large tenant's orders export could exceed job timeouts. The writer streams page by page (bounded memory), but runtime is unbounded; if a real export approaches Horizon's timeout, chunk the job by cursor position rather than raising the timeout. No speculative chunking now.
- `filter[from]` and `filter[to]` semantics (inclusive dates, maximum range) need fixing in the OpenAPI description at task 6; an unbounded range over years of daily rows is still cursor-paginated, so there is no correctness risk, only ergonomics.
- Read replicas (system-design 17) are not provisioned; if Stage 12 load tests show dashboard reads pressuring the primary during sales, replica routing is configuration on the read connection, and this stage must not have hardcoded any connection assumptions (asserted informally by code review, not a test).
