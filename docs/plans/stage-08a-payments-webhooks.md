# Stage 8a Implementation Plan: Payments and Webhooks

Binding inputs: the Stage 8a section, "Method: the TDD loop", "Contract pipeline", and "Payment gateway posture" of [api-implementation-plan.md](../api-implementation-plan.md); [system-design.md](../system-design.md) sections 3.2, 7, 9, and 13; [api-conventions.md](../api-conventions.md), [data-conventions.md](../data-conventions.md), [event-conventions.md](../event-conventions.md); ADRs 004, 006, 008, 010, 013, 018.

## Scope and non-goals

This stage delivers the buyer-to-paid money path against a fake gateway:

- The `GatewayAdapter` interface with capability flags (supported methods, supported currencies, async confirmation, split support), per system-design 7.2.
- `FakeGateway`, an in-repo adapter with deterministic scenario controls (approve synchronously, confirm asynchronously after a delay, decline, expire, emit duplicate webhooks) and a webhook emitter for tests, per the master plan's "Payment gateway posture".
- The `payments` table and payment state machine (`initiated`, `confirmed`, `failed`, `expired`), every transition a conditional UPDATE checked by affected-row count.
- Payment method offer for an order: the union of methods from the tenant's enabled gateways that support the order currency (system-design 7.2), filtered by the per-event slow-method policy including the automatic low-inventory cutoff (system-design 7.4) and by open circuit breakers (system-design 13).
- Payment initiation with `Idempotency-Key` semantics: replays return the original result, key reuse with a different payload is rejected (api-conventions "Idempotency and Correlation", system-design 7.5).
- Webhook ingestion per gateway: signature verification, raw event persistence unique by gateway event ID, always 2xx after persist, normalized `PaymentConfirmed` and `PaymentFailed` processing driving the order state machine (system-design 7.4, 13).
- Payment expiry: hold extension to the method's confirmation window on initiation, an expiry sweeper, and conversion-time validation so an expired payment can never confirm late into a paid order.
- The `ReconcilePendingPayments` poller for `awaiting_payment` orders as the missed-webhook backstop (system-design 7.4, 13: every 5 minutes).
- Per-gateway circuit breaker (closed, open, half-open) that removes an open gateway's methods from the offer instead of degrading checkout (system-design 13).
- Paid-path side effects: `SendOrderConfirmation` through Resend (ADR 010) and `GenerateTicketPdf` (system-design 15.4), both idempotent outbox consumers of `TicketIssued` (system-design 9.2), tested with mail fakes and PDF assertions.

Non-goals, explicitly deferred:

- Refunds, the commission policy flag, and `ledger_entries` with the ordered ledger projection: Stage 8b. `PaymentConfirmed` is recorded here with a payload sufficient for the ledger projection, but nothing consumes it for ledger purposes yet.
- `payouts` mirroring and sub-merchant onboarding: Stage 8c.
- Any real gateway adapter, real webhook signature schemes, and sandbox contract tests: Stage 8d, gated on the launch gateway ADR.
- Waiting room interaction with payment throughput: Stage 10.
- Operational replay and reconcile commands beyond the scheduled poller: Stage 12.
- MFA gating of financially privileged capabilities was built in Stage 3; this stage adds no staff-facing financial mutations (refunds and payouts arrive in 8b and 8c), so no new MFA surface.

## Dependencies

Must already exist:

- Stage 1: RFC 9457 problem handler with the stable `code` registry, `Support/Money` value object and `{amount, currency}` wire transformer, Contract suite wiring against `docs/openapi/openapi.yaml`, real Isolation and Concurrency harnesses, and time control for TTL behavior (payment windows are TTL-driven).
- Stage 2: tenant resolution, `SET LOCAL app.tenant_id` transaction wrapper, RLS policy pattern, sentinel platform tenant, and the platform-scope cross-tenant role (system-design 4.3), which webhook processing uses to resolve the target tenant from an unauthenticated gateway callback. Section 4.3 currently authorizes that role for platform-scope staff only, so this stage ships a one-line 4.3 amendment sanctioning system use for webhook tenant resolution, every use activity-logged, the same pattern as the 9.3 registry update.
- Stage 3: customer authentication for the buyer-facing payment endpoints; activity log for auditing platform-role use during webhook resolution.
- Stage 4: outbox recording, `outbox_deliveries`, after-commit dispatcher, sweeper, and the idempotent-consumer pattern; this stage adds four producers and five consumers over four consumed event types.
- Stage 5a: `events.async_payment_policy` and ticket type currency constrained to the tenant settlement currency; the Tenancy `enabled_gateways` configuration from Stage 2.
- Stage 5c: medialibrary `media` table (UUID keys, non-null `tenant_id`), which `GenerateTicketPdf` attaches PDFs to.
- Stage 6: `holds` with `ExtendHold`, `CommitHold`, `ReleaseHold` Actions; payment initiation extends the hold, the paid transition commits it, failure and expiry release it.
- Stage 7: `orders` state machine with every transition a conditional UPDATE, `IssueTickets`, and the `TicketIssued` event; the buyer order status endpoint the storefront polls after initiating an async payment.

