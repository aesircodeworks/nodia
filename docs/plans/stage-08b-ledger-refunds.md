# Stage 8b: Ledger and Refunds

Implementation plan for Stage 8b of [api-implementation-plan.md](../api-implementation-plan.md). The stage delivers the append-only double-entry ledger projected from outbox events and the full and partial refund flow, entirely against the `FakeGateway` adapter from Stage 8a. All work lives in the Payments bounded context (`app/Payments`), with Orders context additions for ticket voiding and the new refund-state transitions, and a small Support/Outbox extension for payload-keyed ordered consumption. Every design claim below cites its system-design.md section.

## Scope and non-goals

In scope:

- Append-only `ledger_entries` as the source of truth for balances, with the four-leg breakdown per paid order: gross charge, gateway fee, platform commission, tenant net (system-design 7.3).
- The ledger projection as an outbox consumer of `PaymentConfirmed` and `RefundCompleted`, ordered per payment via the Stage 4 ordered-consumption helper extended in this stage with a subscriber-supplied ordering key (see Dependencies), idempotent by event ID, rebuildable by replay (system-design 9.1).
- Per-tenant commission configuration and the per-tenant refund commission policy flag, returned or retained (system-design 7.3).
- Refunds, full and partial: staff-initiated creation with `Idempotency-Key` semantics (api-conventions, Idempotency and Correlation), asynchronous execution through the `GatewayAdapter::refund` capability (system-design 7.2) with the retry budget from system-design 13 (3 attempts at 1s, 5s, 15s, idempotency keys passed to the gateway), completion driven by gateway webhook, backstopped by a refund reconciliation sweeper that queries the adapter for `processing` refunds past a grace window and applies the same conditional transitions (system-design 13: sweepers backstop every path so no single missed message strands state).
- Order state machine refund arcs, all of them: `paid` to `partially_refunded` and `paid` to `refunded` (system-design 7.1), plus the follow-on transitions from `partially_refunded` that the 7.1 diagram omits, whose design update ships in the same change. Stage 7 ships the refund statuses in the `OrderStatus` enum but no refund transition Actions, and its state-machine table test covers only the non-refund states, so this stage's Actions and table-test extension are additive. The transition Actions live in the Orders context; Payments invokes them (system-design 3.1 boundary rule: a context never writes another context's tables).
- Ticket voiding on refund through an Orders context Action recording `TicketRefunded` (system-design 9.3 group 4).
- Read surface: refund show and admin list, cursor-paginated ledger entry list, and a per-currency ledger balance summary (api-conventions lists ledger entries among the collections that MUST use cursor pagination).
- Capability gating and MFA enforcement for the refund capability (system-design 14.2: MFA mandatory for financially privileged tenant roles; all financial mutations activity-logged).

Non-goals, explicitly deferred:

- Payouts and sub-merchant onboarding: Stage 8c. It consumes the ledger balances this stage produces (system-design 7.3: payouts reconcile against ledger balances).
- Real gateway adapter and its refund specifics: Stage 8d, gated on the launch gateway ADR.
- Finance dashboards, per-event finance read models, and exports: Stage 11. This stage ships raw ledger reads only.
- Customer self-service refund requests: not scheduled anywhere; refunds are staff-initiated. Buyers observe outcomes through the Stage 7 order status endpoints.
- Chargebacks and disputes: not in the system design's event registry or data model; noted as a risk (ADR 006 accepts refund and chargeback liability) but out of scope until designed.
- Inventory release on refund (returning refunded tickets to sale): not specified by the design; out of scope, recorded as an open question.
- Outbox archival and financial record retention windows: Stage 12.

## Dependencies

Must already exist:

