# Execution Journal: Stage 2, Tenancy and RLS Regime

Durable record of execution runs for [stage-02-tenancy-rls.md](../stage-02-tenancy-rls.md). Append a new run header per run; never rewrite prior entries.

## Run: 2026-07-09

- Stage: 2, Tenancy and RLS Regime
- Date: 2026-07-09
- Branch: `feat/api-implementation`
- Base commit: `61d886b135c65fc48c74c7fa393aadc3f885774e`

Verified starting state: Stage 1 is done (commit 61d886b marks it done; problem handler, Money, Contract suite, isolation and concurrency harnesses, and the clock all exist). Nothing from Stage 2 exists: there is no `app/Tenancy` directory, `database/migrations` holds only the two Laravel defaults, `phpunit.xml` still defaults `DB_CONNECTION` to sqlite for the Feature suite (Isolation and Concurrency already run on PostgreSQL with driver guards), and the isolation suite runs against throwaway probe tables (`tests/Isolation/Support/ProbeTable.php`), not real tenancy tables. The master plan status table said "Not started"; this run flips it to "In progress".

### Task checklist

- [ ] task-01: RLS roles migration and reusable RLS migration helper (plan task 1, slice 1 implementation)
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

### Review rounds

(none yet)

### Decisions and deviations

(none yet)
