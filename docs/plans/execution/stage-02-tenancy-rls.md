# Execution Journal: Stage 2, Tenancy and RLS Regime

Durable record of execution runs for [stage-02-tenancy-rls.md](../stage-02-tenancy-rls.md). Append a new run header per run; never rewrite prior entries.

## Run: 2026-07-09

- Stage: 2, Tenancy and RLS Regime
- Date: 2026-07-09
- Branch: `feat/api-implementation`
- Base commit: `61d886b135c65fc48c74c7fa393aadc3f885774e`

Verified starting state: Stage 1 is done (commit 61d886b marks it done; problem handler, Money, Contract suite, isolation and concurrency harnesses, and the clock all exist). Nothing from Stage 2 exists: there is no `app/Tenancy` directory, `database/migrations` holds only the two Laravel defaults, `phpunit.xml` still defaults `DB_CONNECTION` to sqlite for the Feature suite (Isolation and Concurrency already run on PostgreSQL with driver guards), and the isolation suite runs against throwaway probe tables (`tests/Isolation/Support/ProbeTable.php`), not real tenancy tables. The master plan status table said "Not started"; this run flips it to "In progress".

### Task checklist

- [x] task-01: RLS roles migration and reusable RLS migration helper (plan task 1, slice 1 implementation)
- [x] task-02: Tenant context holder and SET LOCAL transaction wrapper in Support (plan task 2, slice 1)
- [x] task-03: Default test connection to PostgreSQL for all suites (plan task 3)
- [ ] task-04: `tenants` migration, sentinel platform tenant, model, factory, isolation tests (plan task 4, slice 2)
- [ ] task-05: `tenant_domains` migration, two-tenant isolation fixture, isolation tests (plan task 5, slice 3)
- [ ] task-06: `CreateTenant`, `UpdateBranding`, `ConfigureGateways` Actions, Data objects, `TenantCreated` event class (plan task 6)
- [ ] task-07: `RegisterDomain`, `MakeDomainPrimary`, `RemoveDomain` Actions, Data objects, `DomainVerified` event class (plan task 7)
- [ ] task-08: `TenancyServiceProvider` with the three route groups and the placeholder platform auth alias (plan task 8)
- [ ] task-09: Tenant create, read, update endpoints with feature, contract, and isolation tests, OpenAPI, regenerated TypeScript (plan task 9, slice 4)
- [ ] task-10: Domain endpoints with feature, contract, and concurrency tests, OpenAPI, regenerated TypeScript (plan task 10, slice 5)
- [ ] task-11: Tenant resolution middleware for both populations, resolution matrix, Octane no-leak test (plan task 11, slice 6; blocked on the domain-resolution open question)
- [ ] task-12: Domain verification endpoint plus contract (plan task 12, slice 7 first half)
- [ ] task-13: Platform-role audit log seam and its tests (plan task 13, slice 7 second half)
- [ ] task-14: Stage close: status table to done, `DomainVerified` trigger decision recorded (plan task 14)

### task-01: RLS roles migration and reusable RLS migration helper

- Timestamp: Thu Jul 9 14:39:11 -03 2026
- Commits: `ca493be7e509902c71f01cf9a7502c97075243de` (feat(support): add RLS roles migration and reusable tenant policy helper)

What landed:

