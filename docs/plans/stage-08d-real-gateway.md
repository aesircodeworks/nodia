# Stage 8d: Real Gateway Adapter

Status: Blocked on the launch gateway ADR (api-implementation-plan.md, Status table and Open decisions). This plan is written so that every gateway-agnostic piece can start before the ADR lands, and every gateway-specific piece is explicitly marked "pending ADR". When the ADR merges, the pending items are filled in with the chosen gateway's concrete details and this plan is updated in the same change.

## Scope and non-goals

### Delivers

- A production adapter for the launch gateway implementing the `GatewayAdapter` interface Stage 8a defined (`createPayment`, `capture`, `refund`, `parseWebhook`, plus capability flags), living in `app/Payments/Gateways/` per system-design 3.2. The concrete gateway is pending ADR; the skeleton, registration, and conformance tests are not.
- The real gateway's webhook signature verification scheme behind the per-gateway verifier seam Stage 8a built, and the gateway's dedicated ingestion route wired to the shared always-2xx-after-persist pipeline (system-design 7.4, 13). Signature algorithm and header names are pending ADR.
- The sub-merchant KYC flow for the chosen gateway behind the onboarding abstraction Stage 8c built, mapping gateway-specific account status vocabulary into the platform's normalized onboarding states (system-design 7.3; ADR 006). Gateway status vocabulary and flow shape (hosted onboarding link versus API-driven) are pending ADR.
- A sandbox contract-test harness: recorded, sanitized fixtures of real sandbox HTTP exchanges replayed deterministically in CI, plus a manually triggered record mode that refreshes fixtures against the live sandbox. This harness is gateway-agnostic machinery and starts now.
- An adapter conformance suite: the behavioral contract every `GatewayAdapter` must satisfy, extracted from the Stage 8a `FakeGateway` tests and run against both adapters. Gateway-agnostic; starts now.
- Environment and tenant wiring: the real gateway becomes selectable in `tenants.enabled_gateways` (system-design 8.1) and appears in checkout offers only where its capability flags fit the order currency (system-design 7.2; ADR 008). Credentials come from the secret store, never committed configuration (system-design 14.1).

### Non-goals

- No changes to the payment state machine, order state machine, ledger, refund logic, or payout mirroring semantics. Those are Stage 8a, 8b, and 8c behavior; Stage 8d only feeds them through a different adapter. If the real gateway exposes a failure mode the fake cannot script, the fake gains that scenario in this stage and the state machine change is a flagged deviation, not silent scope growth.
- No second gateway. The roadmap explicitly prefers one complete gateway over several partial adapters (roadmap.md, Sequencing Principles). Additional adapters reuse this stage's conformance suite and harness later.
- No payment-method routing UI or tenant-facing gateway management screens; the API exposes configuration, frontends are out of scope for the whole plan.
- No changes to PCI posture: gateway-hosted fields or redirect flows only, opaque tokens plus last-four and brand stored, SAQ-A target (system-design 7.5). If the chosen gateway's integration mode would widen scope, that is an ADR-level rejection criterion, not something this stage absorbs.
- Chargeback and dispute ingestion is deferred to Stage 12 unless the ADR concludes the launch market makes it a launch blocker.

## Dependencies

### Requires (must exist before this stage starts implementation)

- Stage 8a: the `GatewayAdapter` interface with capability flags, `FakeGateway`, payment initiation with `Idempotency-Key` replay semantics, raw webhook persistence unique by gateway event ID, signature verification seam, normalized `PaymentConfirmed` and `PaymentFailed` driving the order state machine, reconciliation poller, and the per-gateway circuit breaker (system-design 7.4, 13).
- Stage 8b: refund execution path and the ledger projection, so refund fixtures have something to drive.
- Stage 8c: the sub-merchant onboarding abstraction and `payouts` mirroring, so the KYC flow and payout fixtures have a port to implement.
- Stage 2: tenant `enabled_gateways` configuration and RLS regime.
- Stage 1: contract suite, problem-document handler, time control.
- The launch gateway ADR, merged, before any pending-ADR task begins. Sandbox credentials and sub-merchant sandbox approval secured; the ADR should record who owns that access.

### Consumed by

- No later stage depends on 8d (api-implementation-plan.md, Stage 8d section). Launch depends on it. Stage 12's smoke suite continues to run against the fake gateway; the sandbox harness from this stage is the real-gateway counterpart, run on its own cadence.

