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
- [x] task-04: `tenants` migration, sentinel platform tenant, model, factory, isolation tests (plan task 4, slice 2)
- [x] task-05: `tenant_domains` migration, two-tenant isolation fixture, isolation tests (plan task 5, slice 3)
- [x] task-06: `CreateTenant`, `UpdateBranding`, `ConfigureGateways` Actions, Data objects, `TenantCreated` event class (plan task 6)
- [x] task-07: `RegisterDomain`, `MakeDomainPrimary`, `RemoveDomain` Actions, Data objects, `DomainVerified` event class (plan task 7)
- [x] task-08: `TenancyServiceProvider` with the three route groups and the placeholder platform auth alias (plan task 8)
- [x] task-09: Tenant create, read, update endpoints with feature, contract, and isolation tests, OpenAPI, regenerated TypeScript (plan task 9, slice 4)
- [x] task-10: Domain endpoints with feature, contract, and concurrency tests, OpenAPI, regenerated TypeScript (plan task 10, slice 5)
- [x] task-11: Tenant resolution middleware for both populations, resolution matrix, Octane no-leak test (plan task 11, slice 6; domain-resolution open question settled, see the task entry and Decisions)
- [x] task-12: Domain verification endpoint plus contract (plan task 12, slice 7 first half)
- [x] task-13: Platform-role audit log seam and its tests (plan task 13, slice 7 second half)
- [x] task-14: Stage close: status table to done, `DomainVerified` trigger decision recorded (plan task 14)

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

### task-04: tenants migration, sentinel platform tenant, model, factory, isolation tests

- Timestamp: Thu Jul 9 15:06:17 -03 2026
- Commits: `6de572b` (feat(tenancy): add tenants table with sentinel platform tenant and custom RLS policies)

What landed:

- `apps/api/database/migrations/2026_07_09_000001_create_tenants_table.php`: the aggregate root per the plan's Data model table (uuid PK, `name`, jsonb `branding_settings` defaulting `{}`, `default_locale`, jsonb `supported_locales`, jsonb `enabled_gateways` defaulting `[]`, nullable jsonb `payout_schedule`, timestamptz `created_at` and `updated_at`, both not null via explicit `timestampTz` columns because Laravel's `timestampsTz()` produces nullable columns). The root has no `tenant_id`, so the migration does not use the `Rls` helper: it issues its own grants and creates the custom `tenants_tenant_isolation` policy declared `FOR SELECT` comparing `id` to `NULLIF(current_setting('app.tenant_id', true), '')::uuid`, plus `tenants_platform_read` and `tenants_platform_write`. The sentinel platform tenant (`config('tenancy.platform_tenant_id')`, name "Nodia Platform", locale `en`) is inserted inside a transaction under `SET LOCAL ROLE nodia_platform` then `RESET ROLE`, because under `FORCE` RLS the owner's bare INSERT matches no policy. `down()` drops the table.
- `apps/api/app/Tenancy/Models/Tenant.php`: first context-owned model, `HasUuids` (UUIDv7 in Laravel 13) plus array casts for the four jsonb columns, attribute-based `#[Fillable]`. `apps/api/database/factories/Tenancy/Models/TenantFactory.php` follows the default factory-name resolution path for context models; it declares `protected $model` explicitly because Laravel's reverse guess (factory to model) only knows `App\Models`.
- `apps/api/tests/Support/MigratedDatabase.php` plus `tests/Pest.php`: the Isolation suite now runs `migrate:fresh` once per process, before `RlsHonestConnection` downgrades the connection, so the real migration files (policies, grants, sentinel insert) execute as the provisioning user and are what the suite proves. This closes the gap noted in the task-01 and task-03 journal entries: the roles and tenants migrations are now on the automated path.
- `apps/api/tests/Isolation/TenantsIsolationTest.php` (written first; all 6 errored before implementation): tenant A's context sees exactly A's row with the sentinel and tenant B invisible; `nodia_app` INSERT rejected by RLS even when the new row's `id` matches the current tenant setting (proving `FOR SELECT` grants no INSERT even where USING would match); UPDATE and DELETE affect zero rows against A's own row; `nodia_platform` reads all rows including the migrated sentinel and can INSERT (seeding runs through the factory under the platform role, exercising model, factory, and the platform write policy together).
- `apps/api/tests/Unit/Tenancy/TenantTest.php` (written first, errored): UUIDv7 primary keys via `newUniqueId`, array casts on the jsonb columns, factory default state keeps `default_locale` inside `supported_locales`.
- Architecture expectations updated for the modular monolith: `PresetTest` ignores `App\Tenancy\Models` (the Laravel preset expects models in `App\Models`; contexts own their models per system-design 8), and `ContextBoundariesTest` ignores `Database\Factories\{Context}` per context, because factories exist to construct their own context's models and live outside `App` by framework convention. The boundary between contexts is unchanged.

Test evidence:

- New tests first failed as expected: 6 isolation errors and 3 unit errors (`Class "App\Tenancy\Models\Tenant" not found`), then went green after implementation. Two intermediate failures during the loop were mine, not the implementation's: the factory-to-model default guess required the explicit `$model` property, and the isolation test initially used Eloquent's `whereKey` helpers on the base query builder.
- Isolation suite: 25 passed, 52 assertions. Unit: 65 passed. Architecture: 13 passed.
- Full `composer test`: 141 passed, 489 assertions, all six suites. Pint clean; Larastan clean (0 errors); `composer types:generate` produced no changes (no Data classes in this task; they arrive with task-06).
- Migration exercised directly against `nodia_test` beyond the suite run: `migrate:rollback --step=1` then `migrate` both clean; sentinel row present with the fixed UUID; `pg_policy` confirms `tenants_tenant_isolation` has `polcmd = 'r'` (FOR SELECT).

Deviations:

- None in scope. Slice 2 in the plan also names `CreateTenant` and the `TenantCreated` event class; the task breakdown assigns those to task 6, and this run's task list follows the task breakdown, so they are deliberately not here.
- `created_at`/`updated_at` use two explicit `timestampTz` columns instead of `timestampsTz()` to honor the plan's not-null requirement; behavior is otherwise identical.

### task-05: tenant_domains migration, two-tenant isolation fixture, isolation tests

- Timestamp: Thu Jul 9 15:12:53 -03 2026
- Commits: `da5fc4c` (feat(tenancy): add tenant_domains table and the reusable two-tenant isolation fixture)

What landed:

- `apps/api/database/migrations/2026_07_09_000002_create_tenant_domains_table.php`: the first tenant-scoped child table per the plan's Data model (uuid PK, `tenant_id` not-null FK to `tenants(id)`, `domain` unique platform-wide, `is_primary` defaulting false, not-null timestamptz timestamps), the `tenant_id` index, the partial unique index `tenant_domains_primary_per_tenant_idx` on `(tenant_id) WHERE is_primary` created by raw statement with the custom `_idx` name because the builder cannot express partial indexes (data-conventions), and RLS via the task-01 `Rls::applyTenantPolicies` helper with `platformWrite: true` (domain CRUD is platform-admin surface this stage), all in the same migration that creates the table.
- `apps/api/app/Tenancy/Models/TenantDomain.php` (`HasUuids`, boolean cast on `is_primary`, attribute-based `#[Fillable]`) and `apps/api/database/factories/Tenancy/Models/TenantDomainFactory.php` (explicit `$model`, `tenant_id` chained to `Tenant::factory()`, domains generated lowercase, `is_primary` false by default).
- `apps/api/tests/Isolation/Support/TenantFixture.php`: the constants-only class became the reusable two-tenant fixture, `seed()` creating tenant A and B with one primary domain each under `nodia_platform` (the production provisioning posture) and `clean()` removing domains then non-sentinel tenants. This is the shared harness later stages' isolation tests build on, superseding the Stage 1 probe tables, which remain only where the suite proves the RLS helper itself (`RlsBootstrapTest`, `TenantIsolationHarnessTest`). `TenantsIsolationTest` was refactored onto `seed()`/`clean()` so there is exactly one harness.
- `apps/api/tests/Isolation/TenantDomainsIsolationTest.php` (written first; all 6 errored before implementation): the full deny matrix on `tenant_domains`: cross-tenant SELECT empty, cross-tenant UPDATE and DELETE affecting zero rows, INSERT bearing a foreign `tenant_id` rejected by `WITH CHECK`, a raw `DB::select` bypassing all Eloquent scoping still seeing only the current tenant's rows (ADR 003: the database is the guarantee), and `nodia_platform` reading both tenants' domains without tenant context.
- `apps/api/tests/Unit/Tenancy/TenantDomainTest.php` (written first, errored): UUIDv7 primary keys, boolean cast on `is_primary`, factory domains already lowercase.

Test evidence:

- New tests first failed as expected (12 isolation errors and 3 unit errors, `Class "App\Tenancy\Models\TenantDomain" not found`; the 6 refactored `TenantsIsolationTest` tests errored too because the shared fixture now seeds domains), then all went green after implementation: Isolation 31 passed (67 assertions), the 3 new unit tests passed.
- Full `composer test`: 150 passed, 510 assertions, all six suites. Pint clean; Larastan clean (0 errors); `composer types:generate` produced no changes (no Data classes in this task; they arrive with task-06).
- Migration exercised directly against `nodia_test` beyond the suite run: `migrate:fresh`, `migrate:rollback --step=1`, `migrate` all clean; `\d tenant_domains` confirms the unique domain constraint, the `tenant_id` index, the partial unique index `(tenant_id) WHERE is_primary` under its custom name, the FK, forced row security, and all three policies (`tenant_domains_tenant_isolation` FOR ALL with USING and WITH CHECK, `tenant_domains_platform_read` FOR SELECT, `tenant_domains_platform_write` FOR ALL to `nodia_platform`).

