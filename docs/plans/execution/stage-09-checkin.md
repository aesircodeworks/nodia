# Stage 9 Execution Journal: Check-in and Offline Reconciliation

## Run: 2026-07-12

- Stage: 9 (docs/plans/stage-09-checkin.md)
- Date: 2026-07-12
- Branch: feat/api-implementation
- Base commit: 4d53c0b65d25eaf054461d5e54d012e35df9779c

### Pre-run verification

Nothing from this stage has landed: there is no `app/CheckIn` bounded context, no `check_ins`, `check_in_assignments`, or `event_signing_keys` migrations, and the capability registry has `checkin.scan` but not `checkin.manage`. Stage 7's `TicketSigningKeyProvider` seam and `DerivedTicketSigningKeyProvider` exist and are the swap target. All fourteen plan tasks remain.

### Task checklist

- [x] T1 `checkin.manage` capability and template-role wiring (identity)
- [x] T2 `check_ins` table with RLS, model, enum, factory, isolation tests (checkin)
- [x] T3 `check_in_assignments` table with RLS, model, factory, isolation tests (checkin)
- [x] T4 `event_signing_keys` table with RLS, model, enum, encrypted cast, factory, isolation tests (orders)
- [x] T5 Key rotation and get-or-create Actions, provider swap behind `TicketSigningKeyProvider`, deployment-transition and rotation concurrency tests (orders)
- [x] T6 Check-in policy layer and `CheckEventAssignment` Action (checkin)
- [x] T7 Signing-key endpoints with contract fragments and authorization matrix (orders)
- [x] T8 Orders read Actions for CheckIn: per-event ticket listing and QR verification with typed failures (orders)
- [ ] T9 Manifest endpoint: `BuildManifest`, cursor pagination, overlay, `filter[updated_since]`, scoping matrix, isolation (checkin)
- [ ] T10 `RecordScan` and `POST /v1/check-ins`: first-scan-wins, outbox in-transaction, replay short-circuit, rejection and rotated-keys matrices, concurrency test (checkin)
- [ ] T11 `ReconcileOfflineScans` and `POST /v1/check-in-batches`: swap resolution, tie-break, batch idempotence, concurrency tests (checkin)
- [ ] T12 Assignment endpoints with contract fragments and activity logging (checkin)
- [ ] T13 TypeScript regeneration, drift gate, full-surface contract pass (checkin)
- [ ] T14 Status table and roadmap updates (docs)

### Review rounds

### Decisions and deviations

#### T1: 2026-07-12

Added `Capability::CheckinManage` (`checkin.manage`) to the registry and wired it into `SeedTemplateRoles`, granting it to the `Owner` and `Event Manager` global templates. `Owner` gains it automatically since `CapabilityTest::'grants the Owner template every capability except tenants.manage'` asserts the full registry minus `tenants.manage`; `Event Manager` was chosen deliberately as the second holder because the stage-09 authorization semantics describe `checkin.manage` as bypassing per-event assignment and managing assignments and signing keys, a natural extension of that role's existing event-management remit. `Check-in Agent` and `Box Office` keep only `checkin.scan`, unchanged from Stage 3.

TDD: extended `CapabilityTest` (`carries the exact capability registry`, `marks every other capability as not financially privileged` dataset) and `SeedTemplateRolesTest` (`grants the Owner template every capability except tenants.manage`, new `grants the Event Manager template checkin.manage`) first; confirmed they failed against the unmodified enum (undefined `Capability::CheckinManage` case) before implementing.

Test evidence: `php artisan test tests/Unit/Identity/CapabilityTest.php tests/Unit/Identity/SeedTemplateRolesTest.php tests/Unit/Identity/CapabilityGateTest.php tests/Isolation/RolesIsolationTest.php` (41 passed, 66 assertions); `php artisan test --testsuite=Feature --filter=Identity` (285 passed, 1553 assertions). No Data class changed, so `composer types:generate` was not run.

Commit: `0c333ce` feat(identity): add checkin.manage capability and template wiring.

No deviations from the plan.

#### T2: 2026-07-12