- `apps/api/database/migrations/2026_07_09_000000_create_rls_roles.php`: idempotent creation of the `nodia_app` and `nodia_platform` NOLOGIN NOBYPASSRLS group roles (DO block guarded on `pg_roles`) plus a membership grant to the migration's login user guarded on `pg_has_role`, so a managed cluster with pre-provisioned roles no-ops instead of erroring. The CREATEROLE privilege requirement is documented in the migration's comment block per the plan's Risks section. `down()` revokes membership only; the roles are cluster-level and never dropped.
- `apps/api/app/Support/Database/Rls.php`: the reusable helper every later tenant-scoped migration invokes. `applyTenantPolicies($table, platformWrite: false)` issues per-table CRUD grants to both roles, `ENABLE` and `FORCE ROW LEVEL SECURITY`, the `{table}_tenant_isolation` policy using `NULLIF(current_setting('app.tenant_id', true), '')::uuid` in USING and WITH CHECK, the additive `{table}_platform_read` policy, and the opt-in `{table}_platform_write` policy defaulting off. `createRoles()` and `grantMembershipToCurrentUser()` carry the migration's SQL so the migration and the test harness share one source.
- `apps/api/tests/Isolation/RlsBootstrapTest.php` (written first; all 11 failed before implementation): role posture from `pg_roles`; deny-by-default under `SET LOCAL ROLE nodia_app` with no `app.tenant_id` set, and with the empty-string leftover a reset SET LOCAL leaves behind; cross-tenant SELECT invisibility, UPDATE and DELETE affecting zero rows, WITH CHECK rejection of foreign `tenant_id` inserts; `nodia_platform` reading both tenants' rows without any tenant context; platform writes denied by default and permitted only on a `platformWrite: true` probe table, with tenant isolation still intact there.
- Test support: `ProbeTable::createWithHelperPolicies()` drives the production `Rls` helper so the suite proves the helper's SQL, not a copy; a new `actingAsRole()` helper wraps `SET LOCAL ROLE` plus `set_config`; `RlsHonestConnection` now, while still privileged, ensures the group roles exist via `Rls::createRoles()` and grants the downgraded `nodia_isolation` role membership in both, mirroring what the migration grants the production login user.

Test evidence:

- New isolation tests first failed as expected (11 errors, `Class "App\Support\Database\Rls" not found`), then went green after implementation.
- Isolation suite: 19 passed, 38 assertions (the 8 pre-existing harness tests included).
- Full `composer test` (Feature, Unit, Contract, Architecture, Isolation, Concurrency): 115 passed, 419 assertions.
- Pint clean; Larastan clean (0 errors). `composer types:generate` ran with no output changes (no Data classes touched), so nothing regenerated to commit.
- Migration exercised directly against the `nodia_test` database as the compose superuser: up on the pre-existing-roles path, `migrate:rollback --step=1`, roles dropped, up again through the creation path; `pg_roles` confirmed both roles NOLOGIN, NOBYPASSRLS, non-superuser. The database was wiped back to empty afterwards.

Deviations:

- None in substance. One note: no test suite executes the migration's `up()` yet (no suite runs migrations against PostgreSQL until task-03 flips the default connection); its SQL is exercised by the isolation suite through the shared `Rls` methods, and the migration file itself was verified manually as described above. Tasks 03 and 04 put it on the automated path.
- The master plan status table was already flipped to "In progress" during run setup, so this task's plan-task-1 status edit was a no-op.

### task-02: Tenant context holder and SET LOCAL transaction wrapper

- Timestamp: Thu Jul 9 14:49:08 -03 2026
- Commits: `cf86d1b` (feat(support): add tenant context holder and SET LOCAL transaction wrapper)

What landed:

- `apps/api/app/Support/Tenancy/TenantTransaction.php`: the system-design 4.1 request transaction wrapper. `asTenant($uuid, $callback)` and `asPlatform($callback)` open a `DB::transaction`, execute `SET LOCAL ROLE` (`nodia_app` or `nodia_platform`) and set `app.tenant_id` via bindable `set_config(..., true)` (the sentinel platform tenant id for the platform posture), run the callback, and let both settings die with the transaction. Non-UUID tenant ids raise `InvalidTenantIdException` before any SQL executes. Nested tenant transactions raise `LogicException` before any SQL, because `SET LOCAL` survives savepoint release and an inner posture would bleed into the remainder of the outer transaction.
- `apps/api/app/Support/Tenancy/TenantContext.php`: request-scoped holder (`hasTenant()`, `tenantId()` throwing when inactive, `isPlatform()`), registered `scoped()` in `AppServiceProvider` so Octane resets it between requests; the wrapper clears it in a `finally` before the transaction ends, so neither commit nor rollback leaves container state behind.
- `apps/api/config/tenancy.php`: the fixed sentinel platform tenant id (`00000000-0000-7000-8000-000000000000`, UUIDv7-shaped, zero timestamp) published as `config('tenancy.platform_tenant_id')`, deliberately not env-configurable so runtime context can never desynchronize from the sentinel row the task-04 migration will insert.
- Test infrastructure: the pgsql-pointing closure in `tests/Pest.php` was extracted to `Tests\Support\PostgresTestDatabase::use()` so the new unit tests (which prove `SET LOCAL` mechanics against real PostgreSQL) share one source with the Isolation and Concurrency suites; README Testing section notes the pattern. `InvalidTenantIdException` joined the architecture preset's ignore list next to `CurrencyMismatchException` (guard exceptions live with the code they protect, not `App\Exceptions`).

