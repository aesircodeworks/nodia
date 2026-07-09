# Execution Journal: Stage 3, Identity, AuthN, AuthZ

Durable record of execution runs for [stage-03-identity.md](../stage-03-identity.md). Append a new run header per run; never rewrite prior entries.

## Run: 2026-07-09

- Stage: 3, Identity, AuthN, AuthZ
- Date: 2026-07-09
- Branch: `feat/api-implementation`
- Base commit: `d3a95016b94f3bd046e82e12201ddade5cbac913`

Verified starting state: Stages 1 and 2 are done (status table plus commit history through d3a9501). Nothing from Stage 3 exists: there is no `app/Identity` directory, `composer.json` has neither `laravel/passport` nor `spatie/laravel-activitylog`, `database/migrations` holds only the Laravel defaults and the four Stage 2 tenancy migrations, and the two Stage 2 deferral points are still in place exactly as the stage plan expects (`PlatformAuthPlaceholder` behind the `auth.platform` alias, `ResolveTenantFromHeader` validating tenant existence only with the membership check deferred to this stage). The master plan status table said "Not started"; this run flips it to "In progress".

### Task checklist

- [x] task-01: Passport install, uuid oauth migrations, staff and customer guards and providers, client seeding, explicit lifetimes (plan task 1)
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

#### task-01: Passport install and configuration (2026-07-09)

Verified current Passport documentation (context7 `/laravel/passport`, pinned `13.x` docs, cross-checked against the installed `v13.7.5` source under `vendor/laravel/passport`) before writing any code, per the plan's Risks item:

- Password grant: available but disabled by default since Passport 12.0; requires an explicit `Passport::enablePasswordGrant()` call. Confirmed fit for the stage's design; the fallback (first-party token controller not using the password grant type) is not needed. A first-party `/v1/auth/*` controller is still built in task-02, but it drives Passport's `AuthorizationServer` directly with `grant_type=password` rather than exposing Passport's own `/oauth/token` route, which is disabled via `Passport::ignoreRoutes()` so it cannot leak the OAuth-shaped contract Passport ships by default.
- Provider binding: `passport:client --provider=` and `ClientRepository::createPasswordGrantClient($name, $provider, $confidential)` bind a client to a named entry in `config('auth.providers')`; a client's `provider` column is read from `oauth_clients` and compared against the authenticating guard's provider name at token-issuance and resource-request time. Confirmed sufficient for the two-population design without any Passport source changes.
- JWT claim customization: Passport's `AccessTokenTrait::convertToJWT()` (`league/oauth2-server`) is a private trait method, not overridable by subclassing `Laravel\Passport\Bridge\AccessToken`. The supported extension point is `Passport::useAccessTokenEntity()` with a custom entity overriding the public `toString()` method, which task-02 will use to add `identity_type` and (for customers) `tenant_id` claims. Recorded now because it determines task-02's approach; no code needed yet since no endpoint ships this task.
- Refresh token internals: `oauth_refresh_tokens` has no reuse-detection concept built in beyond `revoked` and `Passport::$revokeRefreshTokenAfterUse`; the family-revocation semantics the stage plan wants (revoke every live token in the family on reuse) need custom code around `RefreshTokenRepository`, confirmed as task-03's job, not something Passport provides directly.

No fallback triggered: Passport's password grant is fit for purpose as designed in the stage plan.

Additional decisions made while implementing:

- Passport's five published oauth migrations (`oauth_auth_codes`, `oauth_access_tokens`, `oauth_refresh_tokens`, `oauth_clients`, `oauth_device_codes`) are all kept for parity with Passport's own model expectations, even though the device grant is unused; `user_id` columns changed from `foreignId` (bigint) to `uuid` on the three tables that carry one, matching the uuid primary key shared by `users` and the future `customers` table. `oauth_clients.owner` morphs changed from the default bigint morphs to `nullableUuidMorphs` for the same reason. No FK constraints exist on any `user_id`/`client_id` column in Passport's own migrations (`foreignId`/`foreignUuid` without `->constrained()` just types the column), which is what makes a single polymorphic `user_id` shared between two provider populations possible.
- No tenant_id, no RLS on any oauth table, per the plan's sanctioned deviation. Added `Rls::grantUnscoped()` (plain `GRANT ... TO nodia_app, nodia_platform`, no `ENABLE`/`FORCE ROW LEVEL SECURITY`, no policies) since these tables are reached from tenant-scoped request transactions (customer token issuance and refresh run under `SET LOCAL ROLE nodia_app` via the Host-resolution middleware) and would otherwise be invisible to that unprivileged role once the request stops running as the table owner.
- The two seeded password-grant clients (`Staff` bound to the `users` provider, `Customer` bound to the `customers` provider) are public (no secret): the wire contract's request Data classes (`StaffTokenRequestData`, `CustomerTokenRequestData` in the stage plan) never carry OAuth client credentials, so the `/v1/auth/*` controllers built in later slices look each client up by provider plus grant type at request time instead of managing a shared secret. This also sidesteps `Laravel\Passport\Client::secret()`'s at-rest hashing, which only exposes the plaintext secret transiently on the request that created it, making a deterministic seeded secret awkward without bypassing `ClientRepository`. Seeded via a data migration (`2026_07_09_000009_seed_passport_clients.php`), the same pattern the Stage 2 tenants migration used for the sentinel row, so it runs exactly once and is tracked like every other schema change.
- Refresh token lifetime: the plan fixes the access token lifetime at 15 minutes but leaves the refresh lifetime unspecified (Risks: "Admin portal idle timeout... expressed here only as refresh token lifetime configuration... deferred until the admin frontend consumes the API"). Picked 30 days (`43_200` minutes) as an explicit, config-driven, testable starting value (`config/identity.php`, `PASSPORT_REFRESH_TOKEN_TTL_MINUTES`), open to revisit once that design note lands.
- `App\Identity\Models\Customer` is a minimal stub (`Authenticatable`, `HasUuids`, `HasApiTokens`, implements `Laravel\Passport\Contracts\OAuthenticatable`) with no fillable attributes or casts, so the `customers` guard and provider and the seeded client have a model to bind to before task-12 ships the `customers` table and its RLS policy. Nothing queries it yet. `App\Models\User` gained the same `HasApiTokens`/`OAuthenticatable` wiring; it was not moved into `App\Identity\Models`, matching system-design 3.2's directory diagram, which keeps staff on the default Laravel `users` model at `App\Models\User` (ADR 007) while `Identity/Models` holds `Membership`, `Role`, `Customer`.
- RSA keypair provisioning for JWT signing (`passport:keys`) is not addressed this task: `PassportServiceProvider`'s `AuthorizationServer` binding is lazy (a singleton closure, never resolved during boot), so nothing in this task's tests touches the signing keys, and no CI job needed changes. This is a real open item for task-02, which will need a deterministic way to get keys into place for local dev, tests, and CI (candidates: generate on first boot when missing, or provision via `PASSPORT_PRIVATE_KEY`/`PASSPORT_PUBLIC_KEY` env vars) — flagged here so it isn't missed, not solved.
- Architecture suite: added `App\Identity\IdentityServiceProvider` and `App\Identity\Models`/`App\Identity\Http\Controllers` to the existing `arch()->preset()->laravel()->ignoring([...])` list in `tests/Architecture/PresetTest.php`, mirroring the existing Tenancy entries, so context-owned service providers and models keep passing the Laravel preset's App\Providers/App\Models location rules.

Test evidence: `composer -d apps/api run test` (all six suites: Feature, Unit, Contract, Architecture, Isolation, Concurrency) passed 393 tests, 1453 assertions, 0 failures, including the two new Unit files (`tests/Unit/Identity/PassportConfigurationTest.php`, `tests/Unit/Identity/PassportMigrationsTest.php`, 29 tests covering lifetime-from-config, password grant enablement, route suppression, guard/provider wiring, oauth table uuid typing, absence of `tenant_id`, absence of RLS, the unscoped grants, and the two seeded clients). `composer -d apps/api run lint` (Pint) passed. `composer -d apps/api run analyse` (Larastan, level 5) passed with 0 errors. `composer -d apps/api run types:generate` ran with no diff against the committed generated TypeScript (no laravel-data Data classes changed this task, so no drift possible).

Commit: `9922557` (`feat(identity): install and configure Passport for staff and customer guards`).