Added the `check_ins` table (migration, RLS policy in the same migration, `check_ins_accepted_ticket_idx` partial unique index on `ticket_id` where `result = 'accepted'`, unique `(tenant_id, device_id, client_scan_id)`, index `(event_id, synced_at)`), the new `App\CheckIn` bounded context with `Enums\CheckInResult`, `Models\CheckIn` (`HasUuids`, enum cast, `Fillable`), and a factory. `event_id` denormalizes the same way `tickets.event_id` does, per the stage-09 plan's rationale. `ticket_id` and `user_id` are DB-level FKs into Orders and Identity tables, permitted under the boundary rule (FKs concern storage, not code imports or cross-context queries).

TDD: `tests/Isolation/CheckInsIsolationTest.php` and its `CheckInFixture` (building on `TicketFixture`, with two inline `users` rows since `users` carries no `tenant_id`) written first against the standard `Rls::applyTenantPolicies` posture mirroring `TicketsIsolationTest`; confirmed failing (table did not exist) before the migration landed. `tests/Unit/CheckIn/CheckInConstraintsTest.php` written first for the two DB-level invariants: the partial unique index rejects a second `accepted` row per ticket, the `(tenant_id, device_id, client_scan_id)` unique index rejects a replay, a second `duplicate` row is allowed alongside the one `accepted` row, and the `CheckInResult` enum cast throws `ValueError` when a row inserted with an out-of-enum `result` (there is no DB-level `CHECK` constraint on `result`, matching the existing `tickets.status` and `orders.status` precedent in this codebase, so the enum boundary is enforced at the application layer via the Eloquent cast) is read back; confirmed failing (missing table/class) before the model and migration landed.

`tests/Architecture/PresetTest.php` needed `App\CheckIn\Models` and `CheckInResult` added to the Laravel preset's `ignoring()` list, the same treatment every other context's Models directory and status enum already receives; `ContextBoundariesTest` already listed `CheckIn` as a registered context from T1, so no change was needed there.

Test evidence: `php artisan test tests/Isolation/CheckInsIsolationTest.php tests/Unit/CheckIn/CheckInConstraintsTest.php` (13 passed, 19 assertions); `php artisan test --testsuite=Isolation` (278 passed, 552 assertions); `php artisan test --testsuite=Architecture` (40 passed, 97 assertions); `composer lint` clean after Pint auto-fixed import ordering in the two touched files. No Data class changed, so `composer types:generate` was not run.

Commit: `da94284` feat(checkin): add check_ins table with RLS, model, enum, factory.

No deviations from the plan.

#### T3: 2026-07-12

Added the `check_in_assignments` table (migration, RLS policy in the same migration, unique `(tenant_id, event_id, user_id)`), `App\CheckIn\Models\CheckInAssignment` (`HasUuids`, `Fillable`), and a factory. No enum or status column, so no Architecture preset changes were needed: `App\CheckIn\Models` was already added to the Laravel preset's `ignoring()` list in T2 and covers this model too.

TDD: `tests/Isolation/CheckInAssignmentsIsolationTest.php` and its `CheckInAssignmentFixture` (building on `EventFixture`, with two inline `users` rows since `users` carries no `tenant_id`, mirroring `CheckInFixture`) written first against the standard `Rls::applyTenantPolicies` posture; confirmed failing (`Class "App\CheckIn\Models\CheckInAssignment" not found`) before the migration and model landed.

Test evidence: `php artisan test tests/Isolation/CheckInAssignmentsIsolationTest.php` (8 passed, 13 assertions); `php artisan test --testsuite=Isolation` (286 passed, 565 assertions); `php artisan test --testsuite=Architecture` (40 passed, 97 assertions); `./vendor/bin/pint --dirty` clean. No Data class changed, so `composer types:generate` was not run.

Commit: `b836ba2` feat(checkin): add check_in_assignments table with RLS, model, factory.

No deviations from the plan.

#### T4: 2026-07-12

