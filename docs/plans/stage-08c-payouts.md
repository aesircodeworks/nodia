# Stage 8c: Payouts and Sub-merchant Onboarding

Implementation plan for the Stage 8c slice of [api-implementation-plan.md](../api-implementation-plan.md): the sub-merchant onboarding abstraction and `payouts` mirroring, both exercised through the fake gateway. The TDD loop and the contract pipeline from the master plan are binding, as are [api-conventions.md](../api-conventions.md), [data-conventions.md](../data-conventions.md), and [event-conventions.md](../event-conventions.md). Design authority is [system-design.md](../system-design.md), cited by section throughout. Everything in this stage lives in the Payments context (`apps/api/app/Payments/`), which owns payouts and the Sub-merchant concept (system-design 3.1, 19). Commit scope is `payments`.

## Scope and non-goals

This stage delivers the last piece of the money path described in system-design 7.3: tenants onboard as sub-merchants through the gateway's KYC flow, and payouts executed by the gateway are mirrored into `payouts` records that reconcile against ledger balances.

In scope:

- Extension of the `GatewayAdapter` interface (built in Stage 8a) with sub-merchant operations: create a sub-merchant registration, fetch its status, list payouts. Capability flags already express split support (system-design 7.2); this stage makes the flag consequential.
- `FakeGateway` scenario controls for onboarding and payouts: approve KYC immediately, hold in review, request more information, reject, execute a payout, fail a payout, emit duplicate webhooks for all of these (master plan, Payment gateway posture).
- `submerchant_accounts` table and lifecycle: one registration per tenant per gateway, driven to `active` or `rejected` by gateway webhooks with a manual refresh fallback. The platform never handles KYC documents; the gateway does (system-design 7.3, ADR 006). Spelling follows ADR 006's filename (`submerchant`), the glossary term is Sub-merchant (system-design 19).
- `payouts` table mirroring gateway payout objects (system-design 8.3), created and transitioned only from gateway webhooks and the reconciliation poller, never by staff request. The gateway executes payouts on a per-tenant schedule (system-design 7.3); the platform records what happened.
- `PayoutExecuted` domain event (already in the registry, system-design 9.3) recorded to the outbox in the payout's paying transaction, consumed by the Stage 8b ledger projection so tenant balances reflect payouts.
- Checkout offer gating: a gateway's methods are offered only when the tenant's sub-merchant account on that gateway is `active`, in addition to the Stage 8a currency and circuit-breaker checks. This is implied by system-design 7.3: without an onboarded sub-merchant the gateway cannot split the charge, so offering it would take money the platform cannot pay out.
- Scheduled `ReconcilePayouts` command: polls the gateway for payouts missed by webhooks and checks mirrored payouts against ledger balances, flagging discrepancies (sweepers-as-backstops, system-design 13).
- Admin read and onboarding endpoints, capability-gated and MFA-enforced for financially privileged roles (system-design 5.1, 14.2), with all onboarding and payout state changes in the activity log.

Non-goals, deferred with their target stage:

- Real gateway onboarding and payout API calls: Stage 8d, behind the same interface, once the launch gateway ADR lands.
- Payout finance dashboards, exports, and aggregates: Stage 11 (Reporting).
- Operational replay and reconcile commands beyond `ReconcilePayouts` (support tooling, audited command surface): Stage 12.
- Payout schedule editing UI semantics. `tenants.payout_schedule` exists since Stage 2 (system-design 8.1) and is owned by Tenancy; this stage only pushes it to the gateway at onboarding time. Changing schedules after onboarding is deferred to Stage 8d because the mechanics are gateway-specific.
- Chargebacks and negative-balance recovery: not in the system design's data model; out of scope until the design defines them.

## Dependencies

Consumed from earlier stages:

- Stage 1: problem-document handler and error code registry, `Support/Money`, Contract suite, real Isolation and Concurrency harnesses, fake clock.
- Stage 2: `tenants.enabled_gateways` and `tenants.payout_schedule`, RLS policy pattern, `SET LOCAL app.tenant_id` wrapper, tenant resolution middleware, the platform-scope database role (system-design 4.3).
- Stage 3: capability-based Gates and Policies, MFA enforcement for financially privileged roles, and the `payouts.view` capability, which Stage 3's initial registry already ships marked financially privileged; the endpoints these capabilities guard arrive here, exactly what Stage 3 built the mechanism for per the master plan. This stage adds only `payouts.manage`. Also the activity log.
- Stage 4: outbox recording API, dispatcher, `outbox_deliveries`, ordered-consumption helper, replay primitive.
- Stage 7: orders and the paid path that produces the balances payouts draw down.
- Stage 8a: `GatewayAdapter` interface and `FakeGateway`, raw webhook persistence unique by gateway event ID, signature verification, always-2xx-after-persist ingestion, per-gateway webhook controllers, circuit breaker.
- Stage 8b: append-only `ledger_entries`, the ordered ledger projection consumer, the ledger balance query (tenant net balance derived from entries), refunds affecting balances.

Consumed by later stages:

- Stage 8d implements `createSubmerchant`, `fetchSubmerchantStatus`, and `listPayouts` for the real gateway behind the interface fixed here; the KYC redirect and requirements shapes defined here are the contract it must satisfy.
- Stage 11 reads `payouts` and the payout ledger entries for finance reporting.
- Stage 12's smoke suite includes the payout leg of the full loop; its operational commands build on `ReconcilePayouts`.

## Data model

Two new tables, both tenant-scoped, both shipping their RLS policy in the creating migration (data-conventions, ADR 003). UUIDv7 primary keys via `HasUuids` (ADR 005). All timestamps UTC. Status columns are strings backed by PHP enums (data-conventions).

### submerchant_accounts

One row per tenant per gateway: the tenant as registered with that gateway for split payments and payouts (Sub-merchant, system-design 19).

| Column | Type | Notes |
| --- | --- | --- |
| id | uuid | PK, UUIDv7 |
| tenant_id | uuid | non-null, FK to tenants |
| gateway | string | adapter key, matching the Stage 8a gateway registry |
| status | string | enum `SubmerchantStatus`: `pending`, `under_review`, `action_required`, `active`, `rejected`, `disabled` |
| gateway_account_reference | string nullable | the gateway's sub-merchant identifier, set once the gateway acknowledges creation |
| onboarding_url | text nullable | gateway-hosted KYC URL, present while the gateway wants buyer-side action; the platform never collects KYC data itself (system-design 7.3) |
| requirements | jsonb | list of outstanding requirement keys reported by the gateway, default `[]` |
| activated_at | timestamptz nullable | set on transition to `active` |
| created_at, updated_at | timestamptz | |

Constraints and indexes:

- Unique `(tenant_id, gateway)`: at most one registration per tenant per gateway. This is the concurrency guard for duplicate onboarding starts.
- Unique `(gateway, gateway_account_reference)` where `gateway_account_reference` is not null (partial unique index, named `submerchant_accounts_gateway_reference_idx` per data-conventions custom-name rule), so webhook lookup by gateway reference is unambiguous.
- Index on `(tenant_id, status)` for the checkout offer query.
- RLS policy in the same migration: `tenant_id = current_setting('app.tenant_id')::uuid`, same pattern as every scoped table since Stage 2.

Status transitions (all conditional UPDATEs checked by affected-row count, never read-then-write):

- `pending` to `under_review`, `action_required`, `active`, or `rejected` (gateway acknowledgment or decision)
- `under_review` to `action_required`, `active`, or `rejected`
- `action_required` to `under_review`, `active`, or `rejected`
- `active` to `disabled` and `disabled` to `active` (gateway-side suspension and reinstatement)
- `rejected` to `pending`: the retry after rejection, applied when the gateway reports a new onboarding attempt (webhook or refresh); the gateway may resolve the retry by reusing or replacing the reference (open question for 8d; the fake gateway reuses it)
- `disabled` and `rejected` have no other outgoing transitions

### payouts

