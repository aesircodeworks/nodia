# Stage 3: Identity, AuthN, AuthZ

Implementation plan for Stage 3 of the [API implementation plan](../api-implementation-plan.md). Goal per the master plan: both identity populations can authenticate, and every subsequent endpoint has a real authorization layer to test against. The TDD loop and contract pipeline sections of the master plan are binding for every slice below.

## Scope and non-goals

### Delivers

- Laravel Passport OAuth 2.0 for both identity populations: short-lived JWT access tokens (15 minutes, set explicitly via `Passport::tokensExpireIn()`, never Passport defaults, per api-conventions), rotating refresh tokens with reuse detection, server-side revocation, and identity-type plus tenant claims (system-design 5.4).
- Staff password reset for forgotten credentials: an enumeration-safe request endpoint (always 202, mirroring the customer claim flow), single-use time-limited reset tokens with TTL driven through the Stage 1 time control, a confirm endpoint that sets the new password, and revocation of every live access and refresh token for the user on reset. Invitation acceptance covers only first credentials; this closes the gap.
- `users` extensions (MFA columns), `memberships`, and custom RBAC: `roles` with global templates and per-tenant custom roles carrying flat capability sets, evaluated through Gates and Policies as capability plus tenant context, never role names (system-design 5.3, ADR 012).
- Activation of membership validation in the Stage 2 tenant resolution middleware: `X-Tenant-Id` is now validated against the authenticated user's memberships on every staff request (system-design 4.1, api-conventions).
- Replacement of Stage 2's placeholder platform auth alias: the tenant CRUD surface moves behind Passport bearer authentication, the `tenants.manage` capability, and platform-scope MFA enforcement, closing the deferral recorded in the Stage 2 plan.
- MFA (TOTP) enrollment, confirmation, recovery codes, and enforcement for platform-scope staff and roles that include payout or refund capabilities (system-design 5.1). The capabilities it guards arrive in Stage 8; the mechanism and its denial paths are fully testable now.
- `customers`: tenant-scoped attendee identities with per-tenant email uniqueness, nullable password for guest checkout, and the claim-by-email-verification flow (system-design 5.2, ADR 007).
- Activity log: activitylog's published migration adjusted for UUID keys and non-null `tenant_id` under RLS (system-design 8.1, data-conventions), recording staff logins, tenant-scoped mutations on the endpoints that exist so far, and cross-tenant platform role use (closing the audit flag left open by Stage 2's exit line).
- Identity Actions per system-design 3.2: `InviteUser`, `AssignRole`, `RegisterCustomer`, `ClaimGuestAccount`, structured so Stage 4 can attach outbox recording calls without reshaping them.

### Non-goals, deferred

- Recording `UserInvited`, `UserRoleChanged`, `CustomerRegistered` to the outbox: Stage 4 attaches the producers (master plan Stage 3 and Stage 4 both state this split).
- Transactional email through the outbox and Resend consumers: Stage 8a. Stage 3 sends its two verification emails (invitation acceptance, customer claim) synchronously through Laravel's mailer contract and tests them with mail fakes; no queue dependency.
- `AnonymizeCustomer`, data subject export, retention: Stage 12 (system-design 14.3). The `customers.anonymized_at` column ships now so the schema never needs a later alteration.
- Activity log read endpoints and audit views: recording only in this stage; the read surface belongs to the reporting and operations stages (roadmap Phase 6).
- Tiered rate limiting: Stage 10 and Stage 12. A basic fixed per-IP throttle on the token endpoints ships here as security hygiene only.
- Payout and refund capability enforcement on real endpoints: the capability names exist in the registry now; the endpoints they guard arrive in Stage 8.
- Check-in role event scoping: Stage 9 consumes the RBAC layer built here.
- Third-party OAuth clients, authorization code flow, scopes beyond first-party needs: post-launch (ADR 011 keeps the door open; nothing here closes it).

## Dependencies

### Requires from earlier stages

- Stage 1: RFC 9457 problem+json exception handler with the stable `code` registry, Contract suite wiring with OpenAPI conformance, real Isolation and Concurrency harnesses (two-tenant fixture, parallel process runner against real PostgreSQL), and time control for TTL behavior (token lifetimes, verification token expiry, TOTP windows).
- Stage 2: `tenants` and `tenant_domains`, the `SET LOCAL app.tenant_id` transaction wrapper and per-table RLS policy pattern, the sentinel platform tenant, the platform-scope cross-tenant database role (system-design 4.3), and the tenant resolution middleware for both populations (Host header for storefront, `X-Tenant-Id` for admin; membership validation was stubbed to activate in this stage).

