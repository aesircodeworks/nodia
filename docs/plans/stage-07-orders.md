# Stage 7: Orders, Tickets, Promo Codes

Implementation plan for Stage 7 of [api-implementation-plan.md](../api-implementation-plan.md). The Orders bounded context (`app/Orders`) gains its full surface: the order state machine from system-design 7.1, ticket issuance with signed rotatable QR payloads, promo codes with atomic usage limits, and the buyer-facing and staff-facing endpoints over all of it. All conventions in [api-conventions.md](../api-conventions.md), [data-conventions.md](../data-conventions.md), and [event-conventions.md](../event-conventions.md) are binding, as are the master plan's "Method: the TDD loop" and "Contract pipeline" sections.

## Scope and non-goals

### Delivered in this stage

- `orders`, `order_items`, `tickets`, and `promo_codes` tables with RLS policies in the same migrations.
- `ConvertHoldToOrder`: order creation from a valid hold. The order starts `pending` and inventory stays held (system-design 7.1); an expired hold cannot convert even when the Stage 6 sweeper lags (system-design 6.1). The Action accepts a hold whose `customer_id` is null or already equals the authenticated customer: inside the conversion transaction it attaches the customer with a conditional UPDATE (`SET customer_id = :customer WHERE id = :hold AND (customer_id IS NULL OR customer_id = :customer)`) checked by affected-row count, so Stage 6's anonymous holds gain an owner exactly once. A hold owned by a different customer converts as `hold_not_found`, so hold IDs never leak ownership.
- The full order state machine (`pending`, `awaiting_payment`, `paid`, `expired`, `failed`, `canceled`, `partially_refunded`, `refunded`) with every transition implemented as a conditional UPDATE checked by affected-row count (system-design 7.1, data-conventions). Transition Actions for `awaiting_payment`, `paid`, `expired`, `failed`, and the refund states ship and are fully tested here; Stage 8 wires payments and refunds to them.
- Ticket issuance exactly once, only on the transition to `paid`, in the same transaction that commits the hold from held to sold via Inventory's `CommitHold` (system-design 7.1, stage note). On `expired`, `failed`, or `canceled`, the hold is released via Inventory's `ReleaseHold` in the same transaction.
- Signed, rotatable QR payloads: HMAC over ticket ID, event ID, and a per-ticket rotation counter, generated on render and never stored as a static secret (system-design 8.3 notes, 14.4).
- Promo codes: validity windows, percentage and fixed-amount discount math in integer minor units, atomic `usage_count` increments under `usage_limit` using the same conditional-update pattern as inventory (system-design 8.3 notes).
- Buyer-facing endpoints: create order, order status, order tickets with QR payloads, cancel pending order, promo code check.
- Staff-facing endpoints: order list and detail (query-builder, cursor pagination), promo code CRUD, resend-tickets action, and customer lookup (implemented in the Identity context, since Orders never touches `customers`). All capability-gated and recorded in the activity log.
- Domain events: `OrderCreated` and `TicketIssued` produced to the outbox in the producing transaction; `HoldExpired` consumed to cancel stranded pending orders.

### Non-goals, deferred

- Payment initiation, gateway webhooks, payment expiry sweeper, and the reconciliation poller: Stage 8a. Stage 7 ships the `pending` to `awaiting_payment` and `awaiting_payment` to `paid`/`expired`/`failed` transition Actions that Stage 8a invokes.
- Sending the confirmation email and generating the ticket PDF (`SendOrderConfirmation`, `GenerateTicketPdf` as `TicketIssued` consumers): Stage 8a. The resend-tickets endpoint ships here but is dormant until that consumer exists (master plan Stage 7 bullet).
- Refund execution, commission policy, and ledger projection: Stage 8b. The `paid` to `refunded`/`partially_refunded` transitions and the `TicketRefunded`/`TicketCanceled` event classes are defined here so the state machine is complete, but nothing invokes them until 8b.
- Check-in manifest, per-event signature key rotation and storage: Stage 9. Stage 7 signs QR payloads through a key provider interface with a derived-key implementation; Stage 9 swaps in stored rotating per-event keys behind the same interface (system-design 11).
- Per-customer purchase limits and waiting room admission tokens: Stage 10.
- Reporting projections over order and ticket events: Stage 11.