- Stage 1: problem+json handler with the error code registry, `Support/Money` value object and `{amount, currency}` wire shape (ADR 018), contract suite wiring, time control.
- Stage 2: `tenants` table and RLS policy pattern, tenant resolution middleware, platform-scope role.
- Stage 3: capabilities and Policies (a refund capability slots into the Stage 3 registry), MFA enforcement mechanism (api-implementation-plan Stage 3 explicitly builds it for the refund and payout capabilities arriving here), activity log.
- Stage 4: outbox recording, dispatcher, `outbox_deliveries`, the ordered-consumption helper (the master plan names the ledger projection as its first real user), and the replay primitive. The helper as delivered orders strictly per envelope aggregate (`aggregate_type`, `aggregate_id`), and `PaymentConfirmed` (aggregate `payment`) and `RefundCompleted` (aggregate `refund`) carry different aggregates, so this stage extends the helper to accept a subscriber-supplied ordering key derived from the payload, defaulting to the envelope aggregate (task 5).
- Stage 7: order state machine with conditional-UPDATE transitions, tickets, `TicketRefunded` slot in the Orders event set.
- Stage 8a: `payments` table with `idempotency_key` and `gateway_reference` (system-design 8.3, 7.5), `GatewayAdapter` interface including `refund` and capability flags (system-design 7.2), `FakeGateway` with scriptable scenarios and webhook emitter, raw webhook persistence unique by gateway event ID with always-2xx-after-persist ingestion (system-design 7.4), `PaymentConfirmed` and `PaymentFailed` normalization, and the `Idempotency-Key` request semantics for payment-creating POSTs.

Coordination item with Stage 8a, resolved in the 8a plan: the `PaymentConfirmed` payload carries the gateway fee as `{amount, currency}` and the 8a confirmation Action persists `fee_amount` and `commission_amount` on the payment row (commission through a resolver that returns zero until this stage lands the tenant commission configuration), so this stage's projection reads the money breakdown from row facts. Task 1 verifies the fields arrived as specified.

Later stages consume from this stage:

- Stage 8c: ledger balances for payout mirroring and reconciliation; the tenant commission configuration.
- Stage 11: `RefundCompleted` and `TicketRefunded` feed reporting projections (system-design 9.2 routing).
- Stage 12: the end-to-end smoke suite includes the refund path; operational reconcile commands touch refunds.

## Data model

All tables follow data-conventions: UUIDv7 primary keys via `HasUuids`, non-null `tenant_id`, snake_case, UTC timestamps, integer minor-unit money columns each paired with `currency` on the same row (`refunds` and `ledger_entries` carry their principal value as bare `amount` under the data-conventions exception for rows that are themselves single monetary facts; secondary values like `commission_amount` keep the `*_amount` form), and a single-table RLS policy comparing `tenant_id` to `current_setting('app.tenant_id')` shipped in the same migration that creates the table.

### `refunds` (new table, Payments context)

Per system-design 8.3 `REFUND`, extended with the operational columns the execution path requires (system-design 7.5 and 13 idempotency pattern):

| Column | Type | Notes |
| --- | --- | --- |
| `id` | uuid | PK, UUIDv7 |
| `tenant_id` | uuid | non-null |
| `payment_id` | uuid | FK to `payments` |
| `amount` | bigint | minor units |
| `currency` | char(3) | must equal the payment currency |
| `status` | string | enum: `pending`, `processing`, `completed`, `failed` |
| `reason` | string, nullable | free text |
| `ticket_ids` | jsonb, nullable | the ticket selection from the request, persisted so the asynchronous completion transaction knows which tickets to void |
| `commission_amount` | bigint | returned commission, derived proportionally from the payment's persisted `commission_amount` and capped by its un-returned remainder; 0 when the policy is retained |
| `idempotency_key` | string | from the request header; also passed to the gateway on execute and retry |
| `request_hash` | string | hash of the canonicalized request body, detects key reuse with a different body (the 8a `payments.request_hash` pattern) |
| `gateway_reference` | string, nullable | set on gateway acceptance |
| `failure_code` | string, nullable | normalized gateway failure |
| `created_at`, `updated_at` | timestamptz | |