Deviations:

- None in scope. Slice 3 in the plan also names `RegisterDomain` and the `DomainVerified` event class; the task breakdown assigns those to task 7, and this run follows the task breakdown.
- The double-promotion race test for the partial unique index is deliberately not here: the plan's concurrency bullets for make-primary belong to Slice 5 (task-10), where the transition itself lands. This task ships the database guard; task-10 proves it under contention.

### task-06: tenant Actions, Data objects, TenantCreated event class

- Timestamp: Thu Jul 9 15:26:21 -03 2026
- Commits: `888452e` (feat(tenancy): add tenant Actions, Data objects, and TenantCreated event class)

What landed:

- `apps/api/app/Tenancy/Data/`: `TenantData` (full wire shape with snake_case keys via the shared SnakeCaseMapper pattern and ISO 8601 UTC timestamp strings, built through a `fromModel` named constructor), `CreateTenantData` (name, default_locale, supported_locales required; branding_settings, enabled_gateways, payout_schedule Optional), `UpdateTenantData` (every field Optional for PATCH semantics), and `BrandingSettingsData` with the two typed optional placeholder fields `primary_color` and `logo_url` (logo becomes a medialibrary upload in Stage 5c). All four plus the event payload are exported to `packages/api-client/src/generated/index.ts` by `composer types:generate`; the regenerated output is in the same commit.
- `apps/api/app/Tenancy/Actions/`: `CreateTenant` (locale membership invariant, then `Tenant::create` with defaults `{}`, `[]`, and null for the optional fields, returning `TenantData`; per the plan's Slice 2 bullet it normalizes nothing else), `UpdateBranding` (name, branding_settings, default_locale, supported_locales; re-derives the effective default and supported locales from the partial update against the current row and rejects any combination whose default falls outside the supported set), `ConfigureGateways` (accepts only a list of non-empty strings, no adapter capability validation per the Stage 8a deferral; the deferral is recorded in a comment on the Action).
- `apps/api/app/Tenancy/Exceptions/`: `DefaultLocaleNotSupportedException` and `InvalidGatewayConfigurationException`, the guard exceptions task-09 will map to the `default_locale_not_supported` and `request.validation_failed` problem codes. Both joined the architecture preset's commented ignore list (the Laravel preset expects Throwables in `App\Exceptions`; ours live with their context).
- `apps/api/app/Tenancy/Events/TenantCreated.php`: envelope per the plan's Domain events section: `tenantId` is the sentinel platform tenant, `aggregateType` `tenant`, `aggregateId` the created tenant's id, payload `TenantCreatedPayload` (laravel-data, snake_case: tenant_id, name, default_locale). No producer records it; the outbox arrives in Stage 4.
- `apps/api/app/Tenancy/Models/Tenant.php` gained `@property` docblock types for the jsonb and timestamp columns; Larastan inferred the jsonb columns as string from the migration and flagged `TenantData::fromModel` without them.

Test evidence:

- `tests/Unit/Tenancy/CreateTenantTest.php` (4 tests), `UpdateBrandingTest.php` (4), `ConfigureGatewaysTest.php` (7 including the rejection dataset), `TenantCreatedTest.php` (2) written first; all 17 failed as expected (12 errors, 5 failures: classes not found), then went green after implementation. The Action tests run against real PostgreSQL through `TenantTransaction::asPlatform`, the production provisioning posture, with `MigratedDatabase::ensure()` providing the migrated tables.
- One intermediate failure during the loop was a test bug, not implementation: PostgreSQL jsonb does not preserve key order, so the stored branding assertion became `toEqualCanonicalizing` with a comment.
- Full `composer test`: 167 passed, 569 assertions, all six suites. Pint clean; Larastan clean (0 errors) after the model docblock and an UpdateBranding restructure onto local-variable narrowing (Larastan flagged a repeated property instanceof as always-false). `composer types:generate` added BrandingSettingsData, CreateTenantData, TenantCreatedPayload, TenantData, UpdateTenantData to the generated index.ts; committed together with the code. `pnpm --filter api-client typecheck` clean.

Deviations:

- None in scope. Notes for later tasks: `CreateTenant` does not itself validate `enabled_gateways` contents ("normalizes nothing else" per Slice 2); task-09's request validation covers create-time garbage, and `ConfigureGateways` owns the invariant for updates. `UpdateBranding` ignores `payout_schedule`; the plan assigns no Action to it, so task-09 must decide its PATCH routing (it stays opaque until Stage 8c either way). `TenantCreatedPayload` is exported to the generated TypeScript because the transformer exports every Data class under `app/`; harmless now, worth a directory-scoping decision if event payloads multiply.
- No feature test, OpenAPI path, or endpoint ships here by design: the task breakdown assigns Slice 4's outer loop to task-09; this task is the unit portions of Slices 2 and 4.

### task-07: domain Actions, Data objects, DomainVerified event class

- Timestamp: Thu Jul 9 15:35:29 -03 2026
- Commits: `505faa3` (feat(tenancy): add domain Actions, Data objects, and DomainVerified event class)

What landed:

- `apps/api/app/Tenancy/Actions/RegisterDomain.php`: lowercases the domain (`mb_strtolower`), validates it as a hostname via `filter_var(FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)` plus an explicit trailing-dot rejection (filter_var accepts the FQDN root notation, but `example.com.` and `example.com` would be two rows resolving the same Host header), then creates the row with `is_primary` defaulting false. Malformed hostnames raise the new `InvalidDomainNameException`. Duplicate domains stay with the unique index; the 409 `domain_already_registered` mapping is task-10's.
- `apps/api/app/Tenancy/Actions/MakeDomainPrimary.php`: demote-then-promote inside one `DB::transaction` (a savepoint inside the request transaction, so the pair is atomic there too). The promote step is the plan's conditional UPDATE (`whereKey($id)->where('is_primary', false)->update(['is_primary' => true])`) checked implicitly by writing nothing when zero rows match, so a replayed request is a no-op that does not even bump `updated_at`. The closing `refresh()` runs inside the transaction, so a target row that vanished mid-flight throws `ModelNotFoundException` and rolls the demotion back with the savepoint.
- `apps/api/app/Tenancy/Actions/RemoveDomain.php`: conditional DELETE (`whereKey($id)->where('is_primary', false)->delete()`) checked by affected-row count; zero rows raises the new `TenantDomainIsPrimaryException`, the guard task-10 maps to 409 `tenant_domain_is_primary`. The conditional form means a domain promoted to primary between read and delete is refused, not removed.
- `apps/api/app/Tenancy/Data/`: `TenantDomainData` (id, tenant_id, domain, is_primary, ISO 8601 UTC timestamps, `fromModel`), `RegisterTenantDomainData` (domain required, is_primary Optional), `UpdateTenantDomainData` (is_primary only, Optional for PATCH semantics). All exported to `packages/api-client/src/generated` by `composer types:generate` in the same commit.
- `apps/api/app/Tenancy/Events/DomainVerified.php` and `DomainVerifiedPayload.php`: envelope tenant_id the owning tenant, aggregate_type `tenant_domain`, aggregate_id the domain id; payload tenant_domain_id, tenant_id, domain, snake_case. No producer, per the Stage 4 deferral; the class docblock records that the trigger semantics remain the stage's open question.
- `TenantDomain` model gained the `@property` docblock (same Larastan treatment `Tenant` got in task-06); both new exceptions joined the architecture preset's commented ignore list.

Test evidence:

- `tests/Unit/Tenancy/RegisterDomainTest.php` (16 tests including the 13-case malformed-hostname dataset), `MakeDomainPrimaryTest.php` (4), `RemoveDomainTest.php` (2), `DomainVerifiedTest.php` (2) written first; all 24 failed as expected (22 errors, 2 failures: classes not found), then went green after implementation (24 passed, 59 assertions). The Action tests run against real PostgreSQL through `TenantTransaction::asPlatform` with `MigratedDatabase::ensure()`, matching the task-06 pattern.
- The malformed-hostname dataset was calibrated against ground truth first (a `php -r` probe of `filter_var` behavior): PHP accepts a trailing root dot, which is why the Action rejects it explicitly; single-label hostnames and IP-shaped strings pass filter_var and are deliberately not rejected (the plan asks for malformed-hostname rejection only).
- Full `composer test`: 191 passed, 628 assertions, all six suites. Pint clean; Larastan clean (0 errors). `composer types:generate` added DomainVerifiedPayload, RegisterTenantDomainData, TenantDomainData, UpdateTenantDomainData to the generated index.ts; committed with the code. `pnpm --filter api-client typecheck` clean.

Deviations:

- None in scope. Notes: the replay no-op is asserted through `updated_at` frozen to a past value surviving the replayed call, direct evidence the conditional UPDATE matched zero rows. The plan's make-primary concurrency bullets (double-promotion race) remain task-10's, where the endpoint lands. `RegisterDomain` honors `is_primary: true` at registration; if the tenant already has a primary, the partial unique index rejects it, and whether task-10 surfaces that as a distinct problem code is that task's decision (the plan's POST error table lists only `domain_already_registered`).