## Dependencies

### Required from earlier stages

- Stage 1: RFC 9457 problem handler with the error code registry, `Support/Money` value object and `{amount, currency}` transformers, all six test suites including the Isolation and Concurrency harnesses against real PostgreSQL, fake clock for TTL behavior, OpenAPI conformance wiring.
- Stage 2: tenant resolution middleware (Host for storefront routes, `X-Tenant-Id` for admin routes), the `SET LOCAL app.tenant_id` transaction wrapper, and the per-table RLS policy pattern.
- Stage 3: customer authentication (buyer endpoints), staff authentication with capability-checked policies (admin endpoints), activity log for audited staff actions.
- Stage 4: outbox recording API, dispatcher, `outbox_deliveries`, and the idempotent-consumer pattern for the `HoldExpired` subscription.
- Stage 5a: `events` and `ticket_types` with `price_amount` and `currency` (constrained to the tenant settlement currency, system-design 12).
- Stage 6: `ticket_type_inventory`, `holds`, `hold_items`, `event_seats`, and the `CreateHold`, `ExtendHold`, `ReleaseHold`, `CommitHold` Actions plus the expiry sweeper and `HoldExpired` event.

### Consumed by later stages

- Stage 8a: `MarkOrderAwaitingPayment`, `MarkOrderPaid`, `MarkOrderExpired`, `MarkOrderFailed` Actions; `TicketIssued` events feeding email and PDF consumers; the resend-tickets pathway.
- Stage 8b: refund transitions and `TicketRefunded`.
- Stage 9: `tickets` rows, ticket status, the QR codec and its key provider seam.
- Stage 11: `OrderCreated` and `TicketIssued` feeding sales and attendance projections.

## Data model

All four tables: UUIDv7 `id` via `HasUuids`, non-null `tenant_id`, UTC timestamps, and a single-table RLS policy comparing `tenant_id` to `current_setting('app.tenant_id')` created in the same migration as the table, following the Stage 2 pattern. Status columns are strings backed by PHP enums (`OrderStatus`, `TicketStatus`, `PromoCodeDiscountType`).

### orders

Per system-design 8.3.

- `customer_id` uuid not null (reference into Identity; resolved through Actions, never joined).
- `event_id` uuid not null.
- `promo_code_id` uuid nullable, FK to `promo_codes`.
- `hold_id` uuid not null, unique. The unique index makes double conversion of one hold structurally impossible. Plain uuid column, no FK constraint into Inventory's table.
- `status` string not null, default `pending`.
- `subtotal_amount`, `discount_amount`, `fees_amount`, `total_amount` integer not null, `currency` char(3) not null. Check constraints: all amounts >= 0, `total_amount = subtotal_amount - discount_amount + fees_amount`. `fees_amount` is 0 in this stage (see open questions).
- Indexes: unique `hold_id`; `(tenant_id, created_at, id)` for the deterministic cursor-pagination order; `(tenant_id, event_id)`; `(tenant_id, customer_id)`; partial index on `(tenant_id, status)` where status in (`pending`, `awaiting_payment`) for the Stage 8a sweeper and the `HoldExpired` consumer.

### order_items

Not drawn in the section 8.3 ERD; a necessary elaboration (see risks). Snapshots the priced lines at conversion time so ticket issuance at `paid` never re-reads Inventory's `hold_items` and price edits to a ticket type between order and payment cannot change what was sold.

- `order_id` uuid not null FK, `ticket_type_id` uuid not null (Catalog reference, no cross-context FK), `quantity` integer not null > 0, `unit_price_amount` integer not null >= 0, `currency` char(3) not null, `attendee_names` json nullable (copied to tickets at issuance).
- Unique `(order_id, ticket_type_id)`; index `order_id`.

### tickets

Per system-design 8.3.

