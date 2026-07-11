# Stage 7 Execution Journal: Orders, Tickets, Promo Codes

## Run: 2026-07-11

- Stage: 7 (docs/plans/stage-07-orders.md)
- Date: 2026-07-11
- Branch: feat/api-implementation
- Base commit: f0c28c183f9508bc715bf04d524a7f0c7a3c0781

### Task checklist

- [ ] 07-01 Error code registry additions plus OrderStatus, TicketStatus, PromoCodeDiscountType enums with unit tests (plan task 1)
- [ ] 07-02 orders and order_items migration with RLS, models, factories, isolation tests (plan task 2)
- [ ] 07-03 ConvertHoldToOrder plus POST /v1/storefront/orders end to end, OrderCreated producer, double-conversion and anonymous-hold attachment races (plan slice 1, task 3)
- [ ] 07-04 Transition Actions and the state machine table test (plan slice 2, task 4)
- [ ] 07-05 Buyer cancel endpoint plus hold release wiring (plan task 5)
- [ ] 07-06 HoldExpired subscriber with the duplicate-delivery test (plan task 6)
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
