# Nodia API Implementation Plan

This plan sequences the implementation of the complete Nodia API described in [system-design.md](system-design.md): every bounded context and capability, including reserved seating, promo codes, the waiting room, virtual events, and multi-language content. It covers the API only; no frontend work is scheduled here. All development is test-driven, and every endpoint ships with its OpenAPI contract.

Relationship to [roadmap.md](roadmap.md): the roadmap sequences the product MVP as vertically demoable slices including frontends. This plan sequences the full API surface. Where both are active, the roadmap decides what ships to users; this plan decides how the API grows underneath it. The convention docs ([api](api-conventions.md), [data](data-conventions.md), [event](event-conventions.md)) remain binding for every stage.

MVP thinning: when the roadmap is driving a product release, thin this plan rather than reorder it. Stage 5 collapses to 5a (general admission only), Stage 6 ships counters and holds without reserved seating, Stage 7 ships without promo codes, and Stage 10 waits for real load pressure. Reserved seating, promo codes, and multi-language depth beyond the default locale are within-stage capabilities the roadmap pulls in when it needs them, so the two documents never prescribe conflicting build orders. One cross-stage consequence to track when thinning: Stage 8a's `GenerateTicketPdf` consumes Stage 5c's media infrastructure, so collapsing Stage 5 to 5a means Stage 8a ships without ticket PDFs until 5c lands.

## Status

Update this table when a stage starts and when it merges.

| Stage | Status |
| --- | --- |
| Stage 1: Delivery Kernel and Test Harness | Done |
| Stage 2: Tenancy and RLS Regime | Done |
| Stage 3: Identity, AuthN, AuthZ | Done |
| Stage 4: Transactional Outbox | Done |
| Stage 5a: Catalog Core and Publish | Done |
| Stage 5b: Seating Templates | Done |
| Stage 5c: Search and Media | In progress |
| Stage 6: Inventory and Reserved Seating | Not started |
| Stage 7: Orders, Tickets, Promo Codes | Not started |
| Stage 8a: Payments and Webhooks | Not started |
| Stage 8b: Ledger and Refunds | Not started |
| Stage 8c: Payouts and Sub-merchant Onboarding | Not started |
| Stage 8d: Real Gateway Adapter (gated on ADR) | Blocked on ADR |
| Stage 9: Check-in and Offline Reconciliation | Not started |
| Stage 10: High-Demand On-Sales | Not started |
| Stage 11: Reporting and Exports | Not started |
| Stage 12: Compliance, Operations, Hardening | Not started |

## Method: the TDD loop

Every slice of work follows a double-loop cycle:

1. Write the outside feature test first: a Pest feature test hitting the endpoint over HTTP, asserting status codes, the wire shape, and problem-document `code` values for each failure mode. It fails because nothing exists.
2. Define the contract: laravel-data request and response objects (the source of truth per ADR 013) and the corresponding OpenAPI path in `docs/openapi/openapi.yaml`. The contract suite asserts real responses conform to the spec.
3. Drive the inside with unit tests: Actions, value objects, and state machines get their own failing tests for each invariant before implementation.
4. Go green, refactor, regenerate (`composer types:generate`), commit with the owning context's scope.

Test-first is non-negotiable in three places, because these are where the design says the platform lives or dies:

- Every new tenant-scoped table starts with a failing isolation test proving cross-tenant reads and writes fail under RLS.
- Every invariant-guarding transition (inventory counters, seat status, order status, promo usage) starts with a failing concurrency test against real PostgreSQL.
- Every outbox consumer starts with a failing duplicate-delivery test proving idempotence by event ID.

Test suites, extending the existing four:

| Suite | Drives |
| --- | --- |
| Feature | Endpoint behavior, wire contracts, error codes |
| Unit | Actions, value objects, state machines, ledger math |
| Contract (new) | Response conformance to `docs/openapi/openapi.yaml`, drift detection |
| Architecture | Context boundary enforcement |
| Isolation | RLS cross-tenant denial for every endpoint |
| Concurrency | Oversell, double-booking, promo limits, first-scan-wins |

Definition of done for any slice: feature and unit tests written first and passing, contract merged and conformance-checked, isolation coverage for new tables, architecture suite green, Larastan and Pint clean, generated TypeScript committed without drift.

## Contract pipeline (code-first)