- `order_id` uuid not null FK, `ticket_type_id` uuid not null, `event_id` uuid not null (denormalized like `tenant_id` per the section 4.2 rationale: the Stage 9 manifest and QR signing key lookup are single-table), `event_seat_id` uuid nullable (Inventory reference).
- `status` string not null, default `issued` (enum: `issued`, `canceled`, `refunded`; only `issued` is reachable in this stage).
- `attendee_name` string nullable, `issued_at` timestamp not null.
- `qr_rotation_counter` integer not null default 0. No barcode or token column exists; the QR payload is computed on render (system-design 8.3 notes).
- Indexes: `order_id`; `(tenant_id, event_id)`; partial unique on `event_seat_id` where `status = 'issued'`, so one seat never backs two live tickets while a `canceled` or `refunded` ticket leaves the seat free for a Stage 8b reissue (merged migrations cannot be edited, so scoping the uniqueness now is the cheap option).

### promo_codes

Per system-design 8.3.

- `code` string not null, unique `(tenant_id, code)`.
- `discount_type` string not null (`percentage`, `fixed_amount`).
- `discount_value` integer not null > 0. For `fixed_amount` it is minor units; for `percentage` it is basis points (1000 = 10 percent), so fractional percentages stay integer (see open questions).
- `currency` char(3) nullable, with a check constraint: null when `discount_type = 'percentage'`, non-null when `fixed_amount`. Data-conventions requires no monetary value without a currency on the row; applied fixed discounts must match the order currency.
- `usage_limit` integer nullable (null means unlimited), `usage_count` integer not null default 0, check `usage_limit is null or usage_count <= usage_limit` as a backstop behind the conditional UPDATE.
- `valid_from`, `valid_to` timestamps nullable.

Discount math, all in the `Support/Money` value object: percentage discount is `floor(subtotal_amount * discount_value / 10000)`; fixed discount is `min(discount_value, subtotal_amount)`; `discount_amount` never exceeds `subtotal_amount`, so `total_amount` is never negative.

## Domain events

Envelope per event-conventions: `id` (UUIDv7), `sequence`, `type`, `tenant_id`, `aggregate_type`, `aggregate_id`, `correlation_id`, `occurred_at`, `payload`; recorded through the Stage 4 outbox API in the same transaction as the state change, without exception. All four Orders event types below are already in the section 9.3 registry.

### Produced

- `OrderCreated`: recorded by `ConvertHoldToOrder`. Aggregate: `order`. Payload: `order_id`, `customer_id`, `event_id`, `hold_id`, `promo_code_id` (nullable), `status`, `subtotal`, `discount`, `fees`, `total` as `{amount, currency}` objects. Identifiers and facts only, no entity snapshot.
- `TicketIssued`: recorded by `IssueTickets` inside the `paid` transition transaction, one event per ticket. Aggregate: `ticket`. Payload: `ticket_id`, `order_id`, `ticket_type_id`, `event_id`, `event_seat_id` (nullable), `issued_at`. `attendee_name` stays out of the payload: outbox rows are immutable and replayed, so PII there would defeat the anonymize-in-place erasure of system-design 14.3; Stage 8a's PDF and email consumers load ticket details through an Orders Action by `ticket_id`, per event-conventions (identifiers and facts, not entity snapshots). `order_id` is in the payload so Stage 8a's per-order consumers (confirmation email) can group; see open questions.
- `TicketCanceled`, `TicketRefunded`: event classes and payloads defined now for the complete registry; no producer until Stage 8b (refunds) and event cancellation work. Not recorded in this stage.

### Consumed

- `HoldExpired` (Inventory): the Orders subscriber loads the event by ID, finds the order by `hold_id`, and applies the conditional UPDATE `pending` to `canceled`. Zero affected rows (no order, order already advanced, order already terminal) is success, which is exactly what makes duplicate delivery harmless. Progress recorded in `outbox_deliveries` per event-conventions. Rationale: system-design 7.1 has no arc out of `pending` except `awaiting_payment` and `canceled`, so a pending order whose hold the Stage 6 sweeper released maps to buyer abandonment. Orders on `awaiting_payment` are untouched; their holds are extended per system-design 7.4 and Stage 8a's payment expiry owns that path.

Issuance side effects are synchronous Action calls, not events: `MarkOrderPaid` calls Inventory `CommitHold` in-transaction; `MarkOrderExpired`, `MarkOrderFailed`, and `CancelOrder` call Inventory `ReleaseHold` in-transaction. Contexts never touch each other's tables (system-design 3.1).