### Consumed by later stages

- Stage 4 attaches outbox producers to `InviteUser`, `AssignRole` (role change), and `RegisterCustomer`.
- Stage 5 onward: every admin endpoint authorizes through the capability Gate and Policies built here; every storefront endpoint relies on customer token tenant claims.
- Stage 7: order endpoints authenticate customers and staff; guest checkout uses `RegisterCustomer` with no password.
- Stage 8: `orders.refund` and `payouts.view` capability checks and the MFA enforcement they trigger become load-bearing.
- Stage 9: check-in devices authenticate as staff with a check-in role (system-design 11).
- Stage 12: anonymization operates on the `customers` columns defined here; the security sweep asserts the authorization matrix is complete.

## Data model

All tables use UUIDv7 primary keys via `HasUuids` (data-conventions). Every tenant-scoped table ships its RLS policy in the same migration, following the Stage 2 policy pattern comparing `tenant_id` to `current_setting('app.tenant_id')::uuid`. Timestamps are UTC.

### users (alter existing table; platform-global, no tenant_id, no RLS)

Staff are a platform-level identity gaining tenant access only through memberships (system-design 5.1, ADR 007), so `users` is deliberately not tenant-scoped and carries no RLS policy; the data-conventions RLS rule applies to tenant-scoped tables only. New migration adds:

- `mfa_enabled` boolean not null default false (system-design 8.1)
- `mfa_secret` text nullable, encrypted cast
- `mfa_confirmed_at` timestamp nullable

### mfa_recovery_codes (new; platform-global, no RLS)

Single-use recovery codes need atomic consumption, which a JSON column cannot express as a conditional UPDATE checked by affected-row count, so they get their own rows.

- `id` uuid pk, `user_id` fk users not null, `code_hash` string not null, `used_at` timestamp nullable, timestamps
- Index on `user_id`. Consumption is `UPDATE ... SET used_at = now() WHERE user_id = ? AND code_hash = ? AND used_at IS NULL`, checked by affected-row count.

### memberships (new; tenant-scoped, RLS in same migration)

- `id` uuid pk, `user_id` fk users not null, `tenant_id` fk tenants not null, `role_id` fk roles not null, `scope` string not null (enum-backed: `tenant`, `platform`), timestamps
- Unique `(user_id, tenant_id)`; index on `tenant_id`
- Platform-scope memberships use the sentinel platform tenant as `tenant_id`, never NULL (data-conventions); the application invariant `scope = platform` implies sentinel tenant is enforced in the Action and asserted by a unit test.
- RLS: standard single-table policy. Membership validation in the middleware runs inside a transaction where `SET LOCAL app.tenant_id` is set to the asserted tenant, so an empty membership lookup means no access; the platform-scope fallback looks up under the sentinel platform tenant, and any subsequent cross-tenant access assumes the platform database role and is activity-logged (system-design 4.1, 4.3).
- A second permissive SELECT policy, `memberships_self_read`, matches `user_id = current_setting('app.user_id')::uuid`, where `app.user_id` is a second `SET LOCAL` setting the auth layer sets to the authenticated subject on every staff request. This is the read path for `GET /v1/me`, which takes only a bearer and asserts no tenant: it lists all of the caller's memberships without a tenant header and without touching the platform database role (which system-design 4.3 restricts to platform-scope staff with mandatory activity logging). Created in the same migration; slice 3 isolation tests prove it exposes only the caller's own rows.

### roles (new; RLS in same migration, template-aware policy)

- `id` uuid pk, `tenant_id` fk tenants nullable, `name` string not null, `capabilities` jsonb not null default `[]`, timestamps
- `tenant_id` NULL means a global template role maintained by the platform (Owner, Event Manager, Box Office, Finance, Check-in Agent); a set `tenant_id` means a tenant custom role (system-design 5.3). This is a deliberate, documented exception to the non-null `tenant_id` rule: templates must be readable in every tenant context, which a sentinel-tenant row cannot provide under a single-tenant RLS predicate. See risks.
- Unique `(tenant_id, name)` plus a partial unique index on `name` where `tenant_id IS NULL` (Postgres treats NULLs as distinct), named `roles_template_name_idx` per data-conventions custom-name format.
- RLS: SELECT policy `tenant_id IS NULL OR tenant_id = current_setting('app.tenant_id')::uuid`; INSERT/UPDATE/DELETE policies require `tenant_id = current_setting('app.tenant_id')::uuid`, so tenants can never mutate templates at the database level. Template maintenance requires an explicit write policy, since a NULL `tenant_id` can never satisfy the tenant predicate and Stage 2 granted platform writes on Tenancy tables only: the same migration creates `roles_platform_write FOR ALL TO nodia_platform USING (true) WITH CHECK (true)`, and the template seeder runs under `nodia_platform` (migrations never disable policies per data-conventions). Slice 3 isolation tests prove templates are writable via the platform role and via nothing else.
- Capabilities are a flat set of named capability strings; the authoritative list is a PHP enum (`Identity/Capability.php`). Initial registry at minimum: `roles.manage`, `memberships.manage`, `tenants.manage`, `events.view`, `events.manage`, `events.publish`, `orders.view`, `orders.refund`, `payouts.view`, `checkin.scan` (names from system-design 5.3 examples; later stages extend the enum, never repurpose entries).

