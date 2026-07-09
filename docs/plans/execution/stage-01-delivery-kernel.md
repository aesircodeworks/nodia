# Execution Journal: Stage 1, Delivery Kernel and Test Harness

Durable record of execution runs for [stage-01-delivery-kernel.md](../stage-01-delivery-kernel.md). Append a new run header per run; never rewrite prior entries.

## Run: 2026-07-09

- Stage: 1, Delivery Kernel and Test Harness
- Date: 2026-07-09
- Branch: `feat/api-implementation`
- Base commit: `fdb025183966a6519cc10ff7c9f9425b8540393a`

Verified starting state: only Phase 0 work is present. `app/Support` does not exist, `phpunit.xml` declares four suites (Feature, Architecture, Isolation, Concurrency) defaulting to SQLite in memory, `tests/Isolation/SuiteHarnessTest.php` and `tests/Concurrency/SuiteHarnessTest.php` are still the trivial stubs, and error rendering under `/v1` is plain Laravel JSON via `shouldRenderJsonWhen`. The health 503 path builds its problem document by hand in `HealthController`. All six remaining scope items from the stage plan are open. The master plan status table already marks Stage 1 as "In progress".

### Task checklist

- [ ] task-01: RFC 9457 problem handler and error code registry (slice 1)
- [ ] task-02: Money value object, Eloquent cast, wire transformers (slice 2)
- [ ] task-03: Suite matrix: Unit and Contract suites declared, Isolation and Concurrency on real PostgreSQL locally with driver guards (slice 3)
- [ ] task-04: ADR 019, OpenAPI conformance tooling selection spike (slice 4 spike)
- [ ] task-05: OpenAPI conformance assertions and Contract suite gate (slice 4 remainder)
- [ ] task-06: Tenant isolation harness with two-tenant RLS fixture (slice 5)
- [ ] task-07: Throwaway-branch verification that a failing isolation probe blocks CI (exit criterion 6)
- [ ] task-08: Concurrency harness with parallel process runner (slice 6)
- [ ] task-09: Immutable clock, time-control helpers, architecture time rule (slice 7)
- [ ] task-10: Mark Stage 1 done in the implementation plan status table

### Review rounds

None. No task completed, so no review round ran.

### Decisions and deviations

The partial task-01 work described below is left uncommitted rather than reverted, so the next run can decide to finish it or discard it. Nothing from it is claimed as done.

### Run summary (closed 2026-07-09 05:23 -03)

- Tasks completed: 0 of 10. The run stopped at task-01 (RFC 9457 problem handler and error code registry) because the implementer agent returned no result.
- Gate: not run; no task reached it.
- Review rounds: none, so the run did not end review-clean.
- Unresolved or declined findings: none, because no review took place.
- Blocker: task-01 agent returned no result mid-task. It left partial uncommitted work in the tree: new tests under `apps/api/tests/Feature/Problems/` (`ProblemResponsesTest.php`, `ProblemDataTest.php`, `ErrorCodeTest.php`), new classes under `apps/api/app/Support/Problems/` (`ErrorCode.php`, `ProblemData.php`, `ValidationProblemData.php`, `ProblemRenderer.php`), edits to `ApiErrorRenderingTest.php`, `HealthControllerTest.php`, `HealthEndpointTest.php`, and Problem schema additions to `docs/openapi/openapi.yaml`. The renderer is not wired in `bootstrap/app.php` and `HealthController` still emits `type: about:blank`, so the Feature suite fails 10 of 40 tests (verified 2026-07-09: errors under `/v1` still render `application/json`, not `application/problem+json`). The work sits at the red phase of the TDD loop.

### Exit criteria assessment (2026-07-09)

Assessed against the current committed state plus the uncommitted working tree. None of the 9 exit criteria in the stage plan is met.

