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
- [ ] T3 `check_in_assignments` table with RLS, model, factory, isolation tests (checkin)
- [ ] T4 `event_signing_keys` table with RLS, model, enum, encrypted cast, factory, isolation tests (orders)
- [ ] T5 Key rotation and get-or-create Actions, provider swap behind `TicketSigningKeyProvider`, deployment-transition and rotation concurrency tests (orders)
- [ ] T6 Check-in policy layer and `CheckEventAssignment` Action (checkin)
- [ ] T7 Signing-key endpoints with contract fragments and authorization matrix (orders)
- [ ] T8 Orders read Actions for CheckIn: per-event ticket listing and QR verification with typed failures (orders)
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