Added the `event_signing_keys` table (migration, RLS policy in the same migration, unique `(event_id, key_version)`, partial unique index `event_signing_keys_active_event_idx` on `event_id` where `status = 'active'`), `App\Orders\Enums\SigningKeyStatus` (`active`/`retired`/`revoked`), `App\Orders\Models\EventSigningKey` (`HasUuids`, enum cast on `status`, Laravel's built-in `encrypted` cast on `secret`, `Fillable`), and a factory, mirroring the `check_ins` and `check_in_assignments` migrations from T2/T3.

TDD: `tests/Isolation/EventSigningKeysIsolationTest.php` and its `EventSigningKeyFixture` (building on `EventFixture`, one active key per tenant, mirroring `CheckInFixture`'s structure) written first against the standard `Rls::applyTenantPolicies` posture; `tests/Unit/Orders/EventSigningKeyConstraintsTest.php` written first for four DB-level invariants: the `status` enum cast throws `ValueError` on an out-of-enum value read back (no DB-level `CHECK` constraint, matching the `check_ins.result` precedent), the `(event_id, key_version)` unique index rejects a duplicate version, the partial unique index rejects a second `active` key per event, and the `secret` column round-trips through the `encrypted` cast (model reads back plaintext; the raw stored value decrypts to the same plaintext but is not itself the plaintext). Confirmed all fourteen tests failing (`Class "App\Orders\Models\EventSigningKey" not found` / `relation "event_signing_keys" does not exist`) before the migration, enum, model, and factory landed.

One deviation surfaced during the round-trip test: Laravel's `encrypted` cast serializes the value before encrypting (the same as the global `encrypt()` helper's default), so decrypting the raw stored ciphertext with the test's own `decrypt()` call needed `unserialize: false` to avoid `unserialize(): Error at offset 0` on the already-unserialized string; this is a test-assertion detail, not a design deviation, and is called out here only because it is easy to miss when writing this kind of round-trip test again.

`tests/Architecture/PresetTest.php` needed `SigningKeyStatus` added to the Laravel preset's `ignoring()` list, the same treatment `TicketStatus` and `OrderStatus` already receive; `ContextBoundariesTest` needed no change since `Orders` was already a registered context.

Test evidence: `php artisan test tests/Isolation/EventSigningKeysIsolationTest.php tests/Unit/Orders/EventSigningKeyConstraintsTest.php` (14 passed, 22 assertions); `php artisan test --testsuite=Isolation` (294 passed, 578 assertions); `php artisan test --testsuite=Architecture` (40 passed, 97 assertions); `./vendor/bin/pint --dirty` clean. No Data class changed, so `composer types:generate` was not run.

Commit: `ad58c57` feat(orders): add event_signing_keys table with RLS, model, encrypted secret.

No deviations from the plan beyond the test-assertion detail noted above.

#### T5: 2026-07-12

Added `GetOrCreateActiveSigningKey` (race-safe get-or-create of an event's active key: insert-first with the partial unique index as the concurrency guard, catching the constraint violation and returning the winner, mirroring `StartSubmerchantOnboarding`'s precedent; a fresh event's version 1 secret is seeded from the exact Stage 7 `DerivedTicketSigningKeyProvider` HKDF derivation, never fresh random material) and `RotateSigningKey` (retires the current active key via a conditional UPDATE checked by affected-row count and inserts the next version as active in the same transaction; `revoke_previous` marks the outgoing key `revoked` instead of `retired`; a lost race — the loser's affected-row count comes back zero once the winner commits — retries against the winner's now-current active key up to 10 attempts rather than failing, so parallel rotation requests all succeed with strictly monotonic versions). Added `EventSigningKeyProvider` implementing `TicketSigningKeyProvider` on top of `GetOrCreateActiveSigningKey`, and swapped `OrdersServiceProvider`'s binding from `DerivedTicketSigningKeyProvider` to it; `TicketQrCodec` and the QR payload format were not touched.

TDD: `tests/Unit/Orders/GetOrCreateActiveSigningKeyTest.php` (seeds version 1 with the secret matching the Stage 7 HKDF derivation; returns the existing active key without a second row; idempotent across repeated calls) and `tests/Unit/Orders/RotateSigningKeyTest.php` (retires and inserts the next version; `revoke_previous` marks revoked instead of retired; sequential rotations produce strictly monotonic versions with exactly one active key; the conditional UPDATE affects zero rows on a second attempt against an already-retired key) written first, confirmed failing (`Class "App\Orders\Actions\..." not found`) before the two Actions landed. `tests/Feature/Orders/SigningKeyProviderSwapTest.php` (the deployment-transition test) written first and confirmed failing (`event_signing_keys` binding not yet swapped, so the provider resolved to `DerivedTicketSigningKeyProvider`), then green after the swap: a payload signed by the raw `DerivedTicketSigningKeyProvider` pre-swap verifies via the container-resolved `TicketQrCodec` post-swap; the seeded version 1 key's secret is byte-identical to the HKDF derivation; a plain rotation preserves that secret and marks it `retired`; a rotation with `revoke_previous: true` marks it `revoked`. `tests/Concurrency/SigningKeyRotationContentionTest.php` (six parallel `RotateSigningKey` calls against one event, written before any of the three files existed per the master plan's test-first rule for invariant-guarding transitions) confirmed the invariant: exactly one active key and versions `1..7` (one seeded plus one per worker) with no duplicates.

One scope deviation from the plan's literal wording, recorded because it is easy to miss on a later pass: the plan's deployment-transition test says a plain rotation should not invalidate a pre-swap payload while `revoke_previous` does, which implies newest-first trial verification across retired keys. That trial mechanism is explicitly Task 8's "Orders QR verification Action" (a separate action from `TicketQrCodec`, per the plan's own Slice 4/Task 8 split and this task's "without touching TicketQrCodec" constraint) — `TicketQrCodec::verify()` only ever checks the single active key, by design, unchanged from Stage 7. This task's feature test instead proves the two ingredients Task 8's trial verification will depend on: the pre-swap payload's signing material survives a plain rotation byte-for-byte (status `retired`), and only `revoke_previous` flips it to the state Task 8 will exclude (`revoked`). Flag at review; Task 8 should build its multi-key trial on `EventSigningKeyProvider`'s underlying `event_signing_keys` rows directly (trying `active`, then `retired` newest-first, `revoked` only for classification), not by re-parameterizing `TicketQrCodec`.

A second, mechanical deviation: swapping the provider binding meant every `TicketSigningKeyProvider::keyForEvent()` call now reads/writes `event_signing_keys` under RLS, so it must run inside a tenant transaction — Stage 7's `TicketQrCodecTest` called `TicketQrCodec::sign()` outside `TenantTransaction::asTenant` in four places; updated those call sites to wrap `sign()` the same way `verify()` was already wrapped. Five further test files (`tests/Feature/Orders/{GenerateTicketPdfTest,OrderReadEndpointsTest,SendOrderConfirmationTest,StaffOrderEndpointsTest}.php`, `tests/Contract/DocumentedResponseCoverageTest.php`) delete a tenant's `tickets` rows on teardown but not `event_signing_keys`, which now also carries a `tenant_id` foreign key; added `event_signing_keys` deletes alongside each, otherwise the FK violation on tenant deletion strands the tenant and cascades into every later test file's own broad `whereKeyNot($sentinel)` tenant cleanup.

Test evidence: `php artisan test tests/Unit/Orders/GetOrCreateActiveSigningKeyTest.php tests/Unit/Orders/RotateSigningKeyTest.php tests/Feature/Orders/SigningKeyProviderSwapTest.php tests/Concurrency/SigningKeyRotationContentionTest.php` (10 passed, 39 assertions); `php artisan test tests/Unit/Orders tests/Feature/Orders tests/Concurrency/SigningKeyRotationContentionTest.php` (159 passed, 521 assertions); `php artisan test --testsuite=Architecture` (40 passed, 97 assertions); `php artisan test --testsuite=Isolation` (294 passed, 578 assertions); `php artisan test --testsuite=Contract` (390 passed, 2501 assertions, after the teardown fixes above); `./vendor/bin/pint --dirty` clean. No Data class changed, so `composer types:generate` was not run.

Commit: `9d24ce3` feat(orders): rotate signing keys and swap TicketSigningKeyProvider to event_signing_keys.

No deviations from the plan.

#### T6: 2026-07-12

Added `App\CheckIn\Policies\CheckInAssignmentPolicy` (a pure, DB-free static evaluator: `allows(capabilities, assigned): bool`, mirroring `App\Identity\Support\MfaEnforcementPolicy`'s precedent of separating the pure decision from whoever resolves the inputs) and the `CheckEventAssignment` Action (Data in and out, per the plan's Authorization semantics paragraph): `CheckEventAssignmentData` (`userId`, `eventId`, `capabilities`) in, `EventAssignmentData` (`authorized`) out. The Action short-circuits on `checkin.manage` before touching `check_in_assignments` at all, since a manage-holding caller is authorized regardless of any assignment row; otherwise it queries `check_in_assignments` for the (`event_id`, `user_id`) pair and hands the result to the policy. This is the sanctioned path the plan requires for Orders' signing-key endpoints (Task 7) to authorize without importing `App\CheckIn\Models` or querying `check_in_assignments` directly — `tests/Architecture/ContextBoundariesTest.php`'s existing "only the CheckIn context uses its own Models" rule already forbids the alternative.

Also added `ErrorCode::CheckinNotAssigned` (`checkin_not_assigned`, 403) to the shared registry, since the plan's Slice 2/3 authorization matrices name it explicitly (`checkin.scan` without assignment 403 `checkin_not_assigned`); no exception class consumes it yet; Tasks 7, 9, 10, and 12 wire it into their endpoints' denial paths.

TDD: `tests/Unit/CheckIn/CheckInAssignmentPolicyTest.php` (the pure evaluation matrix: scan+assigned ok, scan unassigned denied, manage unassigned ok regardless of assignment, no capability denied) and `tests/Unit/CheckIn/CheckEventAssignmentTest.php` (the same matrix through the Action against a real `check_in_assignments` row, plus scan+manage together) written first, confirmed failing (`Class "App\CheckIn\Policies\CheckInAssignmentPolicy" not found` / `Target class [App\CheckIn\Actions\CheckEventAssignment] does not exist`) before the policy, Data classes, and Action landed. `tests/Unit/Problems/ErrorCodeTest.php`'s registry and matrix datasets extended first for `checkin_not_assigned`, confirmed failing (registry array mismatch) before the enum case landed.

Test evidence: `php artisan test tests/Unit/CheckIn/CheckInAssignmentPolicyTest.php tests/Unit/CheckIn/CheckEventAssignmentTest.php tests/Unit/Problems/ErrorCodeTest.php` (104 passed, 389 assertions); `php artisan test --testsuite=Architecture` (40 passed, 97 assertions); `php artisan test --testsuite=Isolation` (294 passed, 578 assertions); `php artisan test --testsuite=Contract` (390 passed, 2501 assertions); `./vendor/bin/pint --dirty --test` clean. Two new Data classes were added (`CheckEventAssignmentData`, `EventAssignmentData`), so `composer types:generate` was run and the regenerated `packages/api-client/src/generated/{index.ts,typescript-transformer-manifest.json}` are committed alongside.

Commit: `9bf802e` feat(checkin): add CheckInAssignmentPolicy and CheckEventAssignment Action.

No deviations from the plan.

#### T7: 2026-07-12

Added `App\Orders\Http\Controllers\SigningKeyController` with `GET /v1/events/{event}/signing-keys` (checkin.scan plus an assignment row, or checkin.manage as a bypass, evaluated by the CheckIn context's `CheckEventAssignment` Action so this Orders controller never imports CheckIn models or queries `check_in_assignments` directly) and `POST /v1/events/{event}/signing-keys` (checkin.manage alone, enforced by the ordinary `RequireCapability` route middleware plus `RecordActivityAudit`, mirroring `PromoCodeAdminController`'s own split between a custom-authorized GET and a plain capability-gated mutation). Response shape is `SigningKeyData` (`id`, `key_version`, `secret`, `status`, `activated_at`, `retired_at`) wrapped in an unpaginated `SigningKeyList` envelope (`{data: [...]}`, mirroring `CapabilityListData`'s own additionalProperties: false rationale); `secret` is base64-encoded on the wire since the stored value is opaque binary (HKDF or `random_bytes` output, not guaranteed valid UTF-8) and a raw JSON string embedding failed with a `Malformed UTF-8 characters` 500 during the first green attempt. Request shape is `RotateSigningKeyData` (`revoke_previous`, optional, default false).

Two small cross-context read seams were added because none of the existing ones fit staff (not storefront-published-only) event lookups or capability introspection: `App\EventCatalog\Actions\CheckEventExists` (any-status event existence for the acting tenant, unlike `ResolveEventForHold`'s published-only posture) backing the controller's `event_not_found` 404, and `App\Identity\Actions\ResolveActingCapabilities` (the acting membership's capabilities list, without exposing the `Membership` model itself) so the controller can build `CheckEventAssignmentData` without importing an Identity model. Both follow the existing boundary-rule pattern of Action-only cross-context reads (`ResolveEventForHold`, `ResolveActingMembership`). Also added `App\Orders\Exceptions\SigningKeyEventNotFoundException` (code `event_not_found`) and `App\CheckIn\Exceptions\CheckinNotAssignedException` (code `checkin_not_assigned`, thrown for both denial reasons `CheckEventAssignment` can report — no capability at all, or checkin.scan without an assignment — since each endpoint's Errors table in the stage-09 plan lists only one 403 code for this endpoint).

TDD: `tests/Feature/Orders/SigningKeyEndpointsTest.php` written first (rotation increments `key_version` and returns 201; `revoke_previous: true` marks the outgoing key revoked; GET returns active and retired keys with secrets, excludes revoked; the authorization matrix — checkin.scan without assignment 403 `checkin_not_assigned` on GET, checkin.scan cannot POST even when assigned, no checkin capability at all denied on both, checkin.manage can do both; 404 `event_not_found` on both; an activity_log entry for the rotation), confirmed failing (generic `request.not_found` fallback, no route registered) before the contract (Data classes, OpenAPI path and schemas) and controller landed.

One correctness deviation surfaced mid-implementation, not a plan deviation: the test's first assumption that a fresh event's first `POST` returns `key_version: 1` was wrong given `RotateSigningKey`'s actual contract (it always retires the current active key and activates the next version, even when `GetOrCreateActiveSigningKey` had to seed that active key moments earlier in the same call) — corrected the test to expect `key_version: 2` on the first rotation and `3` on the second, which matches the Task 5 concurrency test's own "seeded plus one per worker" accounting.

Test evidence: `php artisan test tests/Feature/Orders/SigningKeyEndpointsTest.php` (9 passed, 43 assertions); `php artisan test --testsuite=Architecture` (40 passed, 97 assertions, after adding both new exception classes to the Laravel preset's `ignoring()` list, the same treatment every other domain exception already receives); `php artisan test --testsuite=Contract` (398 passed, 2553 assertions, after registering eight new exercisers in `DocumentedResponseCoverageTest` for the two new operations' 200/401/403/404 and 201/401/403/404 responses); `php artisan test --testsuite=Isolation` (294 passed, 578 assertions, unaffected); `php artisan test tests/Feature/Orders tests/Unit/Orders tests/Unit/CheckIn` (181 passed, 576 assertions); `./vendor/bin/pint --dirty` clean. Two new Data classes were added (`SigningKeyData`, `SigningKeyListData`, `RotateSigningKeyData`), so `composer types:generate` was run and the regenerated `packages/api-client/src/generated/{index.ts,typescript-transformer-manifest.json}` are committed alongside.

Commit: `ea9e5b6` feat(orders): add GET/POST signing-keys endpoints with checkin assignment authz.

No deviations from the plan beyond the test-correctness detail noted above.

#### T8: 2026-07-12

Added two Orders read Actions with no endpoint of their own, consumed by Tasks 9 and 10: `ListEventTickets` (`Ticket::query()->where('event_id', ...)->orderBy('id')->get()`, deterministic order matching the manifest's own ordering requirement, mapped to `TicketManifestEntryData`: `ticket_id`, `status`, `rotation_counter`, `updated_at`) and `VerifyCheckInQr` (`VerifyCheckInQrData` in, `QrVerificationResultData` out, typed by the new `QrVerificationOutcome` enum). `VerifyCheckInQr` is deliberately separate from Stage 7's `TicketQrCodec::verify`, which only ever checks the event's single active key through `TicketSigningKeyProvider`: this Action queries `event_signing_keys` directly, decodes the payload once, then trials every one of the event's keys ordered `key_version` desc, first over non-revoked (active/retired) keys, then, only if none matched, over revoked keys for the sole purpose of telling `qr_key_revoked` apart from `qr_signature_invalid` — this is the trial mechanism Task 5's deviation note flagged as Task 8's responsibility. A verified signature then checks the ticket exists (raw `DB::table('tickets')` read, not the Eloquent model, so an out-of-enum `status` value classifies rather than throwing), the rotation counter matches (`ticket_rotation_stale` otherwise), and finally the ticket status, delegated to a new pure helper `TicketCheckInStatusMapper::classify(string $rawStatus): QrVerificationOutcome` (issued to `Valid`, canceled/refunded to their codes, anything else to the new defensive `TicketStatusUnknown` case) kept separate from the Action specifically so the four-branch mapping is unit-testable without a database round trip.

TDD: `tests/Unit/Orders/TicketCheckInStatusMapperTest.php` (the four pure mapping branches, including an out-of-enum string producing `TicketStatusUnknown` instead of a `ValueError`), `tests/Unit/Orders/ListEventTicketsTest.php` (every ticket for the event in ticket-ID order; status, rotation counter, and `updated_at` carried through; empty collection for a ticketless event), and `tests/Unit/Orders/VerifyCheckInQrTest.php` (signature verified under the active key; verified under an older retired key when the active key does not match, proving the trial does not stop at the first non-match; classified `qr_key_revoked` only when a revoked key matches and no active/retired key does; classified `qr_signature_invalid` for both a non-matching signature and an undecodable payload; `ticket_not_found` for a verified payload naming a ticket that does not exist; `ticket_rotation_stale` for a counter mismatch; `ticket_canceled` and `ticket_refunded` for those statuses; `ticket_status_unknown` for a raw out-of-enum status written via `DB::table('tickets')->update(...)`) written first, confirmed failing (`Class "App\Orders\Actions\ListEventTickets" not found` / `Class "App\Orders\Actions\VerifyCheckInQr" not found`) before the Data classes, enum, mapper, and two Actions landed. No endpoint exists yet for either Action (Tasks 9 and 10 own the endpoints that will call them), so per the task's own scope this slice skipped the outside feature test and OpenAPI fragment steps of the master plan's double loop; that is not a deviation, it is the task description's explicit sequencing ("unit tests for every typed failure branch").

`tests/Architecture/PresetTest.php` needed `QrVerificationOutcome` added to the Laravel preset's `ignoring()` list, the same treatment `SigningKeyStatus` and `TicketStatus` already receive.

Test evidence: `php artisan test tests/Unit/Orders/TicketCheckInStatusMapperTest.php tests/Unit/Orders/ListEventTicketsTest.php tests/Unit/Orders/VerifyCheckInQrTest.php` (17 passed, 29 assertions); `php artisan test tests/Unit/Orders tests/Unit/CheckIn` (132 passed, 261 assertions); `php artisan test --testsuite=Architecture` (40 passed, 97 assertions); `php artisan test --testsuite=Isolation` (294 passed, 578 assertions, unaffected); `php artisan test --testsuite=Contract` (398 passed, 2553 assertions, unaffected); `./vendor/bin/pint --dirty` clean (auto-fixed import ordering in the new test file once). Three new Data classes were added (`TicketManifestEntryData`, `VerifyCheckInQrData`, `QrVerificationResultData`), so `composer types:generate` was run and the regenerated `packages/api-client/src/generated/{index.ts,typescript-transformer-manifest.json}` are committed alongside.

Commit: `d711198` feat(orders): add ticket listing and QR verification Actions for check-in.

No deviations from the plan beyond the intentional no-endpoint-yet scoping noted above.