### customers (new; tenant-scoped, RLS in same migration)

- `id` uuid pk, `tenant_id` fk tenants not null, `email` string not null, `name` string not null, `password` string nullable, `locale` string nullable (falls back to tenant default per system-design 12), `email_verified_at` timestamp nullable, `anonymized_at` timestamp nullable, timestamps (system-design 8.1)
- Unique `(tenant_id, email)`: the same email holds independent accounts under different tenants (system-design 5.2)
- RLS: standard single-table policy.

### activity_log (new; tenant-scoped, RLS in same migration)

spatie/laravel-activitylog's published migration adjusted before use (data-conventions, ADR 014):

- `id` uuid pk (replacing bigint), `tenant_id` fk tenants not null, `log_name`, `description`, `subject_type` string with `subject_id` uuid, `causer_type` string with `causer_id` uuid, `properties` jsonb, `event` string, `batch_uuid` uuid, `created_at` (system-design 8.1)
- Platform-scope entries (cross-tenant platform role use, staff logins outside a tenant context) use the sentinel platform tenant, never NULL (data-conventions).
- Indexes on `(tenant_id, created_at)`, `(subject_type, subject_id)`, `(causer_type, causer_id)`; the log is a high-volume collection so its future read endpoint must cursor-paginate (api-conventions), hence the `created_at` composite now.
- RLS: SELECT and INSERT policies only; no UPDATE or DELETE policy exists, so the application role cannot modify rows, making append-only a database guarantee (system-design 14.2).

### Passport oauth tables (new; platform infrastructure, no tenant_id, no RLS)

Passport's published migrations, adjusted so `user_id` columns are uuid. Token and client rows are authentication infrastructure keyed to identities, not tenant-scoped domain data; customer tokens carry the tenant in their claims, and the customer row itself is under RLS. Two provider-bound password-grant clients are seeded: one for the `staff` provider (`users`), one for the `customer` provider (`customers`). Exact table shapes and client provisioning API must be verified against current Passport documentation at implementation start, not assumed from training data.

## Domain events

Produced this stage: none recorded. The Identity event types are `UserInvited`, `UserRoleChanged`, `CustomerRegistered` (system-design 9.3), and the master plan explicitly defers attaching producers to Stage 4, when the outbox exists. Stage 3's obligation is structural:

- `InviteUser`, `AssignRole`, and `RegisterCustomer` each perform their state change inside a single transaction with an obvious single point where Stage 4 inserts the `Outbox::record()` call.
- Envelope fields per event-conventions (`id`, `sequence`, `type`, `tenant_id`, `aggregate_type`, `aggregate_id`, `correlation_id`, `occurred_at`, `payload`) will be satisfiable from data available inside those transactions: the acting tenant is in request context, correlation ID middleware already propagates, and the aggregate is the membership or customer row. A short comment in each Action is not needed; the Stage 4 plan lists the three attachment points.
- Payload Data classes are not written now; additive-only evolution (event-conventions) means writing them early buys nothing and risks churn.

Consumed this stage: none. No consumers exist before Stage 4.

## Endpoints

All routes under `/v1` (api-conventions). All errors are RFC 9457 problem documents with the stable `code` values listed; validation failures additionally carry the `errors` map. Every request accepts and echoes `X-Correlation-Id`. Request and response shapes are laravel-data objects in `app/Identity/Data`; `composer types:generate` runs after every Data change and each endpoint's OpenAPI path merges with its slice.

The `/v1/auth/*` namespace holds flow endpoints rather than resource nouns; token endpoints follow OAuth semantics wrapped in the platform's problem-document error shape so clients branch on `code` uniformly.

### Staff authentication

