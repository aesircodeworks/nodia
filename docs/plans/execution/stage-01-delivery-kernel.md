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

### task-03: Suite matrix: Unit and Contract suites, Isolation and Concurrency on local PostgreSQL (slice 3)

Completed Thu Jul 9 12:27:18 -03 2026. Commit `7509642`, `test: declare unit and contract suites and run isolation and concurrency on postgres locally`.

What landed:

- Tests first, verified red before any wiring: `tests/Isolation/DatabaseDriverGuardTest.php` and `tests/Concurrency/DatabaseDriverGuardTest.php` failed on the SQLite default with the instructive message (observed: "the active driver is [sqlite] ... Start the local stack with `make up` ..."), and `php artisan test --testsuite=Unit` reported no such suite. The guards assert the active driver is `pgsql` via `assertSame` with the instructive message, then open a real connection and assert `current_database()` matches the configured test database, so a guard pass proves connectivity, not just config.
- `phpunit.xml` now declares six suites: Feature, Unit, Contract, Architecture, Isolation, Concurrency.
- Relocations into the new Unit suite: `tests/Feature/Money/{MoneyTest,MoneyCastTest,MoneyDataTest}.php` to `tests/Unit/Money/` and task-01's interim `tests/Feature/Problems/{ErrorCodeTest,ProblemDataTest}.php` to `tests/Unit/Problems/` (git renames, contents untouched). `ProblemResponsesTest` stays in Feature.
- `tests/Contract/SuiteHarnessTest.php` seeds the Contract suite in the Phase 0 harness-stub style: it asserts `docs/openapi/openapi.yaml` exists and declares OpenAPI 3.1. Task-05 replaces it with real conformance checks.
- `tests/Pest.php`: Unit and Contract bind to `Tests\TestCase`; Isolation and Concurrency get a `beforeEach` that, when the default connection is not already `pgsql` (locally it is SQLite from `phpunit.xml`), repoints the `pgsql` connection at the compose test database with env-driven overrides `NODIA_TEST_DB_{HOST,PORT,DATABASE,USERNAME,PASSWORD}` defaulting to `127.0.0.1:5432`, `nodia`/`nodia`, `nodia_test`, sets it as default, and purges the connection. In CI all Pest-running jobs export `DB_CONNECTION=pgsql` with `nodia_test`, which wins over the non-forced `phpunit.xml` env, so the `beforeEach` is a no-op there and no workflow change was needed.
- `infra/compose/postgres/create-test-database.sh` mounted into `/docker-entrypoint-initdb.d/` creates `nodia_test` on first cluster init so local tests never touch `nodia_api`; documented in a new Testing section in `apps/api/README.md`, including the catch-up path for stacks whose volume predates the script (`make fresh` or a one-off `createdb -U nodia nodia_test`).

Test evidence:

- Verified red: both driver guards failed on SQLite with the instructive message; the Unit suite was undeclared ("No tests found.").
- `composer test`: 84 passed, 0 failed, 341 assertions across all six suites (Feature 27, Unit 41, Contract 1, Architecture 11, Isolation 2, Concurrency 2).
- `composer lint` (Pint): passed. `composer analyse` (Larastan): 0 errors. `composer types:generate`: ran clean with no diff (no Data classes changed).
- Init script proven against a fresh cluster in a throwaway `postgres:17-alpine` container (not the running stack): `nodia_test` present after first init. `docker compose config --quiet` passes on the edited compose file.
- Env overrides proven live: running the Isolation suite with `NODIA_TEST_DB_DATABASE=definitely_missing_db` failed on connection to that database name; the normal run connects to `nodia_test`.

Deviations and decisions:

- The running local stack's postgres volume predates the init script, so `nodia_test` was created there with the documented one-off `createdb` rather than `make fresh` (avoids dropping the dev stack's volumes mid-run). Exit criterion 5's "no configuration beyond make up" holds for fresh stacks via the init script.
- The stage plan does not specify the guard's connectivity assertion; it was added beyond the driver-name check because `getDriverName()` reads config without connecting, which would let the guard pass green against an unreachable database.
- Overrides use dedicated `NODIA_TEST_DB_*` names instead of `DB_*` because locally `phpunit.xml` pins `DB_DATABASE=:memory:` and a developer `.env` points `DB_*` at the dev `nodia_api` database, exactly what these suites must never touch.

### task-04: ADR 019, OpenAPI conformance tooling selection spike (slice 4 spike)