laravel-data objects remain the single source of truth. The OpenAPI document grows per feature (api-conventions: no endpoint ships without its contract merged) and CI enforces two gates: the existing TypeScript drift check, and a new conformance check that validates recorded test responses against the spec. The concrete conformance tooling is selected at the start of Stage 1 against current library documentation, not assumed in advance; the mechanism (every feature test doubles as a contract assertion) is fixed regardless of tool. The gate must catch shape drift between the Data classes and the hand-maintained YAML, not only mismatches in recorded responses; if the selected tool cannot, generate the OpenAPI document from the Data classes instead of hand-editing it.

## Payment gateway posture

The launch gateway ADR is still open. The plan is gateway-agnostic: Stage 8 builds the `GatewayAdapter` interface, webhook ingestion, payment state machine, ledger, and payout mirroring entirely against an in-repo `FakeGateway` adapter with deterministic scenario controls (approve synchronously, confirm asynchronously after a delay, decline, expire, emit duplicate webhooks). This is strictly better for TDD: every failure mode is scriptable. The real adapter is Stage 8d, a bounded swap-in behind the same interface once the ADR lands. The ADR should still be recorded as early as possible, because sub-merchant onboarding and KYC shape vary by gateway and external sandbox approval has lead time.

## Stages

Stages are ordered by dependency. Within a stage, work proceeds endpoint by endpoint, each through the full TDD loop. Stages 5 and 8 are split into separately trackable slices so the status table never hides partial completion behind a single cell. Stages 9, 10, and 11 are independent of each other and can be reordered or parallelized; their gates differ. Stage 9 can start once Stage 7 merges (its own tests fabricate issued tickets), with purchase-path end-to-end coverage waiting on slices 8a through 8c (8d, blocked on the ADR, is never a gate). Stage 10 depends only on Stage 6 plus the foundational stages and waits for real load pressure. Stage 11 needs 8a and 8b for its sales and finance slices and Stage 9 for the attendance slice.

### Stage 1: Delivery Kernel and Test Harness

Goal: the machinery every later test depends on exists and is proven on the health endpoint.

Phase 0 already landed part of this stage: the correlation ID middleware with feature tests, `/v1/health` with its OpenAPI fragment, the Architecture suite, stub harnesses for the Isolation and Concurrency suites, and CI jobs that run both suites against real PostgreSQL. Problem documents exist only on the health degradation path; every other error still renders plain JSON. Remaining work:

- RFC 9457 problem+json exception handler with a stable error `code` registry and validation `errors` map, replacing the plain JSON rendering.
- `Support/Money`: minor-unit value object, Eloquent casts, laravel-data transformers for the `{amount, currency}` wire shape.
- Unit suite declared in `phpunit.xml`, and a local test matrix matching CI: `phpunit.xml` currently defaults to SQLite in memory, so Isolation and Concurrency must run against real PostgreSQL locally the way the CI jobs already do.
- Contract suite wiring: OpenAPI conformance assertions integrated into the feature test base, drift gate in CI.
- Real Isolation and Concurrency harnesses replacing the stubs: two-tenant fixture with an RLS-enabled test connection, parallel process runner against real PostgreSQL, both proven with deliberately failing probes.
- Time control for everything TTL-based (holds, tokens, payment windows).

Exit: `/v1/health` is contract-checked end to end; all six suites run in `composer test` and CI; a fake failing isolation test demonstrably blocks the build.

### Stage 2: Tenancy and RLS Regime

Goal: tenant context resolution and database-enforced isolation, the foundation every table after this builds on.

- `tenants` and `tenant_domains` with branding, locale configuration, and enabled-gateway configuration; platform admin create, read, and update endpoints for tenants (tenant DELETE is deferred until the design defines offboarding, earliest Stage 12) and full CRUD for their domains.
- RLS bootstrap: `SET LOCAL app.tenant_id` transaction wrapper, per-table policy pattern, sentinel platform tenant.
- The platform-scope cross-tenant database role (system-design 4.3), created now while the policy pattern is being defined, not retrofitted.
- Tenant resolution middleware for both populations: `Host` header against `tenant_domains` for storefront-facing routes, `X-Tenant-Id` validated against memberships for admin routes (membership validation activates in Stage 3).
- Domain verification endpoint (later consumed by Caddy on-demand TLS).

