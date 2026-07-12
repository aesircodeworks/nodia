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

- [x] T1 `on_sale_policy` column, `OnSalePolicyData`, event create/update/read contracts, OpenAPI, TypeScript (catalog)
- [x] T2 `max_per_customer` column, ticket type contracts, and the Catalog Action read surface exposing it to Inventory (catalog)
- [x] T3 `config/onsale.php` and the named rate limiter tiers with 429 problem-document coverage (inventory)
- [ ] T4 `purchase_counters` table with RLS and CHECK, model, guarded upsert and decrement operations (inventory)
- [ ] T5 Purchase-limit enforcement in the hold lifecycle, `counted_quantity` on hold items, `customer_required`, concurrency simulation (inventory)
- [ ] T6 Queue join and position endpoints, Redis entrant lifecycle, `ChallengeVerifier` with fake and no-op implementations (inventory)
- [ ] T7 Gatekeeper command, admission Lua scripts, budget accounting, signed admission tokens with key rotation (inventory)
- [ ] T8 Admission enforcement on `POST /v1/storefront/holds`, updated OpenAPI error responses (inventory)
- [ ] T9 Clock-aware Redis read cache in front of the availability and seats endpoints (inventory)

### Review rounds

### Decisions and deviations

### T1: `on_sale_policy` column, `OnSalePolicyData`, event contracts (2026-07-12)

Landed the event half of TDD Slice 1 (stage-10 plan, task breakdown item 2):