Completed Thu Jul 9 12:36:18 -03 2026. Commit `f1fb9a1`, `docs: adr 019 openapi conformance tooling`.

What landed:

- `docs/decisions/019-spectator-for-openapi-conformance.md`, following the existing ADR format. Decision: hotmeteor/spectator provides the `assertConformsToOpenApi` macro (criterion a); the Data-class drift gate (criterion b) is built from strict component schemas (`additionalProperties: false` plus exhaustive `required`, enforced by a Contract suite strictness test) and Contract suite coverage checks (spec validity, bidirectional route-to-spec drift, every documented response conformance-asserted), not from spec generation.
- All maintenance and capability claims were verified against live sources on 2026-07-09 per the master plan's binding requirement, via Packagist, GitHub, and the projects' own docs: spectator v3.0.2 (2026-05-01, PHP ^8.3, Laravel >=12, OpenAPI 3.1 since the 2.0 rewrite on cebe/php-openapi plus opis/json-schema); league/openapi-psr7-validator 0.24 (2026-05-08, OpenAPI 3.0.x only via the devizzent/cebe-php-openapi fork); osteel/openapi-httpfoundation-testing v0.14 (2025-12-04, pre-1.0, delegates to the league validator). The repo spec being 3.1.0 disqualifies the latter two.
- The master plan's directed fallback for an unsatisfied criterion b (generate the document from the Data classes, gate on generated-vs-committed diff) was evaluated for feasibility and not adopted: dedoc/scramble's laravel-data support is a paid Scramble PRO feature (a spend decision this task cannot make), xolvionl/laravel-data-openapi-generator is unmaintained since mid-2024 and absent from Packagist, and basillangevin/laravel-data-json-schemas emits per-class JSON Schema 2019-09, not an OpenAPI document. The ADR records the residual risk (optional-field variants and union branches inside one operation are only as covered as their tests) and the revisit triggers.
- No production code, no test changes; task-05 implements the ADR.

Test evidence:

- `composer lint` (Pint): passed. `composer analyse` (Larastan): 0 errors. `composer test`: 84 passed, 0 failed, 341 assertions across all six suites. `composer types:generate`: ran clean, no diff. All run post-ADR as instructed even though the change is docs-only.

Deviations and decisions:

- The task directive said that if no tool satisfies criterion b the decision is to generate the OpenAPI document from the Data classes. The spike found that path infeasible with maintained free tooling (details above and in the ADR), so the ADR instead keeps the hand-maintained YAML and constructs the drift gate from spectator plus schema strictness and coverage rules, satisfying the master plan's underlying requirement that drift cannot escape through untested shapes at the operation level. This is a recorded, reviewable deviation; if a Scramble PRO license is approved, the ADR names that as a revisit trigger.

### task-05: OpenAPI conformance assertions and Contract suite gate (slice 4 remainder)

Completed Thu Jul 9 13:10:45 -03 2026. Commit `5a76435`, `test: openapi conformance assertions and contract suite gate`.

What landed:

- Uncommitted partial task-05 work found in the tree was inspected against the stage plan and ADR 019 and adopted: spectator ^3.0 as a dev dependency, the `assertConformsToOpenApi` and `assertMatchesProblemSchema` macros in `tests/TestCase.php`, `tests/Support/OpenApiSpec.php` (spec loading, meta-schema validation via opis/json-schema against the vendored official OpenAPI 3.1 meta-schema so the check runs offline, component-schema validation with an `unevaluatedProperties: false` wrapper, operation and response-schema enumeration, `$ref` resolution), `tests/Contract/{OpenApiDocumentValidityTest,ResponseSchemaStrictnessTest,RouteSpecDriftTest}.php` replacing the task-03 harness stub, both `HealthEndpointTest` responses chained with `assertConformsToOpenApi()`, and `ProblemResponsesTest` routing every matrix case through `assertMatchesProblemSchema` (`ValidationProblem` for the 422). The adopted work had a real bug: `RouteSpecDriftTest` passed its custom failure message as a second argument to Pest's `toContain`, which treats every argument as a needle, so both drift tests failed even with route and spec in sync (observed: 86 of 88 passing). Fixed with `Assert::assertContains` and its message parameter.
- New in this run: `tests/Contract/DocumentedResponseCoverageTest.php` implements ADR 019's remaining coverage rule (every documented response is exercised with conformance asserted) as an executable check: a dataset over every documented `method /path status` triple fails any response with no registered exerciser, each exerciser's response is status-asserted and conformance-asserted, and a companion test fails exercisers targeting undocumented responses. Exercisers exist for `get /v1/health 200` and `503`. `ApiErrorRenderingTest`'s no-Accept 404 case retrofitted with `assertMatchesProblemSchema`. `OpenApiSpec::path()` made container-free (`dirname` instead of `base_path`) so the dataset can enumerate the spec before the app boots.
- CI (`.github/workflows/api.yml`): the paths filter gains `docs/openapi/**` so spec-only edits trigger the API jobs (without it, a shape-breaking YAML edit would merge without the gate running, violating exit criterion 1), and the contract-drift job gains a redis service, the phpredis extension, and an explicit `php artisan test --testsuite=Contract` step ahead of the TypeScript drift check (redis is needed because the coverage test exercises the healthy 200). The Pest job also runs the Contract suite as part of `composer test` with no change.

