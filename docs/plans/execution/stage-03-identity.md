# Execution Journal: Stage 3, Identity, AuthN, AuthZ

Durable record of execution runs for [stage-03-identity.md](../stage-03-identity.md). Append a new run header per run; never rewrite prior entries.

## Run: 2026-07-09

- Stage: 3, Identity, AuthN, AuthZ
- Date: 2026-07-09
- Branch: `feat/api-implementation`
- Base commit: `d3a95016b94f3bd046e82e12201ddade5cbac913`

Verified starting state: Stages 1 and 2 are done (status table plus commit history through d3a9501). Nothing from Stage 3 exists: there is no `app/Identity` directory, `composer.json` has neither `laravel/passport` nor `spatie/laravel-activitylog`, `database/migrations` holds only the Laravel defaults and the four Stage 2 tenancy migrations, and the two Stage 2 deferral points are still in place exactly as the stage plan expects (`PlatformAuthPlaceholder` behind the `auth.platform` alias, `ResolveTenantFromHeader` validating tenant existence only with the membership check deferred to this stage). The master plan status table said "Not started"; this run flips it to "In progress".

### Task checklist

- [ ] task-01: Passport install, uuid oauth migrations, staff and customer guards and providers, client seeding, explicit lifetimes (plan task 1)
- [ ] task-02: Slice 1: staff token issuance, JWT claims, `GET /v1/me` (empty memberships), contract paths (plan task 2)
- [ ] task-03: Slice 2: refresh rotation, reuse detection with family revocation, logout, parallel-refresh concurrency test (plan task 3)
- [ ] task-04: `memberships` and `roles` migrations with RLS, `Capability` enum, template seeder under `nodia_platform`, isolation tests first (plan task 4)
- [ ] task-05: Slice 3: membership validation in tenant resolution, platform-scope fallback, resolution matrix, `/v1/me` memberships (plan task 5)
- [ ] task-06: Slice 4a: capability Gate and Policy layer, authorization matrix harness (plan task 6)
- [ ] task-07: Stage 2 alias rebinding: Passport bearer plus `tenants.manage` on the platform route group (plan task 7)
- [ ] task-08: Slice 4b: role endpoints and `GET /v1/capabilities` (plan task 8)
- [ ] task-09: Slice 4c: membership endpoints, `InviteUser` and `AssignRole` Actions, invitation acceptance (plan task 9)
- [ ] task-10: users MFA columns and `mfa_recovery_codes` migration, failing unit and recovery-code concurrency tests first (plan task 10)
- [ ] task-11: Slice 5: MFA endpoints, token-endpoint challenge, enforcement middleware, denial paths (plan task 11)
- [ ] task-12: `customers` migration with RLS, isolation tests first (plan task 12)
- [ ] task-13: Slice 6: customer endpoints, `RegisterCustomer` and `ClaimGuestAccount`, customer guard, tenant claim validation, guest-creation concurrency test (plan task 13)
- [ ] task-14: Adjusted activitylog install: uuid keys, non-null `tenant_id`, append-only RLS, isolation tests first (plan task 14)
- [ ] task-15: Slice 7: audit recording on logins, mutations, and platform-role use, data-driven route coverage (plan task 15)
- [ ] task-16: Slice 8: staff password reset with single-use token consumption guard, concurrency test first, family revocation (plan task 16)
- [ ] task-17: Closeout: OpenAPI review, types drift check, master plan status flip (plan task 17)

### Review rounds

### Decisions and deviations