| Method and path | Request Data | Response Data | Error codes |
| --- | --- | --- | --- |
| POST `/v1/auth/staff/token` | `StaffTokenRequestData` (email, password, mfa_code nullable) | `TokenPairData` (access_token, refresh_token, token_type, expires_in) | `invalid_credentials`, `mfa_required`, `mfa_code_invalid`, `request.validation_failed` |
| POST `/v1/auth/staff/refresh` | `RefreshTokenRequestData` (refresh_token) | `TokenPairData` | `invalid_refresh_token`, `refresh_token_reused` |
| POST `/v1/auth/staff/logout` | none (bearer) | 204 | `auth.unauthenticated` |
| GET `/v1/me` | none (bearer) | `CurrentUserData` (id, name, email, mfa_enabled, memberships: list of `MembershipData`) | `auth.unauthenticated` |
| POST `/v1/auth/staff/invitation/accept` | `AcceptInvitationData` (token, password) | 204 | `invitation_token_invalid`, `invitation_token_expired`, `request.validation_failed` |
| POST `/v1/auth/staff/password/reset` | `RequestPasswordResetData` (email) | 202, empty body | `request.validation_failed` (always 202 for unknown email to avoid enumeration) |
| POST `/v1/auth/staff/password/reset/confirm` | `ConfirmPasswordResetData` (token, password) | 204 | `reset_token_invalid`, `reset_token_expired`, `request.validation_failed` |

Staff access tokens carry `identity_type: staff` and the subject ID, never an implicit tenant; the acting tenant is asserted per request via `X-Tenant-Id` and validated against memberships (system-design 5.4, api-conventions). `mfa_required` is returned when the user has confirmed MFA and no `mfa_code` was supplied; a recovery code is accepted in place of a TOTP code and consumed atomically.

### MFA (staff bearer required)

| Method and path | Request Data | Response Data | Error codes |
| --- | --- | --- | --- |
| POST `/v1/auth/mfa/enrollment` | none | `MfaEnrollmentData` (secret, otpauth_uri) | `mfa_already_enrolled` |
| POST `/v1/auth/mfa/enrollment/confirm` | `ConfirmMfaData` (code) | `MfaRecoveryCodesData` (recovery_codes) | `mfa_code_invalid`, `mfa_not_enrolled` |
| POST `/v1/auth/mfa/disable` | `DisableMfaData` (code) | 204 | `mfa_code_invalid`, `mfa_not_enrolled`, `mfa_enforced_for_role` |

Disable is a POST flow endpoint rather than a DELETE because the confirmation code travels in the request body and bodies on DELETE have no defined HTTP semantics; the `/v1/auth` namespace already uses flow-endpoint naming.

Enforcement: when the acting membership is platform-scope, or its role's capability set intersects the financially privileged set (`orders.refund`, `payouts.view` initially; the enum marks which capabilities are privileged), every request outside the auth and MFA-enrollment endpoints is denied with 403 `mfa_enforcement_required` until MFA is confirmed (system-design 5.1, 14.2). Disabling MFA under an enforcing membership is denied with `mfa_enforced_for_role`.

### Customer authentication and lifecycle (tenant from Host header; system-design 4.1)

| Method and path | Request Data | Response Data | Error codes |
| --- | --- | --- | --- |
| POST `/v1/auth/customer/token` | `CustomerTokenRequestData` (email, password) | `TokenPairData` | `invalid_credentials`, `request.validation_failed` |
| POST `/v1/auth/customer/refresh` | `RefreshTokenRequestData` | `TokenPairData` | `invalid_refresh_token`, `refresh_token_reused` |
| POST `/v1/auth/customer/logout` | none (bearer) | 204 | `auth.unauthenticated` |
| POST `/v1/customers` | `RegisterCustomerData` (email, name, password nullable, locale nullable) | `CustomerData` (id, email, name, locale, is_claimed) | `customer_email_taken`, `request.validation_failed` |
| POST `/v1/auth/customer/claim` | `ClaimRequestData` (email) | 202, empty body | `request.validation_failed` (always 202 for unknown email to avoid enumeration) |
| POST `/v1/auth/customer/claim/confirm` | `ConfirmClaimData` (token, password) | 204 | `claim_token_invalid`, `claim_token_expired`, `customer_already_claimed` |

Customer tokens carry `identity_type: customer`, the subject ID, and the tenant ID, and are valid only for that tenant (api-conventions); a customer bearer presented against a host resolving to a different tenant is rejected with 401 `tenant_mismatch`. Token issuance for an unclaimed guest (null password) fails with `invalid_credentials`. `POST /v1/customers` with no password is guest creation; with a password it is registration (`RegisterCustomer` Action covers both).