Every gate was proven to bite by introducing then reverting a deliberate mismatch (none merged): a fake required field on `HealthReport` in the YAML failed the health 200 feature test and the Contract coverage exerciser ("The required properties (uptime) are missing"); an extra property on `HealthReportData` failed conformance via `additionalProperties: false`; a fake required member on the `Problem` component failed all 8 problem-matrix cases; removing `additionalProperties: false` from `HealthReport` failed the strictness test; a temporary app route failed route-to-spec drift and a phantom spec path failed spec-to-route drift; removing the 503 exerciser failed the coverage dataset case with the instructive message.

Test evidence:

- `composer test`: 91 passed, 0 failed, 367 assertions across all six suites (Feature 27, Unit 41, Contract 8, Architecture 11, Isolation 2, Concurrency 2).
- `composer lint` (Pint): passed after fixing import ordering in the two touched files. `composer analyse` (Larastan): 0 errors. `composer types:generate`: ran clean, no diff (no Data classes changed).
- Workflow YAML confirmed parseable after the CI edits.

Deviations and decisions:

- ADR 019 phrases the coverage rule as "exercised by a conformance-asserted feature test"; it is implemented as the Contract suite exercising every documented response itself, because a static scan of feature tests for conformance assertions would be unverifiable and brittle. The Contract check is strictly stronger: it executes the request and asserts conformance rather than trusting that a feature test somewhere does.
- The CI step went into the contract-drift job (the task allowed either that job or the Pest job) so the spec gates are a named, independent check alongside the generated-types drift gate; the cost is a redis service on that job.

### task-06: Tenant isolation harness with two-tenant RLS fixture (slice 5)

Completed Thu Jul 9 13:20:55 -03 2026. Commit `a3ab368`, `test: tenant isolation harness with two-tenant rls fixture`.

What landed:

- Tests first, verified red (7 harness tests erroring on the missing `Tests\Isolation\Support` classes; only the driver guard passed): `tests/Isolation/TenantIsolationHarnessTest.php` replaces the `SuiteHarnessTest.php` stub. Against `harness_probes` (created in `beforeEach`, dropped in `afterEach`, never via migrations, drop-if-exists first so a crashed run cannot poison the next): tenant A sees its row; B's select returns nothing; B's update and delete affect zero rows; B's insert bearing A's `tenant_id` throws the WITH CHECK violation (`QueryException`, "row-level security") and leaves A's view empty; a query outside any tenant transaction sees nothing or errors (the test accepts both the fresh-session "unrecognized configuration parameter" and the post-SET-LOCAL empty-string uuid cast error, since a committed `SET LOCAL` leaves the custom GUC defined-but-empty for the session). The meta-probe creates `harness_probes_unguarded` without any policy and asserts every leak positively: B selects A's row, updates 1 row, inserts a forged `tenant_id` successfully, an outside-transaction select sees everything, and B deletes A's row.
- `tests/Isolation/Support`: `TenantFixture` (two fixed UUIDv7 tenant IDs, `019797f0-0000-7000-8000-00000000000a`/`...0b`), `helpers.php` with `actingAsTenant(string $tenantId, Closure $fn)` running `$fn` inside `DB::transaction` after `set_config('app.tenant_id', $tenantId, true)` (the bindable equivalent of `SET LOCAL`, which cannot take query parameters; registered under composer `autoload-dev.files` so the function loads without class side effects), and `ProbeTable` whose `createWithPolicy` is the reference DDL pattern from the stage plan's Data model section: bare table (id uuid pk, tenant_id uuid not null, label text, timestamptz timestamps), `ENABLE ROW LEVEL SECURITY`, `FORCE ROW LEVEL SECURITY`, and the single policy `USING`/`WITH CHECK` on `current_setting('app.tenant_id')::uuid`.
- `RlsHonestConnection` plus Pest wiring: the compose stack's `nodia` user and the CI service user are the cluster bootstrap superuser (`rolsuper`, `rolbypassrls` both true, verified against the running stack), and superusers bypass RLS even with FORCE, so a harness on that connection proves nothing, the exact risk the stage plan's "RLS owner bypass" entry flags. The Isolation suite's `beforeEach` in `tests/Pest.php` now checks the connected user and, when it would bypass RLS, idempotently creates an unprivileged `nodia_isolation` login role (nosuperuser, nobypassrls) through the privileged connection, grants it usage and create on schema public, and reconnects as it; an already-unprivileged configured user is used as-is, so the harness works under either posture Stage 2 might choose. Probe tables are therefore owned by the unprivileged role, which is exactly why FORCE is load-bearing. Documented in the API README Testing section; the Concurrency suite wiring is unchanged.