Later stages consume from this one:

- Stage 8b: the `GatewayAdapter::refund` capability, the `payments` rows refunds reference with their persisted `fee_amount` and `commission_amount` breakdown, and `PaymentConfirmed` (carrying the gateway fee) as the first input to the ledger projection.
- Stage 8c: the adapter interface's onboarding seam and the fake gateway's scenario machinery.
- Stage 8d: the entire adapter interface, webhook ingestion pipeline, and contract tests; the real adapter is a swap-in behind them.
- Stage 7's staff resend-tickets action activates now that `SendOrderConfirmation` exists, including the `qr_rotation_counter` bump Stage 7 deferred into this pathway.
- Stage 11: `PaymentInitiated`, `PaymentConfirmed`, `PaymentFailed`, `PaymentExpired` feed reporting projections.
- Stage 12: webhook signature negative tests and payment reconciliation commands build on this pipeline.

## Data model

### `payments` (tenant-scoped)

Per system-design 8.3, one row per payment attempt against an order.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | uuid | UUIDv7 via `HasUuids`, primary key |
| `tenant_id` | uuid | non-null |
| `order_id` | uuid | FK to `orders` |
| `gateway` | string | adapter identifier, e.g. `fake` |
| `method` | string | payment method, e.g. `card`, `pix`, `boleto` |
| `idempotency_key` | string | client-supplied `Idempotency-Key`, scopes API replay only; the gateway-facing key is generated server-side (system-design 7.5) |
| `request_hash` | string | hash of the canonicalized initiation payload, detects key reuse with a different request |
| `gateway_reference` | string, nullable | gateway-side payment identifier, set from the adapter response |
| `amount` | bigint | integer minor units, always the order total at initiation |
| `currency` | string(3) | paired with `amount` on the same row (ADR 018) |
| `fee_amount` | bigint | integer minor units, not null default 0; the gateway fee from the adapter's normalized confirmation, persisted by the confirmation Action on `initiated -> confirmed` |
| `commission_amount` | bigint | integer minor units, not null default 0; the platform commission persisted by the confirmation Action through a commission resolver that returns zero until Stage 8b lands the tenant commission configuration and wires the rate into the same Action |
| `status` | string | backed by a `PaymentStatus` enum: `initiated`, `confirmed`, `failed`, `expired` |
| `failure_code` | string, nullable | normalized gateway decline or expiry reason |
| `expires_at` | timestamptz, nullable | end of the method's confirmation window; null for synchronous methods |
| `confirmed_at`, `failed_at` | timestamptz, nullable | transition timestamps |
| `created_at`, `updated_at` | timestamptz | UTC |

Constraints and indexes:

- Unique `(tenant_id, idempotency_key)`: the idempotency guarantee is a constraint, not a read-then-write check; a violation on insert routes to the replay path.
- Unique `(gateway, gateway_reference)` where `gateway_reference` is not null (partial, named `payments_gateway_reference_idx` per data-conventions custom-name rule): webhook processing resolves exactly one payment.
- Index `(order_id)`.
- Partial index on `expires_at` where `status = 'initiated'`, named `payments_expiry_sweep_idx`, for the sweeper and poller scans.
- RLS policy comparing `tenant_id` to `current_setting('app.tenant_id')`, shipped in the same migration (data-conventions "Tenancy").

State transitions, all conditional UPDATEs checked by affected-row count, never read-then-write:

- `initiated -> confirmed` (webhook, synchronous approval, or poller), guarded by `status = 'initiated' AND (expires_at IS NULL OR expires_at > now())` so a payment past its window can never confirm, even before the sweeper has run
- `initiated -> failed` (synchronous decline or webhook failure)
- `initiated -> expired` (sweeper, or conversion-time validation)

`confirmed`, `failed`, and `expired` are terminal. A late webhook for an `expired` or past-window payment affects zero rows and is recorded as ignored, never applied.

