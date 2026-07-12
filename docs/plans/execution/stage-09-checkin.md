# Stage 9 Execution Journal: Check-in and Offline Reconciliation

## Run: 2026-07-12

- Stage: 9 (docs/plans/stage-09-checkin.md)
- Date: 2026-07-12
- Branch: feat/api-implementation
- Base commit: 4d53c0b65d25eaf054461d5e54d012e35df9779c

### Pre-run verification

Nothing from this stage has landed: there is no `app/CheckIn` bounded context, no `check_ins`, `check_in_assignments`, or `event_signing_keys` migrations, and the capability registry has `checkin.scan` but not `checkin.manage`. Stage 7's `TicketSigningKeyProvider` seam and `DerivedTicketSigningKeyProvider` exist and are the swap target. All fourteen plan tasks remain.

### Task checklist

- [ ] T1 `checkin.manage` capability and template-role wiring (identity)
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