## Endpoints

Snake_case JSON, laravel-data request and response objects as the source of truth, OpenAPI paths merged with each endpoint, errors as RFC 9457 problem documents with stable `code` values. Buyer routes sit under the `/v1/storefront` prefix, the Host-resolved routing seam recorded by Stage 5a, and require a customer bearer token; staff routes sit under bare `/v1`, require a staff bearer token plus `X-Tenant-Id` validated against memberships, and are capability-gated by policy and audited via activitylog. The prefix keeps every path and method to a single operation in the OpenAPI document: the buyer and staff order reads share neither path nor auth mode.

### Buyer-facing (storefront population)

| Method and path | Request Data | Response Data | Notes |
| --- | --- | --- | --- |
| POST `/v1/storefront/orders` | `CreateOrderData` (`hold_id`, optional `attendee_names` keyed by ticket type; slice 5 adds the optional `promo_code` field) | 201 `OrderData` | Hold must be anonymous or already belong to the authenticated customer; conversion attaches the customer |
| GET `/v1/storefront/orders/{order}` | none | 200 `OrderData` | Customers see only their own orders; 404 otherwise |
| GET `/v1/storefront/orders/{order}/tickets` | none | 200 list of `TicketData` | `qr_payload` computed on render; empty list before `paid` |
| POST `/v1/storefront/orders/{order}/cancel` | none | 200 `OrderData` | Only from `pending`; releases the hold |
| POST `/v1/storefront/promo-codes/check` | `CheckPromoCodeData` (`code`, `hold_id`) | 200 `PromoCodeCheckData` (`valid`, `discount` as money, `reason_code` nullable) | Read-only preview, never increments `usage_count` |

`OrderData` carries `id`, `status`, `event_id`, `items` (list of `OrderItemData`), the four money fields as `{amount, currency}`, `promo_code` (code string, nullable), `created_at`. `TicketData` carries `id`, `ticket_type_id`, `event_seat_id`, `status`, `attendee_name`, `issued_at`, `qr_payload`.

### Staff-facing (admin population)

| Method and path | Request Data | Response Data | Notes |
| --- | --- | --- | --- |
| GET `/v1/orders` | query-builder params | 200 cursor-paginated `OrderData` | High-volume, `cursorPaginate` with `-created_at,id`; filters `status`, `event_id`, `customer_id`, `created_at` range; unknown params rejected. Capability `orders.view` |
| GET `/v1/orders/{order}` | none | 200 `OrderDetailData` | `OrderData` plus tickets and a customer summary composed through an Identity Action, never a join. Capability `orders.view` |
| POST `/v1/orders/{order}/resend-tickets` | none | 202 | Only for `paid` (or refund-state) orders; dispatches the resend pathway that Stage 8a activates; audited. The `qr_rotation_counter` bump (invalidating screenshots per system-design 8.3) lives in that pathway, not the endpoint, so payloads are only invalidated when new tickets actually go out; Stage 8a's resend activation task owns the bump (see risks). Capability `orders.resend_tickets` |
| GET `/v1/promo-codes` | query-builder params | 200 page-paginated `PromoCodeData` | Bounded collection; filters `code`, validity. Capability `promo_codes.manage` |
| POST `/v1/promo-codes` | `UpsertPromoCodeData` | 201 `PromoCodeData` | Capability `promo_codes.manage` |
| GET `/v1/promo-codes/{promo_code}` | none | 200 `PromoCodeData` | Includes `usage_count` |
| PATCH `/v1/promo-codes/{promo_code}` | `UpsertPromoCodeData` (partial) | 200 `PromoCodeData` | `discount_type`, `discount_value`, `currency`, `code` immutable once `usage_count > 0`; windows editable (deactivation = set `valid_to`) |
| GET `/v1/customers` | query-builder params | 200 cursor-paginated `CustomerSummaryData` | Identity context endpoint; filters `email` (exact), `name` (prefix). Capability `customers.view` |

New capabilities registered in the Stage 3 RBAC capability set: `orders.resend_tickets`, `promo_codes.manage`, `customers.view`. `orders.view` already exists in Stage 3's initial registry; this stage adds only the Policies and endpoint gating that consume it.

