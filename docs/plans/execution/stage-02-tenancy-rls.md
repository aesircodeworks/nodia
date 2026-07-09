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
- [ ] task-02: Tenant context holder and SET LOCAL transaction wrapper in Support (plan task 2, slice 1)
- [ ] task-03: Default test connection to PostgreSQL for all suites (plan task 3)
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

### Review rounds

(none yet)

### Decisions and deviations

(none yet)