### Roles and memberships (staff bearer plus `X-Tenant-Id`; admin lists use query-builder allowlists per api-conventions)

| Method and path | Request Data | Response Data | Error codes |
| --- | --- | --- | --- |
| GET `/v1/roles` | filters: `filter[name]`, sort `name` | paginator of `RoleData` (id, tenant_id, name, capabilities, is_template) | `auth.unauthenticated`, `tenant_access_denied` |
| POST `/v1/roles` | `CreateRoleData` (name, capabilities) | `RoleData` 201 | `missing_capability`, `unknown_capability`, `role_name_taken`, `request.validation_failed` |
| GET `/v1/roles/{role}` | none | `RoleData` | `request.not_found` |
| PATCH `/v1/roles/{role}` | `UpdateRoleData` (name nullable, capabilities nullable) | `RoleData` | `role_not_editable` (template), `missing_capability`, `unknown_capability`, `role_in_use` guard not needed on update |
| DELETE `/v1/roles/{role}` | none | 204 | `role_not_editable`, `role_in_use` (memberships reference it) |
| GET `/v1/capabilities` | none | list of `CapabilityData` (name, is_financially_privileged) | `auth.unauthenticated` |
| GET `/v1/memberships` | filters: `filter[user_id]`, `filter[role_id]`, sort `-created_at` | paginator of `MembershipData` (id, user_id, user_name, user_email, tenant_id, role_id, role_name, scope) | `tenant_access_denied` |
| POST `/v1/memberships` | `InviteUserData` (email, name, role_id) | `MembershipData` 201 | `missing_capability`, `membership_exists`, `request.not_found` (role), `request.validation_failed` |
| PATCH `/v1/memberships/{membership}` | `ChangeMembershipRoleData` (role_id) | `MembershipData` | `missing_capability`, `request.not_found`, `last_owner_removal` if demoting the only Owner |
| DELETE `/v1/memberships/{membership}` | none | 204 | `missing_capability`, `last_owner_removal` |

Role and membership mutations require `roles.manage` and `memberships.manage` respectively; capability denial is 403 `missing_capability`. Authorization always evaluates capability plus tenant context through Gates and Policies, never role names (system-design 5.3). `InviteUser` creates the user if absent (random unusable password) and emails a signed, time-limited acceptance token; acceptance sets the password.

### Error code registry additions

`invalid_credentials`, `auth.unauthenticated`, `tenant_access_denied` (Stage 2 stub, now enforced against memberships), `tenant_mismatch`, `missing_capability`, `mfa_required`, `mfa_code_invalid`, `mfa_already_enrolled`, `mfa_not_enrolled`, `mfa_enforced_for_role`, `mfa_enforcement_required`, `invalid_refresh_token`, `refresh_token_reused`, `invitation_token_invalid`, `invitation_token_expired`, `reset_token_invalid`, `reset_token_expired`, `customer_email_taken`, `customer_already_claimed`, `claim_token_invalid`, `claim_token_expired`, `role_not_editable`, `role_in_use`, `role_name_taken`, `unknown_capability`, `membership_exists`, `last_owner_removal`. Codes are stable API contract; clients branch on `code`, never `detail` (api-conventions).

## TDD sequencing

Each slice follows the master plan's double loop: failing feature test over HTTP first, then the Data objects and OpenAPI path, then failing unit tests per invariant, then green, refactor, `composer types:generate`, commit scoped `identity`. Isolation tests precede any migration creating a tenant-scoped table (master plan test-first rule 1); invariant-guarding transitions get failing concurrency tests before implementation (rule 2).

### Slice 1: Passport install and staff token issuance

- Feature (first): POST `/v1/auth/staff/token` returns a token pair for valid credentials; `invalid_credentials` problem for bad password and unknown email (same code, no user enumeration); access token is a JWT whose claims include `identity_type: staff` and no tenant claim; `expires_in` is 900 and the token is rejected 901 seconds later under the fake clock.
- Contract: token endpoint and `GET /v1/me` paths in openapi.yaml; conformance assertions on the recorded responses.
- Unit: claim builder produces the exact claim set; lifetimes read from config, not Passport defaults.
- Isolation and Concurrency: not applicable; no tenant-scoped tables in this slice.

### Slice 2: Refresh rotation, reuse detection, revocation