### Error codes (registry additions)

| `code` | Status | Condition |
| --- | --- | --- |
| `hold_not_found` | 404 | Hold does not exist for this tenant, or belongs to a different customer (not-found semantics so hold IDs never leak ownership) |
| `checkout.hold_expired` | 409 | `expires_at` has passed, regardless of sweeper state |
| `hold_already_converted` | 409 | An order already references this hold |
| `order_not_found` | 404 | Missing, other tenant (via RLS), or other customer |
| `order_not_cancelable` | 409 | Cancel attempted on a non-pending order |
| `order_not_paid` | 409 | Resend-tickets on an order without issued tickets |
| `invalid_order_transition` | 409 | Transition Action guard affected zero rows |
| `promo_code_invalid` | 422 | Unknown code for this tenant |
| `promo_code_not_active` | 422 | Outside `valid_from`/`valid_to` |
| `promo_code_exhausted` | 422 | `usage_count` would exceed `usage_limit` |
| `promo_code_currency_mismatch` | 422 | Fixed-amount currency differs from the order currency |
| `promo_code_immutable_field` | 422 | PATCH to a locked field after first use |

Validation failures use the Stage 1 `request.validation_failed` problem shape with the `errors` map.

## QR payload design

`Orders/Support/TicketQrCodec` (name indicative) with `sign(ticket): string` and `verify(payload): VerificationResult`.

- Payload: base64url of `ticket_id`, `event_id`, `qr_rotation_counter`, and an HMAC-SHA256 signature over those three fields.
- Keys come from a `TicketSigningKeyProvider` interface. The Stage 7 implementation derives a per-event key from an application-level secret and the event ID (HKDF), so no key material is stored per ticket and nothing static leaks into the database. Stage 9 replaces the provider with stored, rotating per-event keys (system-design 11) behind the same interface without touching the codec or the payload format.
- Verification checks the signature, then that the embedded counter equals the ticket's current `qr_rotation_counter`. Bumping the counter (a plain UPDATE, performed through this primitive by Stage 8a's resend activation task) invalidates every previously rendered payload (system-design 8.3, 14.4).

## TDD sequencing

Every slice follows the master plan's double loop: outside feature test first, contract second (Data objects plus the OpenAPI path, conformance asserted), inside unit tests third, then green, refactor, `composer types:generate`, commit. Isolation and concurrency tests below are written first and failing per the master plan's non-negotiables.

### Slice 1: order creation from a hold

Failing tests first:

- Isolation: cross-tenant read and write of `orders` and `order_items` fail under RLS (written before the migration exists).
- Feature: POST `/v1/storefront/orders` with a valid hold returns 201 `pending` with correct totals from ticket type prices; the response conforms to the OpenAPI path; `ticket_type_inventory.held` is unchanged (inventory stays held); an `OrderCreated` outbox row exists with the correct envelope and payload; `checkout.hold_expired`, `hold_not_found`, `hold_already_converted` problem documents; an anonymous hold (null `customer_id`) converts and ends attached to the authenticated customer; a hold owned by another customer is `hold_not_found`.
- Unit: pricing computation over hold items and ticket type prices in minor units; single-currency invariant across items.
- Concurrency: N parallel conversions of one hold produce exactly one order (unique `hold_id` plus transactional rollback leaves the losers clean).
- Concurrency: two authenticated customers race to convert one anonymous hold; the attachment conditional UPDATE's affected-row check yields exactly one winner, the hold ends attached to that customer, and the loser fails with `hold_not_found` and rolls back clean.
- Contract: recorded responses conform; generated TypeScript committed without drift.

Implement: migration (tables plus RLS), `Order`, `OrderItem` models, `ConvertHoldToOrder` including the customer-attachment conditional UPDATE, controller, Data objects, OpenAPI path.

### Slice 2: state machine and terminal transitions

Failing tests first:

