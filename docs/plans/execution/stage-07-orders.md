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
- [ ] 07-07 Paid path: MarkOrderPaid exactly-once simulation, tickets migration with RLS, IssueTickets, CommitHold wiring, TicketIssued (plan slice 3, task 7)
- [ ] 07-08 Buyer order status and tickets endpoints with contract coverage (plan task 8)
- [ ] 07-09 QR codec, key provider, render integration, rotation invalidation tests (plan slice 4, task 9)
- [ ] 07-10 Identity: GET /v1/customers lookup endpoint with customers.view capability (plan task 10)
- [ ] 07-11 Promo codes core: limit race simulation, migration with RLS, ApplyPromoCode, discount math, check endpoint (plan slice 5, task 11)
- [ ] 07-12 Promo code admin CRUD with capability gates, uniqueness, immutability after first use (plan task 12)
- [ ] 07-13 Staff order list and detail with query-builder allowlists and cursor pagination (plan slice 6, task 13)
- [ ] 07-14 Resend-tickets endpoint with audit and the Stage 8a dispatch seam (plan task 14)
- [ ] 07-15 Registry and docs sync, status table update (plan task 15)

### Review rounds

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