- Feature (first): refresh returns a new pair and the old refresh token stops working; presenting an already-rotated refresh token returns `refresh_token_reused` and revokes every live token in the family (subsequent access with the previously issued access token is 401); logout revokes access and refresh; expired refresh token is `invalid_refresh_token` under the fake clock.
- Concurrency (first for the guard): two parallel refresh requests with the same refresh token; exactly one succeeds, driven by a conditional UPDATE on the refresh token's revoked state checked by affected-row count, never read-then-write.
- Unit: reuse detector family-revocation logic.

### Slice 3: memberships and roles schema, tenant assertion activation

- Isolation (first): two-tenant fixture proves cross-tenant SELECT, INSERT, UPDATE, DELETE on `memberships` and `roles` all fail under RLS; template roles (`tenant_id IS NULL`) are readable from both tenant contexts but not mutable from either, and are writable under `nodia_platform` and nothing else; the `memberships_self_read` policy returns exactly the caller's own rows across tenants and no one else's.
- Feature (first): resolution matrix for staff requests: valid `X-Tenant-Id` with membership passes; valid tenant without membership is 403 `tenant_access_denied`; platform-scope membership plus a tenant header reaches the tenant via the platform role and writes an activity entry once slice 7 lands (asserted there); missing header on an admin route is 400 per the Stage 2 contract.
- Unit: membership resolution inside the `SET LOCAL` wrapper; the platform-scope invariant (scope `platform` implies sentinel tenant).

### Slice 4: capability RBAC, role and membership endpoints, authorization matrix

- Feature (first): the authorization matrix as a data-driven test: for every capability in the enum, for each seeded template role and a custom role, in the member's tenant and a foreign tenant, assert allow or deny with `missing_capability`. The matrix dataset is the single source the Stage 12 completeness check will extend.
- Feature (first): role CRUD including `role_not_editable` on template mutation, `role_in_use` on delete, `unknown_capability` rejection; membership invite, role change, removal including `last_owner_removal`; invitation acceptance with expired and tampered tokens under the fake clock.
- Unit: capability set evaluation; Gate resolution of acting membership from user plus asserted tenant; template seeder idempotence.
- Isolation: covered by slice 3 tables; new denial probes for the endpoints (foreign-tenant role ID in the URL is 404, not 403, so existence does not leak).

### Slice 5: MFA enrollment and enforcement

- Feature (first): enroll, confirm with a valid TOTP code, receive recovery codes exactly once; token issuance without `mfa_code` for an enrolled user is `mfa_required`; wrong code is `mfa_code_invalid`; a recovery code works once and only once; enforcement denial paths: platform-scope membership without confirmed MFA gets `mfa_enforcement_required` on `/v1/roles` but can still reach `/v1/auth/mfa/enrollment`; same for a tenant role holding `orders.refund`; disable under an enforcing membership is `mfa_enforced_for_role`.
- Concurrency (first for the guard): parallel presentation of the same recovery code; exactly one token issuance succeeds via the conditional UPDATE on `used_at`.
- Unit: TOTP verification window under the fake clock; recovery code hashing.

### Slice 6: customers, guest creation, customer authentication, claim flow

- Isolation (first): `customers` cross-tenant fixture; a customer row created under tenant A is invisible and immutable under tenant B.
- Feature (first): guest creation with no password; registration with password; same email registers independently under two tenants (host-resolved) proving per-tenant uniqueness; duplicate email in one tenant is `customer_email_taken`; token issuance for a guest is `invalid_credentials`; claim request always returns 202; claim confirm sets the password and enables login; expired and reused claim tokens fail with their codes; a customer token used against another tenant's host is `tenant_mismatch`; customer claims include `identity_type: customer` and `tenant_id`.
- Concurrency (first for the guard): parallel guest creation with the same email in one tenant yields exactly one row (unique constraint surfaced as `customer_email_taken`, not a 500).
- Unit: `RegisterCustomer` and `ClaimGuestAccount` Actions; locale fallback to tenant default (system-design 12).

### Slice 7: activity log and audit coverage

- Isolation (first): `activity_log` two-tenant fixture; additionally prove UPDATE and DELETE are denied even for the owning tenant (append-only via absent policies).
- Feature (first): successful staff token issuance writes a log entry (sentinel platform tenant, causer user); every mutating endpoint shipped in Stages 2 and 3 writes an entry in the acting tenant (data-driven over the route list); platform-role cross-tenant access writes an entry flagged as platform-scope use, closing Stage 2's deferred audit requirement.
- Unit: logger tenant attribution (acting tenant vs sentinel).

### Slice 8: staff password reset