## Data model

The gateway-agnostic baseline adds no new tables. `payments` (gateway, method, idempotency_key, gateway_reference, amount, currency, status, expires_at), `refunds`, and `payouts` all exist from Stages 8a through 8c (system-design 8.3), and the raw webhook table exists from Stage 8a (`gateway_webhook_events` in the 8a plan; raw webhook persistence per system-design 7.4 and 13).

Pending ADR, expected additive migrations, each shipped with its RLS policy in the same migration if the table is tenant-scoped (data-conventions, Tenancy):

- Additional opaque reference columns if the chosen gateway needs more than one identifier per payment, refund, or payout (for example a separate intent ID and charge ID). Columns are nullable strings named `gateway_*`; no gateway payloads are decomposed into columns beyond what queries need. Raw payloads already persist in the webhook table.
- Sub-merchant account columns on the Stage 8c onboarding table if the gateway's KYC flow requires state the abstraction did not anticipate (for example a hosted-onboarding link expiry). Normalized status stays the platform enum; the gateway's raw status string is stored alongside it for support and reconciliation.
- A unique index on the gateway event ID for the new gateway's webhook rows already exists from 8a's per-gateway uniqueness constraint; verify it covers the new gateway slug rather than assuming.

Constraints that carry over unchanged: all money columns are integer minor units paired with `currency` (ADR 018), all primary keys UUIDv7 via `HasUuids` (ADR 005), merged migrations are never edited, and any new tenant-scoped table (none expected) starts with a failing isolation test.

Credentials and secrets: platform-level gateway API keys and webhook signing secrets live in the secret store and reach the app as environment configuration (system-design 14.1). Per-tenant state (which gateways are enabled, sub-merchant account references) lives in `tenants.enabled_gateways` and the 8c onboarding table. No secret material is ever stored in tenant rows or fixtures.

## Domain events

### Produced

None new. Stage 8d maps gateway webhooks and API responses into the existing Payments events: `PaymentInitiated`, `PaymentConfirmed`, `PaymentFailed`, `RefundInitiated`, `RefundCompleted`, `PayoutExecuted` (system-design 9.3, group 5). The envelope is unchanged per event-conventions: UUIDv7 event ID, global sequence, type, non-null tenant_id, aggregate reference, correlation ID, occurred_at, snake_case laravel-data payload, recorded in the same transaction as the state change.

If Stage 8c introduced a sub-merchant onboarding status event, the real KYC flow records it through the same 8c Action; 8d never records events directly from webhook parsing, it normalizes and calls the owning Action. If the chosen gateway surfaces a fact the current registry cannot express (for example a chargeback opened), that is a new event type added to the system-design 9.3 registry in the same change (event-conventions, Naming and Registry), and it is pending ADR.

### Consumed

None new. The ledger projection, email, and PDF consumers already subscribe to the normalized events and are unaware of which adapter produced them; that indifference is asserted by the conformance suite.

### Idempotence