Constraints and indexes: unique `(tenant_id, idempotency_key)` (the replay anchor); index `payment_id`; check `amount > 0`. RLS policy in the same migration. Replay mirrors 8a: a unique violation on the key loads the original row, compares `request_hash`, then replays the original response or returns the mismatch problem.

Status transitions are conditional UPDATEs checked by affected-row count (data-conventions, Status Columns): `pending` to `processing` guards the executor against double dispatch; `processing` to `completed` and `processing` to `failed` guard duplicate webhook or reconciliation outcomes.

### `payments` alterations (new migration, additive)

- `refunded_amount` bigint not null default 0 and `refunded_commission_amount` bigint not null default 0. The refundable-amount invariant guard: reserving a refund is `UPDATE payments SET refunded_amount = refunded_amount + :amount, refunded_commission_amount = refunded_commission_amount + :commission WHERE id = :id AND refunded_amount + :amount <= amount AND refunded_commission_amount + :commission <= commission_amount`, checked by affected-row count. The commission reservation is what keeps the summed returned commission from ever exceeding the commission actually charged, regardless of later `commission_bps` changes or rounding across partials. A failed refund releases its reservation with the compensating conditional decrement. No read-then-write anywhere on this path.
- `fee_amount` and `commission_amount` ship with the 8a `payments` table and are persisted by the 8a confirmation Action when `PaymentConfirmed` is recorded (fee from the gateway; commission through 8a's resolver, which this stage's slice 2 wires to the tenant commission configuration at confirmation time). No new columns here, only the wiring. Persisting the breakdown as row facts is what makes ledger replay deterministic when tenant commission configuration later changes.

No RLS work needed: the policy shipped with the 8a `payments` migration and merged migrations are never edited.

### `ledger_entries` (new table, Payments context)

Per system-design 8.3 `LEDGER_ENTRY`, append-only per system-design 7.3 and data-conventions (Money): no UPDATE or DELETE, corrections are new entries.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | uuid | PK, UUIDv7 |
| `tenant_id` | uuid | non-null |
| `account` | string | enum: `gateway_receivable`, `gateway_fees`, `platform_commission`, `tenant_net` |
| `direction` | string | enum: `debit`, `credit` |
| `amount` | bigint | minor units, check `amount > 0` |
| `currency` | char(3) | |
| `reference_type` | string | `payment` or `refund` |
| `reference_id` | uuid | the payment or refund |
| `source_event_id` | uuid | the outbox event that produced this entry |
| `created_at`, `updated_at` | timestamptz | |

Constraints and indexes: unique `(source_event_id, account)` (idempotence anchor: one event produces at most one entry per account, so duplicate delivery and replay insert nothing new); index `(tenant_id, currency, account)` for balance sums; index `(reference_type, reference_id)`; index `created_at` supporting the deterministic cursor order.

Same migration also ships: the RLS policy, and a trigger raising an exception on UPDATE or DELETE, so append-only is a database guarantee rather than an application convention. Migrations never disable policies to pass (data-conventions, Migrations).

Ledger legs, all balanced per reference (sum of debits equals sum of credits per currency):

- `PaymentConfirmed` for gross G, fee F, commission C, net N where N = G - F - C: debit `gateway_receivable` G; credit `gateway_fees` F; credit `platform_commission` C; credit `tenant_net` N.
- `RefundCompleted` for refund amount R with commission policy `returned` (returned commission Rc, from `refunds.commission_amount`): credit `gateway_receivable` R; debit `tenant_net` R - Rc; debit `platform_commission` Rc. With policy `retained`: credit `gateway_receivable` R; debit `tenant_net` R. Refunds debit the tenant balance per system-design 7.3.

The tenant balance consumed by Stage 8c is the per-currency sum of `tenant_net` credits minus debits.

### `tenants` alterations (new migration, additive)

- `commission_bps` integer not null default 0: platform commission in basis points of gross. The design fixes that a commission exists (system-design 7.3) but not where the rate lives; a tenant column is the plan decision, flagged under open questions for per-event overrides.
- `refund_commission_policy` string not null default `retained`, enum `returned` | `retained` (system-design 7.3).