Test evidence:

- Verified red first: 7 of 8 Isolation tests erroring on missing Support classes. First green run observed the superuser bypass (5 tests failing with rows leaking through the policy), which drove `RlsHonestConnection`; green after it.
- Harness proven to bite by temporarily removing `FORCE ROW LEVEL SECURITY` from the probe DDL: 5 of 8 tests failed (the owner connection bypassed the policy), then reverted to green. The committed meta-probe additionally asserts the policy-less leak on every run.
- CI-style path proven live: after dropping `nodia_isolation`, the suite run with `DB_CONNECTION=pgsql DB_*` env (no `NODIA_TEST_DB_*` repoint, as CI runs) recreated the role and passed 8 of 8.
- `composer test`: 97 passed, 0 failed, 383 assertions across all six suites (Isolation now 8). `composer lint` (Pint): passed. `composer analyse` (Larastan): 0 errors. `composer types:generate`: ran clean, no diff (no Data classes changed). SQLite refusal unchanged via the task-03 driver guard, which now also runs under the honest role.

Deviations and decisions:

- The stage plan's fixture assumed `FORCE` alone makes a same-role connection honest; that holds for plain owners but not for the compose and CI superuser. `RlsHonestConnection` (role downgrade when the configured user bypasses RLS) was added beyond the plan text to satisfy its own meta-probe requirement; without it the guarded-table tests fail with leaks on any stock local or CI run. No compose, init-script, or workflow changes were needed, preserving exit criterion 5's "no configuration beyond make up".
- `actingAsTenant` uses `select set_config(?, ?, true)` instead of literal `SET LOCAL` because `SET` statements cannot carry bind parameters; `set_config(..., true)` is documented as transaction-local, identical semantics.
- `composer.json` gained `autoload-dev.files` for the helper function; composer.lock is unaffected (autoload is outside the content hash).

### Gate

Run at Thu Jul 9 13:24:28 -03 2026, all green on the first pass with no fixes required:

- `composer lint` (Pint): passed.
- `composer analyse` (Larastan): passed, 0 errors.
- `composer test` (Pest): 97 passed, 0 failed, 383 assertions across all six suites.
- `composer types:generate`: ran clean; `git status --short packages/api-client/src/generated` empty, no contract drift.
- `pnpm typecheck` (run because `packages/api-client/src/generated/index.ts` changed on this branch): all five TS workspaces passed.

### Review round 1

Codex review findings applied Thu Jul  9 13:39:05 -03 2026. The review ran after task-06; its three blocking findings correspond exactly to the then-outstanding tasks 8 (slice 6, concurrency harness) and 9 (slice 7, time control). All three were accepted and fixed; none declined.

Finding 1 (blocking, `tests/Concurrency/SuiteHarnessTest.php`): the concurrency harness was still the placeholder `expect(true)->toBeTrue()`. Fixed in commit `026fe51`, `test: concurrency harness with parallel process runner`:

- Tests first, verified red (2 tests erroring on the missing `Tests\Concurrency\Support\ParallelRunner`): `tests/Concurrency/CounterContentionTest.php` replaces the stub. The `concurrency_counters` probe table (id uuid pk, quantity int, taken int default 0, per the stage plan's Data model section) is created in `beforeEach` and dropped in `afterEach`, never via migrations. Probe 1 (lost-update detection): 8 workers each run 50 read-then-write increments (SELECT taken, jittered usleep, UPDATE to the read value plus one); asserts the final `taken` is strictly less than the 400 attempted increments, proving the runner produces genuine contention. Probe 2 (conditional UPDATE): the same workload as `UPDATE ... SET taken = taken + 1 WHERE id = ? AND taken < quantity` with successes counted by affected-row count against quantity 25; asserts attempts exceed quantity, the workers' granted counts sum to exactly 25, and the final `taken` is exactly 25.
- `tests/Concurrency/Support/ParallelRunner.php`: spatie/fork (`^1.2`, new dev dependency per the stage plan's preference; requirements verified against Packagist and the current README, pcntl and sockets confirmed on the local PHP 8.4.22) forks N worker processes. The parent purges its Laravel connection before forking (a child inheriting the parent's PDO socket corrupts the wire protocol), each worker opens its own PDO connection from the pgsql connection config and blocks on a wall-clock start barrier so all workers hit the row simultaneously; worker return values come back serialized through spatie/fork's sockets. Assertions never run inside children (a child failure would not propagate as a test failure), only on returned values in the parent.
- Both probes proven to bite before trusting them (deliberate breaks reverted, not committed): replacing the conditional UPDATE with read-then-write oversold 211 against quantity 25 and failed; dropping to 1 worker made the lost-update probe fail with "50 is not less than 50" (no contention, no losses). With the committed shape, 8 consecutive full-suite runs all green, satisfying the plan's determinism tuning requirement and exit criterion 7.
- CI (`.github/workflows/api.yml`): every setup-php step gains `pcntl, sockets` (the Pint and Architecture jobs had no extensions line and gain one), because spatie/fork declares `ext-pcntl` and `ext-sockets` as hard platform requirements, so every job's `composer install` needs them present, not just the jobs that run the Concurrency suite.

Finding 2 (blocking, `HealthController.php:28`, direct `CarbonImmutable::now()`) and Finding 3 (blocking, no architecture time rule): both are slice 7 and were fixed together in commit `87b999a`, `feat(support): immutable clock, time-control helpers, architecture time rule`:

- Tests first, verified red for the right reasons: `tests/Architecture/TimeSourceTest.php` failed by flagging exactly `Http/Controllers/HealthController.php:28 ... CarbonImmutable::now()` (the rule catches the finding-2 violation, closing finding 3's gap), and the immutability assertion in `tests/Unit/Time/FrameworkClockTest.php` failed with `Illuminate\Support\Carbon is not an instance of Carbon\CarbonImmutable`.
- The architecture rule is two parts: a Pest arch expectation keeping `App` off `time`, `date`, `mktime`, and `microtime` (ignoring `CorrelationId`, whose `microtime` is duration measurement, not domain time, exactly as the stage plan carves out), and a source scan over `app/` forbidding direct construction of `Carbon`, `CarbonImmutable`, `DateTime`, and `DateTimeImmutable` and their static now-family calls (`now`, `today`, `yesterday`, `tomorrow`), with an instructive failure message pointing at the framework clock. The scan is needed because Pest arch `toUse` cannot distinguish a static `::now()` call from a legitimate type hint, and `CarbonImmutable` type hints stay allowed.
- Implementation: `Date::use(CarbonImmutable::class)` in `AppServiceProvider::boot` (framework clock now immutable everywhere; `now()` and `Date::now()` return `CarbonImmutable`), `HealthController` switched to `Date::now()` (its `HealthReportData::make` parameter is already `DateTimeInterface`, so no Data class or contract change and no TypeScript regeneration needed).
- `FrameworkClockTest` proves the plan's TTL pattern: a test-local `TtlProbe` value object (`expires_at = Date::now() + ttl`, `isExpired()`) holds under `$this->freezeTime()`, expires under `$this->travel(11)->minutes()`, and treats the exact expiry instant as expired, with no sleeping. The probe is test-local rather than app code because no production consumer exists yet (Stage 3 tokens and Stage 6 holds are the first); merging a speculative `App\Support` TTL class would violate the plan's no-speculative-code posture.
- API README Testing section gains the plan-required note: domain time flows through the framework clock, and TTL tests must never sleep.

Gate re-run after both fixes: `composer lint` (Pint) passed; `composer analyse` (Larastan) passed, 0 errors; `composer test` 104 passed, 0 failed, 400 assertions across all six suites (Concurrency now 3, Architecture 13, Unit 45). No Data classes changed, so no generated TypeScript drift.