- Additive migration `2026_07_12_000052_add_on_sale_policy_to_events_table.php` adds `events.on_sale_policy` jsonb, non-null, with a column `DEFAULT` matching `OnSalePolicyData`'s inactive shape (`{"high_demand":false,"admission_rate_per_minute":null,"challenge_required":false}`). Unlike `async_payment_policy` (added on `events`' creating migration, so the Data class's own constructor defaults sufficed), this column lands on an already-populated table, so a DB-level default was needed to backfill existing rows and to satisfy the exit criterion that pre-existing events read back the inactive policy with no Action involvement; no RLS policy change needed since `events` already carries its Stage 5a policy (plan is explicit on this point).
- `App\EventCatalog\Data\OnSalePolicyData` mirrors `AsyncPaymentPolicyData` exactly: `highDemand` (bool, default false), `admissionRatePerMinute` (nullable int, default null, request-validated `nullable|integer|min:1`), `challengeRequired` (bool, default false); cast directly on `App\EventCatalog\Models\Event` via laravel-data's Eloquent Castable support, added to the model's `Fillable` attribute and PHPDoc.
- Threaded through `CreateEventData`, `UpdateEventData`, and `EventData` as an `OnSalePolicyData|Optional` field (mirroring `asyncPaymentPolicy`'s own Optional handling in `CreateEvent`/`UpdateEvent`); `CreateEvent` defaults to `new OnSalePolicyData` when the request omits it (same reasoning as the existing `async_payment_policy` comment: `Event::create()` does not refetch the row, so a column left out of the insert array stays null on the in-memory model).
- OpenAPI: new `OnSalePolicy` schema, added to `Event` (required + properties), `EventCreateRequest`, and `EventUpdateRequest`. `StorefrontEventData`/`StorefrontTicketType` untouched, matching the plan's admin-only scope for this task.
- `composer types:generate` run; `packages/api-client/src/generated/index.ts` and the manifest committed with the new `OnSalePolicyData` type and the `on_sale_policy` field on `CreateEventData`, `UpdateEventData`, and `EventData` (TypeScript names, not the wire snake_case).
- Fixed an existing positional-constructor call site (`EventCreatedOutboxTest`'s rollback test builds `CreateEventData` directly rather than through `::from()`) to pass the new `onSalePolicy` argument; this is a mechanical adjustment for the new constructor parameter, not a behavior change.

Deviation from a literal reading of the plan: the plan's Data model section says the column needs "no policy change" but does not explicitly call for a column `DEFAULT`; the plan's own exit criterion ("events created before the column read back the inactive default policy") and data-conventions' additive-migration discipline both require one, and the tenants `commission_bps`/`refund_commission_policy` migration is the precedent followed for "fast-default" backfill on an alter-table migration.

Test evidence (all run from `apps/api` against the real PostgreSQL test database):

- `php artisan test --filter=OnSalePolicyDataTest`: 10 passed, 16 assertions.
- `php artisan test --filter=EventModelTest`: 8 passed, 22 assertions (includes the two new on_sale_policy cast round-trip tests).
- `php artisan test --filter=EventEndpointsTest`: 80 passed, 374 assertions (includes StorefrontEventEndpointsTest via substring match; both green).
- `php artisan test --testsuite=Feature --filter=EventCatalog`: 291 passed, 1371 assertions.
- `php artisan test --testsuite=Unit --filter=EventCatalog`: 158 passed, 342 assertions.
- `php artisan test --testsuite=Isolation --filter=Event`: 56 passed, 112 assertions.
- `php artisan test --testsuite=Architecture`: 40 passed, 97 assertions.
- `php artisan test --testsuite=Contract`: 427 tests, one first-run failure (`DocumentedResponseCoverageTest`, `get /v1/payouts/{payout} 200`) on a `users_email_unique` collision from unseeded Faker data, unrelated to this change (no payouts/users code touched); re-ran `--filter=DocumentedResponseCoverageTest` alone (422 tests, all passed) and then the full suite again (427/427, all passed), confirming the first failure was a pre-existing Faker-collision flake rather than something introduced here.
- `./vendor/bin/pint --test` on every changed file: passed.
- `./vendor/bin/phpstan analyse` on every changed non-test file (`--memory-limit=1G`, scoped paths, not the full baseline): 0 errors.

Commits:

- `12df8c3` feat(catalog): add events.on_sale_policy and thread it through event contracts

### T2: `max_per_customer` column, ticket type contracts, and the Catalog Action read surface (2026-07-12)

Landed the ticket type half of TDD Slice 1 (stage-10 plan, task breakdown item 3), independent of T1:

- Additive migration `2026_07_12_000053_add_max_per_customer_to_ticket_types_table.php` adds `ticket_types.max_per_customer` integer, nullable, with a CHECK `max_per_customer is null or max_per_customer > 0` (defense in depth behind the request-layer `min:1` validation, mirroring `ticket_types_price_amount_non_negative`'s own precedent from the creating migration). No column `DEFAULT` was needed unlike `events.on_sale_policy`: the column is nullable, so every pre-existing row already reads back null (unlimited) on a plain `ADD COLUMN` with no backfill required. No RLS policy change needed; `ticket_types` already carries its Stage 5a policy.
- `CreateTicketTypeData`/`UpdateTicketTypeData` gained `maxPerCustomer` (`int|Optional|null`, rule `sometimes|nullable|integer|min:1`): omitted or explicit null means unlimited on create; on update, omitted leaves the stored value untouched and explicit null clears it, mirroring `salesStart`/`salesEnd`'s own Optional-or-null precedent rather than `quantity`'s Optional-only one, since `max_per_customer` (unlike `quantity`) persists directly on `ticket_types` and needs an explicit-clear path. `CreateTicketType`/`UpdateTicketType` thread the field onto the model exactly like `requires_seat`. `TicketTypeData` and its OpenAPI `TicketType` schema carry `max_per_customer` as a required-but-nullable response field (existing ticket types read back null). Both admin routes stay gated by the existing `events.manage` capability with no separate check needed, and audited via the same `EventUpdated` outbox row every ticket type mutation already records (event-conventions).
- `App\EventCatalog\Data\HoldableTicketTypeData` (the read model `ResolveEventForHold` hands to Inventory's `CreateHold`, stage-06 precedent) gained `maxPerCustomer`, populated in `fromModel`. This is additive plumbing only: `CreateHold` does not read or enforce the field yet (that lands in stage-10 task 6, depends on task 5's `purchase_counters` table), so the existing Architecture suite's "only EventCatalog uses its own Models" assertion (`tests/Architecture/ContextBoundariesTest.php`) already proves Inventory cannot reach `max_per_customer` any other way; no new architecture test was needed for this slice specifically because that boundary rule is generic to every field on the model, not `max_per_customer`-specific.
- OpenAPI: `max_per_customer` added to `TicketType` (required, nullable), `TicketTypeCreateRequest`, and `TicketTypeUpdateRequest` (both optional, nullable, `minimum: 1`). `composer types:generate` run; `packages/api-client/src/generated/index.ts` and the manifest committed with `max_per_customer` on `CreateTicketTypeData`, `TicketTypeData`, and `UpdateTicketTypeData`.
- Observed but not acted on: `assertConformsToOpenApi()` (the Spectator-backed macro) did not fail against the pre-update spec even though `TicketType`'s `additionalProperties: false` should have rejected the extra `max_per_customer` key in the response body before the OpenAPI schema was updated; the Contract suite (427 tests) passed both before and after the schema update. This looks like a latent looseness in the test harness's response-validation wiring rather than anything this task introduced or should paper over; the OpenAPI schemas were still updated in full per the contract-first discipline this plan and api-conventions mandate, and flagged here rather than quietly relied upon. Worth a separate look, not scoped to this task.

Test evidence (all run from `apps/api` against the real PostgreSQL test database):

- `php artisan test --filter=TicketType`: 138 passed, 388 assertions (covers `TicketTypeEndpointsTest`, `TicketTypeValidationRulesTest`, `CreateTicketTypeTest`, `UpdateTicketTypeTest`, `TicketTypeModelTest`, `TicketTypeInventoryIsolationTest`, `TicketTypesIsolationTest`, `TicketTypeEventUpdatedOutboxTest`).
- `php artisan test --filter=HoldableTicketTypeDataTest`: 2 passed, 3 assertions.
- `php artisan test --testsuite=Architecture`: 40 passed, 97 assertions.
- `php artisan test --testsuite=Contract`: 427 passed, 2739 assertions.
- `php artisan test --testsuite=Feature --filter=EventCatalog`: 299 passed, 1406 assertions.
- `php artisan test --testsuite=Unit --filter=EventCatalog`: 174 passed, 365 assertions.
- `php artisan test --testsuite=Isolation --filter=Ticket`: 30 passed, 50 assertions.
- `./vendor/bin/pint --test` on every changed file: passed.
- `./vendor/bin/phpstan analyse` on every changed non-test file (`--memory-limit=1G`, scoped paths): 0 errors.

Commits:

- `7e90320` feat(catalog): add ticket_types.max_per_customer and thread it through ticket type contracts

### T3: `config/onsale.php` and the named rate limiter tiers (2026-07-12)

Landed TDD Slice 2 (stage-10 plan, task breakdown item 4; task breakdown item 11 confirmed a no-op in the pre-run verification above, so nothing to fold in):

- `config/onsale.php` (new): every tunable this stage introduces, in one file per the task's own instruction, even though only `rate_limits` is consumed yet. `rate_limits.browse`, `rate_limits.queue_entry`, `rate_limits.queue_poll` (each `max_attempts`/`decay_seconds`), and `rate_limits.hold_creation.ip`/`.customer` (stage-10 plan: "hold creation stricter than browse" holds under either key; defaults 10 and 20 per minute respectively against browse's 120). `queue_entry` and `queue_poll` are defined but not yet wired to any route (T6 has not landed); `admission_rate_per_minute_default`, `admission_token` (TTL, current/previous key id and secret for stateless rotation), and `cache` (availability/seats TTLs) are placeholders for T7-T9, documented as "not yet consumed" in their own comment blocks so a later reader does not assume they are already load-bearing.
- `App\Inventory\Support\RateLimiterKeys` (new): pure key-derivation helper, `ip()` (the request's client IP) and `customer()` (the authenticated customer's id via `$request->user('customer')`, mirroring `HoldController::store`'s own existing derivation, `null` for guest checkout). Isolated from `InventoryServiceProvider` so derivation is unit-testable without booting the HTTP kernel.
- `InventoryServiceProvider::registerRateLimiters()` (new, called from `boot()`): four `RateLimiter::for()` closures (`browse`, `queue_entry`, `queue_poll`, `hold_creation`), each reading its tier's config live per request (not captured at boot) so tests can override config and see it take effect immediately. `hold_creation` returns an array of one or two `Limit` objects (IP always, customer only when `RateLimiterKeys::customer()` resolves one), which Laravel's `ThrottleRequests` combines so either key tripping blocks the request. Used the standard `Illuminate\Cache\RateLimiter`/`throttle` middleware machinery deliberately, not a bespoke limiter or `ThrottleRequestsWithRedis` (`bootstrap/app.php` never calls `$middleware->throttleWithRedis()`, so the `throttle` alias resolves to the plain `ThrottleRequests` class): the task's own instruction is that 429 rendering is proven on "the real tiers rather than re-implementing it", and the plain `RateLimiter` already renders through the Stage 1 handler as `ThrottleRequestsException` (a `HttpExceptionInterface`), with zero new rendering code.
- "Redis-backed" (task wording) is satisfied by the app's existing cache configuration rather than a new binding: `Illuminate\Cache\CacheServiceProvider` resolves the `RateLimiter` singleton against `config('cache.limiter')`, which is unset here and so falls back to `config('cache.default')` — `redis` in every real environment (`.env`/`.env.example`: `CACHE_STORE=redis`), `array` only in tests (`phpunit.xml`: `CACHE_STORE=array`). This is deliberate, not an oversight: both `Illuminate\Cache\ArrayStore` and `RateLimiter`'s own `InteractsWithTime` trait key their expiry off `Carbon::now()`, so the array store lets `travelTo`/`freezeTime` reset a rate-limit window with no real sleeping in tests, exactly the "fake clock governs tests" discipline `App\Payments\Support\CircuitBreaker`'s own docblock already establishes for its cache-backed state, and exactly what the plan's Risks section warns a raw Redis TTL cannot do ("Fake clock versus Redis TTL... any test that accidentally depends on a real Redis TTL is a bug in the test"). A `ThrottleRequestsWithRedis`-based implementation would use Lua scripts against Redis's own server-side EXPIRE, which is real wall-clock time and not fake-clock-testable without literal sleeping, so it was deliberately not used.
- Routes: `POST /v1/storefront/holds` gets `throttle:hold_creation`; `GET /v1/storefront/events/{event}/availability` and `GET /v1/storefront/events/{event}/seats` get `throttle:browse` (both in `app/Inventory/Http/routes/storefront.php`, the Stage 6 storefront routes named in the task). `GET/DELETE /v1/storefront/holds/{hold}` were left unthrottled: the plan's tier list names only "availability, seats, event reads" for `browse` and hold *creation* for `hold_creation`; the Stage 5a storefront event list/show routes (`GET /v1/storefront/events` and `/{event}`) were left alone too, since those are EventCatalog's own Stage 5a routes, not "the existing Stage 6 storefront routes" the task scopes this change to — applying `browse` there is EventCatalog-scoped work outside this task's boundary, not something this task's own instructions asked for.
- No OpenAPI or Data-class change: T3 adds no new endpoint and no new wire shape, and the stage-10 plan's own TDD sequencing lists no "Contract (first)" step for Slice 2 (unlike Slice 1), only Feature and Unit. 429 problem documents are validated against the shared `Problem` component schema (`assertMatchesProblemSchema()`), the same pattern Stage 1's own probe-route test uses (`tests/Feature/Problems/ProblemResponsesTest.php`), not per-path OpenAPI responses; no path in `docs/openapi/openapi.yaml` declares a 429 today, and none needed to be added for this slice. `composer types:generate` was not run: no Data class changed.

Test evidence (all run from `apps/api` against the real PostgreSQL test database):

- `php artisan test --filter=RateLimiterKeysTest`: 4 passed, 4 assertions (unit, key derivation: IP, no customer, wrong-guard customer, customer present).
- `php artisan test --filter=ThrottleRenderingTest`: 2 passed, 11 assertions (unit, `ProblemRenderer` rendering a `ThrottleRequestsException` directly as 429 `request.rate_limited` with `Retry-After`, and the non-`/v1` bypass).
- `php artisan test --filter=RateLimitingTest`: 3 passed, 17 assertions (feature, first-written-red-then-green per the double loop: `hold_creation` 429 with `Retry-After` once the IP tier is exceeded; the window resets after `travelTo` past the decay with no real sleeping; `browse` keeps serving past the point an equal request count would already have blocked `hold_creation`, both driven by `config([...])` overrides in-test).
- `php artisan test --testsuite=Feature --filter=Inventory`: 57 passed, 238 assertions (existing Stage 6/7/8 storefront flows through the newly-throttled routes, unaffected at the platform-default tier limits).
- `php artisan test --testsuite=Unit --filter=Inventory`: 85 passed, 197 assertions.
- `php artisan test --testsuite=Architecture`: 40 passed, 97 assertions.
- `php artisan test --testsuite=Contract`: 427 passed, 2739 assertions (every existing Stage 1-9 recorded-response and OpenAPI-conformance check still green through the newly-throttled Inventory storefront routes).
- `./vendor/bin/pint --test` on every changed file: passed.
- `./vendor/bin/phpstan analyse --memory-limit=1G` on every changed non-test file: 0 errors.

Commits:

- `0f2e381` feat(inventory): add config/onsale.php and the named rate limiter tiers