Tests first: the isolation suite's two-tenant fixture runs against `tenants`' first scoped child table; a resolution matrix feature test covers subdomain, custom domain, unknown host, and header mismatch.

Exit: cross-tenant access provably fails; platform role reads provably succeed and are flagged for audit once the activity log exists.

### Stage 3: Identity, AuthN, AuthZ

Goal: both identity populations can authenticate, and every subsequent endpoint has a real authorization layer to test against.

- Passport OAuth 2.0: short-lived JWT access tokens with explicit lifetimes, rotating refresh tokens with reuse detection, revocation, identity-type and tenant claims per api-conventions; staff password reset for forgotten credentials (enumeration-safe request endpoint, single-use time-limited token, revocation of live tokens on reset).
- `users`, `memberships`, custom RBAC (`roles` with global templates and per-tenant custom roles, flat capability sets), Gates and Policies evaluating capability plus tenant context. Global templates are `roles` rows with NULL `tenant_id` behind a template-aware RLS policy, the one sanctioned exception to the non-null `tenant_id` rule, recorded in data-conventions. The capability registry seeded here grows additively: each later stage that introduces a capability lands it with template-role wiring and authorization-matrix updates in the same change, and entries are never repurposed.
- MFA enrollment and enforcement for platform-scope staff and financially privileged roles (the mechanism is testable now; the payout and refund capabilities it guards arrive in Stage 8).
- `customers`: tenant-scoped, guest creation with nullable password, claim-by-email-verification flow, per-tenant email uniqueness.
- Activity log (activitylog migrations adjusted for UUIDs and non-null `tenant_id`), recording staff logins, tenant-scoped mutations, and cross-tenant platform role use.

Identity domain events (`UserInvited`, `UserRoleChanged`, `CustomerRegistered` per system-design 9.3) are recorded only once the Stage 4 outbox lands: this stage ships the Actions without producers, and Stage 4 attaches the recording calls as part of its scope.

Tests first: an authorization matrix (capability by role by tenant) as a data-driven feature test; token lifecycle tests including refresh reuse detection; MFA enforcement denial paths.

Exit: staff and customers authenticate independently; capability checks and audit trail cover every mutating endpoint that exists so far.

### Stage 4: Transactional Outbox

Goal: the event backbone, both recording and delivery, so every context after this records events from day one and consumers are TDD-able immediately.

- `outbox_events` (envelope per event-conventions, bigint identity `sequence`), recording API callable only inside a producing transaction.
- `outbox_deliveries`, after-commit dispatcher, Horizon queues, per-subscriber tracking, reconciliation sweeper with the stability-window semantics from system-design 9.1.
- Ordered-consumption helper for consumers that need per-aggregate sequence order (the ledger projection will be its first real user).
- Replay primitive: rescan in sequence order to rebuild a projection.
- Attach the identity event producers deferred from Stage 3 (`UserInvited`, `UserRoleChanged`, `CustomerRegistered`) and the Tenancy producers deferred from Stage 2 (`TenantCreated`, `DomainVerified`), each recorded by its Action, with the missing Tenancy rows added to the system-design 9.3 registry in the same change.

Tests first: recording rolls back with the producing transaction; duplicate delivery to an idempotent test subscriber causes exactly one effect; the sweeper re-enqueues a delivery stranded between commit and enqueue; ordered consumption defers an event whose predecessor is unprocessed.

Exit: at-least-once delivery with idempotent consumption proven end to end against Redis and real PostgreSQL; the identity and Tenancy Actions record their events.

### Stage 5: Event Catalog

Goal: the full catalog surface, including everything the roadmap deferred, in three separately trackable slices.

#### Stage 5a: Catalog Core and Publish

- `venues`; `events` with translatable name and description, timezone as data, draft/published/canceled lifecycle, virtual events with the exactly-one-of-venue-or-URL invariant, per-event async-payment policy; `ticket_types` with money columns and sales windows, currency constrained to the tenant's settlement currency (system-design 12); the settlement-currency column and its Tenancy read Action ship in this stage as an additive Tenancy change, because Stage 2 creates tenants without one.
- Publish and cancel Actions recording catalog events to the outbox.
- Admin list endpoints via query-builder with explicit allowlists; storefront read endpoints resolved from host, locale-negotiated, drafts invisible.

#### Stage 5b: Seating Templates

