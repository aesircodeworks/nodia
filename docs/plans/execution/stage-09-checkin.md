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
- [ ] T2 `check_ins` table with RLS, model, enum, factory, isolation tests (checkin)
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
