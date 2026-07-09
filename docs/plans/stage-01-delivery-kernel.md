# Stage 1: Delivery Kernel and Test Harness

Detailed implementation plan for Stage 1 of [api-implementation-plan.md](../api-implementation-plan.md). The master plan's stage section, "Method: the TDD loop", and "Contract pipeline" are binding, as are [api-conventions.md](../api-conventions.md), [data-conventions.md](../data-conventions.md), and [event-conventions.md](../event-conventions.md). Design citations reference [system-design.md](../system-design.md) section numbers.

## Scope and non-goals

Goal: the machinery every later test depends on exists and is proven on the health endpoint (the only endpoint that exists).

Phase 0 (roadmap Phase 0, spec `specs/001-project-foundation`) already landed part of this stage. Inspected and confirmed done in `apps/api`:

- Correlation ID middleware (`app/Http/Middleware/CorrelationId.php`) with feature tests covering echo, UUIDv7 generation, exception-path echo, and log context propagation per system-design 9.2.
- `GET /v1/health` (`HealthController`, `HealthReportData`, `HealthChecksData`) with feature tests and its OpenAPI fragment merged into `docs/openapi/openapi.yaml`, including a problem document on the 503 degradation path with code `health.degraded`.
- Architecture suite (`tests/Architecture`): Pest presets plus the context boundary rule that only a context uses its own Models (system-design 18).
- Stub harnesses for the Isolation and Concurrency suites (`tests/Isolation/SuiteHarnessTest.php`, `tests/Concurrency/SuiteHarnessTest.php`, both trivially green placeholders).
- CI (`.github/workflows/api.yml`): Pint, Larastan, Pest, Architecture, Isolation, and Concurrency jobs, the latter three with real PostgreSQL 17 and Redis services, plus the TypeScript contract drift gate against `packages/api-client/src/generated`.
- JSON error rendering forced for `/v1/*` via `shouldRenderJsonWhen` in `bootstrap/app.php` (plain Laravel JSON, not problem documents).

This stage delivers the remaining work:

1. RFC 9457 problem+json exception handling for every error path under `/v1`, with a stable error code registry and a validation `errors` map, replacing the plain JSON rendering (api-conventions Errors; system-design 13).
2. `app/Support/Money`: minor-unit value object, Eloquent cast for `*_amount` plus `currency` column pairs, laravel-data cast and transformer for the `{amount, currency}` wire shape, generated TypeScript type (ADR 018; system-design 8.3).
3. Test matrix completion: a Unit suite and a Contract suite declared in `phpunit.xml`; Isolation and Concurrency running against real PostgreSQL locally exactly as the CI jobs already do, and refusing to run on SQLite.
4. Contract suite wiring: OpenAPI conformance assertions integrated into the feature test base so every feature test doubles as a contract assertion, a spec validity check, and a CI gate that catches shape drift between the Data classes and `docs/openapi/openapi.yaml`. The concrete tool is selected at the start of this stage against current library documentation (master plan "Contract pipeline"; open decision below).
5. Real Isolation and Concurrency harnesses replacing the stubs: a two-tenant fixture with an RLS-enabled test connection, a parallel process runner against real PostgreSQL, both proven with deliberately failing probes.
6. Time control for everything TTL-based: immutable framework clock, test freeze and travel helpers, and an architecture rule keeping app code off uncontrollable time sources.

Non-goals, explicitly deferred:

- Tenants, `tenant_domains`, the `SET LOCAL app.tenant_id` transaction wrapper, the per-table RLS policy pattern as production middleware, the sentinel platform tenant, and the platform-scope database role: Stage 2 (system-design 4.1 to 4.3). Stage 1 builds only the test-side harness those will run inside.
- Authentication, authorization, and any real 401 or 403 semantics beyond the generic problem mapping: Stage 3. Stage 1 exercises the mappings through throwaway probe routes, which are registered inside the feature tests themselves (never in the app's route files), so they exist only while their test runs and never appear in the production route table or the OpenAPI spec.
- The outbox, `outbox_events`, `outbox_deliveries`, dispatchers, sweepers: Stage 4 (system-design 9).
- Any domain table, endpoint, or event beyond `/v1/health`: Stages 2 and later.
- Idempotency-Key semantics: Stage 8a (api-conventions Idempotency and Correlation); only the eventual error codes are reserved in the registry now.
- Money formatting for display, currency conversion, and allocation or split arithmetic: added by the first consumer that needs them (Stage 7 promo math, Stage 8b ledger), behind the same value object per ADR 018.

## Dependencies

Requires (all present):

- Phase 0 skeleton: Laravel 13 on Octane/FrankenPHP, Pest 4, Larastan, Pint, spatie/laravel-data, spatie/laravel-typescript-transformer, the four existing suites, the CI workflow, and the compose stack (`infra/compose/docker-compose.yml`) providing PostgreSQL 17 and Redis locally.

Consumed by later stages:

- Stage 2 builds its tenancy middleware and first RLS policies against the isolation harness and reuses the problem code registry for resolution failures. The harness's `SET LOCAL app.tenant_id` helper is the same mechanism Stage 2 productionizes (system-design 4.1).
- Stage 3 adds real 401/403 flows onto the `auth.unauthenticated` and `auth.forbidden` codes and uses time control for token lifetimes.
- Stage 4 uses the Unit suite, time control (sweeper grace windows, system-design 9.1), and the concurrency harness for dispatcher races.
- Stage 5 puts the Money cast on `ticket_types` price columns and the Money wire shape in catalog contracts (system-design 8.2, 12).
- Stage 6 is driven end to end by the concurrency harness and the fake clock (hold TTLs, system-design 6.1).
- Every stage from 2 onward ships endpoints through the contract conformance gate and isolation coverage.

## Data model

This stage ships no migrations and no persistent tables. That is deliberate: the first real tenant-scoped table is `tenants` in Stage 2, and per data-conventions merged migrations are never edited, so nothing speculative merges here.

The isolation and concurrency harnesses create throwaway probe tables inside their own tests (created in `beforeEach`, dropped in `afterEach`, never via `database/migrations`). The probe DDL is still the reference implementation of the pattern every future migration follows (data-conventions Tenancy; ADR 003):

- `harness_probes`: `id` uuid primary key, `tenant_id` uuid not null, `label` text, timestamps.
- RLS in the same DDL block: `ALTER TABLE harness_probes ENABLE ROW LEVEL SECURITY`, `ALTER TABLE harness_probes FORCE ROW LEVEL SECURITY`, and a single-table policy `USING (tenant_id = current_setting('app.tenant_id')::uuid) WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)`. `FORCE` matters because the test connection owns the table and PostgreSQL exempts owners from RLS otherwise; whether production uses `FORCE` or a separate non-owner application role is a Stage 2 decision (system-design 4.3), and the harness must work under either.
- `concurrency_counters` (concurrency probe): `id` uuid primary key, `quantity` int not null, `taken` int not null default 0. Not tenant-scoped; it exists to prove the runner detects lost updates and that conditional UPDATEs checked by affected-row count do not lose them (system-design 6.1).

Money is a schema convention this stage codifies without creating columns: every monetary value is an integer minor-unit `*_amount` column paired with a `currency` column on the same row, never floats, never an amount without a currency (data-conventions Money; ADR 018). The Eloquent cast tests use an in-test probe table with `price_amount bigint` and `currency char(3)` to prove round-tripping.

## Domain events

None produced and none consumed. The outbox does not exist until Stage 4, and Stage 1 introduces no domain state changes to describe (event-conventions requires events to be recorded in the same transaction as a state change; there are none).

Stage 1's only contact with event-conventions is indirect: the Money laravel-data transformer defined here is what event payloads will later use for monetary fields, keeping payload money shapes identical to the wire format (event-conventions Payloads), and the correlation ID that events must carry (envelope field `correlation_id`) is already propagated by the Phase 0 middleware.

## Endpoints

No new endpoints. Two contract-visible changes:

`GET /v1/health` (exists, unauthenticated):

- 200 `HealthReportData` and 503 degraded problem document as today; the shapes and the `health.degraded` code are frozen contract and must not change.
- The 503 path is refactored to render through the shared problem infrastructure instead of the hand-built array in `HealthController`, and the `type` member is aligned between code (currently `about:blank`) and the OpenAPI example (currently `/problems/health-degraded`). Decision: `type` is `/problems/{slug}` where the slug is derived mechanically from the full `code` by replacing dots with dashes and underscores with dashes (`health.degraded` gives `/problems/health-degraded`, `request.not_found` gives `/problems/request-not-found`); no per-case exceptions, so the derivation stays a pure function of the registry. The controller changes; the spec example already conforms and stays.
- Both responses become conformance-asserted against `docs/openapi/openapi.yaml` in the same feature tests.

All error responses under `/v1/*` (cross-cutting): RFC 9457 `application/problem+json` with `type`, `title`, `status`, `detail`, and stable `code`; validation errors add the `errors` map of field to message list; every response still echoes `X-Correlation-Id` (api-conventions Errors; system-design 13). Rendering lives in `bootstrap/app.php` `withExceptions`, mapping framework exceptions through the registry.

New laravel-data objects (source of truth per ADR 013, `#[MapName(SnakeCaseMapper::class)]`, exported to TypeScript):

- `App\Support\Problems\ProblemData`: `type`, `title`, `status`, `detail`, `code`, plus a `correlation_id` extension member.
- `App\Support\Problems\ValidationProblemData`: extends the problem shape with `errors: array<string, list<string>>`.
- `App\Support\Money\MoneyData` wire shape `{amount: int, currency: string}` (or the Money value object itself made transformable; whichever the data library handles cleaner, the wire shape is fixed by api-conventions).

Initial error code registry (`App\Support\Problems\ErrorCode`, a string-backed enum; each case carries title and HTTP status, and derives its type slug mechanically from the code value as described under Endpoints). Codes are stable API contract from the moment they merge (api-conventions Errors):

| Condition | HTTP | code |
| --- | --- | --- |
| Route or model not found | 404 | `request.not_found` |
| Method not allowed | 405 | `request.method_not_allowed` |
| Validation failed | 422 | `request.validation_failed` |
| Unauthenticated | 401 | `auth.unauthenticated` |
| Forbidden | 403 | `auth.forbidden` |
| Throttled, with `Retry-After` header | 429 | `request.rate_limited` |
| Unhandled server error | 500 | `server.internal_error` |
| Health degradation (existing, migrated into the registry) | 503 | `health.degraded` |

The 500 problem never leaks exception class, message, or trace when `app.debug` is false; `detail` is generic and the correlation ID is the support handle. Domain-specific codes (`checkout.hold_expired`, gateway errors) are added by their owning stages; the registry is the single place they land.

OpenAPI: `docs/openapi/openapi.yaml` gains shared `Problem` and `ValidationProblem` component schemas and a shared `Money` schema; the health path references the shared problem schema base. Generated TypeScript for the new Data objects is committed under `packages/api-client/src/generated` via `composer types:generate`.

## TDD sequencing

Ordered slices, each following the master plan's double loop: outside feature test first, contract next, unit tests driving the inside, then green, refactor, regenerate, commit.

### Slice 1: Problem documents and the error code registry

Failing tests first:

- Feature: hitting an unknown `/v1` route returns 404 `application/problem+json` with `code request.not_found`, `type /problems/request-not-found`, `title`, `status 404`, snake_case keys, and the correlation ID echoed. Same matrix for 405 (wrong verb on `/v1/health`), 422 via a throwaway probe route with a validated request (asserting the `errors` map shape), 401 via a probe route behind `auth` middleware, 403 via a probe route with a denying Gate, 429 via a throttled probe route (asserting `Retry-After` is present per api-conventions), and 500 via a throwing probe route (asserting no message or trace leaks with debug off, and that the response still carries the correlation ID). All probe routes are registered inside the tests themselves (each test defines its route before making the request), never in the app's route files.
- Feature: the health 503 path renders through the same infrastructure with its existing pinned shape unchanged (existing tests in `HealthEndpointTest` and `HealthControllerTest` stay green and are extended to assert `type /problems/health-degraded`).
- Unit: `ErrorCode` maps every case to status, title, and type slug; `ProblemData` and `ValidationProblemData` serialize to the exact snake_case wire shape.

Implement: `App\Support\Problems` (enum, Data objects, a renderer invoked from `withExceptions`), refactor `HealthController`, regenerate TypeScript.

### Slice 2: Support/Money

Failing tests first:

- Unit: construction from integer minor units and uppercase ISO 4217 code; equality and comparison; `add` and `subtract` require matching currency and throw `CurrencyMismatchException` otherwise; multiplication only by integers; negative amounts allowed (refunds); no float ever enters or leaves the object; invalid currency codes rejected.
- Unit: the Eloquent cast round-trips a model attribute through `price_amount` and `currency` columns, reads null when the amount column is null, and refuses to write a Money whose currency mismatches an already-set row currency.
- Unit: a Data object with a Money property serializes to `{"amount": 12500, "currency": "BRL"}` and hydrates from the same shape; the generated TypeScript type is `{amount: number; currency: string}`.

Implement: `App\Support\Money\Money`, `MoneyCast` (Eloquent), data-library cast and transformer registered in the published `config/data.php`, TypeScript transformer registration, regenerate and commit generated output.

### Slice 3: Suite matrix (Unit, Contract, PostgreSQL locally)

Failing tests first:

- A guard test in each of Isolation and Concurrency asserting the active database driver is `pgsql` and failing with an instructive message otherwise (this is the test that makes SQLite refusal real; it fails until the connection wiring lands).
- A trivial first Unit test (the Money tests from slice 2 move here if slice 2 merged earlier under Feature/Unit interim placement).

Implement: declare `Unit` and `Contract` testsuites in `phpunit.xml`; wire Isolation and Concurrency to a real PostgreSQL connection locally via env-driven overrides in `tests/Pest.php` (defaults matching the compose stack: host 127.0.0.1, port 5432, user `nodia`, password `nodia`, a dedicated `nodia_test` database created by a compose init script so tests never touch the dev database), matching the CI jobs' env. `composer test` (`php artisan test`) now runs all six suites; CI's Pest job picks them up with no workflow change, and the dedicated Isolation and Concurrency jobs keep running them in isolation.

### Slice 4: Contract suite wiring

Selection spike first, timeboxed, against current library documentation (hard requirement from the master plan; do not trust training data): evaluate response-validation tooling (candidates to verify: hotmeteor/spectator, osteel/openapi-httpfoundation-testing, league/openapi-psr7-validator) against two criteria: (a) every feature test can assert its response conforms to `docs/openapi/openapi.yaml` via one macro, and (b) the gate catches shape drift between the laravel-data objects and the hand-maintained YAML, not only mismatches in recorded responses. If no tool satisfies (b), switch the pipeline to generating the OpenAPI document from the Data classes and gate on generated-vs-committed diff instead, as the master plan directs. Record the outcome as ADR 019.

Failing tests first:

- Feature: `getJson('/v1/health')` chained with `assertConformsToOpenApi()` fails when a deliberate mismatch is introduced (prove the assertion bites before trusting it; the deliberate mismatch is reverted, not merged).
- Contract suite: `docs/openapi/openapi.yaml` is a valid 3.1 document; every `/v1` route registered in the app has a path entry in the spec (drift in the route-to-spec direction); the Data-class drift check per the selected mechanism. Because probe routes are test-registered, they never exist when this suite enumerates the app's route table, so the route-to-spec check needs no exclusion list; if a probe ever must be app-registered, it is gated to the testing environment and explicitly excluded here.

Implement: the `assertConformsToOpenApi` macro in the feature test base applied to both health responses (full path conformance, since health exists in the spec), `tests/Contract` populated, and a CI step (inside the existing Pest job or the contract-drift job) running the Contract suite. Slice 1 problem responses on probe routes cannot use path-based conformance (their paths are absent from the spec by design); they are instead validated against the shared `Problem` and `ValidationProblem` component schemas directly, via a schema-level assertion the same tooling provides or a dedicated `assertMatchesProblemSchema` helper. Full path conformance is reserved for routes that exist in the spec.

### Slice 5: Isolation harness

Failing tests first (these replace `tests/Isolation/SuiteHarnessTest.php`):

- With the two-tenant fixture (two fixed UUIDv7 tenant IDs, helper `actingAsTenant(string $tenantId, Closure $fn)` running `$fn` inside a transaction after `SET LOCAL app.tenant_id`, mirroring system-design 4.1), on the `harness_probes` table: a row inserted as tenant A is visible to A; invisible to B on select; B's update and delete affect zero rows; B cannot insert a row bearing A's `tenant_id` (WITH CHECK violation); a query outside any tenant transaction sees nothing or errors (no default leaks).
- A meta-probe proving the harness detects leaks: the same assertions against a probe table created without a policy must fail, asserted by expecting the leak (this test documents what a missing policy looks like and is the template for the one-time CI verification below).

Implement: `tests/Isolation/Support` fixture and helpers, probe DDL helper embodying the policy pattern from the Data model section. Then the exit-criterion exercise: push a throwaway branch adding a deliberately leaking probe as a normal test, confirm the Isolation CI job goes red and blocks merge, record the run link in the PR that closes the stage, delete the branch.

### Slice 6: Concurrency harness

Failing tests first (these replace `tests/Concurrency/SuiteHarnessTest.php`):

- Lost-update detection probe: N parallel OS processes each perform read-then-write increments against `concurrency_counters.taken`; the harness asserts the final value is less than the sum of increments, proving the runner produces real contention (if this ever passes by luck, increase iterations; flakiness here means the harness is too weak, which is itself the failure).
- Conditional-UPDATE probe: the same workload as `UPDATE concurrency_counters SET taken = taken + 1 WHERE taken < quantity` checked by affected-row count never exceeds `quantity` and sums exactly (system-design 6.1; data-conventions State Transitions).

Implement: `tests/Concurrency/Support/ParallelRunner` using forked worker processes, each purging inherited connections and opening its own PDO connection to real PostgreSQL before running its task, with a start barrier so workers hit the row simultaneously. Preferred implementation is spatie/fork (dev dependency, in keeping with ADR 014's spatie posture); verify current documentation and pcntl availability in the CI image first, with Symfony Process spawning artisan commands as the fallback.

### Slice 7: Time control

Failing tests first:

- Architecture: app code (excluding `CorrelationId`, whose `microtime` is duration measurement, not domain time) does not call `time()`, `date()`, `mktime`, `microtime`, or construct `DateTime`/`Carbon` directly; domain time flows through the framework clock (`now()`, `Date` facade) so `freezeTime` and `travelTo` control it.
- Unit: a sample TTL value object or helper (`expires_at = now() + ttl`, `isExpired()`) behaves correctly under `$this->freezeTime()` and `$this->travel(11)->minutes()`, proving the pattern Stage 6 holds and Stage 3 tokens will reuse.

Implement: `Date::use(CarbonImmutable::class)` in `AppServiceProvider` (immutable now everywhere; `HealthController` already uses `CarbonImmutable`), the architecture rule, and a short note in the test base or CLAUDE-adjacent docs that TTL tests must never sleep.

## Task breakdown

Ordered; each is a separately mergeable PR unless noted. Scopes per the commit conventions (`support` for shared infra, no scope for repo-wide harness work).

1. `feat(support): rfc 9457 problem handler and error code registry` (slice 1, includes OpenAPI Problem components, generated TS, health controller refactor).
2. `feat(support): money value object, eloquent cast, wire transformers` (slice 2, includes Money OpenAPI component and generated TS).
3. `test: declare unit and contract suites and run isolation and concurrency on postgres locally` (slice 3, includes `phpunit.xml`, Pest wiring, compose test-database init, driver guard tests).
4. `docs: adr 019 openapi conformance tooling` (slice 4 spike output; small, unblocks 5).
5. `test: openapi conformance assertions and contract suite gate` (slice 4 remainder, includes CI step, retrofitting health tests with path conformance, and retrofitting problem tests with component-schema validation).
6. `test: tenant isolation harness with two-tenant rls fixture` (slice 5).
7. Throwaway verification branch with a deliberately failing isolation probe; not merged, CI run linked from the stage-closing PR (exit criterion 6 below).
8. `test: concurrency harness with parallel process runner` (slice 6, includes the fork dependency decision).
9. `feat(support): immutable clock, time-control helpers, architecture time rule` (slice 7).
10. `docs: mark stage 1 done in the implementation plan status table` (and roadmap status untouched; Phase 0 there is already Done).

Tasks 1 and 2 are independent of each other; 3 precedes 5, 6, and 8; 4 precedes 5. 9 can land any time after 3.

## Exit criteria

The master plan's exit line ("/v1/health is contract-checked end to end; all six suites run in composer test and CI; a fake failing isolation test demonstrably blocks the build") expands to:

1. `GET /v1/health` 200 and 503 responses are asserted conformant to `docs/openapi/openapi.yaml` inside their feature tests, and mutating either the Data class or the YAML in a shape-breaking way fails a test or CI gate.
2. Every error produced under `/v1` renders as `application/problem+json` with `type`, `title`, `status`, `detail`, and a registry `code`; 422 carries the `errors` map; 429 carries `Retry-After`; 500 leaks nothing with debug off; all echo `X-Correlation-Id`. Proven by the slice 1 feature matrix.
3. `App\Support\Money` exists with unit-tested arithmetic and currency guards, a round-tripping Eloquent cast, the `{amount, currency}` wire shape on Data objects, and its generated TypeScript committed with the drift gate green.
4. `phpunit.xml` declares Feature, Unit, Contract, Architecture, Isolation, and Concurrency; `composer test` runs all six locally; the CI Pest job runs all six and the dedicated Architecture, Isolation, and Concurrency jobs stay green.
5. Isolation and Concurrency fail with an instructive message when pointed at SQLite, and pass locally against the compose PostgreSQL with no configuration beyond `make up`.
6. A deliberately failing isolation probe on a throwaway branch turns the Isolation CI job red and blocks merge; the run is linked in the stage-closing PR.
7. The concurrency harness demonstrably detects lost updates (the read-then-write probe fails as designed) and demonstrably passes the conditional-UPDATE probe with exact accounting.
8. A TTL behavior test passes under a frozen and travelled clock without sleeping, and the architecture time rule is green over `app/`.
9. Larastan and Pint clean; the Architecture suite green; no hand edits under `packages/api-client/src/generated`.

## Risks and open questions

- OpenAPI conformance tooling (master plan open decision): the selected tool may validate recorded responses but not catch Data-class-to-YAML drift. Mitigation is built into slice 4: if no tool satisfies both criteria, generate the spec from the Data classes and gate on the generated diff. Evaluate against current documentation only; candidate maintenance status must be verified at spike time, not assumed.
- RLS owner bypass: PostgreSQL exempts table owners and superusers from RLS unless `FORCE ROW LEVEL SECURITY` is set or a non-owner role connects. The harness uses `FORCE` on probe tables so a same-role test connection is honest, but the production posture (forced RLS vs a dedicated application role, and the separate platform-scope role of system-design 4.3) is a Stage 2 decision. Risk: a harness that silently tests with a bypassing role would prove nothing; the deliberate-leak meta-probe exists precisely to catch that.
- Parallel runner portability: pcntl-based forking is unavailable on Windows and can interact badly with inherited PDO and Redis connections. Mitigated by purging connections post-fork and by the Symfony Process fallback; the lost-update probe validates whichever mechanism is used actually contends.
- Concurrency probe flakiness: contention-based assertions can pass by luck at low iteration counts. Tune iterations and worker counts until the lost-update probe fails deterministically across repeated CI runs before trusting the suite for Stage 6.
- Error code freeze: every `code` merged in slice 1 is permanent contract (api-conventions). The initial registry is deliberately small; review the names once against Stage 2 and 3 needs (tenant resolution failures, token errors) before merging, since renaming later is a breaking change.
- Test database separation: local Isolation and Concurrency runs must never point at the dev `nodia_api` database. The compose init script creating `nodia_test` is small but load-bearing; document it in the API README.
- `type` URI alignment: the health controller currently emits `about:blank` while the spec example shows `/problems/health-degraded`. This plan resolves it in slice 1 in favor of registry-derived `/problems/{slug}` URIs with the slug mechanically derived from the full `code` (dots and underscores become dashes, no per-case exceptions); if that is contested, decide before slice 1 merges because `type` is also client-visible contract.
