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

### Review rounds

(none yet)

### Decisions and deviations

(none yet)
