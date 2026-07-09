# Stage 10: High-Demand On-Sales

Implementation plan for the waiting room and abuse controls defined in [api-implementation-plan.md](../api-implementation-plan.md) Stage 10 and [system-design.md](../system-design.md) section 10. The TDD loop and contract pipeline from the master plan are binding for every slice below, as are [api-conventions.md](../api-conventions.md), [data-conventions.md](../data-conventions.md), and [event-conventions.md](../event-conventions.md). Design authority is system-design.md; section numbers cited throughout refer to it.

Timing note from the master plan's MVP thinning rule: Stage 10 waits for real load pressure, and the roadmap's sequencing principles say not to build the waiting room until load tests or launch plans show it is needed. This plan is written so the stage can start the moment that signal arrives, and so the Stage 6 interim mitigation (a basic per-IP throttle on POST /v1/storefront/holds, flagged in the Stage 6 plan's risk list) is absorbed and superseded by the rate-limit tiers here.

## Scope and non-goals

### Delivered by this stage

- Per-event high-demand configuration: an `on_sale_policy` object on `events` (high-demand flag, admission rate, challenge requirement), following the `async_payment_policy` precedent from Stage 5a, plus `max_per_customer` on `ticket_types` (section 10: limits are per ticket type).
- The waiting room: a Redis sorted-set queue per flagged event, ordered by arrival (section 10), with a storefront join endpoint and a queue position endpoint polled by the storefront (section 10: position via polling).
- The gatekeeper: a scheduled process admitting entrants into checkout at the configured per-event rate, matched to what the payment path sustains (sections 10 and 17). Admission state moves through atomic Redis operations (Lua scripts), the queue-side analogue of the conditional UPDATE discipline.
- Short-lived signed admission tokens: HMAC over entrant, event, tenant, and expiry with a key ID for rotation, issued on admission and required by POST /v1/storefront/holds for flagged events (section 10). Verification is stateless.
- A challenge hook at queue entry: a `ChallengeVerifier` interface invoked when the event policy requires it, shipped with a deterministic fake verifier for tests and a no-op default (section 10 names proof-of-work or CAPTCHA; the concrete provider is deferred, see non-goals).
- Per-customer purchase limits enforced inside the hold transaction via a new `purchase_counters` table and a conditional upsert checked by affected-row count (section 10: checked in the same transaction; data-conventions: invariant guards are conditional UPDATEs, never read-then-write).
- Application-level rate limiting tiers, hold creation stricter than browse (section 10), Redis-backed named limiters with 429 problem documents carrying `Retry-After` (api-conventions Errors).
- Read path protection: GET /v1/storefront/events/{event}/availability and GET /v1/storefront/events/{event}/seats served from Redis caches with second-level TTLs, never authoritative (section 10); contracts unchanged from Stage 6.

### Non-goals, deferred

- Edge-proxy token buckets per IP and session (section 10 names them alongside the application tiers): Caddy configuration is deployment infrastructure, owned by the production hardening work (roadmap Phase 7, master plan Stage 12 security and load scope). This stage ships the application-level tiers only.
- A real challenge provider (CAPTCHA vendor or proof-of-work scheme): behind the `ChallengeVerifier` interface, selected later by ADR when a launch needs it. This stage proves the hook, the denial path, and the per-event toggle.
- Queue analytics and funnel reporting (roadmap post-MVP item 7): Stage 11 territory, and nothing here records queue facts durably to project from; see Domain events.
- Load tests of the admission path with recorded targets: master plan Stage 12.
- Any change to inventory correctness mechanics: the Stage 6 counter and seat guards remain the sole source of truth; this stage only adds gates in front of them and caches beside them.
- Payment-throughput feedback (dynamically adjusting admission rate from observed payment latency): the rate is static per-event configuration in this stage; section 17 only requires that the waiting room bounds concurrency, not that it self-tunes.

## Dependencies

### Required before this stage starts

- Stage 1: the problem+json handler and error code registry (this stage adds codes, including rendering 429 as a problem document), the Contract suite and OpenAPI conformance gate, the real Concurrency and Isolation harnesses, and the fake clock, which drives admission token expiry, cache freshness, and rate-limit windows in tests.
- Stage 2: tenant resolution from `Host` for storefront routes (section 4.1), the RLS policy pattern, the two-tenant isolation fixture, and the cross-tenant database role (section 4.3), which the gatekeeper uses to read flagged-event configuration across tenants the same way the Stage 4 queue worker bootstrap does.
- Stage 3: customers (per-customer limits need a customer identity at hold time) and staff capabilities gating the admin config surface.
- Stage 4: the outbox exists but is not used by this stage; the dependency is only that its scheduler and Horizon plumbing patterns are established for the gatekeeper command.
- Stage 5a: `events` with the policy-column pattern (`async_payment_policy`) this stage extends, and the admin event and ticket type endpoints whose contracts gain the new fields.
- Stage 6: POST /v1/storefront/holds and `CreateHold` (this stage wraps them with admission and limit checks, exactly as the Stage 6 plan anticipates), `ReleaseHold`, `CommitHold`, the expiry sweeper (all of which must reverse or preserve purchase-counter increments), and the availability and seats endpoints the cache fronts.
- Stage 7 is not a hard dependency: holds, not orders, are where limits and admission are enforced. But if Stage 7 has merged, its release paths (order `expired`, `failed`, `canceled` releasing the hold) exercise the counter decrement through `ReleaseHold` and are covered by this stage's tests.

### Consumed by later stages

- Stage 11: nothing directly; queue analytics would need durable queue facts that are explicitly not recorded here (see Domain events and open questions).
- Stage 12: load tests target hold creation, payment initiation, and waiting room admission (master plan Stage 12); the security sweep covers admission token signing, key rotation, and the challenge denial paths; operational hardening may add the liveness trimming lever noted in risks.

## Data model

No new money columns exist in this stage; nothing here prices anything. All PostgreSQL changes follow data-conventions: UUIDv7 `id` via `HasUuids`, non-null `tenant_id` on tenant-scoped tables, UTC timestamps, additive migrations only (merged migrations are never edited).

### purchase_counters (new table, Inventory context)

One row per customer per ticket type, tracking the quantity currently held plus already committed, so `max_per_customer` is enforceable in a single guarded statement.

| Column | Type | Notes |
| --- | --- | --- |
| id | uuid | PK, UUIDv7 |
| tenant_id | uuid | non-null, RLS (derivable via ticket type, still required per section 4.2) |
| customer_id | uuid | non-null |
| ticket_type_id | uuid | non-null |
| quantity | integer | non-null, default 0, CHECK `quantity >= 0` |

Constraints and indexes: `unique (customer_id, ticket_type_id)` (the upsert conflict target and the lookup path); index on `ticket_type_id`. The RLS policy comparing `tenant_id` to `current_setting('app.tenant_id')` ships in the same migration, or the isolation suite blocks the merge (data-conventions Tenancy).

The guard, executed inside the `CreateHold` transaction for every item whose ticket type has `max_per_customer` set:

```sql
INSERT INTO purchase_counters (id, tenant_id, customer_id, ticket_type_id, quantity, created_at, updated_at)
VALUES (:id, :tenant, :customer, :type, :n, now(), now())
ON CONFLICT (customer_id, ticket_type_id)
DO UPDATE SET quantity = purchase_counters.quantity + :n, updated_at = now()
WHERE purchase_counters.quantity + :n <= :limit
```

Zero affected rows means the limit is exceeded; the hold transaction rolls back and the API returns `purchase_limit_exceeded`. The insert path is guarded by request validation (`:n <= :limit`, deterministic, no race). The quantity actually counted for each item is persisted on the hold item as `counted_quantity` (an additive column on the Stage 6 hold items table, zero for items whose ticket type had no limit at hold time), written in the same `CreateHold` transaction as the increment. Release and expiry decrement exactly that recorded amount with `UPDATE ... SET quantity = quantity - :counted WHERE customer_id = :customer AND ticket_type_id = :type AND quantity >= :counted`, ignoring the ticket type's current policy, so changing or clearing `max_per_customer` between hold creation and release can neither strand counted quantity nor decrement quantity the hold never contributed. Commit leaves the counter untouched, so committed purchases keep counting against the limit across successive holds. The CHECK constraint and the `quantity >= :counted` guard are defense in depth, as with the Stage 6 counters.

### events.on_sale_policy (new column, EventCatalog context)

A new migration adds `on_sale_policy` jsonb, non-null with a default of the inactive policy, cast to an `OnSalePolicyData` laravel-data object exactly as `async_payment_policy` is (Stage 5a plan). Shape, additive-only evolution:

- `high_demand` (bool, default false): flags the event for waiting-room enforcement (section 10).
- `admission_rate_per_minute` (int, nullable, CHECK-equivalent request validation `>= 1`): the gatekeeper's admission budget; null falls back to the platform default in `config/onsale.php`.
- `challenge_required` (bool, default false): activates the challenge hook at queue entry (section 10).

`events` already carries its RLS policy from Stage 5a; a column addition needs no policy change. Same for `ticket_types` below.

### ticket_types.max_per_customer (new column, EventCatalog context)

A new migration adds `max_per_customer` integer, nullable, CHECK `max_per_customer > 0` when set. Null means unlimited. The value is read by Inventory through the same Catalog Action that already supplies ticket type data to `CreateHold` (Stage 6 pattern; contexts never touch each other's tables, section 3.1).

### Redis structures (not tables, never authoritative)

Redis holds queue state and caches only; PostgreSQL remains the system of record for everything durable (section 9.2 states the general rule; section 10 applies it to availability reads). Losing Redis loses queue positions, which buyers recover by rejoining; it can never lose inventory or money. All keys embed `tenant_id` and `event_id`, because Redis has no RLS and key namespacing plus host-resolved tenant scoping is the isolation mechanism on this path:

- `onsale:{tenant_id}:{event_id}:waiting`: sorted set, member is the entrant ID (UUIDv7), score is arrival time in milliseconds from the application clock. Position is rank plus one.
- `onsale:{tenant_id}:{event_id}:admitted`: sorted set of entrant IDs scored by admission expiry in milliseconds, trimmed by the gatekeeper once scores pass, slightly past the admission token lifetime (garbage collection only; token validity is checked against the signed expiry, not Redis). A sorted set rather than a hash with per-entry TTLs keeps cleanup independent of per-field hash expiry, which only exists in Redis 7.4 and later, and keeps admission state in one key per event for the cluster hash-tag safety noted under risks.
- `onsale:active`: set of `{tenant_id}:{event_id}` pairs with a live queue, maintained on first join, iterated by the gatekeeper.
- `onsale:{tenant_id}:{event_id}:budget:{interval}`: the per-interval admission budget counter the gatekeeper decrements atomically.
- Cache keys for availability and seat map payloads, storing the serialized response plus a `cached_at` timestamp; freshness is judged against the injected application clock (`now > cached_at + ttl` means stale), with a Redis TTL slightly above for cleanup. This makes second-level TTL behavior fake-clock testable while behaving identically under real Redis.

Admission and dequeue run as Lua scripts so that popping an entrant from `waiting`, writing it to `admitted`, and decrementing the budget are one atomic step; two racing gatekeeper runs cannot admit the same entrant twice or exceed the interval budget. This is the queue-side equivalent of the affected-row-count guard.

### Admission token

Not stored anywhere. Payload: `entrant_id`, `event_id`, `tenant_id`, `expires_at`; signature: HMAC-SHA256 with a key ID prefix so signing keys rotate without invalidating in-flight tokens. TTL default 5 minutes in `config/onsale.php`. The token is presented in the `X-Admission-Token` header on POST /v1/storefront/holds and verified statelessly against the current and previous keys, the event, the tenant, and the clock. It remains valid for its full TTL so a buyer whose hold attempt fails can retry with the hold-endpoint semantics of section 7.6 (fail fast, buyer retries); abuse within the TTL is bounded by the hold-creation rate tier and the purchase limits.

## Domain events

Produced: none. Consumed: none. The section 9.3 registry is unchanged, and this is deliberate:

- Queue join, admission, and expiry mutate Redis only. Event-conventions requires every outbox event to be recorded in the same database transaction as the state change it describes; there is no database transaction on the queue path, so there is legitimately nothing to record.
- Purchase-counter increments and decrements are part of the hold lifecycle, and `HoldCreated`, `HoldReleased`, and `HoldExpired` (Stage 6) already record those facts; a separate counter event would duplicate them.
- Policy changes on events flow through the Stage 5a update Actions, which already record `EventUpdated`.

If Stage 11 later wants queue analytics, the additive path is a new durable fact table written by the gatekeeper plus new event types added to the registry then, never retrofitted here. Idempotence obligations for this stage therefore reduce to: admission is idempotent per entrant (the Lua script moves an entrant at most once), and token verification is stateless and repeatable.

## Endpoints

All routes under `/v1`, snake_case JSON, laravel-data request and response objects as the source of truth, OpenAPI paths merged with each endpoint, TypeScript regenerated via `composer types:generate` (api-conventions). Errors are RFC 9457 problem documents; the `code` values below enter the stable registry. Storefront endpoints sit under the `/v1/storefront` prefix, the Host-resolved routing seam recorded by Stage 5a, and resolve the tenant from `Host` (section 4.1); admin changes ride on existing staff endpoints with `X-Tenant-Id`.

### Storefront

**POST /v1/storefront/events/{event}/queue-entries** joins the waiting room for a flagged event. Unauthenticated; the entrant ID returned is the capability, per the UUIDv7 anti-enumeration posture (section 14.4). Request `JoinQueueData`: optional `challenge_response` (string). Response 201 `QueueEntryData`: `id`, `event_id`, `status` (`waiting` or `admitted`), `position` (nullable int, null once admitted), `admission_token` (nullable string), `admission_expires_at` (nullable ISO 8601 UTC). When the queue is empty and the budget allows, the join may admit immediately and return `admitted` in the same response.

| Condition | Status | code |
| --- | --- | --- |
| Malformed body | 422 | `request.validation_failed` |
| Event not published or unknown for tenant | 404 | `event_not_found` |
| Event not flagged high-demand | 409 | `queue_not_active` |
| Policy requires a challenge, none supplied | 403 | `challenge_required` |
| Challenge supplied but rejected by the verifier | 403 | `challenge_failed` |
| Queue-entry rate tier exceeded | 429 | `request.rate_limited` (with `Retry-After`) |

**GET /v1/storefront/queue-entries/{entry}** is the queue position endpoint the storefront polls (section 10). Response 200 `QueueEntryData` as above: `position` while waiting; `admission_token` and `admission_expires_at` once the gatekeeper has admitted the entrant (the token is signed at response time from the admitted state, so nothing secret rests in Redis). 404 `queue_entry_not_found` for unknown, expired, or cross-tenant entrants (cross-tenant resolves to not-found by key namespacing, mirroring the RLS-driven 404 pattern). 429 `request.rate_limited` on the poll tier, with `Retry-After` doubling as the polling-interval hint.

**POST /v1/storefront/holds** (existing Stage 6 endpoint, additive contract change). For events whose `on_sale_policy.high_demand` is true, a valid `X-Admission-Token` for that event is required before any inventory work runs. For items whose ticket type has `max_per_customer`, `customer_id` becomes required and the counter guard runs inside the hold transaction. New failure modes joining the Stage 6 table:

| Condition | Status | code |
| --- | --- | --- |
| Flagged event, header absent | 403 | `admission_required` |
| Header present but signature, event, tenant, or expiry invalid | 403 | `admission_invalid` |
| Limited ticket type without `customer_id` | 422 | `customer_required` |
| Counter guard affects zero rows | 409 | `purchase_limit_exceeded` (extension members: `ticket_type_id`, `limit`) |
| Hold-creation rate tier exceeded | 429 | `request.rate_limited` (with `Retry-After`) |

**GET /v1/storefront/events/{event}/availability** and **GET /v1/storefront/events/{event}/seats** (existing Stage 6 endpoints): contracts, shapes, and codes unchanged. Responses are now served from the Redis cache with a second-level TTL (default 2 seconds, config), so browse traffic never touches the inventory tables during an on-sale (section 10). The cache is read-through and never written by the hold path; correctness always comes from PostgreSQL.

### Admin

No new routes. `OnSalePolicyData` becomes an optional field on the Stage 5a event create and update contracts, and `max_per_customer` on the ticket type contracts, both additive, both gated by the existing `events.manage` capability and audited via the activity log like every staff mutation (section 14.2). Validation: `admission_rate_per_minute >= 1` when present; `max_per_customer > 0` when present; lowering `max_per_customer` below a customer's existing counter is allowed and simply blocks further holds.

### Rate limiting tiers

Named Redis-backed limiters registered in the Inventory service provider, defaults in `config/onsale.php`, all tunable without deploy where config allows: `browse` (availability, seats, event reads; generous), `queue_entry` and `queue_poll` (moderate, per IP), `hold_creation` (strict, per IP and, when present, per customer; section 10 requires hold creation stricter than browse). Every 429 is a problem document with code `request.rate_limited` and `Retry-After` (api-conventions Errors); the Stage 1 handler already renders throttle exceptions this way (its slice 1 matrix proves it on a throttled probe route), so slice 2 asserts that rendering on the real tiers rather than re-implementing it.

## TDD sequencing

Each slice follows the double loop: failing tests first, then contract, then implementation, then green with Larastan, Pint, regenerated TypeScript, and the architecture suite. The three mandated failing tests from the master plan's Stage 10 line are marked. Commit scope `inventory` unless noted.

### Slice 1: policy and limit configuration (scope `catalog`)

- Feature (first): event create and update accept and return `on_sale_policy`; ticket type create and update accept and return `max_per_customer`; invalid values (rate zero, negative limit) return 422 `request.validation_failed`; existing events read back the inactive default policy.
- Contract (first): updated OpenAPI schemas for the event and ticket type Data objects; conformance on recorded responses.
- Unit (first): `OnSalePolicyData` defaults, additive-evolution shape, cast round-trip.

### Slice 2: rate limiting tiers

- Feature (first): exceeding the `hold_creation` tier returns a 429 problem document with `code` `request.rate_limited` and a `Retry-After` header; the `browse` tier is measurably looser than `hold_creation` (both driven through config overrides in the test); limits reset when the fake clock advances past the window.
- Unit (first): limiter key derivation (IP, customer when present); problem-document rendering of the throttle exception through the Stage 1 handler.

### Slice 3: purchase limits

- Concurrency (first, mandated): the purchase limit simulation. N parallel processes hold k tickets each for one customer against `max_per_customer = L` where N*k > L; assert the customer's counter never exceeds L, exactly floor(L/k) holds succeed, and inventory counters stay consistent. Variants: two customers racing independently both reach L; mixed create-release interleaving converges to the committed quantity.
- Isolation (first): `purchase_counters` probes under the two-tenant fixture, cross-tenant SELECT and UPDATE affect zero rows.
- Feature (first): hold over the limit returns 409 `purchase_limit_exceeded` with the offending `ticket_type_id` and `limit`; a limited type without `customer_id` returns 422 `customer_required`; release and expiry restore headroom exactly; a committed hold keeps consuming the limit on the next attempt; a hold created before a limit existed releases without decrementing (its `counted_quantity` is zero); clearing `max_per_customer` while counted holds are live still decrements their recorded quantity on release.
- Unit (first): the upsert guard's affected-row semantics; decrements reverse only the recorded `counted_quantity` and floor at zero via the guard, never below (CHECK as backstop); unlimited types skip the counter entirely.

### Slice 4: queue entry and position endpoints

- Feature (first): join on a flagged event returns 201 with `waiting` status and position 1; a second entrant sees position 2; join on an unflagged event returns 409 `queue_not_active`; unknown event 404; poll returns decreasing positions as earlier entrants are admitted (gatekeeper stubbed at this slice); unknown entrant 404 `queue_entry_not_found`; challenge matrix per the endpoint table using the fake verifier (`challenge_required`, `challenge_failed`, success).
- Feature, endpoint-level isolation: an entrant created under tenant A's host returns 404 under tenant B's host.
- Contract (first): OpenAPI paths for both endpoints; conformance on recorded responses.
- Unit (first): position arithmetic from sorted-set rank; arrival ordering uses the injected clock; `ChallengeVerifier` interface contract and the no-op default.

### Slice 5: gatekeeper and admission tokens

- Concurrency (first): two gatekeeper processes racing on one queue admit at most the interval budget with no entrant admitted twice (Lua atomicity proven by affected-entrant accounting).
- Feature (first): with rate R and W waiting entrants, one gatekeeper tick admits min(R-per-interval, W) in arrival order; the poll endpoint then returns `admitted` with a token and expiry; an entrant polling after token expiry (fake clock) gets 404; per-event rate honored across two flagged events of different tenants in one tick (cross-tenant config read via the section 4.3 role, following the Stage 4 worker precedent).
- Unit (first): token payload and HMAC round-trip; key-ID rotation (old key verifies until retired, unknown key ID rejected); expiry checked against the injected clock; budget arithmetic per interval.

### Slice 6: hold endpoint admission enforcement

- Feature (first, mandated): hold creation without an admission token fails for flagged events with 403 `admission_required`; with a token for the wrong event, wrong tenant, bad signature, or expired (fake clock) fails with 403 `admission_invalid`; with a valid token the Stage 6 happy path is unchanged; unflagged events require no token; flipping `high_demand` on an existing event activates enforcement on the next request.
- Contract (first): the updated POST /v1/storefront/holds error responses in OpenAPI.
- Unit (first): the enforcement check runs before any inventory statement (no counter mutation on denial).

### Slice 7: availability read cache

- Feature (first, mandated): availability display converges after cache expiry. Read availability (primes the cache), create a hold (database changes, cache still serves the stale value inside the TTL), advance the fake clock past the TTL, read again and assert the response matches PostgreSQL exactly. Same for the seats endpoint's status collapse.
- Feature (first): the cache is never authoritative: with a poisoned cache entry claiming availability, hold creation still fails on the real counter guard (`insufficient_inventory`).
- Unit (first): clock-aware freshness (`cached_at` plus TTL against the injected clock); cache key includes tenant and event; TTL default from config.

## Task breakdown

Ordered; each is a small PR through the full loop, independently mergeable unless noted.

1. Mark Stage 10 in progress in the api-implementation-plan status table. Scope: `docs`.
2. `on_sale_policy` column, `OnSalePolicyData`, event contract updates, OpenAPI, TypeScript. Slice 1 tests first. Scope: `catalog`.
3. `max_per_customer` column, ticket type contract updates, and the Catalog Action surface exposing it to Inventory. Slice 1 tests first. Scope: `catalog`. Independent of task 2.
4. Rate limiter tiers, `config/onsale.php`, feature coverage asserting the Stage 1 handler's 429 problem-document rendering on the new tiers. Slice 2 tests first. Scope: `inventory` (handler change reviewed as `support` if split). Independent of tasks 2 and 3.
5. `purchase_counters` migration with RLS and CHECK, model, and the guarded upsert and decrement statements as internal Inventory operations. Slice 3 isolation and unit tests first. Depends on task 3.
6. Purchase-limit enforcement wired into `CreateHold`, `ReleaseHold`, `CommitHold`, and the expiry sweeper, with the additive `counted_quantity` column on hold items; `customer_required` validation; the concurrency simulation goes green. Slice 3 feature and concurrency tests first. Depends on task 5.
7. Queue join and position endpoints, entrant lifecycle in Redis, `ChallengeVerifier` interface with fake and no-op implementations, contracts, TypeScript. Slice 4 tests first. Depends on task 2.
8. Gatekeeper command (sub-minute schedule), admission Lua scripts, budget accounting, signed admission token issuance on poll, key rotation config. Slice 5 tests first. Depends on task 7.
9. Admission enforcement on POST /v1/storefront/holds, updated OpenAPI error responses. Slice 6 tests first. Depends on task 8.
10. Clock-aware Redis cache in front of the availability and seats endpoints. Slice 7 tests first. Independent of tasks 5 through 9; depends only on Stage 6.
11. Remove or fold in any interim per-IP throttle pulled forward from the Stage 6 risk note, so `hold_creation` is the single source of throttle truth. With task 4 or immediately after.
12. Mark Stage 10 done in the status table; OpenAPI document consolidation check; confirm no TypeScript drift. Scope: `docs`.

## Exit criteria

The master plan states Stage 10's goal ("the waiting room and abuse controls") and its mandated tests rather than a single exit line. Expanded into individually testable checks, every one automated:

1. Hold creation without an admission token fails with 403 `admission_required` for every event flagged `high_demand`, and succeeds unchanged for unflagged events (the first mandated test).
2. Invalid admission tokens (wrong event, wrong tenant, bad signature, unknown key ID, expired on the fake clock) fail with 403 `admission_invalid`, and no inventory or counter statement executes on any denial path.
3. The purchase limit concurrency simulation passes at every configured parallelism: no customer's counter ever exceeds `max_per_customer`, exactly the expected holds succeed, and release, expiry, and commit adjust the counter exactly (the second mandated test).
4. Availability display converges after cache expiry: stale within the TTL, exact against PostgreSQL after the fake clock passes it, for both the availability and seats endpoints (the third mandated test), and a poisoned cache can never cause a hold to succeed against the real guard.
5. The gatekeeper admits in arrival order at the configured per-event rate, never exceeds the interval budget under racing gatekeeper processes, and admits across events of different tenants in one tick.
6. The queue position endpoint reports monotonically non-increasing positions for a waiting entrant and returns the signed token exactly when admitted; expired entrants and cross-tenant lookups return 404 `queue_entry_not_found`.
7. The challenge hook denies entry with `challenge_required` and `challenge_failed` per policy and admits on verifier success, proven with the fake verifier.
8. Exceeding any tier returns a 429 problem document with stable code `request.rate_limited` and `Retry-After`; the `hold_creation` tier is stricter than `browse` in config and in an asserted test.
9. `purchase_counters` rejects cross-tenant reads and writes under RLS in the isolation suite; its policy shipped in the creating migration; endpoint-level cross-tenant probes cover the queue surface.
10. Every new failure-mode row has a feature test asserting status and `code`, every new or changed response validates against the merged OpenAPI paths in the Contract suite, and the new codes are in the stable registry.
11. The Architecture suite confirms Inventory reads `max_per_customer` and `on_sale_policy` only through Catalog Actions, and no context touches another's tables.
12. `composer lint`, `composer analyse`, all six suites, and the TypeScript drift gate pass; generated types for the new Data objects are committed; the status table row for Stage 10 reads done.

## Risks and open questions

- Context ownership. Section 3.2's directory layout lists no waiting-room files anywhere. This plan places the queue, gatekeeper, admission tokens, rate tiers, and purchase counters in the Inventory context, because everything here exists to protect the hold path Inventory owns, and the availability cache fronts Inventory reads. If review prefers a `Support/OnSale` placement for the Redis machinery, only file paths move; either way system-design 3.2 should be amended in the same change. Decide before task 5.
- Guest identity versus per-customer limits. The hold-ownership question is resolved: Stage 6 supports anonymous holds by design and Stage 7's `ConvertHoldToOrder` attaches the authenticated customer at conversion via a conditional UPDATE. Limits are still unenforceable without an identity at hold time, so this plan requires an authenticated customer when a limited ticket type is in the hold (`customer_required`): on flagged high-demand events, where limits apply, the hold is bound to the customer from creation, which coexists with anonymous holds on unflagged, unlimited flows. For guest checkout this means guest customer creation moves ahead of hold creation on limited types (roadmap Phase 3 groups them this way already). Per-customer is also only as strong as the customer record; one person with N email addresses gets N allowances. Section 10 accepts this by pairing limits with the challenge and rate tiers rather than treating them as airtight.
- Refunds and the counter. A refunded ticket (Stage 8b) arguably restores purchase-limit headroom. Nothing in section 10 says so, and decrementing on refund invites re-buy churn on scarce inventory. This plan keeps refunds counter-neutral; if product wants restoration, it is a Stage 8b consumer of `TicketRefunded` calling an Inventory Action, additive later.
- Limits set after sales exist. Counters accrue only from enforcement onward; a `max_per_customer` added mid-sale does not count earlier purchases. Backfilling from order history would cross into Orders data and is deliberately out of scope; document the behavior for tenants. The release side is immune to mid-sale policy changes by construction: decrements reverse each hold's recorded `counted_quantity` rather than consulting the current policy, so limits added or cleared between hold creation and release neither corrupt nor strand the counter.
- Admission token reuse. Tokens are TTL-bounded but not single-use, preserving the section 7.6 retry posture after failed hold attempts. If load tests show token sharing or replay abuse inside the TTL, the additive fix is marking the entrant consumed on first successful hold (one Redis flag checked in the same enforcement step). Revisit at Stage 12 load testing.
- Queue abandonment. Entrants who stop polling still get admitted and waste budget slots; the harm is bounded because unused admission tokens expire and never touch inventory. A liveness window (skip entrants not seen polling recently) is a clean later lever; noted for Stage 12 operations rather than built now.
- Redis loss and fairness. Queue state is intentionally non-durable; a Redis failover during an on-sale resets positions and buyers rejoin. This matches section 9.2's rule that Redis is never the system of record, but it should be stated in tenant-facing operational docs. The Lua scripts must also be cluster-safe (all keys for one event share a hash tag) if Redis Cluster is ever adopted; cheap to do from the start.
- Gatekeeper cadence. The gatekeeper needs sub-minute ticks to make a per-minute admission rate feel smooth. The scheduler's sub-minute support in the pinned Laravel version must be verified against current framework documentation at implementation time; the fallback is a supervised long-running command in the scheduler container (section 16.3 already runs dedicated scheduler and worker processes).
- Fake clock versus Redis TTL. Tests drive all expiry semantics (tokens, cache freshness, rate windows) through the injected clock; Redis TTLs are garbage collection only and carry no semantics. Any test that accidentally depends on a real Redis TTL is a bug in the test; the harness should assert semantics survive with TTLs disabled.
- Cache stampede. When a hot event's availability entry expires, concurrent requests may all miss to PostgreSQL for one interval. At second-level TTLs the herd is bounded and the read is a single-row counter select, so this plan ships without a lock; add single-flight locking only if Stage 12 load tests show it matters.
- Default numbers. Admission rate default, token TTL, tier limits, and cache TTLs are guesses until Stage 12 load tests produce data; everything lives in `config/onsale.php` and per-event policy so tuning never needs a migration. Section 17's requirement is structural (the waiting room bounds concurrency to what the payment path sustains); the calibration is operational.
