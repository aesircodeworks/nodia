# Stage 10 Execution Journal: High-Demand On-Sales

## Run: 2026-07-12

- Stage: 10 (docs/plans/stage-10-on-sales.md)
- Date: 2026-07-12
- Branch: feat/api-implementation
- Base commit: 1f03eaae4b28afff20e7a019b9ee914d8e96bf38

### Pre-run verification

Nothing from this stage has landed. Verified against the codebase, not the docs:

- No `on_sale_policy` column or `OnSalePolicyData` (only `AsyncPaymentPolicyData` exists as the Stage 5a precedent); no `max_per_customer` on `ticket_types`.
- No `purchase_counters` migration or model; the last migration is `2026_07_12_000051_create_event_signing_keys_table.php`.
- No `config/onsale.php`, no named rate limiters, no `RateLimiter` or `throttle` middleware registration anywhere in `app`, `bootstrap`, or `routes` (so the Stage 6 interim per-IP hold throttle flagged in that plan's risk list was never built: plan task 11 is a no-op, nothing to remove or fold in).
- No waiting-room surface: no queue-entry routes, no `ChallengeVerifier`, no admission token machinery, no Lua scripts, no Redis usage in Inventory (the only `Redis::` caller in the app is `HealthController`).
- No Redis cache in front of the availability or seats endpoints; both read PostgreSQL directly through `GetEventAvailability` and `GetStorefrontEventSeats`.

Dependencies are in place: Stage 6 (`CreateHold`, `ReleaseHold`, `CommitHold`, `ReleaseExpiredHolds`, availability and seats endpoints, `ResolveEventForHold` with `HoldableEventData` and `HoldableTicketTypeData`) and Stage 7 (order release paths) are done.

### Task checklist

- [ ] T1 `on_sale_policy` column, `OnSalePolicyData`, event create/update/read contracts, OpenAPI, TypeScript (catalog)
- [ ] T2 `max_per_customer` column, ticket type contracts, and the Catalog Action read surface exposing it to Inventory (catalog)
- [ ] T3 `config/onsale.php` and the named rate limiter tiers with 429 problem-document coverage (inventory)
- [ ] T4 `purchase_counters` table with RLS and CHECK, model, guarded upsert and decrement operations (inventory)
- [ ] T5 Purchase-limit enforcement in the hold lifecycle, `counted_quantity` on hold items, `customer_required`, concurrency simulation (inventory)
- [ ] T6 Queue join and position endpoints, Redis entrant lifecycle, `ChallengeVerifier` with fake and no-op implementations (inventory)
- [ ] T7 Gatekeeper command, admission Lua scripts, budget accounting, signed admission tokens with key rotation (inventory)
- [ ] T8 Admission enforcement on `POST /v1/storefront/holds`, updated OpenAPI error responses (inventory)
- [ ] T9 Clock-aware Redis read cache in front of the availability and seats endpoints (inventory)

### Review rounds

### Decisions and deviations