- Webhook ingestion remains idempotent by gateway event ID: duplicate deliveries of the same real fixture produce one raw row, one normalized event, one state change (the Stage 8a invariant, re-proven here with real payload shapes).
- Outbound calls remain idempotent by the `payments.idempotency_key` passed to the gateway on creation and every retry (system-design 7.5). The conformance suite asserts the real adapter transmits the key; the fixture harness asserts the sandbox honors replay (pending ADR: the gateway's idempotency mechanism, header versus body field).
- Payload evolution stays additive-only; a gateway API version bump that changes shapes means new fixtures and, if the normalized payload must change breakingly, a new event type, never a version field (event-conventions, Payloads).

## Endpoints

Stage 8d adds at most two routes; everything else reuses the Stage 8a surface.

- `POST /v1/webhooks/{gateway-slug}`: the dedicated ingestion endpoint for the chosen gateway, one webhook controller per gateway (system-design 3.2, 7.4). Unauthenticated but signature-verified; verifies the gateway's signature scheme, persists the raw event keyed by gateway event ID, returns 2xx after persistence regardless of downstream processing, enqueues normalization. Request body is the gateway's raw payload, not modeled as a laravel-data request object, following the ingestion-route exception Stage 8a established for `POST /v1/webhooks/{gateway}` (stage-08a plan, Endpoints); that exception belongs in api-conventions.md alongside the 8a route, not as a new decision here; the response is an empty 202-style acknowledgment Data object consistent with the 8a fake-gateway webhook route. Error responses (only for signature failure or malformed envelope before persistence) are RFC 9457 problem documents with stable codes `webhook_signature_invalid` and `webhook_unparseable`, matching the codes 8a registered. The concrete slug, signature headers, and timestamp-tolerance rules are pending ADR. The OpenAPI contract for the route merges with the route (api-conventions, Requests and Responses).
- Pending ADR, only if the gateway uses redirect-based flows: a buyer return route such as `GET /v1/payments/{payment}/return` that never trusts redirect parameters for state, only triggers reconciliation against the gateway and redirects the buyer to the storefront order status page. If the gateway is purely hosted-fields plus webhooks, this route does not exist.

Existing endpoints whose behavior this stage extends without contract changes: payment initiation (the real gateway's methods appear in the offer union when enabled and currency-compatible, system-design 7.2), the Stage 8c onboarding endpoints (real KYC link or account creation behind the same request and response Data objects), and the payout listing (real payout objects mirrored). Any new error condition the real gateway introduces gets a stable `code` added to the registry and the contract, for example `gateway_unavailable` (circuit open) if 8a did not already register it.

## TDD sequencing

Slices 1 through 4 are gateway-agnostic and can start immediately, before the ADR. Slices 5 through 8 are pending ADR. Every slice follows the master plan's double loop: outside feature test first, contract second, inside unit tests third, green, regenerate types, commit with scope `payments`.

### Slice 1: adapter conformance suite (gateway-agnostic)

Failing tests first:

- Unit: a shared conformance test case, parameterized by adapter, asserting the `GatewayAdapter` behavioral contract: `createPayment` returns a normalized result carrying the gateway reference and echoes the idempotency key; `refund` on an unknown reference returns a typed failure, not an exception; `parseWebhook` on a tampered signature throws the typed verification failure; capability flags are internally consistent (an adapter claiming async confirmation must declare which methods confirm asynchronously). Run first against `FakeGateway`; it must pass unchanged, proving the contract was extracted, not invented.
- Unit: adapter registry resolution by gateway slug; resolving a slug whose adapter is not bound fails with a typed error; an adapter present in code but not enabled for the tenant is never offered (feature-level assertion deferred to slice 4).

### Slice 2: recorded-fixture harness (gateway-agnostic)

Failing tests first:

- Unit: fixture loader reads a recorded exchange (request matcher plus canned response) from `tests/Fixtures/gateways/{slug}/`, and replaying a request the fixture set does not cover fails the test loudly rather than falling through to the network.
- Unit: sanitizer proves recorded fixtures contain no secret material: a fixture containing a known-format credential, bearer token, or PAN-like digit run fails a guard test that scans the fixture directory. This guard runs in CI permanently.
- Unit: record mode is inert in CI: attempting to record while `CI` is set fails immediately.

Implementation: an HTTP fake layer keyed by fixture files, a `php artisan gateway:record-fixtures {slug} {scenario}` command that hits the live sandbox with real credentials from the environment, sanitizes, and writes fixtures. Recording is a manual, documented act; CI only replays.

### Slice 3: adapter and verifier skeletons (gateway-agnostic)

Failing tests first:

- Unit: a `PendingGatewayAdapter` skeleton implements the interface, declares placeholder capability flags of "supports nothing", and every operation throws a typed `GatewayNotConfigured` error.
- Feature: with the skeleton registered but its capabilities empty, checkout offers for a tenant that enabled it contain no methods from it and the offer response is otherwise unchanged; initiating a payment that somehow names it returns a problem document with code `gateway_not_configured` (distinct from `gateway_unavailable`, which signals an open circuit breaker; see Endpoints).
- Unit: webhook verifier seam accepts a per-gateway verifier; the skeleton verifier rejects everything, and ingestion for its slug returns the `webhook_signature_invalid` problem without persisting.

### Slice 4: KYC flow abstraction hardening (gateway-agnostic)

Failing tests first:

- Unit: the Stage 8c onboarding port is exercised with a second fake implementation that uses a different status vocabulary, proving the normalization mapping is data-driven per adapter and unknown gateway statuses land in a quarantined `needs_review` state rather than throwing or silently mapping.
- Feature: onboarding status endpoint renders only the normalized status enum on the wire; the raw gateway status never leaks into responses.
- Concurrency: status normalization applies via conditional UPDATE on the onboarding row (guard on current status, checked by affected-row count) so a stale webhook cannot regress a completed onboarding.

### Slice 5: real adapter, payment happy path (pending ADR)

Failing tests first:

- Contract/Feature: the full Stage 8a purchase feature test re-run with the real adapter substituted and HTTP replayed from fixtures: initiate, awaiting_payment, webhook confirm, paid, tickets issued once. Written against fixture names before the fixtures exist; recording them is part of going green.
- Unit: request mapping (order, amount as integer minor units plus currency, idempotency key, sub-merchant split reference) and response normalization for `createPayment` and capture.
- Unit: circuit breaker wraps the real adapter's transport; a scripted transport failure opens the breaker and the offer endpoint drops the gateway's methods (system-design 13).

### Slice 6: real webhooks and failure modes (pending ADR)

Failing tests first:

- Feature: signature verification against a real recorded webhook with the sandbox signing secret's test-key equivalent; positive, tampered-body, wrong-key, and stale-timestamp cases each mapped to the stable codes.
- Feature: duplicate delivery of the same recorded webhook produces one raw row, one normalized event, one order transition, one ticket batch, one email, one PDF, one ledger set (the Stage 8a/8b invariant with real payloads).
- Feature: decline, expiry, and async-confirm-after-delay fixtures drive `PaymentFailed`, payment expiry, and delayed `PaymentConfirmed` respectively; the reconciliation poller path is exercised with fixtures for the gateway's payment-status query.
- Concurrency: webhook confirm racing payment-expiry sweeper resolves by conditional UPDATE, exactly one wins.

### Slice 7: real refunds, KYC, payouts (pending ADR)

Failing tests first:

- Feature: full and partial refund through the real adapter from fixtures, idempotency key on retry, ledger balanced after each (8b invariant).
- Feature: KYC flow end to end from fixtures: create sub-merchant, receive status webhooks or poll, normalized transitions land, `needs_review` on unknown status.
- Feature: payout webhook or poll fixtures mirror into `payouts` and reconcile against ledger balances (8c invariant, real shapes).

### Slice 8: sandbox verification (pending ADR)

Not CI-per-PR. A manually triggered (and optionally nightly) suite that runs the recorded scenarios against the live sandbox and diffs actual responses against fixtures, flagging drift. Failing test first: the drift detector itself, proven by mutating a fixture and asserting detection.

## Task breakdown

Ordered; each independently mergeable unless noted. Tasks 1 through 5 need no ADR.

1. Extract the adapter conformance suite from the 8a FakeGateway tests; FakeGateway passes it unchanged. Scope `payments`.
2. Fixture harness: loader, no-network guard, sanitizer guard test, record command inert in CI. Scope `payments`.
3. `PendingGatewayAdapter` and skeleton verifier registered behind an environment flag, offer exclusion and `gateway_not_configured` problem code, OpenAPI updated if the code is new. Scope `payments`.
4. KYC abstraction hardening: data-driven status mapping, `needs_review` quarantine, conditional-UPDATE status transitions, wire shape asserted. Scope `payments`.
5. Sandbox drift detector and the operational doc: how to obtain sandbox credentials, record fixtures, and rotate the webhook signing secret. Doc lives beside the harness, not in this plan. Scope `payments`.
6. (ADR gate) Record the launch gateway ADR; update this plan's pending items with concrete names; secure sandbox access and sub-merchant sandbox approval.
7. Real adapter transport plus `createPayment` and capture mapping; conformance suite green against fixtures; circuit breaker wiring. Scope `payments`.
8. Real webhook verifier and ingestion route with contract; signature negative matrix; duplicate-delivery invariant with real payloads. Scope `payments`.
9. Failure-mode fixtures: decline, expiry, delayed confirm, reconciliation poller query. Scope `payments`.
10. Refund execution through the real adapter with idempotent retry; ledger balance assertions. Scope `payments`.
11. KYC flow implementation against the 8c port; onboarding fixtures. Scope `payments`.
12. Payout mirroring fixtures and reconciliation. Scope `payments`.
13. Enablement: real gateway added to the allowed values for `tenants.enabled_gateways`, offer union verified per currency capability, environment flag removed or defaulted on for staging. Scope `payments`.
14. Sandbox verification suite wired as a manual workflow; first green run recorded. Scope `ci`.
15. Update the master plan status table and the roadmap Implementation Status table. Scope `docs`.

## Exit criteria

The stage line "Implement the chosen gateway behind the existing interface: adapter, webhook signature scheme, sub-merchant KYC flow, sandbox contract tests against recorded fixtures" expands to:

1. The launch gateway ADR is merged and this plan's pending items are resolved to concrete names.
2. The real adapter passes the same conformance suite as `FakeGateway`, with zero changes to the suite made for its benefit that the fake does not also pass.
3. The full purchase loop (initiate, awaiting_payment, webhook confirm, paid, tickets, email, PDF, ledger) passes over HTTP with the real adapter replayed from fixtures, and duplicate webhook delivery produces exactly one of each effect.
4. Webhook signature verification passes the positive case and rejects tampered-body, wrong-key, and stale-timestamp cases with stable problem codes, all against real recorded payloads.
5. Idempotency keys are transmitted on `createPayment` and refund creation and on every retry, asserted against fixture requests.
6. Full and partial refunds execute through the real adapter and the ledger balance invariant holds after every scripted sequence, including a replay-rebuilt ledger matching the original.
7. A sandbox sub-merchant completes the KYC flow through the abstraction; every gateway status maps to a normalized state or quarantines as `needs_review`; no raw gateway status appears on the wire.
8. Sandbox payouts mirror into `payouts` and reconcile against ledger balances.
9. An open circuit breaker for the real gateway removes its methods from the checkout offer without degrading other gateways.
10. The fixture directory passes the secret-scan guard; record mode is proven inert in CI; the drift detector catches a deliberately mutated fixture; a live-sandbox verification run is green and its trigger is documented.
11. No new event types were added, or any that were are registered in system-design 9.3 in the same change.
12. All six suites green, contract conformance and TypeScript drift gates green, Larastan and Pint clean, isolation coverage exists for any new tenant-scoped table (expected: none).

## Risks and open questions

- ADR timing is the dominant risk. Sandbox access and especially sub-merchant KYC sandbox approval have external lead time (api-implementation-plan.md, Payment gateway posture). Mitigation: slices 1 through 4 proceed now; the ADR owner secures sandbox access as part of recording it.
- Fixture staleness: recorded fixtures drift from the live sandbox as the gateway evolves. Mitigation: the slice 8 drift detector on a manual or nightly cadence; fixture refresh is a documented one-command act.
- Fake-gateway fidelity: the real gateway may exhibit orderings or partial states the fake cannot script (webhooks arriving before the create call returns, refund webhooks without a preceding API acknowledgment). Each such discovery adds a scenario to `FakeGateway` so Stages 8a through 8c invariants are re-proven under it; treat these as scope for this stage, not deferred cleanup.
- KYC shape variance: hosted-link onboarding versus API-document flows differ enough that the 8c abstraction may need extension. The `needs_review` quarantine bounds the blast radius; extending the port is an additive change flagged in review, not silently absorbed.
- Split-payment fit: ADR 006 requires marketplace or split support; if the chosen gateway's split model settles fees differently than the ledger projection assumes (gross versus net settlement), ledger entry derivation for gateway fees needs a per-adapter strategy. Open question for the ADR: does the gateway report fees per charge, per payout, or both?
- Redirect flows: whether a buyer return route exists at all is pending ADR; if it does, it must never trust redirect parameters (reconcile against the gateway instead), and it adds a storefront-facing contract.
- Chargebacks: explicitly deferred to Stage 12, but the ADR should confirm the launch market's dispute volume tolerates that deferral.
- Webhook secret rotation: the verifier must support at least two active signing secrets to rotate without a rejection window. Confirm the chosen gateway supports overlapping secrets; if not, document the rotation runbook accepting a brief 401 window on the ingestion route (raw persistence still occurs only after verification, so rotation timing matters).
- Currency coverage: capability flags must reflect the sandbox reality, not documentation; the conformance suite should assert offered currencies against a fixture-backed capability probe if the gateway exposes one.