Exposed through the Stage 2 platform admin tenant endpoints (extend `TenantData` and the update request; regenerate types).

## Domain events

Envelope per event-conventions: `id` (UUIDv7), `sequence`, `type`, non-null `tenant_id`, `aggregate_type` and `aggregate_id`, `correlation_id`, `occurred_at`, `payload` as a snake_case laravel-data object; recorded in the same transaction as the state change, without exception.

Produced (Payments context, both already in the system-design 9.3 registry):

- `RefundInitiated`: aggregate `refund`/refund id. Payload: `refund_id`, `payment_id`, `order_id`, `amount` ({amount, currency}), `commission_amount` ({amount, currency}), `ticket_ids`, `reason`. Recorded in the transaction that inserts the refund row (including the persisted `ticket_ids` selection) and reserves `refunded_amount` and `refunded_commission_amount`.
- `RefundCompleted`: aggregate `refund`/refund id. Payload: everything in `RefundInitiated` plus `gateway_reference` and `commission_policy`. Recorded in the transaction that transitions the refund to `completed` and the order to its refunded status; the completion transaction reads `ticket_ids` from the refund row it owns to know which tickets to void. Carrying the money breakdown in the payload keeps downstream consumers (reporting, Stage 11) free of cross-context lookups (event-conventions: payloads carry the facts of the event).

Produced (Orders context):

- `TicketRefunded` (system-design 9.3 group 4): aggregate `ticket`/ticket id, one per voided ticket. Payload: `ticket_id`, `order_id`, `refund_id`. Recorded by the Orders Action that voids tickets; only the owning context records it (event-conventions, Naming and Registry).

No new event types are added, so the 9.3 registry is untouched. Refund failure is refund-row state plus an activity log entry, not a domain event; if Stage 11 needs a `RefundFailed` signal, adding it then is additive.

Consumed:

- Ledger projection consumes `PaymentConfirmed` and `RefundCompleted` (system-design 9.2 routing: both feed the ledger projection). It uses the Stage 4 ordered-consumption helper through the payload-derived ordering key extension (task 5), keyed on the `payment_id` available in both payloads, applying events in outbox `sequence` order per payment and deferring an event whose same-key predecessor is unprocessed (system-design 9.2). The helper's envelope-aggregate default cannot provide this because the two events carry different aggregates. Idempotence is layered: `outbox_deliveries` progress per event-conventions, plus the `(source_event_id, account)` unique index making the insert itself a no-op on duplicates. The projection reads breakdown facts from the payment and refund rows it owns (same context), never recomputing from mutable tenant configuration, so replay rebuild is deterministic.
- The refund executor consumes `RefundInitiated`: it calls `GatewayAdapter::refund` with the refund's idempotency key, retrying per the system-design 13 budget (3 attempts at 1s, 5s, 15s), then exhausts to the failed-jobs dead letter table. Idempotent by event ID; the `pending` to `processing` conditional UPDATE makes double execution structurally impossible even under duplicate delivery.
- The Payments completion path (webhook normalization from 8a, extended here for refund events) transitions the refund and invokes the Orders context's transition and ticket-voiding Actions; consumers never write another context's tables (event-conventions, Delivery and Consumption). A scheduled refund reconciliation sweeper backstops missed completion webhooks: it queries the adapter for `processing` refunds past a grace window and applies the same conditional transitions, idempotent against late webhooks by the same affected-row-count guards.

## Endpoints

All under `/v1`, snake_case JSON, staff-authenticated with `X-Tenant-Id` validated against memberships (api-conventions, Authentication and Tenant Context). Errors are RFC 9457 problem documents with stable `code` values. Every endpoint ships its OpenAPI fragment in the same slice (api-conventions: no endpoint ships without its contract merged) and regenerates TypeScript.

### POST /v1/payments/{payment}/refunds

Creates a refund for a confirmed payment. Requires the refund capability and an MFA-verified session; the mutation is activity-logged (system-design 14.2). `Idempotency-Key` header required; replays with the same key return the original result (api-conventions, Idempotency and Correlation).