- Unit: a data-driven table over every ordered pair of states asserting exactly the arcs in system-design 7.1 succeed and every other pair raises with zero rows affected; each transition Action is a single conditional UPDATE (asserted by affected-row count on a concurrent pre-transition).
- Feature: POST `/v1/storefront/orders/{order}/cancel` cancels a pending order, releases the hold (Inventory counters recover), returns `order_not_cancelable` for any other state.
- Feature: the `HoldExpired` consumer cancels the pending order; duplicate delivery of the same event ID has exactly one effect; delivery for an order already in `awaiting_payment` is a no-op.
- Concurrency: cancel racing `MarkOrderAwaitingPayment` on one pending order; exactly one wins.

Implement: `OrderStatus` enum, transition Actions (`MarkOrderAwaitingPayment`, `MarkOrderPaid` guard only, `MarkOrderExpired`, `MarkOrderFailed`, `CancelOrder`, refund transitions), hold release wiring, the outbox subscriber.

### Slice 3: paid transition and ticket issuance

Failing tests first:

- Isolation: cross-tenant access to `tickets` fails.
- Concurrency: N parallel `MarkOrderPaid` calls on one `awaiting_payment` order produce exactly one status change, exactly the ordered quantity of tickets, `held` decremented and `sold` incremented exactly once, and exactly one `TicketIssued` event per ticket. Written before the `tickets` table exists.
- Feature: after the paid transition, GET `/v1/storefront/orders/{order}` shows `paid` and GET `/v1/storefront/orders/{order}/tickets` returns the tickets; before it, the tickets list is empty.
- Unit: `IssueTickets` creates one ticket per unit of quantity from `order_items` snapshots, copies attendee names, assigns `event_seat_id` for seated items, records events in the same transaction (asserted via transaction rollback leaving no outbox rows).

Implement: migration (tickets plus RLS), `Ticket` model, `IssueTickets`, full `MarkOrderPaid` (conditional UPDATE, then in the same transaction `CommitHold`, `IssueTickets`, outbox records).

### Slice 4: QR payloads

Failing tests first:

- Unit: sign and verify round-trip; tampered payload rejected; payload for a different event's key rejected; after a rotation-counter bump the old payload verifies false and a fresh render verifies true; codec output is deterministic for fixed inputs under the fake clock.
- Feature: GET `/v1/storefront/orders/{order}/tickets` returns a `qr_payload` that the codec verifies; two renders after a bump differ from renders before it.
- Contract: `TicketData` shape including `qr_payload` conforms.

Implement: codec, key provider interface plus derived-key implementation, render wiring.

### Slice 5: promo codes

Failing tests first:

- Isolation: cross-tenant access to `promo_codes` fails; a promo code from tenant A is `promo_code_invalid` for tenant B's order.
- Concurrency: N parallel order creations redeeming one code with `usage_limit` L (L < N) end with `usage_count = L`, exactly L discounted orders, and N minus L failures with `promo_code_exhausted`, holds intact for the losers. Written before the table exists.
- Unit: discount math (basis points floor, fixed-amount cap at subtotal, currency mismatch), window evaluation against the fake clock, `ApplyPromoCode` increments through a conditional UPDATE only.
- Feature: order creation with a valid code prices `discount_amount` correctly and links `promo_code_id`; expired, not-yet-valid, exhausted, and mismatched-currency codes return their problem codes and roll the whole conversion back; POST `/v1/storefront/promo-codes/check` previews without incrementing; admin CRUD honors capability gates, uniqueness of `(tenant_id, code)`, and immutability after first use.

Implement: migration (plus RLS), `PromoCode` model, `ApplyPromoCode`, CRUD controllers, `CreateOrderData` gains the optional `promo_code` field (additive contract change), check endpoint.

### Slice 6: staff surface

Failing tests first:

- Feature: GET `/v1/orders` cursor-paginates deterministically, honors each allowed filter, rejects unknown filter and sort params; capability matrix (a role without `orders.view` gets 403 with the Stage 3 problem code); order detail composes the customer summary; resend-tickets returns 202 on paid, `order_not_paid` otherwise, leaves `qr_rotation_counter` untouched (the bump ships with Stage 8a's resend activation task), and writes an activity log entry with the acting user; GET `/v1/customers` filters by exact email, capability-gated.
- Isolation: staff list and detail from tenant A never expose tenant B rows even with forged IDs.
- Contract: all admin paths conform.

Implement: query-builder list endpoints, detail composition through Identity, `ResendTickets` Action with the dormant dispatch seam, customer lookup endpoint in Identity, activity log wiring.

## Task breakdown

Ordered; each is a small, independently mergeable PR unless noted. Commit scope is `orders`, except task 10 (`identity`) and registry touches noted inline. Per the master plan's non-negotiable, every task guarding an invariant opens with its failing concurrency simulation, written before the guarded table or transition exists, and the same PR turns it green.

1. Error code registry additions and the `OrderStatus`, `TicketStatus`, `PromoCodeDiscountType` enums, with unit tests for the enum-backed state list.
2. Migration for `orders` and `order_items` with RLS policies, models, factories, and the failing-then-passing isolation tests.
3. `ConvertHoldToOrder` plus POST `/v1/storefront/orders` end to end (slice 1 tests, `OrderCreated` producer, OpenAPI path, generated types). The failing hold double-conversion and anonymous-hold attachment race concurrency tests are the first commit; the implementation turns them green.
4. Transition Actions and the state machine table test (slice 2 unit layer).
5. Buyer cancel endpoint plus hold release wiring.
6. `HoldExpired` subscriber with the duplicate-delivery test.
7. Paid path (slice 3): the failing exactly-once `MarkOrderPaid` concurrency simulation first, written before the `tickets` migration exists, then the migration with RLS, `Ticket` model, factory, failing-then-passing isolation tests, `IssueTickets`, `CommitHold` wiring, and `TicketIssued`, turning the simulation green. Depends on 4.
8. Buyer order status and tickets endpoints (GET order, GET tickets) with contract coverage.
9. QR codec, key provider, render integration, rotation invalidation tests (slice 4).
10. Identity: GET `/v1/customers` lookup endpoint with `customers.view` capability (scope `identity`).
11. Promo codes core (slice 5): the failing promo-limit concurrency simulation first, written before the `promo_codes` migration exists, then the migration with RLS, model, factory, failing-then-passing isolation tests, `ApplyPromoCode` in order creation, discount math, and the check endpoint, turning the simulation green.
12. Promo code admin CRUD endpoints with capability gates, `(tenant_id, code)` uniqueness, and immutability after first use.
13. Staff order list and detail with query-builder allowlists and cursor pagination (slice 6).
14. Resend-tickets endpoint with audit and the Stage 8a dispatch seam (the rotation bump ships inside Stage 8a's resend activation task, which bumps through the slice 4 primitive).
15. Registry and docs sync: confirm system-design 9.3 needs no change (all four event types already listed), update the master plan status table row for Stage 7.

## Exit criteria

Expanding the master plan's exit line ("a hold becomes an order, a paid order issues tickets exactly once, and no promo code exceeds its limit under parallel redemption") into individually testable checks:

1. POST `/v1/storefront/orders` on a valid hold yields a `pending` order whose totals match ticket type prices in minor units, with inventory still held and `OrderCreated` recorded in the producing transaction.
2. An expired hold can never convert, even with the Stage 6 sweeper disabled in the test (fake clock past `expires_at`).
3. N parallel conversions of one hold create exactly one order; two authenticated customers racing to convert one anonymous hold yield exactly one winner through the attachment conditional UPDATE, with the hold attached to the winning customer and the loser receiving `hold_not_found`.
4. The state machine table test proves exactly the system-design 7.1 arcs succeed and all other transitions affect zero rows and raise `invalid_order_transition`.
5. N parallel `MarkOrderPaid` calls produce one paid order, exactly the ordered ticket count, held-to-sold committed exactly once, and one `TicketIssued` outbox row per ticket, all in the winning transaction.
6. `expired`, `failed`, and `canceled` transitions release the hold and availability arithmetic recovers exactly (Inventory counters equal their pre-hold values).
7. Duplicate delivery of a `HoldExpired` event cancels the pending order exactly once; replay is a no-op.
8. A rendered QR payload verifies; a tampered one, one signed for another event, and one predating a rotation bump all fail verification.
9. Under parallel redemption, `usage_count` never exceeds `usage_limit`, exactly `usage_limit` orders carry the discount, and losing conversions roll back completely with holds intact.
10. Promo validity windows, currency matching, and discount math (basis-point floor, fixed cap at subtotal) are unit-proven; `total_amount` is never negative.
11. Every new table has isolation coverage proving cross-tenant reads and writes fail; every new endpoint has isolation coverage against forged IDs.
12. Every endpoint has its OpenAPI path merged, conformance-checked responses, and regenerated TypeScript committed with the drift gate green.
13. Staff endpoints enforce their capabilities (403 matrix test) and resend-tickets writes an activity log entry.
14. Architecture suite green: Orders imports no models from Inventory, Identity, or EventCatalog; all cross-context calls go through Actions or events.
15. Larastan and Pint clean; all suites pass in `composer test` and CI.

## Risks and open questions

- `order_items` is not in the system-design 8.3 ERD. It is required so that ticket issuance at `paid` has an in-context source of truth and prices are snapshotted at conversion. Treating the ERD as conceptual, this is an elaboration, not a contradiction; if reviewers disagree, the alternative is re-reading hold items through an Inventory Action at issuance and accepting that price edits between conversion and payment change nothing only because ticket types should be immutable once on sale, which nothing currently enforces.
- `tickets.event_id` is a denormalization beyond the ERD, mirroring the section 4.2 rationale for `tenant_id`; Stage 9's manifest and the QR key lookup want it single-table. Cheap now, painful to retrofit.
- Promo `discount_value` semantics: the design stores one integer for both types. This plan fixes percentage as basis points and fixed as minor units with a required `currency`. Needs confirmation before the migration merges, since the wire contract exposes it.
- `fees_amount` stays 0: no fee policy is defined anywhere in the design yet. The column and the total check constraint exist so introducing fees later is additive.
- Whether promo usage is released when an order dies unpaid (`expired`, `failed`, `canceled`) is undecided. This stage increments at conversion and never decrements; if product wants exhausted codes to recover from abandoned checkouts, a conditional decrement in the terminal transitions is a small additive change. Flagged for a product decision before launch.
- `TicketIssued` is per ticket, but Stage 8a's confirmation email is per order. The payload carries `order_id` so the 8a consumer can key its idempotence per order rather than per event; if that proves awkward, an additional order-level event type is an additive registry change.
- System-design 7.1 shows `awaiting_payment` to `failed` as terminal while 7.6 says a declined synchronous payment leaves the hold intact for retry. Stage 7 implements the 7.1 arcs verbatim; how Stage 8a maps individual payment-attempt declines onto the order (retry within `awaiting_payment` versus terminal `failed`) is Stage 8a's design question and does not block this stage.
- The 7.1 refund arcs are likely incomplete for Stage 8b in the same way: the diagram draws no `partially_refunded` to `refunded` arc (completing a refund after a partial) and no repeated `partially_refunded` to `partially_refunded` arc, yet "refunds, full and partial" will predictably need both. The slice 2 table test hard-codes every undrawn pair as illegal, so the diagram should be confirmed or amended before that test merges, or Stage 8b inherits a design amendment mid-stage.
- Hold ownership and guest checkout at conversion: resolved, this stage owns customer attachment. Holds may be created anonymously in Stage 6; the buyer authenticates by the time POST `/v1/storefront/orders` is called (a Stage 3 guest customer obtaining a token counts), and `ConvertHoldToOrder` attaches the authenticated customer to an anonymous hold inside the conversion transaction via the conditional UPDATE described above. A hold owned by a different customer is `hold_not_found`, so hold IDs never leak ownership, and two customers racing to convert one anonymous hold produce exactly one winner by affected-row count. No scoped order-access credential is needed: every order has a customer from creation.
- The resend-tickets endpoint is dormant until Stage 8a's email consumer exists. The `qr_rotation_counter` bump is deferred into Stage 8a's resend activation task, which owns bumping the counter through this stage's codec primitive and tests that pre-resend payloads no longer verify, precisely so a resend call before 8a cannot invalidate QR payloads the buyer already holds while sending nothing in return; until then the endpoint is a pure 202 seam with an audit entry and should not be exposed in the admin UI until 8a merges.
