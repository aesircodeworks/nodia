# Stage 7 Execution Journal: Orders, Tickets, Promo Codes

## Run: 2026-07-11

- Stage: 7 (docs/plans/stage-07-orders.md)
- Date: 2026-07-11
- Branch: feat/api-implementation
- Base commit: f0c28c183f9508bc715bf04d524a7f0c7a3c0781

### Task checklist

- [x] 07-01 Error code registry additions plus OrderStatus, TicketStatus, PromoCodeDiscountType enums with unit tests (plan task 1)
- [x] 07-02 orders and order_items migration with RLS, models, factories, isolation tests (plan task 2)
- [x] 07-03 ConvertHoldToOrder plus POST /v1/storefront/orders end to end, OrderCreated producer, double-conversion and anonymous-hold attachment races (plan slice 1, task 3)
- [x] 07-04 Transition Actions and the state machine table test (plan slice 2, task 4)
- [x] 07-05 Buyer cancel endpoint plus hold release wiring (plan task 5)
- [x] 07-06 HoldExpired subscriber with the duplicate-delivery test (plan task 6)
- [x] 07-07 Paid path: MarkOrderPaid exactly-once simulation, tickets migration with RLS, IssueTickets, CommitHold wiring, TicketIssued (plan slice 3, task 7)
- [x] 07-08 Buyer order status and tickets endpoints with contract coverage (plan task 8)
- [x] 07-09 QR codec, key provider, render integration, rotation invalidation tests (plan slice 4, task 9)
- [x] 07-10 Identity: GET /v1/customers lookup endpoint with customers.view capability (plan task 10)
- [x] 07-11 Promo codes core: limit race simulation, migration with RLS, ApplyPromoCode, discount math, check endpoint (plan slice 5, task 11)
- [x] 07-12 Promo code admin CRUD with capability gates, uniqueness, immutability after first use (plan task 12)
- [x] 07-13 Staff order list and detail with query-builder allowlists and cursor pagination (plan slice 6, task 13)
- [x] 07-14 Resend-tickets endpoint with audit and the Stage 8a dispatch seam (plan task 14)
- [x] 07-15 Registry and docs sync, status table update (plan task 15)

### Gate

2026-07-11 15:18 -03: full local gates green at the current HEAD.
`composer lint` passed (after fixing import ordering in two promo test
files), `composer analyse` passed (after replacing a never-null
nullsafe access in ConvertHoldToOrder), `composer test` passed all six
suites (2193 tests, 8791 assertions), `composer types:generate`
produced no diff under `git status --short
packages/api-client/src/generated`, and `pnpm typecheck` passed in all
TS workspaces.

### Review rounds

#### Round 1 (2026-07-11, Codex)

Four important findings, no blocking. Applied:

1. order_items.ticket_type_id carried a cross-context FK into
   EventCatalog; the plan's Data model says plain reference. Dropped
   (migration edited in place: the branch is unpushed, so nothing is
   merged).
2. tickets.ticket_type_id and tickets.event_id likewise; dropped. Only
   tenant_id and order_id keep FKs on both tables, verified against the
   rebuilt schema (pg_constraint shows exactly those four).
3. Buyer GET /orders/{order}/tickets without cursor pagination:
   declined. The plan's endpoint table specifies a plain list of
   TicketData for one order, and the collection is bounded by the
   order's own item quantities; api-conventions' "tickets" bullet
   targets tenant-wide ticket collections (the Stage 9 manifest), not a
   single order's.
4. Flipping an unused fixed_amount code to percentage left the stale
   currency behind and tripped the promo_codes_currency_by_type CHECK
   as a 500. Fixed in UpdatePromoCode (tests first): the currency is
   cleared on the flip to percentage, and a flip to fixed_amount
   without a currency is a validation failure.

#### Round 2 (2026-07-11, Codex)

Two important findings, no blocking. Disposition:

1. orders.customer_id and orders.event_id database FKs flagged as
   context-boundary violations: declined. The merged stage-6 holds
   migration deliberately established this exact posture ("real FKs to
   events and customers respectively, the same cross-context FK
   posture"), and the stage-07 plan forbids an FK only for hold_id and
   the snapshot references (order_items.ticket_type_id, tickets); the
   "resolved through Actions, never joined" language governs
   application-level access, not referential integrity, and the arch
   suite covers the model-import rule.
2. UpdatePromoCode immutability was a read-then-write against
   usage_count: confirmed and fixed (test first: a stale admin update
   racing a first redemption). Locked-field changes now ride a
   conditional UPDATE with usage_count = 0 checked by affected-row
   count.

#### Round 3 (2026-07-11, Codex)

One blocking finding, confirmed and fixed: AttachHoldCustomer's
conditional UPDATE guarded only ownership, so a sweeper expiry or a
release racing in between ConvertHoldToOrder's liveness checks and the
attach could still let an invalid hold convert. The UPDATE now also
guards status = active and expires_at > now() (failing unit spread
first: expired, released, committed, and clock-expired holds all refuse
attachment), and ConvertHoldToOrder maps a lost attach onto
checkout.hold_expired versus hold_not_found by re-reading the hold.
Full suite re-run green after the fix (2201 tests, 8806 assertions).

### Decisions and deviations

#### Task 07-01: error codes and Orders enums (2026-07-11)

Added the eleven Stage 7 error codes from the plan's registry table to
`App\Support\Problems\ErrorCode` with statuses, titles, and
`ProblemRenderer` details (`hold_not_found` already existed from Stage 6
and is reused). Created `App\Orders\Enums\{OrderStatus, TicketStatus,
PromoCodeDiscountType}` including the Stage 8b refund statuses in the
enum from day one per the plan. Tests first: extended
`ErrorCodeTest` expectations and added `OrdersEnumsTest` (both failed
before the implementation). Allowlisted the three enums in
`PresetTest` following the HoldStatus precedent. Regenerated TS types
(ErrorCode union grew). Evidence: `php artisan test
--filter='OrdersEnums|ErrorCode|Preset'` 87 passed.

#### Task 07-02: orders and order_items tables (2026-07-11)

Migrations `2026_07_11_000035_create_orders_table` and
`000036_create_order_items_table` with `Rls::applyTenantPolicies` in the
same files, all plan indexes (unique `hold_id`, cursor index
`(tenant_id, created_at, id)`, partial open-status index) and CHECK
constraints (amounts non-negative, `total = subtotal - discount +
fees`, `quantity > 0`). `Order` and `OrderItem` models with MoneyCast
virtual attributes over the shared `currency` column, factories, and
isolation tests written first (13 tests failed before the migration,
green after). Deviation: `orders.promo_code_id` ships as a plain
nullable uuid; the FK constraint is added by the promo_codes migration
in task 07-11, since merged migrations are never edited and the tables
land in different slices. Evidence: Isolation suite 221 passed,
Architecture suite 38 passed.

#### Task 07-03: hold conversion end to end (2026-07-11)

Failing tests first (committed separately as the plan requires): the
double-conversion race (6 workers, one winner via the unique hold_id
index, losers roll back clean), the anonymous-hold attachment race (two
customers, one winner via the conditional UPDATE), the full endpoint
feature spread (201 shape, held counters unchanged, OrderCreated
envelope, hold_not_found, checkout.hold_expired,
hold_already_converted, 401, 422, attendee names), and pricing units
(multi-line minor-unit math, mixed-currency rejection, expired and
released holds). Implementation: `ConvertHoldToOrder` with the
customer-attachment conditional UPDATE behind Inventory's new
`AttachHoldCustomer` seam, hold facts through Inventory's new
`ResolveHoldForOrder` read model, prices through EventCatalog's new
`ResolveTicketTypePricing` (no price seam existed; Orders never touches
TicketType), `OrderCreated` producer registered by the new
`OrdersServiceProvider`, OpenAPI path plus Order/OrderItem/
CreateOrderRequest schemas, contract exercisers for all five documented
responses, regenerated TS. Deviation: the contract suite's afterEach
now deletes customers after holds and orders, since conversion
attaches customers to holds (FK order). Evidence: 19 task tests green,
Contract suite 274 passed, Architecture 38 passed.

#### Tasks 07-04 to 07-06: state machine, cancel, HoldExpired consumer (2026-07-11)

07-04: five transition Actions (`MarkOrderAwaitingPayment`,
`MarkOrderPaid` guard-only, `MarkOrderExpired`, `MarkOrderFailed`,
`CancelOrder`) over a shared `TransitionsOrderStatus` conditional-UPDATE
concern; the data-driven table test (42 cases, written first and
failing) proves exactly the five system-design 7.1 arcs succeed, every
other ordered pair including the refund states raises with zero rows
affected, and no Action targets a refund state. `MarkOrderExpired`,
`MarkOrderFailed`, and `CancelOrder` release the hold through
Inventory's `ReleaseHold` in the same transaction.

07-05: POST /v1/storefront/orders/{order}/cancel with ownership scoping
(order_not_found for foreign customers), OpenAPI path, contract
exercisers, and the cancel-versus-awaiting-payment race (exactly one
winner; hold released only on the cancel outcome). Two fixes surfaced
by the tests: laravel-data renders POST responses as 201, so cancel
returns an explicit 200 JsonResponse, and the customer guard caches the
first bearer within a test, so multi-identity tests call
Auth::forgetGuards() (CustomerAuthenticationTest precedent).

07-06: `CancelOrderOnHoldExpired` subscriber registered for HoldExpired
(one conditional UPDATE pending-to-canceled by hold_id; zero rows is
success). Feature coverage: cancellation on delivery, exactly one
effect under duplicate delivery of the same event id, awaiting_payment
untouched. Evidence: state table 42 passed, cancel spread 15 passed,
consumer 4 passed, Architecture 38, Contract 278.

#### Tasks 07-07 to 07-09: paid path, QR codec, buyer reads (2026-07-11)

07-07: failing tests committed first (paid-path race, tickets
isolation, IssueTickets units), then the tickets migration with RLS,
partial live-seat unique index, Ticket model and factory, TicketIssued
plus the producer-less TicketCanceled and TicketRefunded event classes
for a complete Orders event surface, `IssueTickets` (one ticket per
unit, attendee names positional, seats from Inventory's
ResolveHoldForOrder seam), and the full `MarkOrderPaid` (conditional
UPDATE, then CommitHold and IssueTickets in the same transaction). The
6-worker race proves exactly one paid transition, 3 tickets, held to
sold once, and 3 TicketIssued rows. A latent bug in
HoldForOrderData's seat grouping (`toArray()` on a plain array)
surfaced and was fixed.

07-09: `TicketQrCodec` (base64url JSON of ticket_id, event_id,
rotation, HMAC-SHA256), `TicketSigningKeyProvider` interface with the
HKDF `DerivedTicketSigningKeyProvider` bound in the provider, versioned
info string so Stage 9 can seed stored keys from the same derivation.
Unit spread: round-trip, tamper, cross-event key, rotation bump,
determinism.

07-08: GET order and GET tickets buyer endpoints with qr_payload
computed on render, OpenAPI paths, Ticket schema, and exercisers.
Deviation: the tickets list is wrapped in a `data` envelope rather than
the plan's bare list, because the spec has no top-level bare arrays and
the contract strictness gate cannot assert a bare-array schema
(api-conventions collection shape). Evidence: paid-path set 52 passed,
read endpoints 7 passed, codec 5 passed, Contract 284 passed,
Isolation 227 passed, Architecture 38 passed.

#### Tasks 07-10 to 07-12: capabilities, customer lookup, promo codes (2026-07-11)

07-10: all three stage-7 capabilities (orders.resend_tickets,
promo_codes.manage, customers.view) added to the registry in one pass
with template wiring (Owner all three; Event Manager promo_codes.manage;
Box Office resend plus customers.view; Finance customers.view), then
GET /v1/customers in Identity: cursor-paginated CustomerSummaryData
over descending UUIDv7 id, filters email exact and name prefix,
customers.view gate, OpenAPI plus exercisers. Deviation: the cursor
orders by id desc alone rather than (created_at desc, id) because the
cursor paginator derives the next cursor from the transformed Data
items, which carry id; UUIDv7 makes the order identical.

07-11: failing tests first (committed separately): the 6-worker
usage_limit=3 race (exactly 3 discounted orders, usage_count 3, losers
promo_code_exhausted with holds still active), promo_codes isolation
including the shared-code-two-tenants case, discount math units
(basis-point floor 999*1250/10000=124, fixed cap at subtotal, currency
mismatch, windows on the fake clock, conditional increment,
preview-no-increment), and the checkout feature spread. Implementation:
promo_codes migration with RLS, CHECKs, and the deferred
orders.promo_code_id FK; EvaluatePromoCode (read-only, shared) plus
ApplyPromoCode (conditional usage UPDATE); CreateOrderData gains the
optional promo_code (additive); ConvertHoldToOrder prices discount and
total; POST /v1/storefront/promo-codes/check preview endpoint.

07-12: promo admin CRUD behind promo_codes.manage with
RecordActivityAudit on mutations; (tenant_id, code) uniqueness rides
the DB unique index surfaced as request.validation_failed; code,
discount_type, discount_value, and currency lock once usage_count > 0
(promo_code_immutable_field), windows stay editable; unknown ids are
request.not_found per the roles and ticket types precedent. OpenAPI
paths, schemas, and 17 new contract exercisers. Evidence: promo suites
28 passed, Contract 309 passed, Architecture 38 passed, Isolation 234
passed.

#### Task 07-15: registry and docs sync (2026-07-11)

system-design 9.3 already lists all four Orders event types
(OrderCreated, TicketIssued, TicketCanceled, TicketRefunded), so no
design change was needed; the event classes exist for all four and the
provider registers the two with producers. Master plan status table
flipped to Done at close-out.

### CI

2026-07-11: pushed 2802aa8 to origin. All five workflows for that exact
head SHA concluded success: API 29164034085, Packages 29164034115,
Storefront 29164034130, Admin 29164034078, Checkin 29164034079.

### Final summary (2026-07-11)

15 of 15 tasks completed. Local gates green (lint, Larastan, all six
suites: 2201 tests / 8806 assertions after review fixes, no generated
TS drift, pnpm typecheck clean); CI green on the pushed HEAD. Review:
three Codex rounds; round 1 four important (three fixed, one declined
with reasoning: buyer per-order tickets stay an unpaginated bounded
list per the plan's endpoint table), round 2 two important (one fixed,
one declined: orders.customer_id/event_id FKs follow the merged
stage-6 holds posture), round 3 one blocking (fixed: hold-liveness
guard inside AttachHoldCustomer). No unaddressed blocking or important
findings.

Exit criteria walk:

1. Met: CreateOrderEndpointTest proves 201 pending with totals from
   ticket type prices, held counters unchanged, OrderCreated recorded
   in-transaction with full envelope.
2. Met: "checkout.hold_expired ... even when the sweeper lags" feature
   case plus the ConvertHoldToOrderTest expiry unit under the fake
   clock; hardened further by the round-3 attach guard.
3. Met: OrderConversionContentionTest (6-way double conversion, one
   winner; two-customer anonymous attachment race, one winner, loser
   hold_not_found, rollback clean).
4. Met: OrderStateMachineTest, 42 cases over every ordered pair
   including refund states; exactly the five 7.1 arcs succeed; no
   Action targets a refund state.
5. Met: OrderPaidContentionTest (one status change, 3 tickets, held to
   sold exactly once, 3 TicketIssued rows).
6. Met: cancel feature test (counters recover exactly, hold released);
   MarkOrderExpired/Failed release via ReleaseHold in-transaction
   (state machine table exercises them against real holds).
7. Met: HoldExpiredConsumerTest (cancellation, exactly-one-effect
   duplicate delivery, awaiting_payment untouched).
8. Met: TicketQrCodecTest (round-trip, tamper, cross-event key,
   rotation bump) plus OrderReadEndpointsTest render coverage.
9. Met: PromoCodeLimitContentionTest (usage_count = limit, exactly
   limit discounted orders, losers roll back with holds active).
10. Met: ApplyPromoCodeTest (basis-point floor, fixed cap, currency
    mismatch, windows on the fake clock); orders_total_arithmetic and
    non-negative CHECKs back it in the schema.
11. Met: Orders/OrderItems/Tickets/PromoCodes isolation tests plus
    endpoint-level foreign-id cases (staff list/detail, buyer reads).
12. Met: every new endpoint documented in openapi.yaml with strict
    schemas, exercised in the Contract suite (321 tests), generated TS
    committed, drift gate green locally and on CI.
13. Met: capability matrix cases across customers, promo CRUD, staff
    orders, resend; resend writes an activity log entry
    (StaffOrderEndpointsTest).
14. Met: Architecture suite green; Orders imports no other context's
    models; cross-context calls go through ResolveHoldForOrder,
    AttachHoldCustomer, CommitHold, ReleaseHold,
    ResolveTicketTypePricing, ResolveCustomerSummary.
15. Met: Pint and Larastan clean; composer test green locally and in
    the API CI workflow.