- `seat_maps` and `seats` as reusable venue templates; materialization onto events stays in Stage 6.

#### Stage 5c: Search and Media

- Media through medialibrary; PostgreSQL full-text search behind an interface that permits the Meilisearch upgrade path. The search index is a disposable derived index: its rebuild command scans current published events instead of replaying the outbox, a sanctioned exception to the Stage 4 replay-rebuild mechanism, because search documents derive entirely from current event state and historical payload equivalence is not required.

Tests first: publish state machine transitions, draft invisibility on every storefront path, locale negotiation and fallback, search relevance smoke tests.

Exit: a tenant can build and publish GA and virtual events entirely over the API, and a host-resolved storefront consumer sees exactly the published surface. Seated events can be built from templates here, but publishing them is only complete once Stage 6 adds `event_seats` materialization to the publish Action.

### Stage 6: Inventory and Reserved Seating

Goal: the oversell-proof core, driven by the concurrency suite.

- `ticket_type_inventory` counter rows; `holds` and `hold_items`; create, extend, release, and commit as atomic conditional UPDATEs checked by affected-row count.
- Expiry sweeper plus conversion-time validation (an expired hold is unusable even when the sweeper lags).
- Reserved seating: `event_seats` materialization on publish, unique on event and seat, conditional status transitions, per-event blocking and ticket-type zoning without touching venue templates.

Tests first: this stage inverts the usual order fully; parallel oversell and double-booking simulations are written and failing before the tables exist, and TTL behavior is driven through the fake clock.

Exit: no simulation configuration can oversell a ticket type or double-book a seat; availability arithmetic recovers exactly when holds expire.

### Stage 7: Orders, Tickets, Promo Codes

Goal: the order state machine and everything issued from it.