1. Health conformance to OpenAPI: not met. No conformance assertions exist in `HealthEndpointTest` or `HealthControllerTest`; the conformance tooling spike (task-04) and the Contract suite gate (task-05) never started.
2. Problem-document rendering under `/v1`: not met. Verified by running the Feature suite on 2026-07-09: `ProblemResponsesTest` fails all 8 matrix cases (404, 405, 422, 401, 403, 429, 500, plus content type without Accept) because responses still carry `application/json`, and the health 503 still emits `type: about:blank` instead of `/problems/health-degraded`. The `App\Support\Problems` classes exist uncommitted but are not wired into exception rendering.
3. `App\Support\Money`: not met. The class does not exist; `apps/api/app/Support/` contains only `Problems/`. Task-02 never started.
4. Six suites in `phpunit.xml`, `composer test`, and CI: not met. `phpunit.xml` still declares only Feature, Architecture, Isolation, and Concurrency; no Unit or Contract suite exists.
5. Isolation and Concurrency on real PostgreSQL with SQLite guards: not met. `tests/Isolation/SuiteHarnessTest.php` and `tests/Concurrency/SuiteHarnessTest.php` are still the Phase 0 trivial stubs with no driver guard and no RLS fixture.
6. Failing isolation probe blocks CI: not met. No throwaway branch was created and no CI run exists to link.
7. Concurrency harness detects lost updates: not met. No harness or probes exist.
8. TTL test under frozen clock and architecture time rule: not met. No clock abstraction, no TTL test, no time rule in the Architecture suite.
9. Larastan, Pint, Architecture suite green over the stage's merged work: not applicable as stated, since no stage work was committed; the working tree as left by the blocked task fails 10 Feature tests, so the gates are not green over it either.

## Run: 2026-07-09, second run

- Stage: 1, Delivery Kernel and Test Harness
- Date: Thu Jul 9 12:05:08 -03 2026
- Branch: `feat/api-implementation`
- Base commit: `47817a7` (docs: close stage 1 execution journal)

### task-01: RFC 9457 problem handler and error code registry (slice 1)

Completed. Commit `88dfcb17637d2da88062cfcacdc30826ae1ac0f7`, `feat(support): rfc 9457 problem handler and error code registry`.

What landed:

- The previous run's uncommitted red-phase work was inspected against the stage plan and adopted rather than discarded: the feature matrix (`tests/Feature/Problems/ProblemResponsesTest.php`, all 8 cases: 404, 405, 422 with errors map, 401, 403, 429 with Retry-After, 500 leak check, plus the no-Accept-header case in `ApiErrorRenderingTest`), the unit-style tests (`ErrorCodeTest`, `ProblemDataTest`), the `App\Support\Problems` classes (`ErrorCode`, `ProblemData`, `ValidationProblemData`, `ProblemRenderer`), the extended health tests asserting `type /problems/health-degraded`, and the `Problem`/`ValidationProblem`/`HealthDegradedProblem` component schemas in `docs/openapi/openapi.yaml`. Verified red first: the Feature suite failed 10 of 40 before wiring.
- New in this run: the renderer wired in `bootstrap/app.php` via `$exceptions->render(...)` alongside the retained `shouldRenderJsonWhen`; `App\Http\Data\HealthDegradedProblemData` extending `ProblemData` with `checks` and `checked_at`; `HealthController` refactored off its hand-built array onto that Data object (the 503 body keeps its frozen shape, `correlation_id` stays header-only via `Optional`); generated TypeScript (`ErrorCode`, `ProblemData`, `ValidationProblemData`, `HealthDegradedProblemData`) committed under `packages/api-client/src/generated`.
- Probe routes (`/v1/__probe/*`) are registered inside the tests only; nothing was added to `routes/api.php` or the OpenAPI paths.

Test evidence:

- `composer test`: 53 passed, 0 failed (Feature 40, Architecture 11, Isolation 1 stub, Concurrency 1 stub), 289 assertions.
- `composer lint` (Pint): passed. `composer analyse` (Larastan): 0 errors.
- `composer types:generate`: regenerated cleanly; `pnpm --filter api-client exec tsc --noEmit` exit 0; `docs/openapi/openapi.yaml` parses as valid YAML with the 503 response referencing `HealthDegradedProblem` (which now `allOf`s the shared `Problem`).

Deviations and decisions:

- `tests/Architecture/PresetTest.php` now ignores `App\Support\Problems\ErrorCode` on the Laravel preset only: the preset requires enums under `App\Enums`, while the stage plan mandates the registry live in `App\Support\Problems`. The ignore is scoped to that single class; the php and security presets still cover it.
- `ProblemRenderer` mirrors the correlation header name in a private `CORRELATION_HEADER` constant instead of referencing `App\Http\Middleware\CorrelationId::HEADER`, because the Laravel arch preset forbids using middleware classes outside the Http layer.
- The health 503 `detail` text changed from "One or more dependencies are unavailable." to "One or more backing services failed their health check.", matching the OpenAPI example; `detail` is explicitly not stable contract per api-conventions, and no test pinned the old text.
- Unit-style tests for `ErrorCode` and the Data wire shapes sit under `tests/Feature/Problems/` for now; the stage plan's slice 3 sanctions this interim placement and moves them when the Unit suite is declared (task-03).

### task-02: Money value object, Eloquent cast, wire transformers (slice 2)

Completed Thu Jul 9 12:17:19 -03 2026. Commit `4577492`, `feat(support): money value object, eloquent cast, wire transformers`.

What landed:

- Tests first, verified red (28 tests, 0 passing, every case failing on the missing classes) before any implementation: `tests/Feature/Money/MoneyTest.php` (construction, invalid-code rejection incl. lowercase and wrong lengths, equality, comparison, cross-currency add/subtract/compare throwing `CurrencyMismatchException`, integer-only multiplication, negative amounts, float rejection under `declare(strict_types=1)`), `MoneyCastTest.php` (in-test probe tables with `price_amount` bigint and `currency` char(3): round trip, null amount reads null, null write clears the amount column, currency-mismatched write refused, same-currency overwrite allowed, non-Money write refused, bare `amount` column via cast parameter per the data-conventions exemption), and `MoneyDataTest.php` (a Data object with a Money property serializes to `{"amount": 12500, "currency": "BRL"}`, hydrates back, and round-trips).
- `App\Support\Money`: `Money` (immutable, private constructor behind `Money::of(int, string)`, `[A-Z]{3}` currency validation, add/subtract/multiplyBy/equals/greaterThan/lessThan/isNegative), `CurrencyMismatchException`, `MoneyCast` (Eloquent `CastsAttributes` on a virtual attribute, amount column defaulting to `{attribute}_amount` with an explicit-column parameter for the bare-`amount` tables), `MoneyDataCast` and `MoneyDataTransformer` (laravel-data), registered in the newly published `config/data.php`.
- TypeScript: `Money` carries `#[TypeScript]` plus `#[LiteralTypeScriptType(['amount' => 'number', 'currency' => 'string'])]`, so the generated type is exactly `{amount: number; currency: string}` (the reflection path emitted `readonly` modifiers from the PHP readonly properties, which the stage plan's pinned shape does not include). Regenerated output committed under `packages/api-client/src/generated`; `pnpm --filter api-client exec tsc --noEmit` exit 0.
- Shared `Money` component schema added to `docs/openapi/openapi.yaml` (integer minor units, `^[A-Z]{3}$` currency, `additionalProperties: false`); file confirmed parseable YAML.

Test evidence:

- `composer test`: 81 passed, 0 failed, 334 assertions (Feature 68 incl. the 28 new Money tests, Architecture 11, Isolation 1 stub, Concurrency 1 stub).
- `composer lint` (Pint): passed. `composer analyse` (Larastan): 0 errors. `composer types:generate`: clean, second run produced no further diff.

Deviations and decisions:

- `tests/Architecture/PresetTest.php` now also ignores `CurrencyMismatchException` on the Laravel preset, which requires Throwables under `App\Exceptions` while ADR 018 places the Money guard exception with its value object in `App\Support\Money`. Same precedent and scoping as the existing `ErrorCode` ignore; the php and security presets still cover it.
- The security preset forbids `assert()`, so `MoneyDataTransformer` guards its input with an explicit `InvalidArgumentException` instead.
- Publishing `config/data.php` pulled in the vendor stub, which Pint then reformatted (import ordering, indentation); the only functional edits are the two Money registrations under `casts` and `transformers`.
- Money unit tests sit under `tests/Feature/Money/` for now, same interim placement as task-01's, moving when task-03 declares the Unit suite.