### `gateway_webhook_events` (platform-scoped raw log)

Raw webhook persistence per system-design 7.4: persist first, always 2xx after persist, process asynchronously. A webhook arrives with no tenant context, so rows carry the sentinel platform tenant (data-conventions: platform-scope rows use the sentinel, never NULL); the tenant-scoped effect lives on `payments` and `orders`.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | uuid | UUIDv7 primary key |
| `tenant_id` | uuid | non-null, sentinel platform tenant |
| `gateway` | string | receiving adapter |
| `gateway_event_id` | string | the gateway's own event identifier |
| `payload` | jsonb | raw body as received |
| `status` | string | enum: `received`, `processed`, `ignored` |
| `received_at` | timestamptz | |
| `processed_at` | timestamptz, nullable | |
| `created_at`, `updated_at` | timestamptz | |

Constraints and indexes:

- Unique `(gateway, gateway_event_id)`: duplicate deliveries insert-conflict into a no-op and reuse the existing row, which is the idempotence anchor for the whole ingestion path.
- Index `(status, received_at)` for retention and stuck-event sweeps (retention window itself is Stage 12, system-design 14.3).
- RLS policy in the same migration, same pattern as every table.

### Additive changes to existing tables

- `orders.confirmation_sent_at` (timestamptz, nullable), new migration in the Orders context. `TicketIssued` is recorded per ticket, so an order with three tickets yields three events; the confirmation email must send once per order. The consumer claims the send with `UPDATE orders SET confirmation_sent_at = now() WHERE id = ? AND confirmation_sent_at IS NULL` and only the claim winner sends. Outbox delivery tracking alone cannot provide this because each ticket event has a distinct event ID.

### No new tables for

- Circuit breaker state: per-gateway counters and open-until timestamps live in Redis (cache, not system of record; a Redis flush simply closes breakers, which fails safe into normal error handling).
- Ticket PDFs: medialibrary `media` rows on the Ticket model, single-file collection `ticket_pdf` so duplicate generation converges to one attachment.
- Slow-method policy: interpreted from `events.async_payment_policy` (Stage 5a column), read through an EventCatalog Action, never by querying `events` from the Payments context.

## Domain events

All envelopes follow event-conventions: UUIDv7 `id`, global `sequence`, `type`, non-null `tenant_id`, `aggregate_type`/`aggregate_id`, `correlation_id`, `occurred_at`, laravel-data payload with snake_case keys, money as `{amount, currency}`. Recorded in the same transaction as the state change, without exception.

### Produced (Payments context, aggregate `payment`)