### task-08: TenancyServiceProvider with three route groups and placeholder platform auth alias

- Timestamp: Thu Jul 9 15:47:22 -03 2026
- Commits: `a5926d3` (feat(tenancy): wire the three route groups and the placeholder platform auth alias)

What landed:

- `apps/api/app/Tenancy/TenancyServiceProvider.php`, registered in `bootstrap/providers.php`: the first context service provider (system-design 3.2 puts it at the context root, so it joined the architecture preset's ignore list next to the context exceptions). It registers the three populations of system-design 4.1 as router middleware groups: `tenancy.platform` (`auth.platform` alias, then `PlatformRequestTransaction`), `tenancy.admin` (`ResolveTenantFromHeader` slot), `tenancy.storefront` (`ResolveTenantFromHost` slot), plus the platform route group under `/v1` loading `app/Tenancy/Http/routes/platform.php` (empty; tasks 9 and 10 populate it). Admin and storefront ship route-less per the plan; later stages attach routes through the group names, never by importing Tenancy's middleware.
- `apps/api/app/Tenancy/Http/Middleware/PlatformAuthPlaceholder.php` behind the `auth.platform` alias (the name Stage 3 rebinds to Passport plus capability policies): pass-through in the `testing` and `local` environments, throws `AuthenticationException` everywhere else, so a premature deploy renders the 401 `auth.unauthenticated` problem document instead of exposing tenant CRUD (the plan's Risks mitigation).
- `apps/api/app/Tenancy/Http/Middleware/PlatformRequestTransaction.php`: the platform posture, wrapping the rest of the request in `TenantTransaction::asPlatform` (task-02's wrapper), so handlers run under `SET LOCAL ROLE nodia_platform` with `app.tenant_id` set to the sentinel. One discovered subtlety: Laravel 13's routing pipeline (`Illuminate\Routing\Pipeline::handleException`, verified in vendor) renders exceptions thrown below a route middleware into responses before the middleware sees them, so a naive wrapper would commit partial writes behind a 500. The middleware detects the exception carried by the rendered response and throws the internal `RenderedErrorRollback` to abort the transaction, then returns the already-rendered response, so error responses and rollbacks stay paired without double reporting.
- `apps/api/app/Tenancy/Http/Middleware/ResolveTenantFromHeader.php` and `ResolveTenantFromHost.php`: the resolution middleware slots for the admin and storefront groups. Bodies land in task-11; until then both throw `LogicException` on any invocation, so a route attached to either group before resolution exists fails loudly instead of executing without tenant context.
- `apps/api/tests/Architecture/ContextBoundariesTest.php` gained the Http-layer confinement rule for all eight contexts (a context's `Http` namespace is only used inside that context), mirroring the Models rule for the new wiring; `PresetTest` ignores extended for `TenancyServiceProvider` and `RenderedErrorRollback`.

Test evidence:

- `tests/Feature/Tenancy/TenancyRouteGroupsTest.php` (6 tests: group and alias registration; platform posture probe asserting `current_user` is `nodia_platform`, `app.tenant_id` is the sentinel, transaction level 1, `TenantContext::isPlatform()`, and no context or transaction residue after the response; 401 problem documents in `production` and `staging`; pass-through in `local`; rollback of writes behind a failing handler) and `tests/Unit/Tenancy/PlatformAuthPlaceholderTest.php` (4 tests: environment matrix) written first; all 10 failed as expected (`Target class [tenancy.platform] does not exist`, middleware class not found), then went green after implementation (10 passed, 34 assertions).
- Full `composer test`: 209 passed, 670 assertions, all six suites. Pint clean; Larastan clean (0 errors). `composer types:generate` produced no changes (no Data classes in this task), so nothing regenerated to commit.

Deviations:

- The contract step of the TDD loop does not apply: this task ships no endpoint, so there is no request or response Data object and no OpenAPI path to merge. Probe routes are test-registered and absent from the spec by design (the existing `assertMatchesProblemSchema` macro covers their problem documents).
- The plan's Endpoints intro says the admin and storefront groups carry a "resolution middleware slot"; this run interprets the slot as a real middleware class per group whose body throws until task-11 implements it, so premature route attachment fails loudly rather than running unscoped. The `nodia_app` posture for those groups therefore also lands in task-11, inside the resolution middleware, since posture needs the resolved tenant id.
- The rollback-behind-rendered-errors behavior of `PlatformRequestTransaction` is not spelled out by the plan but is required for the request-transaction posture to be safe: Laravel's routing pipeline converts downstream exceptions to responses before route middleware see them, which would otherwise commit partial writes behind error responses. Feature-tested and recorded here so task-11 reuses the same pattern for the `nodia_app` posture.

### task-09: platform tenant create, read, and update endpoints

- Timestamp: Thu Jul 9 16:08:51 -03 2026
- Commits: `28c4e86` (feat(tenancy): add platform tenant create, read, and update endpoints)

What landed:

- `apps/api/app/Tenancy/Http/Controllers/TenantController.php` plus routes in `platform.php`: `POST /v1/tenants` (201 TenantData via `CreateTenant`), `GET /v1/tenants` (spatie/laravel-query-builder, allowlist `filter[name]` partial and sorts `name`/`created_at` both directions, default `-created_at`, page pagination returned as laravel-data's `PaginatedDataCollection`, the standard `data`/`links`/`meta` envelope), `GET /v1/tenants/{tenant}`, and `PATCH /v1/tenants/{tenant}` routing branding and locale fields to `UpdateBranding` and gateway fields to `ConfigureGateways`. Item routes carry `whereUuid`, so a malformed id is a route miss (404 `request.not_found`) while a well-formed unknown id is 404 `tenant_not_found`. spatie/laravel-query-builder ^7.3 installed per ADR 014.
- Problem plumbing: `ErrorCode` gains `invalid_query_parameter` (400), `tenant_not_found` (404), and `default_locale_not_supported` (422); new `App\Support\Problems\HasErrorCode` interface lets domain exceptions map to codes without Support importing any context. `DefaultLocaleNotSupportedException` implements it; new `TenantNotFoundException` implements it; the vendor `InvalidQuery` exceptions (which cannot implement it) are mapped explicitly in `ProblemRenderer` before the generic HttpException arm. Exception messages become the problem `detail` (never stable contract).
- Request validation: `CreateTenantData` and `UpdateTenantData` gained `rules()` (required trio on create; `sometimes`/`filled` on update; `array`+`list`+`filled` items for `supported_locales` and `enabled_gateways`; `payout_schedule` nullable array), so create-time and update-time garbage renders 422 `request.validation_failed` with the errors map and `ConfigureGateways`' invariant guard is unreachable from HTTP.
- Contract: all four paths merged into `docs/openapi/openapi.yaml` with strict response schemas (`Tenant`, `BrandingSettings`, `TenantPage`/`PageLink`/`PageMeta` pinned to laravel-data's actual paginated output, `TenantNotFoundProblem`, `InvalidQueryParameterProblem`, and `TenantUnprocessableProblem` as a Problem-plus-oneOf covering both 422 codes); nine new exercisers in `DocumentedResponseCoverageTest` (which now migrates and cleans the test database) keep every documented response conformance-asserted. Request parameter schemas are deliberately loose (deepObject `filter`, free-string `sort`) so error-path exercisers stay request-valid; the allowlist is enforced by the API and asserted behaviorally.
- `BrandingSettingsData` fields changed from `string|Optional` to `?string` with null defaults so empty branding serializes as a JSON object with both keys instead of `[]` (an all-Optional Data object collapses to an empty PHP array), keeping the `object` typing of the contract; stored branding now always carries both keys.
- `UpdateBranding` gained the opaque `payout_schedule` passthrough (unit-tested: present, absent, explicit null), settling the routing question task-06 left open: no Action owns the field before Stage 8c, so the tenant profile update carries it.
- `apps/api/tests/Feature/Tenancy/TenantEndpointsTest.php` (22 tests): wire shape with snake_case keys and ISO 8601 UTC timestamps, defaults, both 422 codes with and without the errors map, envelope, filter, all four sorts, unknown filter and unknown sort each 400, show/patch happy paths and 404s, PATCH field routing and untouched-field semantics, all conformance-asserted. `apps/api/tests/Isolation/PlatformEndpointsIsolationTest.php` (4 tests): list, read, update, and create over HTTP with injected `X-Tenant-Id` and `Host` headers for tenant A still behave platform-scoped (tenant B remains visible and writable), proving the posture comes from the route group, never from headers.
- Architecture preset: `App\Tenancy\Http\Controllers` and `TenantNotFoundException` joined the commented ignore list (context-owned controllers and exceptions, same rationale as `App\Tenancy\Models`).

Test evidence:

- Feature tests written first: 21 of 22 failed (404s, missing routes; the malformed-uuid case passed by construction), then all 22 green after implementation (110 assertions). ErrorCode registry unit test updated first and failing, UpdateBranding payout passthrough unit tests written first and failing, isolation tests written before the routes existed.
- Intermediate failures during the loop, both mine: query-builder v7's `allowedFilters`/`allowedSorts` take variadics, not arrays, and the Laravel arch preset flags controllers outside `App\Http\Controllers` (base `Controller` import dropped, ignore entry added, matching the Models precedent).
- Full `composer test`: 249 passed, 869 assertions, all six suites (Contract 17, Isolation 35 including the 4 new HTTP tests). Pint clean; Larastan clean (0 errors). `composer types:generate` regenerated `BrandingSettingsData` (nullable fields) and `ErrorCode` (three new codes) in `packages/api-client/src/generated`; committed with the code; `pnpm --filter api-client typecheck` clean.

Deviations and decisions:

- `GET /v1/tenants` includes the sentinel platform tenant row (the platform posture reads all rows and the plan defines no exclusion); revisit if product wants infrastructure rows hidden from the listing.
- `BrandingSettingsData`'s move from Optional to nullable fields is a deliberate contract decision, not drift: the plan's "typed optional fields" is satisfied on input (both keys may be omitted) while the response shape stays a stable object. Two task-06 unit assertions were updated for the new stored shape.
- The 422 response is one OpenAPI response object covering both `request.validation_failed` and `default_locale_not_supported` (RFC 9457 problems distinguished by `code`, statuses shared), expressed as `allOf` Problem plus a `oneOf`, keeping the strictness gate satisfied.
- Both 422 codes on POST and PATCH, 400 on list, and 404 on item routes are rendered through the new `HasErrorCode` seam, which later Tenancy tasks (409s in task-10, resolution errors in task-11) can reuse without touching `ProblemRenderer` again; the vendor `InvalidQuery` mapping is the one exception, by necessity.
- The running dev API container was not rebuilt to probe the new routes over curl (its image predates this stage); end-to-end behavior is exercised through the HTTP kernel in the Feature and Isolation suites against real PostgreSQL.

### task-10: domain endpoints with feature, contract, and concurrency tests

- Timestamp: Thu Jul 9 16:29:13 -03 2026
- Commits: `44eb12d` (feat(tenancy): add tenant domain endpoints with concurrency-proven conflict handling)

What landed:

- `apps/api/app/Tenancy/Http/Controllers/TenantDomainController.php` plus routes in `platform.php`: `POST /v1/tenants/{tenant}/domains` (201 `TenantDomainData` via `RegisterDomain`), `GET /v1/tenants/{tenant}/domains` (paginator envelope sorted by domain, no query-builder parameters since the plan defines none), and the top-level item routes per the one-level nesting rule: `PATCH /v1/tenant-domains/{tenant_domain}` (promote via `MakeDomainPrimary`) and `DELETE /v1/tenant-domains/{tenant_domain}` (204 via `RemoveDomain`). All item routes carry `whereUuid`, so malformed ids are route misses (404 `request.not_found`) while well-formed unknown ids render the resource-specific 404.
- Problem plumbing: `ErrorCode` gains `tenant_domain_not_found` (404), `domain_already_registered` (409), and `tenant_domain_is_primary` (409); new `TenantDomainNotFoundException` and `DomainAlreadyRegisteredException`, and `TenantDomainIsPrimaryException` now implements `HasErrorCode`, all rendered through the task-09 `HasErrorCode` seam without touching `ProblemRenderer`'s flow (only its detail map grew).
- Conflict handling is the database, never read-then-write: `RegisterDomain` catches `UniqueConstraintViolationException` and translates by constraint name (`tenant_domains_domain_unique` to `domain_already_registered`, `tenant_domains_primary_per_tenant_idx` to `tenant_domain_is_primary`, anything else rethrown). `MakeDomainPrimary` maps the double-promotion race loser (partial-index violation after its demote scan missed a concurrently committed primary) to `tenant_domain_is_primary`.
- Request validation: `RegisterTenantDomainData::rules()` mirrors the hostname invariant through the extracted `RegisterDomain::isValidHostname()` (single source; the Action still throws `InvalidDomainNameException` for non-HTTP callers), so malformed hostnames render 422 `request.validation_failed` with the errors map. `UpdateTenantDomainData::rules()` accepts only `is_primary: true` (`accepted`).
- Contract: the four paths merged into `docs/openapi/openapi.yaml` with `TenantDomain`, `TenantDomainRegisterRequest`, `TenantDomainUpdateRequest` (`is_primary` enum [true]), `TenantDomainPage`, `TenantDomainNotFoundProblem`, `TenantDomainConflictProblem` (409 with both codes), and `TenantDomainIsPrimaryProblem`; eleven new exercisers in `DocumentedResponseCoverageTest` (whose afterEach now clears `tenant_domains` before tenants for the FK). The 204 documents no content, so it has no coverage key; the feature test conformance-asserts it.
- `apps/api/tests/Feature/Tenancy/TenantDomainEndpointsTest.php` (22 tests): happy paths, both 409s, all 404s, lowercase normalization visible on the wire from mixed-case input (`Tickets.ACME.Com` in, `tickets.acme.com` out, and duplicate detection across case), malformed-hostname 422 dataset, promote-demote semantics, replay no-op, empty-PATCH no-op, demotion rejection.
- `apps/api/tests/Concurrency/TenantDomainContentionTest.php` (2 tests): the races run through the real HTTP kernel inside forked workers (new `ParallelRunner::runEach(callable ...$tasks)` keeps the barrier and per-child connection discipline while letting contenders differ; the child inherits the booted app and its kernel opens a fresh connection after the parent purge). Parallel registration of one domain for two tenants deterministically yields exactly one 201 and one 409 `domain_already_registered` with exactly one row; parallel make-primary for two domains of one tenant asserts statuses in {200, 409}, at least one 200, and exactly one `is_primary` row belonging to a contender.
- `apps/api/tests/Isolation/PlatformDomainEndpointsIsolationTest.php` (3 tests): the two-tenant fixture over HTTP with injected `X-Tenant-Id` and `Host` headers cannot coerce the domain endpoints out of the platform posture (list, register, and delete against tenant B while injecting tenant A's headers). The cross-tenant admin-listing assertion is deferred to task-11 as the plan directs; the file's docblock records the pickup point.

Test evidence:

- Feature tests written first: 21 of 22 failed (404s and wrong codes; the malformed-uuid case passed by construction), then all 22 green (142 assertions). ErrorCode registry test updated first and failing (dataset references to missing enum cases), RegisterDomain conflict-mapping unit tests written first and failing; all green after implementation.
- Concurrency: 2 tests, 8 assertions, run five consecutive times, stable. Isolation suite: 38 passed (88 assertions). Contract suite: 28 passed (153 assertions, up from 17 tests).
- Full `composer test`: 292 passed, 1118 assertions, all six suites. One intermediate failure was the architecture preset needing the two new exceptions in the commented ignore list, same treatment as every prior context exception. Pint clean; Larastan clean (0 errors).
- `composer types:generate` regenerated `ErrorCode` (three new codes) in `packages/api-client/src/generated`; committed with the code; `pnpm --filter api-client typecheck` clean.

Deviations and decisions:

- Registering a second primary domain (`is_primary: true` when the tenant already has one) surfaces as 409 `tenant_domain_is_primary`, the decision task-07 left open. The plan's POST error table lists only `domain_already_registered`; reusing the planned code (detail: promote through PATCH instead) keeps the registry at exactly the planned codes rather than minting a new one. The POST 409 is documented as one response object with both codes, mirroring the task-09 two-code 422 treatment.
- `PATCH /v1/tenant-domains/{tenant_domain}` accepts only `is_primary: true`: the plan defines no demotion Action, and a bare demotion would leave the tenant with no primary domain, so demotion happens by promoting another domain and `is_primary: false` is rejected as 422 `request.validation_failed`. An empty PATCH body is a no-op returning the current state.
- The make-primary race loser maps to 409 `tenant_domain_is_primary` (`forConcurrentPromotion`) instead of leaking a 500. That 409 is not documented in OpenAPI because it cannot be produced deterministically by a single-threaded exerciser and the coverage gate requires one per documented response; the concurrency test asserts the code whenever the interleaving produces the conflict.
- TDD ordering note: the concurrency tests were written after the endpoint slice went green rather than before implementation, because the invariant guards themselves (unique and partial unique indexes, conditional UPDATEs) landed test-first in tasks 05 and 07; this task's races prove the shipped guards through the full HTTP path. The registration race is deterministic; the make-primary assertion tolerates the serialized interleaving, with the row-count invariant asserted unconditionally.

### task-11: tenant resolution middleware for both populations

- Timestamp: Thu Jul 9 16:46:45 -03 2026
- Commits: `d0b24c3` (feat(tenancy): add the nodia_resolver role for anonymous domain resolution), `3a5dfcf` (feat(tenancy): resolve tenant context from Host and X-Tenant-Id)

Decision, anonymous domain resolution versus system-design 4.3 (the open question the plan requires settled before this task merges): a dedicated narrow resolver role, `nodia_resolver`, the first of the plan's listed candidates. NOLOGIN, NOBYPASSRLS, its entire privilege surface is SELECT on `tenant_domains` through the permissive `tenant_domains_resolver_read` policy; it can neither write `tenant_domains` nor read any other table, `tenants` included. Storefront Host lookup runs under it (and task-12's Caddy verification endpoint will), so `nodia_platform` remains exactly what 4.3 says it is: assumable by platform-scope staff only, every use recorded in the activity log. No 4.3 amendment is needed because the resolver role is not the cross-tenant platform role and grants access to nothing but the table whose whole purpose is answering "which tenant owns this host"; no audit sampling is involved anywhere. The rejected alternatives: a permissive resolution policy for `nodia_app` would have destroyed tenant isolation on `tenant_domains` (this task's own isolation test fails under it), and amending 4.3 needs the design owner and turned out unnecessary. Stage 4's queue-worker tension with 4.3 remains its own open question; nothing here prejudges it.

What landed:

- `apps/api/database/migrations/2026_07_09_000003_create_domain_resolver_role.php`: idempotent `nodia_resolver` creation, membership grant to the migration user (same pre-provisioning escape hatch as the roles migration), SELECT grant and resolver read policy on `tenant_domains`. A new migration rather than an edit because the roles and tenant_domains migrations are merged. `down()` drops the policy and revokes; the cluster-level role is never dropped.
- `apps/api/app/Support/Database/Rls.php`: `RESOLVER_ROLE`, `createResolverRole()`, `grantResolverMembershipToCurrentUser()`, and `applyResolverReadPolicy($table)` so the grant travels with the policy if a later stage ever extends resolution.
- `apps/api/app/Support/Tenancy/TenantTransaction.php` gained `asDomainResolver($callback)`: a short transaction under `SET LOCAL ROLE nodia_resolver`, no tenant setting, no `TenantContext` entry, rejected inside any open transaction (stricter than the tenant postures' context-based guard, because the resolver never enters the context that guard reads).
- `apps/api/app/Tenancy/Actions/ResolveDomain.php`: `normalizeHost()` (trim, lowercase, port stripped; bracketed IPv6 keeps its brackets and simply never matches) and `tenantIdFor()` running the lookup under the resolver posture; an empty normalized host short-circuits to null without SQL.
- `apps/api/app/Tenancy/Http/Middleware/ResolveTenantFromHost.php`: resolves the Host header through `ResolveDomain`, unknown hosts render 404 `unknown_host`, and the resolved request runs inside `TenantTransaction::asTenant` for the owning tenant.
- `apps/api/app/Tenancy/Http/Middleware/ResolveTenantFromHeader.php`: X-Tenant-Id presence (400 `missing_tenant_header`, blank counts as missing), UUID shape (400 `invalid_tenant_header`), then existence checked inside the tenant transaction itself: under the tenant's own posture the `tenants` FOR SELECT policy exposes exactly the row whose id matches `app.tenant_id`, so RLS answers existence with no extra role and an unknown tenant renders 403 `tenant_access_denied`, deliberately contract-identical (code and message shape) to the Stage 3 membership denial.
- `apps/api/app/Tenancy/Http/Middleware/TransactsRequests.php`: the rendered-error rollback pattern task-08 discovered, extracted from `PlatformRequestTransaction` into a trait all three posture middleware share.
- Problem plumbing: `ErrorCode` gained `unknown_host` (404), `missing_tenant_header` (400), `invalid_tenant_header` (400), `tenant_access_denied` (403), rendered through the task-09 `HasErrorCode` seam by four new context exceptions; regenerated `packages/api-client/src/generated` committed.
- `tests/Feature/Tenancy/TenantResolutionTest.php`: the plan's data-driven resolution matrix against test-only probe routes (platform subdomain, custom domain, mixed-case host with port, unknown host, valid header, missing, blank, malformed, unknown tenant id), each error a conformance-asserted problem document; the SET LOCAL proof (probe returns `current_setting('app.tenant_id')`, `current_user`, transaction level, and the container context); the Octane simulation (three sequential kernel handles on one process across both populations, each observing only its own tenant, no residue after); rollback of writes behind a failing storefront handler, reusing the shared trait's guarantee under the `nodia_app` posture.
- `tests/Isolation/DomainResolverIsolationTest.php`: resolver reads both tenants' domains with no tenant context; INSERT, UPDATE, DELETE on `tenant_domains` and SELECT on `tenants` all permission-denied; the resolver policy grants `nodia_app` nothing. `tests/Isolation/StorefrontResolutionIsolationTest.php`: through the storefront probe route, tenant A's host sees only A's `tenant_domains` rows (and B only B's). `RlsHonestConnection` grants the downgraded isolation role resolver membership, mirroring the migration.
- `tests/Unit/Tenancy/ResolveDomainTest.php` (host normalization before lookup, resolution against real PostgreSQL) and five new `TenantTransactionTest` cases for the resolver posture invariants.

Test evidence:

- Slice A (resolver role) tests written first: 33 failed (`RESOLVER_ROLE` and `createResolverRole()` undefined, `ResolveDomain` not found), green after implementation (34 passed, 71 assertions including the touched `TenantTransactionTest`). Slice B (middleware) tests written first: the run aborted on the ErrorCode dataset referencing missing enum cases, then after implementation all 34 matrix, posture, isolation, and registry tests passed.
- One intermediate failure was the test harness, not the implementation: Laravel's `Request::create()` overwrites `HTTP_HOST` with the URI's host, so a Host header passed through the headers argument silently becomes `localhost`; storefront cases carry the host in the request URL instead, with a comment in the test recording the trap.
- Full `composer test`: 333 passed, 1232 assertions, all six suites (Isolation 46, up 8). Pint clean; Larastan clean (0 errors). `composer types:generate` regenerated `ErrorCode` (four new codes); `pnpm --filter api-client typecheck` clean.
- The resolver migration was exercised directly against `nodia_test` beyond the suite run: `migrate:fresh`, `migrate:rollback --step=1`, `migrate` all clean; `pg_roles` confirms `nodia_resolver` NOLOGIN, NOBYPASSRLS, non-superuser; `pg_policy` shows all four `tenant_domains` policies including `tenant_domains_resolver_read` (`polcmd = 'r'`).

Deviations and decisions:

- The contract step ships no OpenAPI path, same as task-08 and for the same reason: this task adds no production endpoint, the probe routes are test-registered, and the resolution error surface attaches to future routes in these groups. The four codes join the spec when task-12 and Stage 3+ document routes that produce them.
- The admin happy path, a blank (whitespace) header case, and a mixed-case-host-with-port case were added to the plan's six-row matrix; additions, not changes.
- `asDomainResolver` rejects execution inside any open transaction rather than only inside a tenant transaction, deliberately stricter than `asTenant`/`asPlatform`: the resolver posture never enters `TenantContext`, so the context-based nesting guard cannot see it, and `SET LOCAL ROLE` inside a savepoint would survive the savepoint's release.
- The rollback-behind-rendered-errors test for the `nodia_app` posture is not demanded by the plan's Slice 6 bullets but pins the shared trait's guarantee for both tenant-scoped groups, since task-08's equivalent test only covered the platform group.

### task-12: domain verification endpoint (Caddy on-demand TLS ask)

- Timestamp: Thu Jul 9 16:55:35 -03 2026
- Commits: `a2d864d` (feat(tenancy): add the domain verification endpoint for Caddy on-demand TLS)

What landed:

- `apps/api/app/Tenancy/Http/routes/internal.php`, loaded by `TenancyServiceProvider` under `/v1` with no auth and no tenancy posture group: `GET /v1/internal/domain-verification?domain=` is unauthenticated by design (Caddy calls it during the TLS handshake, before any credential can exist) and network-internal; the route file's comment records that infrastructure must never route `/v1/internal` through the public edge. The read posture inherits the task-11 resolver decision unchanged: the lookup reuses `ResolveDomain`, so it runs under `nodia_resolver`, never bare `nodia_platform`, and system-design 4.3 stands unamended.
- `apps/api/app/Tenancy/Http/Controllers/DomainVerificationController.php`: single-action controller, 204 no-content when `ResolveDomain::tenantIdFor` finds the normalized domain in `tenant_domains` (existence is the gate per system-design 16.3), otherwise the new `UnknownDomainException`. Case-insensitive matching falls out of the shared `normalizeHost` lowercasing.
- `apps/api/app/Tenancy/Data/DomainVerificationData.php`: the request contract, `domain` required with the hostname closure mirroring `RegisterDomain::isValidHostname` (single source with the registration invariant), so a missing or malformed parameter renders 422 `request.validation_failed` with the errors map; a value that could never have been stored is a validation failure, not an unknown domain.
- Problem plumbing: `ErrorCode` gains `unknown_domain` (404) with its `ProblemRenderer` detail; `UnknownDomainException` renders through the task-09 `HasErrorCode` seam. The exception joined the architecture preset's commented ignore list like every prior context exception.
- Contract: the path merged into `docs/openapi/openapi.yaml` (loose string parameter schema, per the established pattern, so error-path exercisers stay request-valid; hostname validity is enforced by the API) with the new `UnknownDomainProblem` component; two new exercisers in `DocumentedResponseCoverageTest` for the 404 and 422. The 204 documents no content, so it has no coverage key, same as the domain DELETE; the feature test conformance-asserts it.

Test evidence:

- `tests/Feature/Tenancy/DomainVerificationTest.php` (9 tests: 204 for a registered domain, case-insensitive 204, 404 `unknown_domain` problem, 422 for a missing parameter, a 4-case malformed dataset covering embedded space, trailing root dot, port suffix, and scheme prefix, plus a no-auth/no-context/no-transaction-residue check) written first; all 9 failed (404, route missing). The ErrorCode registry unit test was updated next and failed (dataset referencing the missing enum case); the two contract exercisers plus the route-spec drift check failed before implementation. All green after: feature 9 passed (53 assertions).
- Full `composer test`: 345 passed, 1303 assertions, all six suites. Pint clean; Larastan clean (0 errors).
- `composer types:generate` regenerated `packages/api-client/src/generated` (new `DomainVerificationData`, `ErrorCode` gains `unknown_domain`); committed with the code; `pnpm --filter api-client typecheck` clean.

Deviations and decisions:

- The inside loop is thin by construction and mostly reuses proven parts: normalization and the resolver posture were unit- and isolation-tested in task-11 (`ResolveDomainTest`, `DomainResolverIsolationTest`), and the hostname invariant in task-07's 13-case dataset. The new unit-level failing test was the ErrorCode registry; the endpoint's own behavior is proven at the feature and contract level, matching how tasks 09 through 11 treated their error plumbing.
- The plan's Data-object list does not name a request object for this endpoint; `DomainVerificationData` was added because the master plan's contract step requires laravel-data request objects as the source of truth. There is no response Data object because the success response is 204 with no body.
- A domain parameter carrying a port or scheme is rejected as 422 rather than normalized and matched: Caddy's ask passes the bare SNI hostname, and accepting decorated forms would make the verification surface looser than the registration surface.
- The structured audit-seam feature test from Slice 7's second half is task-13; nothing here executes under `nodia_platform`, so this endpoint adds no audit obligation.

### task-13: platform-role audit log seam

- Timestamp: Thu Jul 9 17:05:36 -03 2026
- Commits: `d7f8d33` (feat(tenancy): emit a structured audit entry for every platform-role request)

What landed:

- `apps/api/app/Support/Tenancy/PlatformRoleAudit.php`: the audit seam of system-design 4.3, next to the `TenantTransaction` posture machinery it audits. `recordRequest($request)` emits one `Log::info('audit.platform_role.request', ...)` entry whose context carries `role` (`nodia_platform`), `correlation_id` (from the `X-Correlation-Id` header the global middleware guarantees; the header constant is mirrored, not imported, same as `ProblemRenderer`, because the architecture preset forbids Support referencing middleware), `method`, and `path`. `MESSAGE` is a public const so tests and the Stage 3 upgrade share the stable entry name.
- `apps/api/app/Tenancy/Http/Middleware/PlatformRequestTransaction.php`: the single call site. The seam fires as the first statement inside `TenantTransaction::asPlatform`, so the entry is recorded exactly when the role is assumed: every request through the `tenancy.platform` group is audited (future platform routes inherit it via the group), an auth-denied request (placeholder 401 outside testing/local) emits nothing because the role was never assumed, and a failing handler still emits because a log line survives the rolled-back transaction.
- `apps/api/tests/Support/LogCapture.php`: the log fake. timacdonald/log-fake is uninstallable here (v2.4.2, the only Laravel 13 release, pins symfony/var-dumper ^7 while Laravel 13 locks v8.1.1; verified against Packagist including dev-master), so the fake swaps the default channel's handlers for a Monolog `TestHandler` already in the tree, and tests assert against captured `LogRecord`s.
- `apps/api/tests/Feature/Tenancy/PlatformRoleAuditTest.php` (16 tests, written first, all failing): a 10-case dataset covering every platform CRUD route including error paths (422 validation, 404s) asserting exactly one entry with the supplied correlation id, method, and path; the generated-correlation-id case (no header sent, entry matches the response's echoed header); the rollback case (throwing platform handler, 500, entry still emitted); the negative matrix proving no entry for the 401 auth denial, the domain verification endpoint (`nodia_resolver`), storefront host resolution, and admin header resolution (`nodia_app`), pinning the task-11 decision into the audit contract.
- `apps/api/tests/Unit/Tenancy/PlatformRoleAuditTest.php` (3 tests, written first, all failing): single info entry under the stable message name, full context shape with query string stripped from the path, and null correlation id when the header is absent (documents that the seam relies on the global middleware).

Test evidence:

- Feature tests first failed as expected (16 errors, `Class "App\Support\Tenancy\PlatformRoleAudit" not found`), then the unit tests likewise (3 errors); all 19 green after implementation (63 assertions). One intermediate failure was the test, not the implementation: the shared log context set by the CorrelationId middleware merges its `correlation_id` key ahead of the entry's own, so the feature assertion compares pairs (`toEqual`), not key order.
- Full `composer test`: 364 passed, 1366 assertions, all six suites. Pint clean; Larastan clean (0 errors). `composer types:generate` produced no changes (no Data classes in this task), so nothing regenerated to commit.

Deviations and decisions:

- The plan's "asserted via the log fake" is implemented with a Monolog `TestHandler` swap (`Tests\Support\LogCapture`) instead of the timacdonald/log-fake package, which cannot be installed against Laravel 13's Symfony 8 lock (its constraint pins var-dumper ^7). No production code is affected; the fake captures the same default channel the seam writes to.
- The contract step ships no OpenAPI change, same as tasks 08 and 11 and for the same reason: this task adds no endpoint and no Data object; the audit entry is a log contract, not a wire contract.
- Audit scope confirmed against the task-11 decision: only the platform CRUD group executes under `nodia_platform`, so it is the only audited surface; the verification endpoint and storefront resolution run under `nodia_resolver`/`nodia_app` and the feature tests assert they emit nothing.

### task-14: stage close

- Timestamp: Thu Jul 9 17:09:37 -03 2026
- Commits: `4890103` (docs: close stage 2 with the DomainVerified registration trigger decision), plus the SHA-recording follow-up carrying this line

Decision, `DomainVerified` trigger semantics (the open question this stage owns; the plan requires it settled before this task closes): `DomainVerified` means registered. Stage 4 attaches the producer to `RegisterDomain`, recording the event in the same transaction as the row insert. No `verified_at` column and no DNS-challenge flow ship, because nothing in the design demands them. Rationale:

- system-design 16.3 gates TLS issuance on existence in `tenant_domains` alone, and the ERD (8.1) carries no verification state; in the current design, registration through the authenticated, audited platform-admin surface is the platform's act of verification, so the registered row is the verified fact the event announces.
- "First ask-endpoint confirmation" is rejected: the verification endpoint runs under the read-only `nodia_resolver` posture (task-11 decision), which can write nothing, so recording an event there would need write privileges on the TLS-handshake hot path plus dedup state (a `verified_at` column in all but name) to make "first" meaningful, and it would make a domain event's timing depend on external infrastructure behavior rather than a domain action.
- A `verified_at` column with a DNS-challenge flow is rejected for now: domain registration is platform-staff-only this stage, so there is no untrusted claim to challenge. If a later stage adds tenant self-service domain registration, verification becomes a real flow and gets its own new event type per event-conventions (evolution is additive; a new event, never a redefinition of this one).

The decision is recorded in the `DomainVerified` class docblock (the artifact Stage 4 reads when attaching the producer) and in Decisions below. `RegisterDomain` is the settled Action; no code change was needed beyond the docblock, because the Action already creates exactly the row whose existence the event reports.

What landed:

- `apps/api/app/Tenancy/Events/DomainVerified.php`: docblock updated from "open question" to the settled registration trigger and Stage 4 attachment point.
- `docs/api-implementation-plan.md`: Stage 2 flipped from "In progress" to "Done" in the status table.
- This journal entry and the run summary below. `docs/roadmap.md` untouched: no roadmap Phase 1 work was consumed by this stage (the domain verification endpoint is Phase 7's consumer, delivered early by design).

Exit criteria verification (all 12 checks from the stage plan, each mapped to its passing evidence in this run's final state):

1. Cross-tenant SELECT invisibility and zero-row UPDATE/DELETE on `tenants` and `tenant_domains`: `tests/Isolation/TenantsIsolationTest.php`, `tests/Isolation/TenantDomainsIsolationTest.php` (tasks 04, 05).
2. Foreign `tenant_id` INSERT/UPDATE fails `WITH CHECK`: `tests/Isolation/TenantDomainsIsolationTest.php` and `tests/Isolation/RlsBootstrapTest.php` (tasks 01, 05).
3. Raw SQL bypassing Eloquent scoping still isolated: the raw `DB::select` case in `tests/Isolation/TenantDomainsIsolationTest.php` (task 05).
4. Deny by default with no tenant context: `tests/Isolation/RlsBootstrapTest.php` (including the empty-string leftover case, task 01); malformed tenant id rejected before SQL: `tests/Unit/Tenancy/TenantTransactionTest.php` (task 02).
5. `nodia_platform` reads all tenants' rows; `nodia_app` cannot insert, update, or delete `tenants` rows including its own: `tests/Isolation/TenantsIsolationTest.php` (task 04).
6. Both tenancy migrations carry their RLS policies in the creating file: statically true of `2026_07_09_000001_create_tenants_table.php` and `2026_07_09_000002_create_tenant_domains_table.php`; the Isolation suite executes the real migrations via `MigratedDatabase` (task 04), so removing a policy fails the suite.
7. Resolution matrix with problem documents per stable code: `tests/Feature/Tenancy/TenantResolutionTest.php` (task 11; the plan's six rows plus three added cases).
8. Sequential same-worker requests never leak context: the Octane simulation in `tests/Feature/Tenancy/TenantResolutionTest.php` (task 11).
9. Every platform-role execution path emits the structured audit entry with correlation ID: `tests/Feature/Tenancy/PlatformRoleAuditTest.php` 10-route dataset plus the negative matrix, and `tests/Unit/Tenancy/PlatformRoleAuditTest.php` (task 13).
10. Duplicate-domain and double-make-primary races resolve to one winner via constraints and conditional UPDATEs: `tests/Concurrency/TenantDomainContentionTest.php` (task 10).
11. Every endpoint in `docs/openapi/openapi.yaml` with passing conformance: Contract suite, 28 passed including `DocumentedResponseCoverageTest` exercisers (tasks 09, 10, 12); generated TypeScript committed with zero drift (verified again at close, below).
12. Architecture suite green including the Models and Http confinement rules; Larastan and Pint clean; all six suites in `composer test` on PostgreSQL: verified at close, below.

Test evidence (stage close, after the docblock and status edits):

- `composer test`: 364 passed, 1366 assertions, all six suites (Feature, Unit, Contract, Architecture, Isolation, Concurrency) against PostgreSQL.
- Pint clean; Larastan clean (0 errors).
- `composer types:generate`: ran to completion with `packages/api-client` unchanged in git afterwards, so zero drift.

Deviations:

- None. The decision demanded no `verified_at` column or verification flow, so none shipped, exactly as the plan's open-question bullet anticipated ("this stage ships no verification state unless the decision demands it").

### Run summary

Stage 2 is done. Fourteen tasks landed the RLS bootstrap (roles, policy helper, resolver role), the `SET LOCAL` tenant transaction wrapper, PostgreSQL as the only test database, the `tenants` and `tenant_domains` tables with their policies in the creating migrations, the sentinel platform tenant, the full platform tenant and domain CRUD surface with OpenAPI contracts and generated TypeScript, tenant resolution for both populations, the Caddy domain-verification endpoint, the platform-role audit seam, and the two stage-owned decisions (anonymous domain resolution via `nodia_resolver`; `DomainVerified` fires on registration). Final state: 364 tests passing with 1366 assertions across all six suites, Pint and Larastan clean, zero contract drift. Handoffs: Stage 3 rebinds `auth.platform`, validates `X-Tenant-Id` membership, and upgrades the audit seam to activity-log records (see the task-13 decision entry); Stage 4 attaches the `TenantCreated` and `DomainVerified` producers to `CreateTenant` and `RegisterDomain` and adds the Tenancy rows to the system-design 9.3 registry.

### Review rounds

#### Review round 1

Recorded at Thu Jul 9 17:20:13 -03 2026.

Findings received: one, blocking. The Codex reviewer never reviewed the stage diff: both invocation attempts (with and without `--effort high`) failed with a 400 before any review work, because the configured default model `gpt-5.6-terra` requires a newer Codex CLI than the installed one. The finding was explicitly about the environment, not the Nodia repository.

What was done:

- Confirmed the root cause: `~/.codex/config.toml` pins `model = "gpt-5.6-terra"` and the Homebrew-installed Codex CLI was 0.143.0, which the API rejects for that model ("requires a newer version of Codex"). The failed job log in the codex plugin state directory shows the identical 400 on turn start.
- Upgraded the CLI: `brew update` (local metadata still listed 0.143.0 as latest) then `brew upgrade codex`, landing 0.144.0.
- Terminated the stale codex app-server broker for this project (spawned before the upgrade, so it still held the 0.143.0 binary in memory); the broker's SIGTERM handler shut it and its app-server child down cleanly and removed the pid file, so the next companion invocation spawns a fresh broker on the new binary.
- Verified end to end: a direct `codex exec` turn and a minimal codex-companion task in this repository both completed on the default model with no 400.
- The finding's alternative remedy (forcing a different model via `--model`) was rejected as a workaround; the upgrade fixes the actual mismatch and keeps the user's configured model.

No repository code was implicated, so no source or test changes were made. The gates were re-run anyway per the round protocol: Pint passed, Larastan passed with 0 errors, Pest passed 364 tests with 1366 assertions across all six suites.

Consequence: the stage 2 diff has still received no substantive Codex review; the environment is now capable of running one, so the next review round performs it.

#### Review round 2

Recorded at Thu Jul 9 17:32:11 -03 2026.

Findings received: one, blocking. Codex again performed no review: the reviewer had no tool access because the workspace command host binary `/opt/homebrew/bin/codex-code-mode-host` is missing, so it could not read the diff or any repository files. As in round 1, the finding is about the environment, not the Nodia repository; this time the cause is different from round 1's model-availability 400.

Root cause, confirmed against ground truth:

- Codex CLI 0.144.0 (the version round 1 upgraded to) moved command execution to hosted code mode by default (openai/codex PR 31500). In hosted mode every command the model runs is executed by a separate `codex-code-mode-host` process; `codex-rs/code-mode/src/remote_session.rs` resolves that binary from the `CODEX_CODE_MODE_HOST_PATH` env var, else as a sibling of the invoked `codex` executable, which here is the `/opt/homebrew/bin/codex` symlink, hence the `/opt/homebrew/bin/codex-code-mode-host` path in the finding.
- The Homebrew cask for codex 0.144.0 installs only the main binary (its artifact stanza is `binary "codex-#{arch}-#{os}", target: "codex"`); it does not ship `codex-code-mode-host`, even though the upstream `rust-v0.144.0` GitHub release publishes `codex-code-mode-host-aarch64-apple-darwin.tar.gz` and the official npm distribution (`@openai/codex` 0.144.0, via its darwin-arm64 platform package) bundles the host next to the codex binary. The cask at homebrew-cask HEAD still has the gap, and no issue or PR about it exists yet in Homebrew/homebrew-cask or openai/codex.
- Reproduced locally: a trivial `codex exec` command task fails with `failed to spawn code-mode host /opt/homebrew/bin/codex-code-mode-host: No such file or directory`.
- Remediation verified: with the exact-version host binary downloaded from the `rust-v0.144.0` release and `CODEX_CODE_MODE_HOST_PATH` pointing at it for a single invocation, the same `codex exec` task executed its command and reported correct output.

What was not done, and why: the actual repair, placing `codex-code-mode-host` next to the codex binary (or persisting `CODEX_CODE_MODE_HOST_PATH`), is a persistent change outside this repository, and the permission system denied installing the downloaded binary into `/opt/homebrew` ("Unauthorized Persistence" / "Code from External"). Equivalent routes (npm global install shadowing the brew codex, editing shell profiles or user-level settings to persist the env var) carry the same intent the denial flagged, so they were not attempted as workarounds. The fix is left to the user; either option below was validated or inspected in this round:

1. Version-matched host next to the brew install (mirrors what this round verified): `gh release download rust-v0.144.0 --repo openai/codex --pattern 'codex-code-mode-host-aarch64-apple-darwin.tar.gz'`, extract into `/opt/homebrew/Caskroom/codex/0.144.0/`, then `ln -s /opt/homebrew/Caskroom/codex/0.144.0/codex-code-mode-host-aarch64-apple-darwin /opt/homebrew/bin/codex-code-mode-host`. Codex resolves the host from disk on every spawn, so no broker restart is needed. A future `brew upgrade codex` leaves the symlink dangling, which fails loudly rather than silently running a stale host; re-run the download for the new version (or drop the symlink once the cask ships the host itself).
2. Replace the brew install with the official npm distribution, which is complete: `npm install -g @openai/codex` (installs into the Herd nvm bin dir, which precedes `/opt/homebrew/bin` on PATH), then `brew uninstall --cask codex` to avoid a shadowed duplicate.

No repository code was implicated, so no source or test changes were made. The gates were re-run per the round protocol: Pint passed, Larastan passed with 0 errors, Pest passed 364 tests with 1366 assertions across all six suites.

Consequence: the stage 2 diff has still received no substantive Codex review across two rounds. The blocking finding stands until the user installs the host binary; the moment either remedy above is applied, the next review round can run (verified end to end this round via the env-var override).

#### Review round 3

Recorded at Thu Jul 9 17:34:44 -03 2026.

The orchestrator classified this round as approved with only minor notes. The single finding, listed verbatim for the record:

```json
[
  {
    "severity": "minor",
    "file": "N/A",
    "summary": "Codex invocation failed again in round 3: the review could not be performed because the Codex workspace command host is unavailable ('/opt/homebrew/bin/codex-code-mode-host' is missing), the same failure mode as round 2. Codex could not read the diff, the stage plan, or any convention docs, and explicitly declined to fabricate findings. No code review of the Nodia stage 2 implementation was performed in this round. This is an environment/tooling issue (missing command host binary) that must be fixed on the Codex CLI installation before any further review rounds can succeed; re-running without fixing the binary will not produce a different result."
  }
]
```

For accuracy: the finding's own content states that no review of the stage 2 diff was performed in this round. The workspace command host binary `/opt/homebrew/bin/codex-code-mode-host` is still missing, the same failure mode diagnosed in round 2, whose remediation was left to the user (see that entry for the two validated fix options). Codex could not read the diff, the stage plan, or the convention docs, and explicitly declined to fabricate findings. The "approved" classification reflects only that the sole finding carries minor severity and targets the environment rather than the repository; it does not represent a substantive review of the code, and this journal records it as such rather than as an approval.

No repository code was implicated and none was changed in this round.

Consequence: after three rounds the stage 2 diff has received no substantive Codex review. Further rounds are pointless until the host binary is installed per the round 2 remediation options; once it is, the next round performs the actual review.

### Decisions and deviations

- task-11: anonymous domain resolution (storefront Host lookup, Caddy verification) runs under the dedicated narrow `nodia_resolver` role, not `nodia_platform`; system-design 4.3 stands unamended and no audit sampling is involved. Full rationale in the task-11 entry.
- task-14: `DomainVerified` fires on registration; Stage 4 attaches the producer to `RegisterDomain` in the same transaction as the insert. No `verified_at` column, no challenge flow; tenant self-service registration, if it ever arrives, introduces a new event type for its verification flow. Full rationale in the task-14 entry.
- task-13, the audit seam and its Stage 3 upgrade point: `App\Support\Tenancy\PlatformRoleAudit::recordRequest` is the seam; its single call site is `PlatformRequestTransaction`, which invokes it as the first statement inside `TenantTransaction::asPlatform`. Stage 3 upgrades the body of `recordRequest` to spatie/laravel-activitylog records without touching the call site. Three facts the upgrade inherits: (1) the seam runs inside the platform transaction with `app.tenant_id` set to the sentinel, so an activity-log insert passes that table's RLS `WITH CHECK` with the sentinel `tenant_id` (data-conventions: platform-scope rows use the sentinel, never NULL) and commits atomically with the request's writes; (2) a rolled-back request currently keeps its audit evidence because a log line is not transactional, so Stage 3 must decide how an activity-log row survives the rollback (record again from the error path, or accept the structured log line as the failure-path record and write DB rows for committed requests only); (3) the entry name `audit.platform_role.request` and its context keys (`role`, `correlation_id`, `method`, `path`) are pinned by feature and unit tests, and the actor (authenticated platform staff) is the datum Stage 3 adds once Passport exists. The feature test's negative matrix also pins that only the `tenancy.platform` group is audited; if Stage 3 moves any surface onto `nodia_platform`, those tests force the audit decision at the same time.

### Gate

Run at Thu Jul 9 17:12:58 -03 2026, all green on the first pass with no fixes required:

- `composer lint` (Pint): passed.
- `composer analyse` (Larastan): passed, 0 errors.
- `composer test` (Pest): 364 passed, 0 failed, 1366 assertions across all six suites.
- `composer types:generate`: ran clean; `git status --short packages/api-client/src/generated` empty, no contract drift.
- `pnpm typecheck` (run because `packages/api-client/src/generated/index.ts` changed on this branch): all five TS workspaces passed.

### Close-out

Recorded at Thu Jul 9 17:37:20 -03 2026. Final status: Done.

- Tasks: 14 of 14 completed, none blocked. Task commits `ca493be`, `cf86d1b`, `da500e5`, `6de572b`, `da5fc4c`, `888452e`, `505faa3`, `a5926d3`, `28c4e86`, `44eb12d`, `d0b24c3` plus `3a5dfcf`, `a2d864d`, `d7f8d33`, `4890103`.
- Gate: all green on the first pass (entry above, Thu Jul 9 17:12:58 -03 2026): Pint passed, Larastan 0 errors, Pest 364 passed with 1366 assertions across all six suites on PostgreSQL, zero contract drift, all five TS workspaces typecheck clean. The gates were re-run and stayed green in review rounds 1 and 2.
- Review rounds: three. Every finding across all three rounds targeted the Codex review environment, never the Nodia repository. Round 1 (blocking): Codex CLI 0.143.0 rejected the configured model with a 400; fixed by upgrading to 0.144.0 and restarting the stale broker. Round 2 (blocking): CLI 0.144.0's hosted code mode requires `codex-code-mode-host`, which the Homebrew cask does not ship; the fix is a persistent change outside this repository that the permission system denied, so remediation was documented and left to the user. Round 3 (minor, classified approved): same missing host binary. Consequence, recorded plainly: the stage 2 diff received no substantive external Codex review in any round; the code shipped under the stage's own TDD, gate, and exit-criteria discipline only. If a post-hoc review is wanted once the host binary is installed, it can run against this closed stage.
- Unresolved or declined findings: none against the repository. The one open item is the environmental round 2/3 finding (install `codex-code-mode-host` per the two validated options in the round 2 entry), owned by the user, not by this stage.
- Blockers: none.

Exit criteria walk (the 12 checks from the stage plan, each verified against the working tree and the gate run at close-out time):

1. Met. Cross-tenant SELECT invisibility and zero-row UPDATE/DELETE on `tenants` and `tenant_domains`: `tests/Isolation/TenantsIsolationTest.php` and `tests/Isolation/TenantDomainsIsolationTest.php`, green in the gate's Isolation suite.
2. Met. Foreign `tenant_id` INSERT/UPDATE fails `WITH CHECK`: `tests/Isolation/RlsBootstrapTest.php` and `tests/Isolation/TenantDomainsIsolationTest.php`.
3. Met. Raw SQL bypassing Eloquent scoping still isolated: the raw `DB::select` case in `tests/Isolation/TenantDomainsIsolationTest.php`.
4. Met. Deny by default with no tenant context (including the empty-string leftover case): `tests/Isolation/RlsBootstrapTest.php`; malformed tenant id rejected before any SQL: `tests/Unit/Tenancy/TenantTransactionTest.php`.
5. Met. `nodia_platform` reads all tenants' rows and `nodia_app` cannot insert, update, or delete `tenants` rows, its own included: `tests/Isolation/TenantsIsolationTest.php`.
6. Met. Both tenancy migrations carry their RLS in the creating file, verified statically at close-out: `2026_07_09_000001_create_tenants_table.php` contains its custom policies inline and `2026_07_09_000002_create_tenant_domains_table.php` calls `Rls::applyTenantPolicies('tenant_domains', platformWrite: true)`; the Isolation suite executes the real migrations via `MigratedDatabase`, so a removed policy fails the suite.
7. Met. Resolution matrix (platform subdomain, custom domain, unknown host 404 `unknown_host`, missing header 400 `missing_tenant_header`, malformed header 400 `invalid_tenant_header`, unknown tenant 403 `tenant_access_denied`, each a conformance-asserted problem document): `tests/Feature/Tenancy/TenantResolutionTest.php`.
8. Met. Sequential same-worker requests never leak context: the Octane simulation in `tests/Feature/Tenancy/TenantResolutionTest.php`.
9. Met. Every platform-role execution path emits the structured audit entry (`audit.platform_role.request`) with the correlation ID: the 10-route dataset plus negative matrix in `tests/Feature/Tenancy/PlatformRoleAuditTest.php` and `tests/Unit/Tenancy/PlatformRoleAuditTest.php`.
10. Met. Duplicate-domain registration and double-make-primary races resolve to exactly one winner via the unique index, partial unique index, and conditional UPDATEs: `tests/Concurrency/TenantDomainContentionTest.php`, stable across five consecutive runs in task-10.
11. Met. Every stage endpoint present in `docs/openapi/openapi.yaml`, verified at close-out: `/v1/tenants`, `/v1/tenants/{tenant}`, `/v1/tenants/{tenant}/domains`, `/v1/tenant-domains/{tenant_domain}`, `/v1/internal/domain-verification`; Contract suite 28 passed in the gate; `git status --short packages/api-client/src/generated` empty at close-out, zero drift.
12. Met. Architecture suite green including the Models and Http confinement rules; Pint and Larastan clean; all six suites run in `composer test` against PostgreSQL (gate entry above).

The master plan status table (`docs/api-implementation-plan.md`) marks Stage 2 "Done", set by task-14 commit `4890103` and confirmed accurate at close-out: every exit criterion is met, the gates are green, and the review ended with no unaddressed blocking or important findings against the repository. The absence of a substantive external review is recorded above as the honest caveat to that classification.

## Run: 2026-07-09, CI close-out

Interactive session after the workflow run closed the stage.

- Pushed the stage 2 commits to origin (`41db5a3`). The first CI pass exposed two defects the unpushed workflow run could not have caught:
  - The API Contract Drift job had no PostgreSQL service while task-03 moved the Contract suite onto the database; the suite failed with connection refused. Fixed by giving the job the same postgres service and DB env as the other API jobs (`6f5f778`).
  - The regenerated api-client types carried `Record<string, any>` for `payout_schedule` in three Data classes, rejected by the Packages ESLint no-explicit-any rule. Fixed by pinning the property to `Record<string, unknown> | null` with `LiteralTypeScriptType`, plus the transformer's `Optional` attribute on the request DTOs so the literal annotation does not drop the `?` optionality marker the wire contract had (`91d57fd`).
- Both fixes verified locally (Pint, Larastan, 364 Pest tests across all six suites, api-client ESLint and tsc) and on CI: all five workflows green on `6f5f778`, including API Contract Drift and Packages.
- Codex review: still unavailable. Root cause identified: codex 0.144.0 was installed from the Homebrew cask, which ships only the main binary; the `codex-code-mode-host` binary it needs to execute commands is absent, so every review attempt (plugin runtime and direct `codex exec`) fails without reading any code. The user chose to skip the external review for stage 2 rather than install the npm runtime. Stage 2 remains Done with that caveat.
