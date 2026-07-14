# Stage 2 Implementation Plan: Tenancy and RLS Regime

Stage 2 of [api-implementation-plan.md](../api-implementation-plan.md). This stage delivers tenant context resolution and database-enforced isolation, the foundation every tenant-scoped table after this builds on (system-design.md section 4). The Method (TDD loop) and Contract pipeline sections of the master plan are binding for every slice below, as are [api-conventions.md](../api-conventions.md), [data-conventions.md](../data-conventions.md), and [event-conventions.md](../event-conventions.md).

## Scope and non-goals

Delivered by this stage:

- `tenants` and `tenant_domains` tables with branding, locale configuration, and enabled-gateway configuration, each shipping its RLS policy in the creating migration (system-design 8.1, ADR 003, ADR 008).
- The RLS bootstrap: database roles, the `SET LOCAL app.tenant_id` request transaction wrapper (system-design 4.1), a reusable per-table policy pattern for all later migrations, and the sentinel platform tenant (data-conventions, Tenancy).
- The platform-scope cross-tenant database role (system-design 4.3), created now while the policy pattern is being defined.
- Tenant resolution middleware for both populations: `Host` header against `tenant_domains` for storefront-facing routes, `X-Tenant-Id` for admin routes (system-design 4.1, api-conventions Authentication and Tenant Context).
- Platform admin endpoints: create, read, and update for tenants (no tenant DELETE; the master plan's Stage 2 bullet records the same scope, deferring deletion to the offboarding design), full CRUD for their domains.
- The domain verification endpoint that Caddy on-demand TLS will consume (system-design 16.3).
- The audit seam: every use of the platform-scope role emits a structured log entry carrying the correlation ID, so Stage 3 can upgrade it to activity-log records without touching call sites.

Explicitly deferred:

- Authentication and authorization on all endpoints: Stage 3. In this stage the platform admin routes are wired behind a named auth middleware alias that is a pass-through placeholder; Stage 3 replaces the alias binding with Passport plus capability policies. See Risks.
- Membership validation of `X-Tenant-Id`: Stage 3 (the master plan states membership validation activates there). This stage validates header presence, UUID shape, and tenant existence only.
- Activity-log recording of cross-tenant platform role use: Stage 3 (the log table does not exist yet); this stage ships the structured-log seam.
- Recording `TenantCreated` and `DomainVerified` to the outbox: Stage 4 (the outbox does not exist yet). This stage ships the Actions without producers; the master plan's Stage 4 bullet attaches the Tenancy producers alongside the identity ones and adds the missing system-design 9.3 registry rows in the same change, mirroring the identity arrangement.
- Tenant branding assets as files (logo uploads through medialibrary): Stage 5c. `branding_settings` here is JSON configuration only.
- Tenant deletion, suspension, or any lifecycle beyond create and update: deferred until the design defines tenant offboarding (earliest Stage 12, compliance). The master plan's Stage 2 bullet records the same deferral, so the missing DELETE is a design decision, not unnoticed scope drop.
- Gateway configuration validation against real adapter capability flags: Stage 8a. Here `enabled_gateways` is stored and served, validated only as an array of non-empty strings.
- Caching of host-to-tenant resolution and of the Caddy verification endpoint: deferred until load pressure (Stage 10 or 12); this stage hits the database per resolution.

## Dependencies

Required from Stage 1 (blocking):

- RFC 9457 problem+json exception handler with the stable `code` registry: every error path in this stage's feature tests asserts problem documents.
- Isolation and Concurrency harnesses running against real PostgreSQL, replacing the current stubs in `tests/Isolation/SuiteHarnessTest.php` and `tests/Concurrency/SuiteHarnessTest.php`. Stage 2 is their first real consumer: the two-tenant fixture defined by Stage 1 gets its tenants from this stage's `tenants` table, so the fixture's final form lands here in coordination with the Stage 1 harness work.
- Contract suite wiring (OpenAPI conformance assertions in the feature test base, drift gate in CI).
- The Unit suite declared in `phpunit.xml`.

Forced by this stage (coordinate with the Stage 1 local-matrix task): the Feature suite can no longer run on SQLite. The tenancy migrations contain PostgreSQL-only DDL (`CREATE POLICY`, `ALTER TABLE ... FORCE ROW LEVEL SECURITY`) and the request wrapper executes `SET LOCAL`, which SQLite cannot parse. `phpunit.xml` currently defaults to `sqlite :memory:`; this stage includes the task that moves the default test connection to PostgreSQL for all suites, matching what CI already does for Isolation and Concurrency. Driver-conditional migrations that skip policies on SQLite are rejected: they would let the Feature suite exercise a database without the isolation guarantees the design says are the guarantee (ADR 003).

Consumed by later stages:

- Stage 3: memberships FK `tenant_id`, the `X-Tenant-Id` validation seam, the sentinel platform tenant for activity-log rows, the audit seam for platform role use.
- Stage 4: the `TenantCreated` and `DomainVerified` event classes and the Actions to attach producers to.
- Every stage from 3 on: the RLS policy helper for new tables, the two-tenant isolation fixture, the tenant context wrapper, the platform role.
- Stage 5a onward: `tenants.default_locale` and `supported_locales` for locale negotiation (system-design 12); `enabled_gateways` for checkout method offers in Stage 8a (system-design 7.2).
- Phase 7 of the roadmap: the domain verification endpoint gates Caddy on-demand TLS in production.

## Data model

All tables follow data-conventions: UUIDv7 `id` via `HasUuids`, UTC timestamps, snake_case, Laravel default index names except where the builder cannot express the index.

### RLS bootstrap (migration 1, before any tenant table)

Database roles, created idempotently (`DO` block guarding on `pg_roles`) because roles are cluster-level objects:

- `nodia_app`: `NOLOGIN` group role, `NOBYPASSRLS`. The default runtime posture; tenant policies apply to it.
- `nodia_platform`: `NOLOGIN` group role, `NOBYPASSRLS`. The cross-tenant platform-scope role of system-design 4.3; policies grant it cross-tenant reads everywhere and full writes on Tenancy-owned tables only (see Risks for the read-versus-write interpretation).

The application's login user is granted membership in both. Every request runs inside a transaction that begins with `SET LOCAL ROLE` (one of the two) and `SET LOCAL app.tenant_id = '<uuid>'`; both settings die with the transaction, which makes the pattern safe under Octane worker reuse (no static state, system-design 4.1) and under transaction-pooling (`SET LOCAL` never leaks across pooled transactions). Table grants are issued per table rather than through `ALTER DEFAULT PRIVILEGES`: default privileges attach only to objects created by the role they are declared for, so they would silently fail to cover an environment where migrations run as a different database user (a possibility the Risks section raises for managed PostgreSQL), leaving every query under `SET LOCAL ROLE` failing with permission errors. The RLS migration helper below issues the GRANTs alongside the policies, so grants travel with each table regardless of which user ran the migration.

A reusable helper (invoked by every tenant-scoped migration from now on, this stage defines it) applies to a table:

```sql
GRANT SELECT, INSERT, UPDATE, DELETE ON {t} TO nodia_app, nodia_platform;
ALTER TABLE {t} ENABLE ROW LEVEL SECURITY;
ALTER TABLE {t} FORCE ROW LEVEL SECURITY;
CREATE POLICY {t}_tenant_isolation ON {t}
  USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
  WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
CREATE POLICY {t}_platform_read ON {t} FOR SELECT TO nodia_platform USING (true);
```

Notes on the pattern:

- `FORCE` keeps even the table owner subject to policies, so migrations run inside the RLS regime as data-conventions requires; a data-backfilling migration sets its own `app.tenant_id` context instead of disabling policies.
- The two-argument `current_setting(..., true)` returns NULL when the setting is absent, so a query without tenant context matches zero rows (deny by default) instead of erroring, and `WITH CHECK` makes an INSERT or UPDATE that smuggles a foreign `tenant_id` fail outright.
- The platform read policy is additive and permissive; roles other than `nodia_platform` never match it.
- Tables needing platform writes (this stage: the two Tenancy tables) add `CREATE POLICY {t}_platform_write ON {t} FOR ALL TO nodia_platform USING (true) WITH CHECK (true)` explicitly; the helper takes an option, defaulting to read-only.

The sentinel platform tenant (data-conventions: platform-scope rows in shared tables use it, never NULL) is a fixed UUID published through `config('tenancy.platform_tenant_id')` and inserted by the `tenants` migration under `SET LOCAL ROLE nodia_platform` (under `FORCE` RLS the owner's bare INSERT matches no policy; see the `tenants` policy note).

### `tenants` (migration 2)

The tenant is the aggregate root of the Tenancy context (system-design 8.1, glossary).

| Column                     | Type        | Constraints                                                         |
| -------------------------- | ----------- | ------------------------------------------------------------------- |
| `id`                       | uuid        | PK, UUIDv7                                                          |
| `name`                     | string      | not null                                                            |
| `branding_settings`        | jsonb       | not null, default `{}`                                              |
| `default_locale`           | string      | not null                                                            |
| `supported_locales`        | jsonb       | not null (JSON array of locale strings)                             |
| `enabled_gateways`         | jsonb       | not null, default `[]` (ADR 008: any adapter the platform provides) |
| `payout_schedule`          | jsonb       | nullable (interpreted by Stage 8c)                                  |
| `created_at`, `updated_at` | timestamptz | not null                                                            |

Constraints and invariants: `default_locale` must be a member of `supported_locales`, enforced as an application invariant in `CreateTenant` and `UpdateBranding` (unit-tested), not as a check constraint, because JSON containment checks on locale arrays are fragile across writes from future stages.

RLS, in the same migration: `tenants` is the root, so it has no `tenant_id`; its isolation policy compares `id` to the setting instead. This is the one sanctioned deviation from the standard column and it is confined to this migration:

```sql
CREATE POLICY tenants_tenant_isolation ON tenants
  FOR SELECT
  USING (id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
```

plus the platform read and platform write policies, and this table's own GRANTs (it does not use the helper, so the grants are issued alongside these custom policies). The `FOR SELECT` is load-bearing: a policy without it defaults to `FOR ALL`, and PostgreSQL reuses the `USING` expression as the implicit `WITH CHECK`, which would let a tenant-scoped connection update or delete its own row and insert a row whose `id` equals the current setting. Declared `FOR SELECT`, `nodia_app`'s INSERT, UPDATE, and DELETE match no policy, giving it the intended SELECT-only semantics: tenants are created only through the platform role, and a tenant must never write tenant rows. Tenant self-service updates are out of scope for this stage; if a later stage adds them, it adds an UPDATE policy then. Because the table is under `FORCE ROW LEVEL SECURITY` and the owner's bare INSERT matches no policy, the sentinel-tenant insert in this migration must run under `SET LOCAL ROLE nodia_platform` so it passes through the platform write policy.

### `tenant_domains` (migration 3)

The first tenant-scoped child table; the isolation suite's two-tenant fixture runs against it first, per the master plan.

| Column                     | Type        | Constraints                                                   |
| -------------------------- | ----------- | ------------------------------------------------------------- |
| `id`                       | uuid        | PK, UUIDv7                                                    |
| `tenant_id`                | uuid        | not null, FK `tenants(id)`                                    |
| `domain`                   | string      | not null, stored lowercase (normalized in the Action), unique |
| `is_primary`               | boolean     | not null, default false                                       |
| `created_at`, `updated_at` | timestamptz | not null                                                      |

Indexes:

- Unique index on `domain` (a domain resolves to exactly one tenant, platform-wide).
- Index on `tenant_id` (RLS predicate and FK lookups).
- Partial unique index `tenant_domains_primary_per_tenant_idx` on `(tenant_id) WHERE is_primary` (custom name per data-conventions because the builder cannot express partial indexes): at most one primary domain per tenant, enforced by the database so the make-primary transition cannot race into two primaries.

RLS, in the same migration, via the helper: standard `tenant_id` isolation policy with `WITH CHECK`, platform read policy, platform write policy (domain CRUD is platform-admin surface this stage).

Resolution note: the storefront middleware looks up `Host` in `tenant_domains` before any tenant context exists, and the Caddy verification endpoint has the same shape. Running these anonymous lookups under `nodia_platform` would contradict system-design 4.3, which reserves that role for platform-scope staff with every use recorded in the activity log; neither condition holds for anonymous storefront traffic, and sampling audit entries would not satisfy "every use". How these lookups get their cross-tenant read is an open question that must be settled before Slice 6 (see Open questions); candidates are a dedicated narrow resolver role or an additional permissive SELECT policy on `tenant_domains` scoped to domain resolution.

## Domain events

Produced: none recorded in this stage, because the outbox is Stage 4. The event classes ship now in `app/Tenancy/Events/` per system-design 3.2:

- `TenantCreated`: envelope `tenant_id` is the sentinel platform tenant, per event-conventions Envelope (platform-scope events use the sentinel; tenant creation runs in a platform-posture transaction whose `app.tenant_id` is the sentinel, so this is also what lets the Stage 4 outbox insert pass the outbox table's `WITH CHECK`), `aggregate_type` `tenant`, `aggregate_id` the created tenant's id; payload carries `tenant_id`, `name`, `default_locale` (identifiers and facts, not a snapshot, per event-conventions Payloads).
- `DomainVerified`: envelope `tenant_id` the owning tenant, `aggregate_type` `tenant_domain`; payload carries `tenant_domain_id`, `tenant_id`, `domain`. Its precise trigger is an open question (see below) and must be resolved before Stage 4 attaches the producer.

Stage 4 attaches the recording calls inside the producing Actions' transactions (event-conventions Envelope: same transaction, without exception). Idempotence notes for future consumers: both events are facts keyed by event ID; `TenantCreated` consumers (none planned before Reporting) must be idempotent by event ID per event-conventions Delivery and Consumption.

Consumed: none. The Tenancy context subscribes to nothing in this stage.

Registry note: system-design 9.3 does not list the Tenancy events even though 3.2 names the classes. Per event-conventions Naming and Registry, the change that first records them (Stage 4) must add them to the 9.3 catalog in the same change. Flagged in Open questions.

## Endpoints

All routes under `/v1`, snake_case JSON, laravel-data request and response objects as the source of truth (ADR 013), every error an RFC 9457 problem document with a stable `code`, every endpoint's OpenAPI path merged in `docs/openapi/openapi.yaml` before the slice is done. New TypeScript is regenerated via `composer types:generate` and committed.

Route groups introduced by this stage (registered by `TenancyServiceProvider`):

- Platform group: platform role posture, `app.tenant_id` set to the sentinel platform tenant, placeholder auth alias (Stage 3 hardens it).
- Admin group: `X-Tenant-Id` resolution middleware, `nodia_app` posture. Empty of routes in this stage; Stage 3 onward populates it.
- Storefront group: `Host` resolution middleware, `nodia_app` posture. Empty of routes in this stage; Stage 5a populates it.

### Platform admin CRUD

| Method and path                             | Request Data                                                                                                                          | Response Data                                                                | Errors (`code`)                                                                             |
| ------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| `POST /v1/tenants`                          | `CreateTenantData` (name, default_locale, supported_locales, branding_settings?, enabled_gateways?, payout_schedule?)                 | 201 `TenantData`                                                             | 422 `request.validation_failed`; 422 `default_locale_not_supported`                         |
| `GET /v1/tenants`                           | query-builder allowlist: `filter[name]`, `sort` in (`name`, `created_at`, `-name`, `-created_at`)                                     | 200 paginator envelope of `TenantData` (page pagination, bounded collection) | 400 `invalid_query_parameter` (unknown filter or sort rejected, not ignored)                |
| `GET /v1/tenants/{tenant}`                  | none                                                                                                                                  | 200 `TenantData`                                                             | 404 `tenant_not_found`                                                                      |
| `PATCH /v1/tenants/{tenant}`                | `UpdateTenantData` (all fields optional; branding and locale fields route to `UpdateBranding`, gateway fields to `ConfigureGateways`) | 200 `TenantData`                                                             | 404 `tenant_not_found`; 422 `request.validation_failed`; 422 `default_locale_not_supported` |
| `POST /v1/tenants/{tenant}/domains`         | `RegisterTenantDomainData` (domain, is_primary?)                                                                                      | 201 `TenantDomainData`                                                       | 404 `tenant_not_found`; 409 `domain_already_registered`; 422 `request.validation_failed`    |
| `GET /v1/tenants/{tenant}/domains`          | none                                                                                                                                  | 200 paginator envelope of `TenantDomainData`                                 | 404 `tenant_not_found`                                                                      |
| `PATCH /v1/tenant-domains/{tenant_domain}`  | `UpdateTenantDomainData` (is_primary only)                                                                                            | 200 `TenantDomainData`                                                       | 404 `tenant_domain_not_found`                                                               |
| `DELETE /v1/tenant-domains/{tenant_domain}` | none                                                                                                                                  | 204                                                                          | 404 `tenant_domain_not_found`; 409 `tenant_domain_is_primary` (demote first)                |

Domain item operations live at top-level `/v1/tenant-domains` because `/v1/tenants/{tenant}/domains/{domain}` would exceed the one-level nesting rule (api-conventions URLs).

The make-primary transition touches two rows (demote the current primary, promote the target). It runs in a single transaction; the partial unique index is the invariant guard, so a concurrent double-promotion fails on the index rather than succeeding via read-then-write. The promote step is a conditional UPDATE (`SET is_primary = true WHERE id = ? AND is_primary = false`) checked by affected-row count so a replayed request is a no-op, not an error.

### Domain verification (Caddy on-demand TLS ask endpoint)

| Method and path                                | Request               | Response                                                                                              | Errors (`code`)                                                                       |
| ---------------------------------------------- | --------------------- | ----------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| `GET /v1/internal/domain-verification?domain=` | query string `domain` | 204 when the normalized domain exists in `tenant_domains` (system-design 16.3: existence is the gate) | 404 `unknown_domain`; 422 `request.validation_failed` (missing or malformed `domain`) |

Unauthenticated by design (Caddy calls it during the TLS handshake) but network-internal: infra must never route it through the public edge. Its read posture follows the domain-resolution open question (resolver role or resolution policy, not `nodia_platform` unless system-design 4.3 is amended). Contract still ships in OpenAPI like every endpoint.

### Resolution middleware error surface (applies to admin and storefront groups)

| Condition                                       | Status and `code`                                                                                                                                                  |
| ----------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Storefront `Host` not found in `tenant_domains` | 404 `unknown_host`                                                                                                                                                 |
| Admin request missing `X-Tenant-Id`             | 400 `missing_tenant_header`                                                                                                                                        |
| `X-Tenant-Id` not a UUID                        | 400 `invalid_tenant_header`                                                                                                                                        |
| `X-Tenant-Id` references no tenant              | 403 `tenant_access_denied` (deliberately indistinguishable from the Stage 3 membership denial, so activating membership checks later does not change the contract) |

### laravel-data objects

`TenantData`, `CreateTenantData`, `UpdateTenantData`, `BrandingSettingsData` (typed optional fields, initially `primary_color`, `logo_url` placeholder until medialibrary in 5c), `TenantDomainData`, `RegisterTenantDomainData`, `UpdateTenantDomainData`. All in `app/Tenancy/Data/`, all exported to `packages/api-client/src/generated`.

### Actions

Per system-design 3.2: `CreateTenant`, `UpdateBranding`, `ConfigureGateways`, `RegisterDomain`, plus `MakeDomainPrimary` and `RemoveDomain` (new, following the same single-purpose pattern). Controllers stay thin; invariants live in Actions and are unit-tested.

## TDD sequencing

Ordered slices, each through the full double loop of the master plan's Method section: outside feature test first, contract second, inside unit tests third, green, refactor, `composer types:generate`, commit scoped `tenancy` (bootstrap-only slices may scope `support`).

### Slice 1: RLS bootstrap and tenant context wrapper

Failing tests first:

- Isolation: connecting with `SET LOCAL ROLE nodia_app` and no `app.tenant_id`, a probe query against a policy-bearing table returns zero rows (deny by default). Written against a throwaway probe table created inside the test until Slice 2 provides `tenants`.
- Isolation: with the role set and `app.tenant_id` set to tenant A, rows of tenant B are invisible to SELECT, unaffected by UPDATE and DELETE (affected-row count zero), and an INSERT carrying tenant B's `tenant_id` fails the `WITH CHECK`.
- Isolation: under `SET LOCAL ROLE nodia_platform`, both tenants' rows are readable.
- Unit: the tenant context wrapper opens a transaction, applies both `SET LOCAL`s, exposes the current tenant id through the request-scoped container, and leaves no state behind after commit or rollback (Octane discipline, system-design 4.1).
- Unit: the wrapper rejects a non-UUID tenant id before any SQL executes.

Implementation: roles-and-defaults migration, RLS migration helper, `Support` tenant context holder and wrapper, `pgsql` as the default test connection in `phpunit.xml`.

### Slice 2: `tenants` table and sentinel platform tenant

Failing tests first:

- Isolation: under tenant A's context, `SELECT * FROM tenants` returns exactly A's row; the sentinel platform tenant and tenant B are invisible; as `nodia_app`, INSERT is rejected and UPDATE and DELETE affect zero rows, including against A's own row (the `FOR SELECT` isolation policy grants no writes).
- Isolation: `nodia_platform` reads all tenant rows and can INSERT.
- Unit: `CreateTenant` rejects a `default_locale` absent from `supported_locales`; normalizes nothing else; returns `TenantData`.
- Feature: none (no endpoint yet).

Implementation: `tenants` migration with policies and sentinel insert, `Tenant` model (`HasUuids`), factory, `CreateTenant` Action, `TenantCreated` event class (no producer).

### Slice 3: `tenant_domains` and the two-tenant isolation fixture

Failing tests first:

- Isolation: the reusable two-tenant fixture (tenant A and B, one domain each) proves the full deny matrix on `tenant_domains`: cross-tenant SELECT empty, UPDATE and DELETE zero rows, INSERT with foreign `tenant_id` rejected by `WITH CHECK`. This fixture becomes the shared harness every later stage's isolation tests build on.
- Isolation: a raw `DB::select` that deliberately omits any Eloquent scope still cannot see tenant B's rows (RLS is the guarantee, not application scoping, ADR 003).
- Unit: `RegisterDomain` lowercases the domain and rejects malformed hostnames.

Implementation: `tenant_domains` migration via the helper (policies plus partial unique index), `TenantDomain` model, factory, `RegisterDomain` Action, `DomainVerified` event class (no producer).

### Slice 4: platform tenant endpoints (create, read, update)

Failing tests first:

- Feature: `POST /v1/tenants` returns 201 with the `TenantData` wire shape (snake_case, ISO 8601 UTC timestamps); 422 problem documents with `request.validation_failed` and `default_locale_not_supported`, including the `errors` map.
- Feature: `GET /v1/tenants` paginator envelope; unknown `filter[...]` or `sort` returns 400 `invalid_query_parameter`.
- Feature: `GET` and `PATCH /v1/tenants/{tenant}` happy paths and 404 `tenant_not_found` as a problem document.
- Contract: all four paths added to `docs/openapi/openapi.yaml`; conformance assertions run inside the feature tests.
- Unit: `UpdateBranding` and `ConfigureGateways` invariants (locale membership check re-applied on update; `enabled_gateways` accepts only non-empty strings).
- Isolation: the endpoints, exercised over HTTP under the platform group, cannot be coerced into a tenant-scoped posture (a request with a forged tenant context still behaves platform-scoped only via the platform route group, never via header injection on these routes).

### Slice 5: domain endpoints

Failing tests first:

- Feature: register, list, make-primary, delete, including 409 `domain_already_registered` and 409 `tenant_domain_is_primary` problem documents; domain normalization visible on the wire (mixed-case input, lowercase output).
- Contract: the four paths merged into the OpenAPI document.
- Concurrency: parallel registration of the same domain for two tenants yields exactly one success and one 409 (unique index, no read-then-write); parallel make-primary requests for two different domains of one tenant leave exactly one `is_primary` row (partial unique index guard); asserted by row counts against real PostgreSQL.
- Isolation: two-tenant fixture over HTTP, tenant A's admin listing can never include B's domains once the admin group exists (deferred assertion wired now, activated in Slice 6).

### Slice 6: tenant resolution middleware, both populations

Failing tests first:

- Feature (the resolution matrix the master plan names, one data-driven test): platform subdomain resolves, custom domain resolves, unknown host yields 404 `unknown_host`, missing header yields 400 `missing_tenant_header`, malformed header yields 400 `invalid_tenant_header`, unknown tenant id yields 403 `tenant_access_denied`. The matrix runs against a test-only probe route registered inside each group for the test, since the groups ship empty of production routes.
- Feature: a resolved request observes `SET LOCAL app.tenant_id` (probe route returns `current_setting('app.tenant_id')`), and two sequential requests for different tenants on the same worker never leak context (Octane simulation: same process, sequential kernel handles).
- Isolation: through the storefront probe route, tenant A's host sees only A's rows of `tenant_domains`.
- Unit: host normalization (port stripping, lowercase) before lookup.

### Slice 7: domain verification endpoint and the audit seam

Failing tests first:

- Feature: 204 for a registered domain, 404 `unknown_domain` problem for an unknown one, 422 for a missing parameter; case-insensitive matching.
- Contract: path merged.
- Feature: any request executing under `nodia_platform` (the platform CRUD group, plus this endpoint and storefront host resolution only if the domain-resolution open question leaves them on the platform role) emits the structured audit log entry with correlation ID; asserted via the log fake. This is the "flagged for audit once the activity log exists" seam from the stage exit line.

## Task breakdown

Ordered; each task is a small PR-sized unit, independently mergeable unless noted. Commit scope `tenancy` except tasks 1 and 2 (`support`) and task 3 (`ci` or omitted).

1. Mark Stage 2 "In progress" in the master plan status table; RLS roles migration (roles and role memberships); the migration RLS helper (policies plus per-table GRANTs).
2. Tenant context holder and `SET LOCAL` transaction wrapper in `Support`, with unit tests; wire nothing yet.
3. Move the default test connection in `phpunit.xml` to PostgreSQL; align local matrix with CI (coordinated with the Stage 1 owner if that task is still open; merge together if needed).
4. `tenants` migration with policies and sentinel tenant, model, factory, isolation tests (Slice 2).
5. `tenant_domains` migration with policies and indexes, model, factory, the reusable two-tenant isolation fixture, isolation tests (Slice 3).
6. `CreateTenant`, `UpdateBranding`, `ConfigureGateways` Actions plus Data objects, unit tests; `TenantCreated` event class.
7. `RegisterDomain`, `MakeDomainPrimary`, `RemoveDomain` Actions plus Data objects, unit tests; `DomainVerified` event class.
8. `TenancyServiceProvider` with the three route groups and the placeholder platform auth alias.
9. Tenant create, read, and update endpoints, feature and contract tests, OpenAPI paths, regenerated TypeScript (Slice 4). Depends on 6 and 8.
10. Domain endpoints, feature, contract, and concurrency tests, OpenAPI paths, regenerated TypeScript (Slice 5). Depends on 7 and 8.
11. Resolution middleware for both groups, resolution matrix, Octane no-leak test (Slice 6). Depends on 8.
12. Domain verification endpoint plus contract (Slice 7 first half). Depends on 5.
13. Platform-role audit log seam and its tests (Slice 7 second half); document the seam for Stage 3 pickup.
14. Stage close: master plan status table updated to done; confirm the `DomainVerified` trigger decision (open questions below) is recorded, so Stage 4 attaches the producer to a settled Action; roadmap Implementation Status untouched unless Phase 1 work consumed this (roadmap is product sequencing; note only if asked).

## Exit criteria

The master plan's exit line, "cross-tenant access provably fails; platform role reads provably succeed and are flagged for audit once the activity log exists", expands to these individually testable checks:

1. Isolation suite: under tenant A's context, tenant B's rows in `tenants` and `tenant_domains` are invisible to SELECT, and UPDATE and DELETE against them report zero affected rows.
2. Isolation suite: INSERT or UPDATE writing a foreign `tenant_id` fails the policy `WITH CHECK`, not silently succeeds.
3. Isolation suite: a raw SQL query bypassing all Eloquent scoping is still isolated (the database, not the application, is the guarantee).
4. Isolation suite: a query with no tenant context set returns zero rows from policy-bearing tables (deny by default), and a malformed tenant id is rejected before SQL executes.
5. Isolation suite: `nodia_platform` reads rows of all tenants; `nodia_app` cannot insert, update, or delete `tenants` rows, its own included.
6. Both tenancy migrations contain their RLS policies in the same file that creates the table (reviewable statically; the isolation suite fails without them, satisfying the data-conventions merge gate).
7. Feature suite: the resolution matrix passes for subdomain, custom domain, unknown host, missing header, malformed header, and unknown tenant, each failure a problem document with its stable `code`.
8. Feature suite: sequential requests for different tenants on one worker process never observe each other's context.
9. Feature suite: every platform-role execution path emits the structured audit entry with the request correlation ID (the Stage 3 activity-log upgrade point).
10. Concurrency suite: duplicate-domain registration and double-make-primary races resolve to exactly one winner via database constraints and conditional UPDATEs.
11. Contract: every endpoint above is present in `docs/openapi/openapi.yaml`, conformance assertions pass, generated TypeScript is committed with zero drift.
12. Architecture suite green: no other context imports `App\Tenancy\Models`; Larastan and Pint clean; all suites run in `composer test` against PostgreSQL.

## Risks and open questions

Risks:

- Unauthenticated platform endpoints until Stage 3. The tenant CRUD surface ships behind a placeholder auth alias; it must not be deployed to any shared environment before Stage 3 replaces the alias. Mitigation: the alias denies by default outside the `testing` and `local` environments, so a premature deploy returns 401 rather than exposing tenant CRUD.
- Platform role write scope. System-design 4.3 describes the platform role as allowing cross-tenant reads; tenant CRUD requires platform writes on Tenancy tables. This plan grants platform write policies on `tenants` and `tenant_domains` only, as the narrowest reading that makes tenant lifecycle possible. If that is wrong, the alternative is a third role; decide before Slice 4 merges.
- Whole-request transactions (system-design 4.1) hold a connection and any acquired locks for the request's full duration. Fine at this stage's traffic; may need revisiting for slow endpoints before Stage 10 load tests. Recorded here so it is a known posture, not an accident.
- Storefront host resolution reads `tenant_domains` before any tenant context exists, on every request, which costs a query. The cache (with invalidation on domain CRUD) is deferred to a later stage; revisit if Stage 5a storefront tests show latency. Which role performs the read is the domain-resolution open question below; sampling or aggregating audit entries is not on its own an acceptable answer, because system-design 4.3 requires every platform-role use to be recorded.
- SQLite-to-PostgreSQL test migration (task 3) can surprise existing Stage 1 Feature tests (transaction semantics, `:memory:` speed loss). Budget for flakiness triage in that task, not in the slices that depend on it.
- Role creation requires a privileged migration user (`CREATEROLE`). Dev and CI use the superuser Postgres container so this is free; managed PostgreSQL in production may need the roles pre-provisioned by infra. Document in the migration's comment block.

Open questions:

- Anonymous domain resolution versus system-design 4.3: storefront `Host` lookup and the Caddy verification endpoint need a cross-tenant read before any tenant context exists, but 4.3 states the cross-tenant role is only assumable by platform-scope staff and that every use is recorded in the activity log; anonymous traffic satisfies neither. Options: a dedicated narrow resolver role limited to SELECT on `tenant_domains`, an additional permissive SELECT policy on `tenant_domains` scoped to domain resolution, or a design-owner amendment to 4.3 exempting infrastructure reads (the Stage 4 plan raises the same tension for queue workers). Must be decided before Slice 6 merges; until then the plan does not treat sampling as a resolution.
- `DomainVerified` semantics: the ERD (system-design 8.1) has no verification state on `tenant_domains`, and 16.3 gates TLS issuance on existence alone. Does `DomainVerified` mean "registered", "first confirmed by the ask endpoint", or does the design need a `verified_at` column and a DNS-challenge flow? This decision is owned by this stage and must be made before task 14 closes it, because the answer may require a `verified_at` column or a verification flow this stage would otherwise not ship; Stage 4 attaches the producer only to the Action the decision settles, never inheriting the open question. This stage ships no verification state unless the decision demands it.
- Tenancy events are absent from the system-design 9.3 registry while present in 3.2. Proposed resolution: Stage 4's change adding the producers also adds a Tenancy row to 9.3, per event-conventions. Needs design-owner sign-off.
- `payout_schedule` shape is uninterpreted until Stage 8c. Stored as opaque JSON now; Stage 8c defines and validates the schema. Risk of garbage accumulating in the column is accepted for two stages.
- Subdomain provisioning: the design implies platform subdomains exist (`system-design 4.1: custom domains and platform subdomains`) but nothing specifies whether a `{slug}.nodia.example` row is auto-created on tenant creation. This plan treats all domains, including platform subdomains, as explicit `tenant_domains` rows registered via the API; if auto-provisioning is wanted, it is a `CreateTenant` follow-up that can land additively.
- Whether `GET /v1/tenants` needs cursor pagination at platform scale. Treated as a bounded collection (page pagination) per api-conventions; flip to cursor pagination later if tenant count grows, which is additive.