| Event | Recorded when | Payload |
| --- | --- | --- |
| `PaymentInitiated` | payment row created and adapter `createPayment` succeeded | `payment_id`, `order_id`, `gateway`, `method`, `amount` (money), `expires_at` (nullable) |
| `PaymentConfirmed` | `initiated -> confirmed` transition committed | `payment_id`, `order_id`, `gateway`, `method`, `amount` (money), `fee` (money, the gateway fee from the adapter's normalized confirmation), `gateway_reference`; together with the `fee_amount` and `commission_amount` the confirmation Action persists on the payment row, the payload is deliberately sufficient for the Stage 8b ledger projection |
| `PaymentFailed` | `initiated -> failed` transition committed | `payment_id`, `order_id`, `gateway`, `method`, `failure_code` |
| `PaymentExpired` | `initiated -> expired` transition committed | `payment_id`, `order_id`, `gateway`, `method` |

`PaymentExpired` is new: system-design 9.3 lists only `PaymentInitiated`, `PaymentConfirmed`, `PaymentFailed` for Payments, but the order state machine (system-design 7.1) distinguishes `expired` from `failed`, and event payload evolution is additive-only so overloading `PaymentFailed` with an expiry flag would blur a fact boundary. Per event-conventions "the catalog in system-design.md section 9.3 is the registry", the same change that introduces the event updates that list.

### Consumed

| Event | Consumer | Context | Effect |
| --- | --- | --- | --- |
| `PaymentConfirmed` | `HandlePaymentConfirmed` | Orders | calls the idempotent `MarkOrderPaid` Action: conditional `awaiting_payment -> paid`, `CommitHold`, `IssueTickets`, `TicketIssued` recorded, all in one transaction |
| `PaymentFailed` | `HandlePaymentFailed` | Orders | conditional `awaiting_payment -> failed`, `ReleaseHold` |
| `PaymentExpired` | `HandlePaymentExpired` | Orders | conditional `awaiting_payment -> expired`, `ReleaseHold` |
| `TicketIssued` | `SendOrderConfirmation` | Orders | one confirmation email per order via Resend, guarded by the `confirmation_sent_at` claim |
| `TicketIssued` | `GenerateTicketPdf` | Orders | renders the ticket PDF and attaches it to the ticket's `ticket_pdf` single-file media collection |

Idempotence notes:

- Every consumer records progress in `outbox_deliveries` and is idempotent by event ID; duplicate delivery is normal (event-conventions "Delivery and Consumption").
- The order-transition consumers are additionally idempotent by conditional UPDATE: a duplicate `PaymentConfirmed` finds the order already `paid`, affects zero rows, and skips ticket issuance entirely, so one webhook storm produces one state change and one ticket batch.
- `SendOrderConfirmation` is idempotent across distinct events for the same order via the `confirmation_sent_at` claim; `GenerateTicketPdf` is idempotent because the single-file collection replaces rather than accumulates, asserted as exactly one media row.
- Synchronous approval also routes through `MarkOrderPaid` (Payments invoking an Orders Action, allowed by the boundary rule in system-design 3.1): the buyer gets a `paid` order in the request, and the later `PaymentConfirmed` delivery to the Orders consumer is a zero-row no-op.
- Confirm-after-hold-expiry has a defined outcome, not an error path. The payment confirm and `MarkOrderPaid` run in separate transactions, and Stage 6's `CommitHold` refuses a dead hold, so a payment confirmed near the window edge can reach the consumer after its hold expired or was released. When `CommitHold` affects zero rows, `HandlePaymentConfirmed` applies the same conditional `awaiting_payment -> expired` transition `HandlePaymentExpired` uses, records the mismatch in the activity log, and raises an ops alert flagging the confirmed payment for refund; refund execution arrives in Stage 8b, so until then the alert is the compensating action. Slice 6 tests this edge.

## Endpoints

Snake_case JSON, laravel-data request and response objects as source of truth, errors as RFC 9457 problem documents with stable `code` values, every path added to `docs/openapi/openapi.yaml` before implementation (Contract pipeline). Buyer endpoints sit under the `/v1/storefront` prefix, the Host-resolved routing seam recorded by Stage 5a, resolve the tenant from `Host`, and require a customer token bound to that tenant and owning the order, matching the Stage 7 buyer order endpoints; gateway webhook ingestion stays under bare `/v1`.

### GET /v1/storefront/orders/{order}/payment-methods

The offer: the union of methods from the tenant's enabled gateways whose capability flags cover the order currency (system-design 7.2), minus methods excluded by the event's slow-method policy or the automatic low-inventory cutoff (system-design 7.4), minus methods of gateways with an open circuit breaker (system-design 13). Remaining inventory for the cutoff is read through an Inventory Action.

- Response 200: `PaymentMethodOfferData` list; each item `{method, gateway, confirmation: "sync"|"async", confirmation_window_minutes (nullable)}`.
- Errors: 404 `request.not_found` (order absent, other tenant, or not owned); 409 `order_not_payable` (order not in `pending`).

### POST /v1/storefront/orders/{order}/payments

Initiate payment. Requires `Idempotency-Key` (api-conventions "Idempotency and Correlation"). Request `InitiatePaymentData`: `{method}` plus method-specific fields the fake gateway defines (for cards this is the gateway token from hosted fields, never PAN data, system-design 7.5).

Behavior:

1. Insert the payment row (`initiated`) with the key and request hash; a unique violation on `(tenant_id, idempotency_key)` loads the original row, verifies `request_hash`, and replays the original response, or returns the mismatch problem.
2. Call `GatewayAdapter::createPayment` with a server-generated idempotency key, the payment row's UUID: globally unique, so two tenants supplying the same header value never collide at the gateway, and matching 7.5's generated-per-attempt key. The client header scopes API replay only and is never forwarded to the gateway.
3. Async pending: conditional `pending -> awaiting_payment` on the order (zero rows affected means a concurrent initiation won; mark this payment `failed` and return `order_not_payable`), `ExtendHold` to the method window, set `payments.expires_at`, record `PaymentInitiated`.
4. Sync approve: `initiated -> confirmed`, `pending -> awaiting_payment` then `MarkOrderPaid`, record `PaymentInitiated` and `PaymentConfirmed`, all in the request transaction.
5. Sync decline: `initiated -> failed`, record `PaymentInitiated` and `PaymentFailed`, leave the order in `pending` with the hold intact so the buyer retries with another method until the hold expires (system-design 7.6).

- Response 201: `PaymentData` `{id, order_id, status, gateway, method, amount: {amount, currency}, expires_at, next_action}` where `next_action` is `{type: "none"|"redirect"|"display_code", redirect_url?, code?}` from the adapter.
- Replay: 200 with the original `PaymentData` body.
- Errors: 400 `idempotency_key_missing`; 409 `idempotency_key_reuse_mismatch`; 404 `request.not_found`; 409 `order_not_payable`; 422 `payment_method_not_available` (not in the current offer, including policy and cutoff exclusions); 402 `payment_declined` (sync decline; hold intact, buyer retries); 503 `gateway_unavailable` with `Retry-After` (open breaker or adapter transport failure; no automatic retry on the interactive path, system-design 7.6).

### GET /v1/storefront/payments/{payment}

Buyer polls an async payment. Response 200: `PaymentData`. Errors: 404 `request.not_found`. The storefront polls the Stage 7 order endpoint for the order status flip; this endpoint reports the payment leg (and re-serves `next_action`, e.g. the Pix code).

### POST /v1/webhooks/{gateway}

Gateway-facing ingestion, one route per registered adapter (system-design 3.2: one webhook controller per gateway), unauthenticated, exempt from tenant resolution, signature-verified via `GatewayAdapter::parseWebhook`.

Behavior: verify signature; persist to `gateway_webhook_events` (insert-conflict on the unique key is a success that reuses the existing row); enqueue `ProcessGatewayWebhook` with the row ID after commit; return 200. After persist, nothing returns non-2xx (system-design 13): processing failures are the job's problem, and gateway retries land on the unique key.

- Response 200: empty body.
- Errors: 401 `webhook_signature_invalid` (nothing persisted; Stage 12's negative tests build on this); 422 `webhook_unparseable` (signature valid but no gateway event ID extractable).

`ProcessGatewayWebhook` (Payments job): loads the raw row, normalizes it via the adapter, resolves the payment by `(gateway, gateway_reference)` using the platform-scope role because the callback has no tenant (system-design 4.3, amended by this stage to sanction system use for webhook tenant resolution; every use is activity-logged), then applies the conditional payment transition inside a tenant-scoped transaction, recording `PaymentConfirmed` or `PaymentFailed`. Unmatched references or zero-row transitions mark the raw row `ignored` rather than erroring, so replays and late events are inert.

### Scheduled commands (no HTTP surface)

- `payments:expire` (sweeper): conditional `initiated -> expired` where `expires_at < now()`, recording `PaymentExpired` per row. Conversion-time validation is the backstop: the confirm transition's window guard (`status = 'initiated' AND (expires_at IS NULL OR expires_at > now())`) means a lagging sweeper never lets an expired-window payment through; the zero-row late confirm routes to the ignored path, and `MarkOrderPaid` re-validates the hold per Stage 6.
- `payments:reconcile` (`ReconcilePendingPayments`, every 5 minutes per system-design 13): for `initiated` payments on `awaiting_payment` orders past a grace period, query the adapter and apply the same normalized transitions. Idempotent against webhooks by the same conditional UPDATEs.

## TDD sequencing

Ordered slices, each through the full double loop: outside feature test first, contract next, inside unit tests, then green, refactor, `composer types:generate`, commit scoped `payments` (or `orders` for the consumer slices). Failing tests are written before any implementation in every slice; the three non-negotiable test-first rules (isolation for new tables, concurrency for guarded transitions, duplicate-delivery for consumers) are called out per slice.

### Slice 1: GatewayAdapter and FakeGateway

- Unit (first): interface conformance test every adapter must pass (capability flags shape; `createPayment` returns reference and next action; `parseWebhook` rejects bad signatures); FakeGateway scenario tests: scripted approve-sync, confirm-async-after-delay (fake clock), decline, expire, duplicate-webhook emission, each confirmation carrying a deterministic gateway fee; webhook emitter produces payloads whose HMAC the adapter verifies.
- No feature, contract, isolation, or concurrency tests: no endpoint or table yet.

### Slice 2: payments table and state machine

- Isolation (first, per the non-negotiable rule): cross-tenant read and write of `payments` fail under RLS before the migration is written.
- Unit: `PaymentStatus` enum transition table (every legal and illegal edge, including confirm past `expires_at` with the sweeper not run, which must affect zero rows under the window guard, fake clock); transition Actions assert affected-row counts; unique `(tenant_id, idempotency_key)` violation surfaces as the replay path.
- Concurrency (first for the guarded transition): parallel confirm and expire on one `initiated` payment yields exactly one terminal status.

### Slice 3: payment method offer

- Feature (first): matrix over enabled gateways, order currency, capability flags; slow methods excluded when the event policy disables them; automatic exclusion when remaining inventory drops below the cutoff; `order_not_payable` when the order is not `pending`.
- Contract: `GET /v1/storefront/orders/{order}/payment-methods` path and `PaymentMethodOfferData` schema; conformance asserted on the recorded responses.
- Isolation: another tenant's order returns `request.not_found`.
- Unit: offer assembly (pure function of gateway capabilities, tenant config, policy, inventory level).

### Slice 4: payment initiation and idempotency

- Feature (first): sync approve returns 201 with a `paid` order and issued tickets; async initiate returns 201 with `next_action` and the order in `awaiting_payment` with the hold extended (fake clock asserts the new `expires_at`); sync decline returns 402 `payment_declined` with the order still `pending` and the hold intact, and a second initiation with a new key succeeds; replay with the same key returns the original body with 200; same key different payload returns 409; missing header returns 400; method outside the offer returns 422.
- Contract: `POST /v1/storefront/orders/{order}/payments`, `InitiatePaymentData`, `PaymentData`, and every problem code above.
- Concurrency (first): parallel initiations with the same key produce one payment row and byte-identical responses; parallel initiations with different keys on one order produce exactly one `awaiting_payment` winner.
- Isolation: initiating against another tenant's order fails.
- Unit: request hashing; `MarkOrderPaid` invoked synchronously is idempotent by affected-row count.

### Slice 5: webhook ingestion (raw path)

- Isolation (first, per the non-negotiable rule): `gateway_webhook_events` cross-tenant probes fail under RLS before the migration is written (sentinel-tenant table still ships its policy and its isolation coverage).
- Feature: valid signature persists one row and returns 200; the same gateway event ID delivered twice persists one row and both requests return 200; invalid signature returns 401 `webhook_signature_invalid` and persists nothing; unparseable body returns 422.
- Contract: `POST /v1/webhooks/{gateway}` path with problem codes.
- Concurrency (first, ingestion uniqueness is invariant-guarding): parallel duplicate deliveries insert exactly one row.

### Slice 6: normalized processing and the order state machine

- Feature (first, the stage's centerpiece): async purchase end to end over HTTP: create hold, convert to order, initiate Pix via fake gateway, emit the confirmation webhook, assert the order is `paid`, inventory committed, tickets issued once, `fee_amount` persisted on the payment row and the `PaymentConfirmed` payload carrying the matching `fee`; the failure webhook drives `awaiting_payment -> failed` and releases the hold; a payment confirmed in-window whose hold has expired before the consumer runs takes the compensating path: order `expired`, mismatch activity-logged, ops alert flagging the payment for refund.
- Duplicate-delivery (first, per the non-negotiable rule): the same webhook delivered five times and the `ProcessGatewayWebhook` job executed repeatedly produce one payment transition, one order transition, one ticket batch.
- Concurrency: duplicate webhooks processed in parallel still one transition; webhook confirm racing the expiry sweeper ends in exactly one of (`paid` with committed inventory) or (`expired` with released hold), never both, never neither; a confirm arriving past `expires_at` with the sweeper lagging affects zero rows and routes to ignored.
- Unit: normalization mapping from fake gateway webhook shapes to transitions, including the gateway fee; the confirmation Action persists `fee_amount` from the normalized confirmation and `commission_amount` through the commission resolver (zero until Stage 8b) in the same transaction as `PaymentConfirmed`; `ignored` handling for unmatched references, late events, and past-window confirms.

### Slice 7: payment expiry and reconciliation poller

- Feature (first): fake clock advances past the Pix window, sweeper runs, payment `expired`, `PaymentExpired` recorded, order `expired`, hold released and availability restored (exact recovery per Stage 6 exit); a boleto-window order stays alive across the shorter Pix window.
- Unit: sweeper selects only `initiated` past `expires_at`; poller queries the adapter and applies transitions; poller and webhook double-processing is a zero-row no-op.
- Concurrency: sweeper racing a confirming webhook (shared with slice 6 matrix).

### Slice 8: circuit breaker

- Unit (first): closed to open after the failure threshold, half-open probe after cooldown with the fake clock, close on probe success.
- Feature: with the breaker open, the gateway's methods disappear from the offer and initiation returns 503 `gateway_unavailable` with `Retry-After`; other gateways' methods remain offered (system-design 13: never degrade the whole checkout).

### Slice 9: SendOrderConfirmation

- Duplicate-delivery (first): one `TicketIssued` event delivered repeatedly sends one email (`Mail::fake` assertions); three `TicketIssued` events for one three-ticket order send one email via the `confirmation_sent_at` claim.
- Feature: full paid path results in one queued Resend mailable to the customer, localized to `customers.locale` (system-design 12), containing order summary and ticket access; no static QR secret embedded (system-design 8.3 note: QR payloads are generated on render).
- Unit: claim UPDATE affected-row semantics; retry budget wiring (5 attempts, linear 1 minute, system-design 13).

### Slice 10: GenerateTicketPdf

- Duplicate-delivery (first): repeated delivery for one ticket yields exactly one `media` row in the `ticket_pdf` collection.
- Feature: paid path produces one PDF per ticket, stored via medialibrary on S3-compatible storage; assertions on existence, mime type, and non-trivial size; rendered content spot-checked via text extraction.
- Unit: renderer invocation behind an interface so the Gotenberg-or-dompdf choice (system-design 15.4) stays swappable.

### Slice 11: stage integration proof

- Feature: the scripted-scenario matrix end to end: every FakeGateway scenario (sync approve, async confirm, decline, expire, duplicate webhooks) driven over HTTP, asserting order status, inventory, tickets, emails, and PDFs per scenario. This is the 8a portion of the Stage 8 exit line and becomes the seed of Stage 12's smoke suite.

## Task breakdown

Ordered; each is a small, independently mergeable PR unless noted. Every task lands with its tests, contract fragment, regenerated TypeScript, and green Larastan and Pint.

1. `feat(payments): GatewayAdapter interface, capability flags, FakeGateway with scenario controls and webhook emitter` (slice 1). No routes, no tables.
2. `feat(payments): payments table with RLS, PaymentStatus enum, conditional transition Actions` (slice 2).
3. `feat(payments): payment method offer endpoint with slow-method policy and low-inventory cutoff` (slice 3). Depends on 1 and 2; the breaker exclusion arrives in task 9.
4. `feat(payments): payment initiation with Idempotency-Key semantics` (slice 4, sync paths only: approve and decline). Depends on 3.
5. `feat(payments): async initiation, hold extension to method windows, PaymentInitiated` (slice 4 remainder). Depends on 4.
6. `feat(payments): webhook ingestion with raw persistence and signature verification` (slice 5). Independent of 4 and 5 once 2 is merged; can proceed in parallel.
7. `feat(orders): PaymentConfirmed, PaymentFailed consumers driving the order state machine` plus `ProcessGatewayWebhook` normalization (slice 6). Depends on 5 and 6. Spans both contexts; split producer and consumer commits inside it by scope. Includes the system-design 4.3 amendment sanctioning system use of the cross-tenant role for webhook tenant resolution.
8. `feat(payments): expiry sweeper, PaymentExpired, reconciliation poller` plus the `feat(orders): HandlePaymentExpired` consumer (slice 7). Depends on 7. Spans both contexts like task 7; split commits by scope. Includes the system-design 9.3 registry update adding `PaymentExpired`.
9. `feat(payments): per-gateway circuit breaker` (slice 8). Depends on 3 and 4.
10. `feat(orders): confirmation_sent_at migration and SendOrderConfirmation consumer via Resend` (slice 9). Depends on 7.
11. `feat(orders): GenerateTicketPdf consumer with medialibrary storage` (slice 10). Depends on 7; parallel with 10.
12. `feat(orders): activate staff resend-tickets action` deferred from Stage 7. The activated pathway bumps each ticket's `qr_rotation_counter` through the Stage 7 codec primitive before re-sending, invalidating every previously rendered QR payload, with a test asserting a payload rendered before the resend no longer verifies while a fresh render does. Depends on 10.
13. `test(payments): scripted-scenario integration matrix` (slice 11). Depends on all above.
14. `docs: update roadmap Implementation Status and api-implementation-plan status table for Stage 8a`.

## Exit criteria

Each individually checkable; together they are the 8a share of the Stage 8 exit line ("the full purchase, confirmation, refund, and payout loop... balanced books" completes only with 8b and 8c).

1. A buyer completes a synchronous card purchase over HTTP against the FakeGateway: order `paid`, inventory committed, tickets issued, within the initiating request.
2. A buyer completes an async Pix purchase over HTTP: initiation returns `next_action`, the webhook confirms, the order reaches `paid`, and polling endpoints reflect every intermediate state.
3. Replaying a payment initiation with the same `Idempotency-Key` returns the original result; the same key with a different payload returns 409 `idempotency_key_reuse_mismatch`; the concurrency suite proves one payment row under parallel same-key initiation.
4. Duplicate webhook delivery, both serial and parallel, produces exactly one payment transition, one order transition, one ticket batch, one confirmation email, and one PDF per ticket.
5. Invalid webhook signatures return 401 and persist nothing; valid webhooks always return 2xx after persist, even when processing later fails.
6. A payment past its method window is expired by the sweeper: `PaymentExpired` recorded, order `expired`, hold released, availability restored exactly; a confirm racing the sweeper ends in exactly one outcome.
7. A synchronous decline returns 402 with the order still `pending` and the hold intact, and a retry with another method succeeds until the hold expires.
8. The offer endpoint excludes slow methods per event policy, excludes them automatically below the inventory cutoff, and excludes an open-breaker gateway's methods while other gateways remain; initiation against an open breaker returns 503 with `Retry-After`.
9. The reconciliation poller resolves an `awaiting_payment` order whose webhook was never delivered.
10. Isolation suite covers `payments` and `gateway_webhook_events`; the concurrency suite covers every guarded transition added; the architecture suite confirms Payments touches Orders and Inventory only through Actions and events.
11. Both new tables shipped their RLS policy in their creating migration; all endpoints have merged OpenAPI contracts with conformance passing; generated TypeScript committed without drift; Larastan and Pint clean.
12. System-design 9.3 lists `PaymentExpired` and 4.3 sanctions system use of the cross-tenant role for webhook tenant resolution; the roadmap and master plan status tables reflect Stage 8a.

## Risks and open questions

- Retry after async failure: system-design 7.1 makes `failed` terminal, and 7.6's "retry with another method" reads naturally for synchronous declines (resolved here by keeping the order `pending` on sync decline). A buyer whose async payment fails therefore cannot retry on the same order and must restart checkout. If product wants same-order retry, that is a new state machine edge and a design doc change, not a stage decision. Flagged for design review before 8b.
- `PaymentExpired` is an addition to the section 9.3 registry. The alternative (a `failure_code` of `expired` on `PaymentFailed`) was rejected because the order machine treats the outcomes as distinct states; confirm the design owner agrees when task 8 lands.
- `TicketIssued` granularity: settled by the Stage 7 plan, which records one event per ticket and puts `order_id` in the payload specifically so this stage's per-order consumers can key idempotence per order (stage-07-orders.md, "Domain events" and its open questions). The `confirmation_sent_at` claim is therefore the confirmed mechanism for one email per order, not a contingency.
- PDF renderer: system-design 15.4 allows Gotenberg or dompdf. dompdf is in-process and simpler for CI; Gotenberg is another container. Slice 10 hides the choice behind an interface; pick dompdf initially unless rendering fidelity forces Gotenberg, and record the choice in the task PR, not an ADR, since the design already scopes both.
- Sentinel-tenant webhook log: `gateway_webhook_events` cannot carry a real tenant at persist time. The sentinel approach satisfies data-conventions, but it means tenant-facing support tooling can only see webhook effects via `payments`, not raw payloads. Acceptable for 8a; revisit if Stage 12 support tooling needs tenant-visible raw events.
- Low-inventory cutoff threshold: platform default in `config/payments.php`, overridden per event by `async_payment_policy.low_inventory_cutoff`. The policy shape is fixed by Stage 5a's `AsyncPaymentPolicyData` (`slow_methods_enabled` bool, `low_inventory_cutoff` nullable int) with additive-only evolution; this stage consumes that shape as is. If a per-method disable list ever proves necessary, it enters as an additive optional field through the Stage 5a change process, never as a replacement of the boolean.
- Circuit breaker thresholds and cooldowns are config, tuned only under Stage 12 load tests; the risk of premature tuning is accepted.
- FakeGateway scenario scripting must stay test-deterministic under parallel test execution; scenarios are scripted per test through an injected scenario store, never global mutable state (Octane discipline, system-design 16.2).
- Resend is exercised through Laravel's mailer contract with `Mail::fake` in tests (ADR 010 keeps the provider swappable); no test ever hits the Resend API, so a real API key is a deployment concern only.