Request `CreateRefundData`: `amount` (money object, nullable; null means the full remaining refundable amount), `reason` (nullable string), `ticket_ids` (nullable array of UUIDs; when present, those tickets are voided on completion; on a full refund all order tickets are voided regardless). The selection is persisted on the refund row at creation, so the asynchronous completion transaction and the idempotency replay both read it from the row.

Response 201 `RefundData`: `id`, `payment_id`, `order_id`, `status`, `amount`, `commission_amount`, `reason`, `gateway_reference`, `failure_code`, `created_at`. Status is `pending`; execution is asynchronous (system-design 13: automatic retries apply only to non-interactive operations, and refund execution is one).

Error codes: 401/403 standard auth codes plus `mfa_required`; 404 `refund_payment_not_found` (also for cross-tenant IDs, which RLS makes unfindable); 400 `idempotency_key_missing`; 409 `idempotency_key_reuse_mismatch` (same key, different request body); 409 `payment_not_refundable` (payment not confirmed or order not in a refundable status); 422 `request.validation_failed` with the `errors` map; 422 `refund_amount_exceeds_refundable`; 422 `refund_currency_mismatch`; 422 `refund_tickets_not_in_order`.

### GET /v1/refunds/{refund}

Response 200 `RefundData`. 404 `refund_not_found`.

### GET /v1/refunds

Admin list via query-builder with explicit allowlists (api-conventions, Lists): `filter[payment_id]`, `filter[order_id]`, `filter[status]`, `sort=-created_at` default. Cursor-paginated in the standard paginator envelope. Unknown filter or sort values are rejected.

### GET /v1/ledger-entries

Finance read. Cursor pagination mandatory (api-conventions names ledger entries a high-volume collection) with deterministic order `created_at, id`. Filters: `filter[account]`, `filter[reference_type]`, `filter[reference_id]`, `filter[created_at_from]`, `filter[created_at_to]`. Response items `LedgerEntryData`: `id`, `account`, `direction`, `amount` (money object), `reference_type`, `reference_id`, `created_at`. Requires a ledger-view capability.

### GET /v1/ledger-balances

Per-currency balance summary computed from the ledger (system-design 7.3: the ledger is the source of truth for balances). Response `LedgerBalanceData` list: `currency`, `account`, `balance` (money object, signed by convention: credit-positive for `tenant_net`, `platform_commission`, `gateway_fees`; debit-positive for `gateway_receivable`). Bounded collection, no pagination. Same capability as ledger entries. This is the read Stage 8c reconciles payouts against.

No new customer-facing endpoints: buyers see `partially_refunded` and `refunded` through the Stage 7 order status surface.

## TDD sequencing

Every slice follows the master plan's double loop: outside feature test first, contract second, inside unit tests third, then green, refactor, `composer types:generate`, commit with scope `payments` (Orders slice uses `orders`). The three non-negotiable test-first rules apply: failing isolation tests before each new tenant-scoped table, failing concurrency tests before each invariant-guarding transition, failing duplicate-delivery tests before each outbox consumer.

Slice 1: commission configuration.

- Feature (failing first): platform admin updates `commission_bps` and `refund_commission_policy` through the Stage 2 tenant endpoints; invalid policy value rejected with `request.validation_failed`.
- Contract: extended `TenantData` in the OpenAPI document; TS regenerated.
- Unit: policy enum; commission computation on `Support/Money` values (bps of gross, minor-unit rounding pinned down: round half up, asserted at boundaries).

Slice 2: money breakdown persisted at confirmation.

- Unit (failing first): the 8a confirmation Action persists `fee_amount` from the gateway payload and `commission_amount` from tenant config in the same transaction as `PaymentConfirmed`; zero-commission tenant produces zero legs.
- Feature: FakeGateway confirmation scenario leaves the breakdown on the payment row.

Slice 3: `ledger_entries` table and entry-set builder.