Test evidence:

- `tests/Unit/Tenancy/TenantContextTest.php` (7 tests) and `tests/Unit/Tenancy/TenantTransactionTest.php` (10 tests including datasets) written first; all 17 failed (12 errors, 5 failures: classes and config key missing), then went green after implementation.
- Coverage matches the plan's Slice 1 unit bullets: transaction opened (level 1 inside, 0 after), both `SET LOCAL`s observed via `current_user` and `current_setting('app.tenant_id', true)`, tenant id exposed through the request-scoped container (including scoped reset via `forgetScopedInstances`), no connection or container state after commit or rollback, non-UUID rejected before any SQL (empty query log, transaction level 0, datasets covering empty string and an injection-shaped value).
- Full `composer test`: 132 passed, 468 assertions, all six suites. Pint clean; Larastan clean (0 errors). `composer types:generate` ran with no output changes (no Data classes in this task).

Deviations:

- One architecture test failed mid-task (Laravel preset expects Throwables in `App\Exceptions`); resolved by extending the existing, commented ignore list in `tests/Architecture/PresetTest.php`, the same treatment `CurrencyMismatchException` already had. Not a plan deviation, recorded for review visibility.
- The wrapper rejects nested tenant transactions outright. The plan does not specify nesting semantics; rejection is the conservative choice because `SET LOCAL` survives savepoint release, and it can be relaxed later if a real nesting need appears (none is expected: middleware wraps the whole request in task-11).

### task-03: Default test connection to PostgreSQL for all suites

- Timestamp: Thu Jul 9 14:55:37 -03 2026
- Commits: `da500e5b52f089d84107bbc73e75afc1af28e96d` (ci: move the default test connection to PostgreSQL for all suites)

What landed:

- `apps/api/phpunit.xml`: `DB_CONNECTION` flipped from `sqlite` (`:memory:`) to `pgsql`, with `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` defaulting to the compose stack's dedicated `nodia_test` database (127.0.0.1:5432, nodia/nodia), the same values the CI jobs export. phpunit `env` entries do not force, so CI's exported `DB_*` still take precedence, as does any developer-exported override; no driver-conditional migration escape hatch exists, per the plan's explicit rejection.
- `apps/api/README.md` Testing section rewritten for the new matrix: all six suites on real PostgreSQL, `make up` as the only local setup, overrides via exported `DB_*` rather than `NODIA_TEST_DB_*` in the normal path.
- `tests/Pest.php` and `tests/Support/PostgresTestDatabase.php` comment blocks updated: the helper is now documented as a guard (a no-op when the default is already pgsql; it redirects Isolation, Concurrency, and the PostgreSQL-bound unit tests to the `NODIA_TEST_DB_*` connection only if the environment forces the default onto another driver). The helper and its call sites were kept, not removed, so a forced `DB_CONNECTION=sqlite` in someone's shell can never make the isolation proofs vacuous.

Test evidence:

- Baseline before the change: full `composer test` green on the sqlite default, 132 passed, 468 assertions.
- After the change: full `composer test` green, same 132 passed, 468 assertions, all six suites. No Feature-suite failures from transaction semantics surfaced; the budgeted flakiness triage was not needed (no test uses RefreshDatabase yet, so no migration or transaction-wrapping behavior changed).
- Driver verified against ground truth, not inferred: a throwaway Feature test asserting `DB::connection()->getDriverName() === 'pgsql'` and `current_database() === 'nodia_test'` passed and was then deleted.
- Pint clean; Larastan clean (0 errors); `composer types:generate` produced no changes (no Data classes touched).

Deviations:

- None. Note for task-04: flipping the connection does not by itself put the roles migration on the automated path, because no suite runs migrations yet (nothing uses RefreshDatabase); task-04's migrations plus its isolation tests complete that, as task-01's journal note anticipated.

### Review rounds

(none yet)

### Decisions and deviations

(none yet)