Mirror of gateway payout objects (system-design 7.3, 8.3). Rows are created by webhook ingestion or the reconciliation poller, never by an admin request.

| Column | Type | Notes |
| --- | --- | --- |
| id | uuid | PK, UUIDv7 |
| tenant_id | uuid | non-null |
| gateway | string | adapter key |
| gateway_reference | string | the gateway's payout identifier |
| amount | bigint | integer minor units; column name follows the system-design 8.3 diagram, which pairs bare `amount` with `currency` on `payments`, `refunds`, and `payouts` alike; Stage 8a set this precedent for `payments` |
| currency | string | the tenant settlement currency (system-design 12) |
| status | string | enum `PayoutStatus`: `pending`, `in_transit`, `paid`, `failed`, `canceled` |
| executed_at | timestamptz nullable | gateway-reported completion time, set on transition to `paid` |
| reconciled_at | timestamptz nullable | set by `ReconcilePayouts` when the payout has been checked against the ledger |
| discrepancy_amount | bigint nullable | minor units in the row's `currency`; non-null when reconciliation found the mirrored amounts diverging from ledger expectations |
| created_at, updated_at | timestamptz | |

Constraints and indexes:

- Unique `(gateway, gateway_reference)`: the idempotence anchor for payout webhooks; duplicate deliveries and poller overlap resolve to the same row.
- Index on `(tenant_id, status)` and `(tenant_id, created_at)` for the cursor-paginated admin list.
- RLS policy in the same migration, standard pattern.

Status transitions, each a conditional UPDATE checked by affected-row count:

- `pending` to `in_transit`, `paid`, `failed`, or `canceled`
- `in_transit` to `paid`, `failed`, or `canceled`
- `paid`, `failed`, `canceled` are terminal
- Out-of-order webhooks (a `paid` webhook arriving before `in_transit`) must land correctly: the transition guard permits skipping forward, and a late `in_transit` after `paid` affects zero rows and is dropped as already-processed.

No ledger columns live here: the ledger remains the source of truth for balances (system-design 7.3); `payouts` is a mirror that reconciles against it.

## Domain events

Produced:

- `PayoutExecuted` (registry entry exists, system-design 9.3). Recorded to the outbox in the same transaction as the payout's conditional transition to `paid`, without exception (event-conventions). Envelope: `type` `PayoutExecuted`, `tenant_id` the payout's tenant, `aggregate_type` `payout`, `aggregate_id` the payout id, `correlation_id` from the ingesting request, `occurred_at` UTC. Payload (laravel-data, snake_case, additive-only evolution): `payout_id`, `gateway`, `gateway_reference`, `amount` and `currency` as the money wire shape, `executed_at`. Identifiers and facts only, no entity snapshot (event-conventions).
- No domain events for onboarding transitions or failed payouts in this stage. The registry (system-design 9.3) lists only `PayoutExecuted` for this area, no consumer needs the others yet (checkout gating reads `submerchant_accounts` inside the Payments context synchronously), and adding event types requires a registry update in the same change (event-conventions). Revisit when Reporting (Stage 11) states a need; see open questions.

Consumed:

- `ProjectLedgerEntries` (the Stage 8b ordered consumer) adds `PayoutExecuted` to its subscription. For each event it appends a balanced pair of entries referencing the payout (`reference_type` `payout`, `reference_id` the payout id): a debit to `tenant_net` and a credit to `gateway_receivable` (the gateway's holding decreases when it pays the tenant), using the four-account taxonomy Stage 8b established; no new account value is needed. Idempotent by event ID via `outbox_deliveries` (event-conventions): duplicate delivery appends nothing. Ordered per aggregate by outbox `sequence` using the Stage 4 helper, as the ledger projection already does (system-design 9.2). Replay from the outbox rebuilds the payout entries identically (system-design 9.1).

Webhook events from the gateway (not domain events) that this stage teaches the Stage 8a normalizer to handle, all idempotent by gateway event ID through the existing raw-persistence unique constraint: sub-merchant status updates, payout created, payout status changed. Ingestion stays always-2xx-after-persist (system-design 7.4); normalization runs async in `ProcessGatewayWebhook`. These callbacks arrive with no tenant context: `ProcessGatewayWebhook` resolves the tenant by looking up the sub-merchant account via `(gateway, gateway_account_reference)` under the platform-scope role (system-design 4.3; the use is activity-logged, mirroring the Stage 8a payment-webhook path), then applies the upsert and conditional transition inside a tenant-scoped transaction.

## Endpoints

All under `/v1`, staff-authenticated with `X-Tenant-Id` validated against memberships (api-conventions). Every capability below is financially privileged, so MFA enforcement from Stage 3 applies (system-design 5.1); denial renders the Stage 3 problem code for missing MFA. Every response is a laravel-data object regenerated into `packages/api-client` and every path lands in `docs/openapi/openapi.yaml` before the endpoint ships (api-conventions).

Capabilities: `payouts.view` (named in system-design 5.3) already exists in Stage 3's registry, marked financially privileged; this stage introduces only `payouts.manage` (onboarding and refresh) and wires the Policies and endpoint gating for both.

### POST /v1/submerchant-accounts

Starts onboarding for one gateway. Requires `payouts.manage`.

- Request `StartSubmerchantOnboardingData`: `gateway` (string, must be a registered adapter).
- Behavior: validates the gateway is in the tenant's `enabled_gateways` (read through a Tenancy Action, never the tenant model, per the boundary rule in system-design 3.1); inserts the `pending` row relying on the `(tenant_id, gateway)` unique constraint; calls `GatewayAdapter::createSubmerchant` with the tenant's settlement details and `payout_schedule`; stores the returned reference, onboarding URL, and requirements. Gateway acknowledgment may arrive synchronously (fake gateway immediate-approve scenario) or via webhook.
- Response 201 `SubmerchantAccountData`: `id`, `gateway`, `status`, `gateway_account_reference`, `onboarding_url`, `requirements`, `activated_at`, `created_at`, `updated_at`.
- Errors (RFC 9457 problem documents, stable `code`): 422 validation with `errors` map; 422 `gateway_unknown` (no such adapter); 409 `gateway_not_enabled`; 409 `submerchant_already_onboarded` (unique constraint hit, response includes the existing account id in the problem document); 503 `gateway_unavailable` when the circuit breaker for that gateway is open (system-design 13), with `Retry-After`.

### GET /v1/submerchant-accounts

Lists the tenant's registrations, at most one per gateway. Requires `payouts.view`. Bounded collection, page pagination is acceptable (api-conventions). Query-builder allowlist: `filter[gateway]`, `filter[status]`, `sort=-created_at`; unknown values rejected.

### GET /v1/submerchant-accounts/{submerchant_account}

Single account, `SubmerchantAccountData`. Requires `payouts.view`. 404 `request.not_found` (the Stage 1 registry's generic not-found code) for absent or other-tenant ids; RLS makes the cross-tenant case indistinguishable from absence.

### POST /v1/submerchant-accounts/{submerchant_account}/refresh

Pulls current status from the gateway (`fetchSubmerchantStatus`) and applies the same conditional transition the webhook path uses; the manual fallback for missed webhooks. Requires `payouts.manage`. Response 200 `SubmerchantAccountData`. Errors: 404 `request.not_found`; 503 `gateway_unavailable`.

### GET /v1/payouts

Cursor-paginated list (money collections use cursor pagination per api-conventions; deterministic order by `created_at` then `id`). Requires `payouts.view`. Allowlist: `filter[status]`, `filter[gateway]`, `sort=-created_at`. Response items `PayoutData`: `id`, `gateway`, `gateway_reference`, `amount` as `{amount, currency}`, `status`, `executed_at`, `reconciled_at`, `discrepancy` as nullable `{amount, currency}`, `created_at`. Standard paginator envelope (`data`, `links`, `meta`).

### GET /v1/payouts/{payout}

Single payout, `PayoutData`. Requires `payouts.view`. 404 `request.not_found`.

No endpoint creates or mutates payouts: the gateway executes them (system-design 7.3) and the mirror is written by webhook ingestion and the poller. The existing per-gateway webhook endpoint from Stage 8a is extended, not duplicated.

## TDD sequencing

Every slice follows the master plan's double loop: outside feature test first, contract second, unit tests driving the inside, then green, refactor, `composer types:generate`, commit. The three non-negotiable test-first rules apply: failing isolation tests before each new table, failing concurrency tests before each invariant-guarding transition, failing duplicate-delivery tests before each consumer change.

### Slice 1: Gateway interface extension and fake scenarios

No endpoints; pure Payments-internal surface.

- Unit (first, failing): `GatewayAdapter` gains `createSubmerchant`, `fetchSubmerchantStatus`, and `listPayouts`; the split-support capability flag Stage 8a already shipped is wired into the offer logic; `FakeGateway` scenario tests for immediate approve, under review, action required with a requirements list, reject, retry after reject, payout executed, payout failed, duplicate webhook emission for each.
- Architecture: the new interface members live in `app/Payments/Gateways` and leak into no other context.

### Slice 2: submerchant_accounts and onboarding start

- Isolation (first, failing): cross-tenant SELECT, UPDATE, DELETE on `submerchant_accounts` return zero rows under RLS; platform role reads succeed.
- Feature (failing): POST happy path returns 201 with `pending` status and an onboarding URL from the fake gateway; immediate-approve scenario returns `active`; `gateway_unknown`, `gateway_not_enabled`, `submerchant_already_onboarded`, capability denial, MFA denial, missing `X-Tenant-Id`; GET list and detail shapes; unknown filter rejected.
- Contract: OpenAPI paths for all four submerchant endpoints; conformance assertions on the recorded responses; TypeScript regenerated without drift.
- Unit: `StartSubmerchantOnboarding` action records an activity log entry; the Tenancy read goes through an Action (architecture test tightens this).
- Concurrency (first, failing, before the insert path merges): parallel onboarding starts for the same tenant and gateway produce exactly one row and one gateway `createSubmerchant` call side effect recorded by the fake; the loser receives `submerchant_already_onboarded`.

### Slice 3: Onboarding lifecycle via webhooks and refresh

- Feature (failing): fake gateway emits a sub-merchant status webhook; ingestion returns 2xx after persist; the account transitions `pending` to `active` with `activated_at` set; `action_required` carries the requirements list; `rejected` path; retry after rejection returns the account to `pending`; refresh endpoint applies the same transition and returns the updated account.
- Unit (failing): transition matrix over `SubmerchantStatus`, every legal transition affects one row, every illegal transition affects zero and changes nothing; out-of-order webhook (active before under_review) lands on the terminal-forward rule.
- Concurrency (first, failing, before the transition Action merges): a webhook delivery and a refresh call applying conflicting transitions to the same account in parallel produce exactly one state change; the loser's conditional UPDATE affects zero rows and is dropped as already-processed.
- Duplicate delivery (first, failing): the same gateway event ID delivered twice produces one state change and one activity log entry.

### Slice 4: Checkout offer gating

- Feature (failing): with a tenant whose gateway is enabled but sub-merchant is `pending`, the Stage 8a method-offer response excludes that gateway's methods; flipping to `active` includes them; `disabled` excludes them again. Payment initiation against a non-active gateway fails with 409 `gateway_not_enabled` semantics extended by a distinct code `submerchant_not_active`.
- Unit: offer composition consults `supportsSplit` plus account status alongside the existing currency and circuit-breaker checks.

### Slice 5: payouts mirroring

- Isolation (first, failing): cross-tenant access to `payouts` fails under RLS.
- Feature (failing): fake gateway emits payout created then payout paid webhooks; a `payouts` row appears and transitions; GET list (cursor envelope, filters) and detail; out-of-order paid-before-in_transit lands `paid`; failed and canceled paths.
- Unit (failing): `RecordGatewayPayout` action upserts by `(gateway, gateway_reference)`; transition guards by affected-row count; `PayoutExecuted` recorded in the same transaction as the transition to `paid` and only on that transition (failed payouts record no domain event).
- Concurrency (first, failing): the same payout webhook delivered on two parallel workers produces exactly one row, one transition, and exactly one `PayoutExecuted` outbox event.
- Contract: payout paths in OpenAPI, conformance, regenerated types.

### Slice 6: Ledger projection and reconciliation

- Duplicate delivery (first, failing): `PayoutExecuted` delivered twice to `ProjectLedgerEntries` appends exactly one balanced entry pair.
- Unit (failing): the entry pair balances (debits equal credits per reference); tenant net balance after purchase, refund, payout sequences equals gross minus fees minus commission minus refunds minus payouts; ordered consumption defers a payout event whose predecessor for the aggregate is unprocessed; outbox replay rebuilds payout entries identical to the incremental ledger (system-design 9.1).
- Feature (failing): `ReconcilePayouts` scheduled command pulls `listPayouts` from the fake gateway, creates any payout missed by webhooks (poller-as-backstop, system-design 13), sets `reconciled_at`, and writes `discrepancy_amount` plus an activity log entry when the fake is scripted to report an amount diverging from the mirror.

### Slice 7: Stage exit loop

- Feature (failing, the stage's capstone): entirely over HTTP against the fake gateway: publish, hold, order, initiate payment, async confirmation webhook, partial refund, full refund on a second order, payout webhook; after every step the ledger balance invariant holds and at the end the payout equals the tenant net balance drawn down; then rerun under each scripted failure mode (declined payment, expired payment, duplicate webhooks everywhere, failed payout) and assert books still balance and the failed payout left the ledger untouched.

## Task breakdown

Ordered; each is a small PR, independently mergeable unless noted, each carrying its tests per the slice it implements.

1. `payments`: extend `GatewayAdapter` with sub-merchant and payout operations and wire the existing Stage 8a split-support flag into the offer logic; `FakeGateway` scenarios and webhook emitter additions (slice 1).
2. `payments`: `submerchant_accounts` migration with RLS policy, `SubmerchantAccount` model, `SubmerchantStatus` enum, factory, isolation tests (slice 2, mergeable alone because nothing routes to it yet).
3. `identity`: register `payouts.manage` in the capability set, mark it financially privileged for MFA enforcement, and wire it into the template roles; `payouts.view` is already registered and marked financially privileged by Stage 3 (small, unblocks every endpoint task).
4. `payments`: `StartSubmerchantOnboarding` action, POST endpoint, list and detail endpoints, Data objects, OpenAPI paths, error codes, concurrency test on duplicate start (slice 2).
5. `payments`: webhook normalization for sub-merchant status events, transition Action with the conditional-update matrix, refresh endpoint, concurrency test on parallel webhook and refresh transitions, duplicate-delivery test (slice 3).
6. `payments`: checkout offer and payment initiation gating on active sub-merchant, `submerchant_not_active` code (slice 4; depends on task 5).
7. `payments`: `payouts` migration with RLS policy, `Payout` model, `PayoutStatus` enum, factory, isolation tests; `docs`: amend the data-conventions money rule to bless bare `amount` on gateway-mirror money tables, the reconciliation promised in the open questions (slice 5, mergeable alone).
8. `payments`: `RecordGatewayPayout` action, payout webhook normalization, `PayoutExecuted` event class and payload Data object, outbox recording, concurrency test (slice 5; depends on task 7).
9. `payments`: payout read endpoints with cursor pagination, Data objects, OpenAPI paths (slice 5; depends on task 7, parallel with task 8).
10. `payments`: `ProjectLedgerEntries` subscription to `PayoutExecuted`, balanced entry pair, replay rebuild test (slice 6; depends on task 8).
11. `payments`: `ReconcilePayouts` scheduled command with gateway polling, missed-payout creation, ledger discrepancy flagging, activity log (slice 6; depends on tasks 8 and 10).
12. `payments`: stage exit end-to-end loop test across all scripted failure modes; `docs`: flip the Stage 8c row in the master plan status table to done in the same change (slice 7).

## Exit criteria

The master plan's Stage 8 exit line, "the full purchase, confirmation, refund, and payout loop runs over HTTP against the fake gateway with balanced books under every scripted failure mode", expands for this slice into individually testable checks:

1. A tenant can start onboarding over HTTP, follow the fake gateway's KYC states through webhooks, and reach `active`; the rejected and action-required paths are equally reachable and observable through the API.
2. Parallel duplicate onboarding starts yield exactly one `submerchant_accounts` row (concurrency suite).
3. A gateway's methods appear at checkout only when the tenant's sub-merchant account is `active`; payment initiation against a non-active gateway fails with `submerchant_not_active`.
4. A payout webhook creates and transitions a `payouts` row; duplicate delivery of the same gateway event produces one row, one transition, one `PayoutExecuted`, one ledger entry pair (concurrency and duplicate-delivery suites).
5. Out-of-order payout webhooks converge on the correct terminal state; late stale transitions affect zero rows.
6. The ledger balance invariant holds after every purchase, refund, and payout sequence the fake gateway can script, and a replay-rebuilt ledger matches the incremental one including payout entries.
7. `ReconcilePayouts` creates payouts missed by webhooks and flags amount discrepancies with `discrepancy_amount` and an activity log entry.
8. Both new tables have RLS policies shipped in their creating migrations and cross-tenant access provably fails (isolation suite covers every new endpoint).
9. Every endpoint has its OpenAPI contract merged, conformance-checked, with regenerated TypeScript committed without drift; problem documents carry the stable codes listed above.
10. All onboarding transitions, payout state changes, and reconciliation discrepancies appear in the activity log; every endpoint enforces its capability and MFA for the financially privileged roles.
11. The capstone HTTP loop (publish through payout) passes under every scripted failure mode, and Larastan, Pint, and the architecture suite are green.

## Risks and open questions

- Onboarding shape divergence at Stage 8d: real gateways split between hosted-redirect KYC (Stripe Connect onboarding links) and API-driven document flows (some Brazilian gateways). The interface here deliberately exposes only a reference, an optional URL, and an opaque requirements list; if the chosen gateway needs more (webhook-driven requirement re-collection, per-country fields), 8d may force additive interface growth. Mitigation: keep the fake's requirements list opaque strings, never typed fields.
- Ledger account taxonomy: Stage 8b's `ledger_entries.account` enum is exactly `gateway_receivable`, `gateway_fees`, `platform_commission`, `tenant_net`, and the payout pair uses two of them (debit `tenant_net`, credit `gateway_receivable`), so no enum extension or balance sign-convention change is needed. If 8d's real gateway surfaces a genuine clearing step, adding an account value then is additive.
- Column naming `amount` versus `*_amount`: data-conventions mandates `*_amount` columns while system-design 8.3 draws bare `amount` on `payments`, `refunds`, and `payouts`. This plan follows the diagram and the Stage 8a precedent (`payments.amount` shipped bare); task 7 carries the docs change amending data-conventions so the convention and the schema stop contradicting each other.
- Onboarding domain events: Reporting (Stage 11) or tenant-facing notifications may eventually want `SubmerchantActivated` or `PayoutFailed`. The registry does not define them and no consumer exists, so this stage stays silent; adding them later is additive (new event types, registry updated in the same change per event-conventions).
- Payout schedule changes after onboarding: `tenants.payout_schedule` is pushed once at `createSubmerchant`. Whether schedule edits re-push automatically (Tenancy event consumed by Payments) or manually is deferred to 8d, where the real gateway's schedule API determines what is possible.
- Retry-after-rejection semantics vary by gateway (new account versus revived account). The fake gateway revives the same reference; 8d must confirm against the real gateway and may add a `superseded` state.
- Multi-currency payouts: system-design 12 constrains ticket currency to the tenant settlement currency, so this stage assumes one payout currency per tenant. A tenant changing settlement currency mid-life is unhandled and should be rejected at the Tenancy layer until designed.