- Isolation (failing first, before the migration exists): cross-tenant reads and writes on `ledger_entries` fail under RLS with the two-tenant fixture.
- Unit (failing first): append-only trigger raises on UPDATE and on DELETE; the entry-set builder produces the four payment legs and both refund-policy leg sets, balanced per currency, rejecting unbalanced sets; `(source_event_id, account)` uniqueness makes re-insertion a no-op.

Slice 4: ledger projection for `PaymentConfirmed`.

- Duplicate-delivery (failing first): delivering the same `PaymentConfirmed` twice yields exactly one ledger set.
- Unit (failing first, against the extended helper): the ordered-consumption helper accepts a payload-derived ordering key and defers an event whose same-key predecessor is unprocessed, so a refund event for a payment whose confirmation is unprocessed is deferred; projection output is a pure function of row facts plus payload.
- Feature: an HTTP purchase confirmed through FakeGateway ends with four balanced entries referencing the payment.
- Replay: rebuild from outbox replay equals the incrementally built ledger row for row (ids aside, compared on the natural key `source_event_id`, `account`).

Slice 5: refund creation endpoint and refundable-amount guard.

- Concurrency (failing first, before the endpoint exists): N parallel partial-refund creations against one payment never reserve more than `payments.amount`; the losers get `refund_amount_exceeds_refundable`.
- Feature (failing first): 201 shape; full-refund default when `amount` is null; every error code listed above including the `Idempotency-Key` replay returning the original 201 body and `idempotency_key_reuse_mismatch` on body mismatch; MFA and capability denial paths; activity log row asserted.
- Isolation: `refunds` two-tenant denial (before the migration merges).
- Contract: refund paths and schemas merged; conformance green.
- Unit: `RefundInitiated` recorded in the same transaction as the insert and reservation, and rolled back with it; returned commission derived proportionally from the payment's persisted `commission_amount`, capped by the un-returned remainder, rounding pinned at boundaries.

Slice 6: refund execution and completion.

- Concurrency (failing first, before the transitions exist): parallel executors racing `pending` to `processing` admit exactly one gateway call; parallel completion webhooks racing `processing` to `completed` and to `failed` produce exactly one outcome and, on failure, release the `refunded_amount` and `refunded_commission_amount` reservations exactly once.
- Duplicate-delivery (failing first): duplicate `RefundInitiated` delivery calls the gateway once (the `pending` to `processing` conditional UPDATE admits one executor).
- Unit: retry budget 3 attempts at 1s, 5s, 15s through the fake clock, same idempotency key on every attempt, dead letter after exhaustion; `processing` to `completed` and to `failed` transitions are conditional UPDATEs proven by affected-row count under a scripted duplicate completion webhook.
- Feature: FakeGateway async-refund scenario drives `pending` through `processing` to `completed` including webhook ingestion; declined-refund scenario lands `failed` with `failure_code` and the order still `paid`; the reconciliation sweeper resolves a `processing` refund whose completion webhook never arrived, through the same conditional transitions, and a late webhook after the sweeper is a no-op.

Slice 7: order transitions, ticket voiding, refund ledger legs.

- Concurrency (failing first, before the completion transaction exists): order transition to `refunded` vs a concurrent conflicting transition resolves by affected-row count, never read-then-write.
- Unit (failing first, Orders context): the refund transition Actions cover every refund arc (`paid` to `partially_refunded`, `paid` to `refunded`, `partially_refunded` to `partially_refunded`, `partially_refunded` to `refunded`), and the Stage 7 state-machine table test is extended so exactly the amended arc set succeeds and everything else still raises `invalid_order_transition`.
- Unit (failing first): completion transaction invokes the Orders transition Actions (`paid` to `partially_refunded` when `refunded_amount < amount`, to `refunded` when equal; `partially_refunded` onward per the noted design amendment), records `RefundCompleted`, and calls the Orders voiding Action with the `ticket_ids` read from the refund row, which records one `TicketRefunded` per ticket; ledger legs for both commission policies.
- Duplicate-delivery: duplicate `RefundCompleted` produces one ledger set and one ticket-void pass.
- Feature: end-to-end full refund over HTTP leaves order `refunded`, tickets voided, books balanced; two sequential partial refunds accumulate to `refunded`.