- Order creation from a valid hold (the order starts `pending`, inventory stays held); the full state machine from system-design 7.1 with every transition a conditional UPDATE. Held inventory commits to sold in the same transaction as the transition to `paid`; on `expired`, `failed`, or `canceled` the hold is released.
- Tickets issued only on the transition to paid; signed, rotatable QR payloads (HMAC over ticket, event, rotation counter) generated on render, never stored as static secrets.
- Promo codes: validity windows, discount math in minor units, atomic `usage_count` increments under `usage_limit`.
- Buyer-facing order status endpoints (the API surface the roadmap's checkout screens would consume).
- Staff-facing order list and detail, customer lookup, and a resend-tickets action, capability-gated and audited (the tenant operations surface from roadmap Phase 6; resend activates once the Stage 8a email consumer exists).

Tests first: state machine table tests covering every legal and illegal transition; promo limit concurrency simulation; QR signature verification including rotation-counter invalidation.

Exit: a hold becomes an order, a paid order issues tickets exactly once, and no promo code exceeds its limit under parallel redemption.

### Stage 8: Payments, Ledger, Payouts

Goal: the complete money path against the fake gateway, in three separately trackable slices.

#### Stage 8a: Payments and Webhooks

- `GatewayAdapter` interface with capability flags; `FakeGateway` with scriptable scenarios and a webhook emitter for tests.
- Payment initiation with `Idempotency-Key` semantics (replays return the original result); raw webhook persistence unique by gateway event ID, signature verification, always-2xx-after-persist ingestion; normalized confirmation and failure driving the order state machine; payment expiry and hold extension per async method windows (expiry is recorded as a new `PaymentExpired` event type, added to the system-design 9.3 registry in the same change); per-event slow-method policy including the automatic low-inventory cutoff.
- Reconciliation poller for `awaiting_payment` orders; circuit breaker per gateway removing an open gateway's methods from the offer.
- Paid-path side effects: `SendOrderConfirmation` through Resend (ADR 010) and `GenerateTicketPdf`, both outbox consumers of `TicketIssued`, idempotent by event ID, tested with mail fakes and PDF assertions.

#### Stage 8b: Ledger and Refunds

- Refunds, full and partial, with the per-tenant commission policy flag; append-only `ledger_entries` projected from outbox events by the ordered consumer (gross, gateway fee, platform commission, tenant net). The ledger consumer extends the Stage 4 ordered-consumption helper with a payload-derived ordering key, because `PaymentConfirmed` and `RefundCompleted` carry different envelope aggregates.

#### Stage 8c: Payouts and Sub-merchant Onboarding

- Sub-merchant onboarding abstraction and `payouts` mirroring, both exercised through the fake gateway.

Tests first: duplicate webhook delivery produces one state change, one ticket batch, one email, one PDF, one ledger set; idempotency replay; a ledger balance invariant asserted after every simulated purchase and refund sequence, including replay-rebuilt ledgers matching the original.

Exit: the full purchase, confirmation, refund, and payout loop runs over HTTP against the fake gateway with balanced books under every scripted failure mode.

### Stage 8d: Real Gateway Adapter

Gated on the launch gateway ADR. Implement the chosen gateway behind the existing interface: adapter, webhook signature scheme, sub-merchant KYC flow, sandbox contract tests against recorded fixtures. No other stage depends on this, but launch does; record the ADR early so sandbox access is secured before this stage is reached.

### Stage 9: Check-in and Offline Reconciliation

Goal: the full check-in contract from system-design 11, not the roadmap's reduced version.

- Manifest endpoint (ticket IDs, signature keys, status) scoped to event and check-in role; per-event key rotation.
- Scan recording as a first-scan-wins conditional transition; batch reconciliation endpoint for offline scan queues, resolving cross-device duplicates by timestamp and recording `DuplicateScanDetected` rather than dropping.
- Device identity on check-in records; check-in events to the outbox for reporting.

Tests first: cross-device duplicate reconciliation matrix (same ticket, two devices, offline overlap); signature validation against rotated keys; manifest scoping denial for other events and roles.

### Stage 10: High-Demand On-Sales

Goal: the waiting room and abuse controls.

- Redis sorted-set queue, gatekeeper admitting at a configurable rate, short-lived signed admission tokens required by the hold endpoint for flagged events, queue position endpoint.
- Application-level rate limiting tiers (hold creation stricter than browse); per-customer purchase limits enforced inside the hold transaction; challenge hook at queue entry.
- Availability read path from Redis caches with second-level TTLs, never authoritative.

Tests first: hold creation without an admission token fails for flagged events; purchase limit concurrency simulation; availability display converges after cache expiry.

### Stage 11: Reporting and Exports

Goal: projections and the read surface for dashboards and finance.

- Projectors per subscribed event type building pre-aggregated read models (sales, attendance, per-event finance); dashboard read endpoints; `BuildExport` with cursor-paginated sources.
- Rebuild tooling: any projection reconstructs from outbox replay and matches its incremental state.

Tests first: projection idempotence under duplicate delivery; rebuild equivalence (replayed aggregates identical to incrementally built ones).

### Stage 12: Compliance, Operations, Hardening

Goal: everything an operator or regulator needs, and the proof the whole surface holds.

- GDPR and LGPD: anonymize-in-place erasure marking `anonymized_at`, per-tenant data subject export; retention windows for raw webhooks and logs; outbox archival to object storage.
- Operational commands: replay failed deliveries, reconcile payments, release stuck holds, all support-safe and audited.
- Security sweep: isolation suite coverage asserted for every endpoint, authorization matrix completeness check, webhook signature negative tests, secret handling review.
- API-level smoke suite: publish, purchase, async confirmation, check-in, refund, entirely over HTTP against the fake gateway.
- Load tests focused on hold creation, payment initiation, and waiting room admission, with results recorded. The waiting-room scenario applies once Stage 10 has shipped; an MVP-thinned release records the omission in the load doc and does not count as completing this stage against the full plan.

Exit: the smoke suite is a CI gate; no endpoint lacks isolation and contract coverage; load targets are documented with headroom.

## Open decisions

- Launch gateway ADR: does not block any stage except 8d, but should be recorded as early as possible for sandbox and KYC lead time.
- Categories: named in the system design's Event Catalog context description but absent from its data model; deferred until the design defines them.
- OpenAPI conformance tooling: selected at Stage 1 start against current documentation.
- Meilisearch adoption: deferred behind the search interface until PostgreSQL full-text search demonstrably falls short.
- Orchestrator: unchanged from ADR 017, out of scope for this plan.
- Cross-cutting design questions raised by the stage plans (infrastructure use of the platform-scope role, `DomainVerified` trigger semantics, the settlement-currency column shape, the `events.seat_map_id` linkage, refund-arc completion in system-design 7.1): tracked in each stage plan's risks and open-questions section, which is authoritative until the owning stage amends the design docs.