- Feature (first): the request endpoint returns 202 for known and unknown emails alike (no enumeration); confirm with a valid token sets the new password, the old password stops working, and every previously issued access and refresh token for the user is rejected afterwards; an expired token is `reset_token_expired` under the fake clock; a consumed token cannot be reused (`reset_token_invalid`); the reset email goes through the mailer contract and is asserted with the mail fake.
- Concurrency (first for the guard): parallel confirmations with one reset token succeed exactly once via a conditional UPDATE on the token's consumed state checked by affected-row count.
- Unit: token TTL from config under the fake clock; token hashing at rest; full-family token revocation on reset.

## Task breakdown

Ordered; each is a small PR, independently mergeable unless noted, commit scope `identity` (or `tenancy`/`support` where stated).

1. Install Passport, publish and adjust oauth migrations for uuid `user_id`, configure `staff` and `customer` auth guards and providers, seed provider-bound clients, set explicit token lifetimes. Verify current Passport documentation for password grant enablement, provider binding, and JWT claim customization before writing code.
2. Slice 1: staff token issuance endpoint, claims, `GET /v1/me` (memberships list arrives with task 5; until then it returns an empty list), contract paths, error codes.
3. Slice 2: refresh rotation, reuse detection with family revocation, logout, concurrency test for parallel refresh.
4. Migration task: `memberships` and `roles` tables with RLS policies (including `memberships_self_read` and `roles_platform_write`), `Capability` enum, template role seeder running under `nodia_platform`. Isolation tests first; mergeable alone because nothing routes to it yet.
5. Slice 3: activate membership validation in the tenant resolution middleware (touches Tenancy's middleware; coordinate scope, commit as `identity` with the Tenancy change reviewed by that context's tests), platform-scope fallback, resolution matrix feature test, `/v1/me` now returns memberships.
6. Slice 4a: capability Gate and Policy layer plus the authorization matrix test harness.
7. Stage 2 alias rebinding: replace the placeholder platform auth alias with Passport bearer authentication plus the capability layer, so tenant and domain CRUD require `tenants.manage` and the platform route group falls under MFA enforcement once task 11 lands; add the Stage 2 endpoints to the authorization matrix dataset and to the slice 7 audit coverage route list. Touches Tenancy's routes; commit as `identity` with Tenancy's tests re-run.
8. Slice 4b: role endpoints and `GET /v1/capabilities`.
9. Slice 4c: membership endpoints, `InviteUser` and `AssignRole` Actions, invitation acceptance flow with mail fake.
10. Migration task: users MFA columns and `mfa_recovery_codes` table (no RLS; platform-global) with the failing unit tests and the failing parallel recovery-code concurrency test for the consumption guard, both written before the guard is implemented (master plan test-first rule 2).
11. Slice 5: MFA endpoints, token-endpoint MFA challenge, enforcement middleware, denial paths; goes green against the concurrency test from task 10.
12. Migration task: `customers` table with RLS; isolation tests first.
13. Slice 6: customer endpoints, `RegisterCustomer` and `ClaimGuestAccount` Actions, customer guard wiring, tenant claim validation middleware, guest-creation concurrency test.
14. Migration task: adjusted activitylog install (uuid, `tenant_id`, RLS with no UPDATE/DELETE policies); isolation and append-only tests first. Commit scope `support` if the logger wrapper lands in `app/Support`, otherwise `identity`.
15. Slice 7: audit recording on logins, mutations, and platform-role use; data-driven coverage test over existing mutating routes.
16. Slice 8: staff password reset endpoints, single-use reset token storage with the consumption conditional UPDATE and its failing concurrency test first, token-family revocation on reset, mail fake coverage, contract paths.
17. Closeout: OpenAPI document review for the whole stage, `composer types:generate` drift check, master plan status table flip, roadmap Implementation Status untouched (this is the API plan's stage, not a roadmap phase).

## Exit criteria

The master plan exit line: staff and customers authenticate independently; capability checks and audit trail cover every mutating endpoint that exists so far. Expanded into testable checks:

1. A staff user obtains a token pair with email and password; the access token is a 15-minute JWT with `identity_type: staff` and no tenant claim (feature test, fake clock).
2. A customer obtains a token pair against their tenant's host; the token carries `identity_type: customer` and `tenant_id` and is rejected with `tenant_mismatch` on any other tenant's host.
3. The same email address exists simultaneously as a user and as customers of two different tenants with three independent credential sets, and each authenticates only through its own endpoint.
4. Refresh rotation works; replaying a rotated refresh token returns `refresh_token_reused` and revokes the token family; parallel refresh of one token succeeds exactly once.
5. `X-Tenant-Id` without a matching membership is 403 `tenant_access_denied` on every admin route; with a membership the request proceeds under RLS for exactly that tenant.
6. The authorization matrix test enumerates every capability in the enum against every template role plus a custom role, in-tenant and cross-tenant, and passes; every mutating endpoint that exists (Stage 2 tenant CRUD and all Stage 3 endpoints) requires a named capability.
7. MFA: an enrolled user cannot get a token without a code; platform-scope staff and financially privileged roles are denied everything but auth and enrollment endpoints until MFA is confirmed; a recovery code is single-use under parallel presentation.
8. A guest customer (null password) can be created, cannot log in, and can claim the account via the emailed token, after which login works; claim tokens expire and cannot be reused.
9. The isolation suite covers `memberships`, `roles`, `customers`, and `activity_log` with cross-tenant read and write denial; template roles are readable everywhere and writable nowhere; `activity_log` rejects UPDATE and DELETE entirely.
10. Staff logins, all Stage 2 and Stage 3 mutations, and every platform-role cross-tenant access appear in the activity log with correct tenant attribution (sentinel platform tenant where applicable).
11. Every endpoint above has its OpenAPI path merged and conformance-checked; generated TypeScript is committed with no drift; Feature, Unit, Contract, Architecture, Isolation, and Concurrency suites are green; Larastan and Pint pass.
12. `InviteUser`, `AssignRole`, and `RegisterCustomer` Actions exist as single-transaction units ready for Stage 4 to attach outbox recording.
13. A staff user resets a forgotten password via the emailed token: the request endpoint returns 202 regardless of email existence, confirmation sets the new password and revokes every live access and refresh token, expired and reused tokens fail with their codes, and parallel confirmations of one token succeed exactly once.

## Risks and open questions

- Passport version behavior. Password grant availability, provider-bound clients, JWT claim customization, and refresh token internals vary across recent Passport majors. Task 1 verifies all of it against current documentation before any code; if the password grant is unavailable or unfit, the fallback is a first-party token-issuance controller using Passport's token machinery directly, keeping the same endpoint contract. This is the stage's largest unknown.
- `roles.tenant_id` nullable deviates from the data-conventions non-null rule. Justified by system-design 5.3 (NULL means global template) and by the requirement that templates be readable under every tenant's RLS context. The mitigation is the mutation policy restricted to the current tenant plus the partial unique index. If review prefers zero exceptions, the alternative is a separate `role_templates` table without `tenant_id`, at the cost of a union read path; decide at task 4 review.
- The Passport oauth tables also deviate from the data-conventions non-null `tenant_id` rule: access and refresh token rows belong in substance to one tenant's customer, yet ship with no `tenant_id` and no RLS. Justified because token and client rows are authentication infrastructure keyed to identities, and customer tokens carry the tenant in their claims. This means behavioral coverage rests solely on the slice 6 `tenant_mismatch` feature test rather than the isolation suite. If review prefers defense in depth, the alternative is a denormalized `tenant_id` column on the token tables with a standard RLS policy; decide at task 1 review.
- MFA challenge inside the OAuth token exchange is nonstandard (OAuth has no native TOTP step). The `mfa_code` parameter on the token request is pragmatic and testable; if task 1's Passport review makes parameter injection awkward, the fallback is a two-step flow (password exchange returns a short-lived `mfa_token`, a second call exchanges it plus the TOTP code for the real pair), which changes the `mfa_required` response shape. Contract freezes only after this is settled, within slice 5.
- Reuse detection semantics: revoking the whole token family on reuse is stricter than Passport's default single-token revocation and needs custom code around the refresh grant; the concurrency test in slice 2 pins the behavior.
- Membership validation ordering: the middleware must set `SET LOCAL app.tenant_id` before querying `memberships` under RLS, which means an attacker-supplied tenant ID briefly scopes the lookup transaction. This is safe (an empty result denies) but the middleware must not run any other query in that transaction before validation completes; an architecture or feature test should pin this.
- Staff password reset, previously unowned (the master plan's Stage 3 list originally omitted it and invitation acceptance covers only first credentials), is delivered by this stage in slice 8; the master plan's Stage 3 bullet now records it, so master plan and stage plan agree.
- Admin portal idle timeout (system-design 5.4) is expressed here only as refresh token lifetime configuration. If a distinct idle semantic (rolling window vs absolute) is wanted, it needs a design note; deferred until the admin frontend consumes the API.
- Email delivery in this stage is synchronous through the mailer contract with fakes in tests. Real Resend configuration and deliverability are proven in Stage 8a; a misconfigured mailer would surface as invitation and claim emails silently failing in staging. Acceptable for an API-only stage, noted for the Stage 8a plan.