Slice 8: read endpoints.

- Feature (failing first): refund show and list with filter allowlist rejection; ledger entry cursor pagination with deterministic order across pages; balance endpoint matches hand-computed sums; capability denial on all three.
- Isolation: every new endpoint covered by the isolation suite.
- Contract: all read schemas merged, drift gate green.

Slice 9: balance invariant harness.

- A scripted-scenario test running randomized purchase and refund sequences (sync approve, async confirm, decline, expire, duplicate webhooks, partial refunds under both commission policies) asserting after every step: per-currency debits equal credits per reference; `tenant_net` balance equals the independently computed expectation; then a full replay rebuild equals the incremental ledger. This is the stage's standing invariant test and stays in the suite permanently.

## Task breakdown

Ordered; each is a small PR that is independently mergeable unless noted.

1. Coordination verification: assert Stage 8a delivered the `fee` field on the `PaymentConfirmed` payload and the persisted `payments.fee_amount` and `commission_amount`; only if something is missing, add it additively here (event-conventions permits additive-only evolution). Scope `payments`.
2. `tenants` commission migration, enums, `TenantData` extension, admin endpoint update, types regenerated. Scope `tenancy` (touches the Tenancy surface) with the Payments enums landing where the capability map says.
3. Confirmation Action persists the breakdown (slice 2). Scope `payments`.
4. `ledger_entries` migration with RLS policy and append-only trigger, model, enums, entry-set builder, isolation and unit coverage (slice 3). Scope `payments`.
5. Ordered-consumption helper extension in `Support/Outbox`: a subscriber-supplied ordering key derived from the payload, defaulting to the envelope aggregate, with unit coverage for cross-aggregate deferral on a shared key. Scope `support`.
6. Ledger projection consumer for `PaymentConfirmed` with ordered consumption keyed on `payment_id` via the task 5 extension, duplicate-delivery and replay tests, wired into the Stage 4 subscriber routing (slice 4). Scope `payments`. Depends on 5.
7. `refunds` migration with RLS policy, model, status enum, `payments.refunded_amount` and `refunded_commission_amount` migration, isolation coverage. Scope `payments`.
8. `CreateRefund` Action and POST endpoint: reservation guard, commission derivation, `ticket_ids` and `request_hash` persistence, `RefundInitiated`, idempotency replay, MFA and capability gates, activity log, OpenAPI fragment, concurrency test (slice 5). Scope `payments`. Depends on 7.
9. Refund executor consumer, FakeGateway refund scenarios (accept, decline, async complete, duplicate webhook, missed webhook), completion webhook normalization, refund status transitions, and the scheduled refund reconciliation sweeper (slice 6). Scope `payments`. Depends on 8.
10. Orders context `MarkTicketsRefunded` Action recording `TicketRefunded`. Scope `orders`. Mergeable independently of 9.
11. Orders context refund transition Actions (every arc into and out of `partially_refunded` and `refunded`), extending the Stage 7 state-machine table test with the refund arcs, with the system-design 7.1 diagram amendment in the same change. Scope `orders`. Mergeable independently of 9.
12. Completion transaction: invokes the Orders transition and voiding Actions, records `RefundCompleted`, ledger legs for both policies, duplicate-delivery coverage (slice 7). Scope `payments`. Depends on 9, 10, and 11.
13. Read endpoints and contracts: refunds show and list, ledger entries, ledger balances (slice 8). Scope `payments`. Depends on 4 and 7 only, so it can proceed in parallel with 9 through 12.
14. Balance invariant scenario harness (slice 9) and the roadmap and master-plan status table updates. Scope `payments`. Last.

## Exit criteria

The master plan's Stage 8 exit line, restricted to this slice and expanded into individually testable checks:

1. A full refund initiated over HTTP against FakeGateway completes asynchronously: refund `completed`, order `refunded`, all tickets voided with one `TicketRefunded` each, ledger balanced.
2. Partial refunds accumulate correctly; the final partial that exhausts the payment transitions the order to `refunded`; no sequence or concurrency of partial refunds can exceed the payment amount (proven by the parallel simulation).
3. Both commission policies produce the specified leg sets; per-currency debits equal credits for every reference in every scripted scenario.
4. Duplicate delivery of any consumed event (`PaymentConfirmed`, `RefundInitiated`, `RefundCompleted`) and duplicate gateway webhooks produce exactly one state change, one gateway call, one ledger set, one ticket-void pass.
5. `Idempotency-Key` replay on refund creation returns the original result; a reused key with a different body returns `idempotency_key_reuse_mismatch`.
6. A replay-rebuilt ledger matches the incrementally built one row for row on `(source_event_id, account, direction, amount, currency, reference_type, reference_id, tenant_id)`.
7. UPDATE and DELETE on `ledger_entries` fail at the database level.
8. The isolation suite covers `refunds`, `ledger_entries`, and every new endpoint; the two-tenant fixture proves cross-tenant denial.
9. Refund creation is denied without the refund capability or without MFA; every refund mutation appears in the activity log.
10. A declined or errored refund leaves the order `paid`, releases the reserved `refunded_amount` and `refunded_commission_amount` exactly once, and dead-letters after the 3-attempt budget.
11. A `processing` refund whose completion webhook never arrives is resolved by the reconciliation sweeper through the same conditional transitions, and a late webhook after the sweeper changes nothing.
12. All new endpoints are in `docs/openapi/openapi.yaml`, the conformance and TypeScript drift gates are green, Larastan and Pint are clean, and all six suites pass in `composer test` and CI.
13. The master plan status table marks Stage 8b merged.

## Risks and open questions

- State machine gap: system-design 7.1 draws `paid` to `partially_refunded` and `paid` to `refunded` but no edges out of `partially_refunded`. This stage needs `partially_refunded` to `partially_refunded` (further partials) and `partially_refunded` to `refunded`. Task 11 amends the design diagram in the same change; flagged so the design stays authoritative. Stage 7 deliberately shipped no refund transitions and scoped its table test to the non-refund states, so nothing here rewrites frozen assertions.
- Commission rate location: the design fixes the refund policy flag per tenant (7.3) but is silent on where the commission rate lives. This plan puts `commission_bps` on `tenants`; per-event or per-ticket-type overrides would be additive later. Confirm before task 2.
- Partial refund semantics: the plan takes amount-based refunds with optional explicit `ticket_ids` voiding and no automatic proration of promo discounts or fees. Whether finance needs proration rules (and how discounts split across tickets) is undesigned; deferred until asked for.
- Refunded inventory: whether voided tickets return seats or GA quantity to sale is not in the design. Out of scope here; if wanted it is an Inventory context consumer of `TicketRefunded` in a later change.
- Gateway refund fees: some gateways charge a fee on refunds. The leg sets above assume none; if the launch gateway ADR (Stage 8d) introduces one, an additional `gateway_fees` leg is additive to the builder and the FakeGateway grows a scenario for it.
- Multiple payments per order: the model allows several payments per order (8.3). With 8a's flow only one confirms, so order-level refunded status derives from the single confirmed payment; if split payments ever land, the order transition rule needs revisiting.
- `RefundFailed` is not an event type in the 9.3 registry; this stage keeps failure as row state. If Stage 11 reporting needs it, adding the type updates the registry per event-conventions.
- Rounding: commission in basis points forces a rounding rule; the plan pins round half up in `Support/Money` unit tests. Any statutory rule from the launch market ADR overrides it before real money flows (Stage 8d).
- Replay determinism depends on the projection reading persisted row facts, not live tenant config. The unit suite guards this, but reviewers should watch for accidental config reads inside the projection.
