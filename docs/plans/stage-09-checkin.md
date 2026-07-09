# Stage 9: Check-in and Offline Reconciliation

Status: Not started. This stage delivers the full check-in contract from system-design 11, not the roadmap Phase 5 reduced version. Per the master plan, Stage 9 is independent of Stages 10 and 11 and can start once Stage 7 merges; only end-to-end coverage that starts from a purchase waits on slices 8a through 8c (8d, blocked on the ADR, is never a gate).

## Scope and non-goals

### Delivers

- The manifest surface as two endpoints: a cursor-paginated ticket manifest (ticket IDs, status, rotation counter, check-in state) and a key-distribution endpoint carrying the event's signature keys. Together they cover system-design 11's manifest (ticket IDs, signature keys, and status), scoped to the event and to a check-in role, suitable for pre-doors device sync (ADR 015 makes these endpoints the shared contract for the PWA and the future native app).
- Per-event QR signing keys with rotation: a key store, a rotation Action and endpoint, and verification that tries the event's keys newest-first, so devices holding synced keys can validate QR signatures offline across rotations (system-design 11, "manifest keys rotate per event"). Provider-swap continuity is part of this deliverable: an event's version 1 stored key is seeded from the exact Stage 7 HKDF derivation for that event, so QR payloads rendered before the swap (already in emails and PDFs) keep verifying after it.
- Online scan recording as a first-scan-wins invariant: the guard is evaluated in the same statement that mutates, checked by affected-row count, per data-conventions Status Columns and State Transitions and system-design 6.1's pattern applied to check-in.
- Batch reconciliation for offline scan queues: cross-device duplicates for the same ticket are resolved first-scan-wins by `scanned_at` timestamp, and losing scans are persisted and flagged with `DuplicateScanDetected` rather than dropped (system-design 11, Reconciliation).
- Device identity on every check-in record (`device_id`, system-design 8.3 `CHECK_IN`).
- Per-event check-in assignments: the mechanism that scopes a check-in role to specific events (system-design 11, "check-in role scoped to specific events"), layered on the Stage 3 capability RBAC.
- Check-in domain events (`TicketCheckedIn`, `DuplicateScanDetected`, system-design 9.3 group 6) recorded to the outbox in the producing transaction, feeding Stage 11 reporting (system-design 9.2).

### Non-goals

- No frontend work of any kind; `apps/checkin` (PWA scanner, IndexedDB queue, service worker) is out of scope for this whole plan (api-implementation-plan.md, intro).
- No reporting projections or attendance dashboards; Stage 11 consumes this stage's events.
- No device registry, pairing tokens, or device-level credentials. Devices authenticate as staff (system-design 11); `device_id` is an opaque client-generated identifier recorded for attribution. A registry, if ever needed, is a later additive feature.
- No rate limiting tiers or abuse controls on scan endpoints; Stage 10 owns rate limiting, Stage 12 owns the security sweep.
- No retention or archival policy for check-in rows; Stage 12 owns retention windows.
- No check-in time-window policy (rejecting scans before doors or after event end). Deferred until the design defines it; recorded as an open question below.
- No changes to QR payload semantics at all. The signed payload is Stage 7 property and its format is pinned: base64url of ticket ID, event ID, and rotation counter plus an HMAC over those three fields, with no key version (stage-07-orders.md, "QR payload design"; system-design 8.3 notes). This stage adds the per-event key store behind Stage 7's `TicketSigningKeyProvider` seam; because the payload names no key, verification resolves the signing key by trying the event's keys newest-first.

## Dependencies

### Requires

- Stage 1: RFC 9457 problem handler and code registry, contract suite, real Isolation and Concurrency harnesses, time control for anything timestamp-sensitive.
- Stage 2: tenant resolution, RLS bootstrap and policy pattern, the two-tenant isolation fixture.
- Stage 3: staff authentication, `X-Tenant-Id` membership validation, capability RBAC, the `checkin.scan` capability, and the global Check-in Agent role template, all of which Stage 3 already ships (its initial registry and template seeder, system-design 5.3); this stage adds only the `checkin.manage` capability and its template-role wiring. Also the activity log for staff mutations.
- Stage 4: outbox recording API, dispatcher, delivery tracking, so both check-in events are recorded in the producing transaction and are consumable by Stage 11.
- Stage 7: `tickets` with status (`issued`, `canceled`, `refunded`), the per-ticket rotation counter, and QR payload generation and signing. Stage 9 needs Orders Actions that expose ticket facts to the CheckIn context without cross-context table access: a per-event ticket listing for the manifest and a QR verification Action for scans (boundary rule, system-design 3.1). Stage 7's plan signs through the `TicketSigningKeyProvider` interface with an HKDF-derived per-event key and pins the payload format (ticket ID, event ID, rotation counter, HMAC; no key version); this stage swaps the provider to `event_signing_keys` behind that seam without touching the codec or the payload format, and verification identifies the signing key by trial: active first, then retired newest-first, with revoked keys tried solely to classify the failure.
- Stage 8a: only indirectly; tickets exist only on the transition to `paid` (system-design 7.1), so end-to-end tests that start from a purchase need 8a. Stage 9's own tests fabricate issued tickets through factories and Orders Actions and do not require the payment path, which is why the master plan lets Stage 9 start once Stage 7 merges and reserves the 8a-through-8c gate for purchase-path end-to-end coverage only.

### Consumed by

- Stage 11: Reporting projectors subscribe to `TicketCheckedIn` and `DuplicateScanDetected` for attendance read models (system-design 9.2).
- Stage 12: the API-level smoke suite includes check-in; the security sweep asserts isolation and authorization coverage for this stage's endpoints; retention policy covers `check_ins`.

## Data model

Three new tables. Every table is tenant-scoped and ships its single-table RLS policy comparing `tenant_id` to `current_setting('app.tenant_id')` in the same migration that creates it (data-conventions, Tenancy; ADR 003). All primary keys are UUIDv7 via `HasUuids` (ADR 005). No money columns exist in this stage, so the money conventions are trivially satisfied. All timestamps UTC (data-conventions, Tables and Keys).

### check_ins (CheckIn context)

Matches system-design 8.3 `CHECK_IN` plus three additive columns justified here.

- `id` uuid PK (UUIDv7)
- `tenant_id` uuid non-null, FK `tenants`
- `ticket_id` uuid non-null, FK `tickets` (DB-level FK is fine; the code boundary rule concerns model imports and queries, and the ERD in system-design 8.3 already draws `TICKET ||--o{ CHECK_IN`)
- `event_id` uuid non-null, FK `events`. Additive denormalization beyond the 8.3 sketch: lets the CheckIn context answer manifest overlays and event-scoped queries from its own table without touching Orders tables, the same rationale as the denormalized `tenant_id` (system-design 4.2)
- `user_id` uuid non-null, FK `users` (the scanning staff identity, system-design 8.3)
- `device_id` string non-null (opaque client identifier)
- `client_scan_id` uuid non-null. Additive: client-generated per scan, the idempotence key for online replays and offline batch resubmission
- `result` string non-null, enum-backed: `accepted`, `duplicate`. Additive: system-design 11 requires losing scans to be persisted and flagged, so the row itself must say which kind it is
- `scanned_at` timestamp non-null (client-attested moment of scan)
- `synced_at` timestamp non-null (server receipt time; equals request time for online scans)
- `created_at`, `updated_at`

Constraints and indexes:

- Partial unique index `check_ins_accepted_ticket_idx` on (`ticket_id`) where `result = 'accepted'`: the first-scan-wins invariant is structural, exactly one accepted check-in per ticket can exist (custom name because the builder cannot express partial indexes, data-conventions, Tables and Keys)
- Unique index on (`tenant_id`, `device_id`, `client_scan_id`): batch and online replay idempotence
- Index on (`event_id`, `synced_at`): manifest overlay and incremental sync queries
- RLS policy in the same migration

### event_signing_keys (Orders context)

Owned by Orders, co-located with QR generation and the per-ticket rotation counter it signs over. The CheckIn context obtains keys through an Orders Action for the manifest; it never reads this table. This placement also keeps Stage 7 (which precedes this stage and signs QRs) free of any dependency on the CheckIn context. The decision is recorded here because system-design 3.2 does not assign this table; it is flagged for review as an additive refinement of the design, not a contradiction of it.

- `id` uuid PK (UUIDv7)
- `tenant_id` uuid non-null, FK `tenants`
- `event_id` uuid non-null, FK `events`
- `key_version` integer non-null, monotonic per event
- `secret` text non-null, encrypted at rest via Laravel's encrypted cast; it leaves the API only on the manifest key endpoint below
- `status` string non-null, enum-backed: `active` (signs new renders and verifies), `retired` (verifies only, still distributed in the manifest), `revoked` (neither verifies nor appears in the manifest)
- `activated_at` timestamp non-null, `retired_at` timestamp nullable, `revoked_at` timestamp nullable
- `created_at`, `updated_at`

Constraints and indexes:

- Unique on (`event_id`, `key_version`)
- Partial unique index `event_signing_keys_active_event_idx` on (`event_id`) where `status = 'active'`: exactly one active key per event
- Rotation is a conditional UPDATE retiring the current active key (`WHERE event_id = ? AND status = 'active'`, affected-row count checked) plus an insert of the new active key, in one transaction; the partial unique index backstops races
- Seeding: get-or-create of an event's version 1 key never generates fresh material; it derives the secret with the same HKDF (application secret plus event ID) the Stage 7 provider used, so the provider swap is invisible to payloads already rendered. Keys from version 2 onward are randomly generated by rotation
- RLS policy in the same migration

### check_in_assignments (CheckIn context)

The event-scoping layer for check-in roles.

- `id` uuid PK (UUIDv7)
- `tenant_id` uuid non-null, FK `tenants`
- `event_id` uuid non-null, FK `events`
- `user_id` uuid non-null, FK `users`
- `created_at`, `updated_at`

Constraints and indexes:

- Unique on (`tenant_id`, `event_id`, `user_id`)
- RLS policy in the same migration

Authorization semantics: manifest, keys, scan, and batch endpoints require the `checkin.scan` capability on the acting membership's role plus an assignment row for the target event; a role holding `checkin.manage` bypasses the assignment requirement and may also manage assignments and rotate keys. Policies evaluate capability plus tenant context, never role names (system-design 5.3). Assignment evaluation is exposed as a CheckIn Action, `CheckEventAssignment` (Data in and out), so the Orders-context signing-key endpoints authorize without importing CheckIn models or querying `check_in_assignments` (boundary rule, system-design 3.1).

## Domain events

Both events already exist in the registry (system-design 9.3, group 6); no registry change is needed. Envelope per event-conventions on every row: UUIDv7 `id`, global `sequence`, `type`, non-null `tenant_id`, `aggregate_type` and `aggregate_id`, `correlation_id` propagated from the request, `occurred_at`, snake_case laravel-data `payload`. Recorded in the same database transaction as the check-in row insert, without exception.

### Produced

- `TicketCheckedIn`: aggregate `ticket`/`ticket_id`. Payload: `check_in_id`, `ticket_id`, `event_id`, `device_id`, `user_id`, `scanned_at`. Recorded exactly once per ticket: the recording call sits in the same transaction as the `accepted` insert, and the partial unique index guarantees at most one such insert ever commits. A reconciliation swap (below) does not re-record it; the ticket was already checked in, attendance counts are unaffected.
- `DuplicateScanDetected`: aggregate `ticket`/`ticket_id`. Payload: `check_in_id`, `ticket_id`, `event_id`, `device_id`, `user_id`, `scanned_at`, `first_check_in_id`, `first_scanned_at`, `first_device_id`. Recorded once per `duplicate` row insert, including the row demoted by a reconciliation swap. This is the staff follow-up signal (system-design 11); it is never dropped.

Payload evolution is additive-only; any future breaking need is a new event type, never a version field (event-conventions, Payloads).

### Consumed

None. Manifest data and scan-time ticket facts come synchronously from Orders Actions, not from an event-built projection; the single shared database makes the projection's staleness cost pure loss here. Stage 11's reporting projectors are the consumers of this stage's events and are idempotent by event ID with progress in `outbox_deliveries` (event-conventions, Delivery and Consumption).

### Idempotence notes

- Producer side: replays of the same scan (same `device_id` and `client_scan_id`) short-circuit on the unique index and record no second event.
- Consumer side (Stage 11): duplicate delivery of either event must cause exactly one projection effect, proven by that stage's duplicate-delivery tests; nothing in this stage's payloads assumes single delivery.

## Endpoints

All routes versioned under `/v1` (api-conventions). All are staff endpoints: bearer JWT plus `X-Tenant-Id` validated against memberships. Wire format snake_case JSON; timestamps ISO 8601 UTC; every error is an RFC 9457 problem document with a stable `code`. Each endpoint's OpenAPI fragment merges with the endpoint (api-conventions, Requests and Responses). Request and response shapes are laravel-data objects in `app/CheckIn/Data/` and `app/Orders/Data/`; TypeScript regenerated after every Data change.

### GET /v1/events/{event}/check-in-manifest

CheckIn context. Authz: `checkin.scan` plus assignment (or `checkin.manage`). Cursor-paginated (high-volume collection, api-conventions, Lists), deterministic order by ticket ID. Optional `filter[updated_since]` (ISO 8601) for incremental re-sync: an entry is included when the greater of its ticket's `updated_at` (carrying Orders-side changes: status, rotation counter) and its accepted check-in's `synced_at` (carrying check-in state from other devices) is after the filter value. Unknown filters rejected. Built by `BuildManifest` calling the Orders per-event ticket listing Action and overlaying `check_ins` accepted rows.

- Response `data` items, `ManifestEntryData`: `ticket_id`, `status` (ticket status enum on the wire), `rotation_counter`, `checked_in_at` (nullable). Deliberately no attendee PII: system-design 11 defines the manifest as ticket IDs, signature keys, and status, and keeping PII off door devices keeps GDPR surface small (system-design 14.3).
- Errors: 401; 403 `checkin_not_assigned`; 404 `event_not_found` (also the shape a cross-tenant probe sees under RLS).

### GET /v1/events/{event}/signing-keys

Orders context. Authz: `checkin.scan` plus assignment (or `checkin.manage`); the assignment check goes through the CheckIn `CheckEventAssignment` Action. Returns every non-revoked key for the event so a device can verify QRs rendered under retired versions. Bounded collection, unpaginated.

- Response `data` items, `SigningKeyData`: `id`, `key_version`, `secret`, `status`, `activated_at`, `retired_at`. This is the only place `secret` crosses the wire; TLS everywhere is assumed (system-design 14.1).
- Errors: 401; 403 `checkin_not_assigned`; 404 `event_not_found`.

### POST /v1/events/{event}/signing-keys

Orders context. Authz: `checkin.manage`. Rotates the event's key: retires the current active key via conditional UPDATE, inserts the next version as active. Request `RotateSigningKeyData`: `revoke_previous` (boolean, default false; true marks the outgoing key `revoked` so it stops verifying, the leak-response path). Mutation is activity-logged (system-design 14.2).

- Response: 201 with the new `SigningKeyData`.
- Errors: 401; 403 `auth.forbidden`; 404 `event_not_found`.

### POST /v1/check-ins

CheckIn context. Authz: `checkin.scan` plus assignment for the event named inside the verified QR payload. The online scan path: `RecordScan` verifies the QR through the Orders verification Action (signature tried against the event's stored keys newest-first, rotation counter currency, ticket status), then attempts the accepted insert.

- Request `RecordScanData`: `qr_payload`, `device_id`, `client_scan_id`, `scanned_at`.
- Response: 201 `CheckInResultData`: `check_in_id`, `ticket_id`, `event_id`, `result`, `scanned_at`, `synced_at`. Replay of the same (`device_id`, `client_scan_id`) returns 200 with the original result.
- Errors, each a problem document with the listed stable `code`:
  - 422 `qr_signature_invalid` (no non-revoked key verifies the HMAC; covers tampering and keys the event never issued, which the payload format makes indistinguishable), `qr_key_revoked` (the signature verifies only against a revoked key, which is tried solely for this classification), `ticket_rotation_stale` (counter mismatch, the screenshot case), `scanned_at_in_future` (beyond skew tolerance), plus standard validation `errors` maps
  - 404 `ticket_not_found`
  - 409 `ticket_already_checked_in` with extension members `first_scanned_at`, `first_device_id` (the duplicate row is still persisted and `DuplicateScanDetected` still recorded; the 409 is the door UI signal, not a refusal to record)
  - 409 `ticket_canceled`, 409 `ticket_refunded`
  - 403 `checkin_not_assigned`

### POST /v1/check-in-batches

CheckIn context. Authz: `checkin.scan`; each scan is additionally checked against the caller's event assignments. The offline reconciliation path: `ReconcileOfflineScans` processes up to 500 scans, returning a per-scan outcome; the batch never fails wholesale for per-scan problems. Idempotent per persisted scan by (`device_id`, `client_scan_id`): resubmitting a partially synced queue returns the recorded outcomes for scans that produced an `accepted` or `duplicate` row and records nothing new for them. Rejected scans are not persisted, so a resubmitted rejection is re-verified from scratch and may return a different outcome once the blocking condition clears (an assignment granted, a rotation counter re-synced); this re-verification is intended behavior, not an idempotence gap.

- Request `ReconcileBatchData`: `device_id`, `scans` array of `OfflineScanData` (`client_scan_id`, `qr_payload`, `scanned_at`).
- Response: 200 `BatchResultData` with `results`: array of `ScanOutcomeData`: `client_scan_id`, `outcome` (`accepted`, `duplicate`, `rejected`), `check_in_id` (nullable), `code` (nullable, the same stable codes as the single-scan endpoint for `rejected` and `duplicate` entries), `first_scanned_at` and `first_device_id` (nullable, populated for duplicates).
- Reconciliation semantics: for each ticket, the accepted check-in is the one with the earliest `scanned_at`; ties break deterministically by smallest `client_scan_id`, making resolution order-independent and replay-safe. If an incoming scan predates the currently accepted one, the resolution swaps them: a conditional UPDATE demotes the current accepted row to `duplicate` (`WHERE ticket_id = ? AND result = 'accepted' AND (scanned_at, client_scan_id) > (?, ?)`, affected-row count checked), the incoming scan inserts as `accepted`, and `DuplicateScanDetected` is recorded for the demoted row. Zero rows affected means the existing scan stands and the incoming one inserts as `duplicate`. The partial unique index backstops concurrent batches; a conflicting insert retries the resolution. Attendance is bookkeeping here: both attendees are already inside, the swap only corrects which scan counts as first.
- Errors: 422 for a malformed envelope or more than 500 scans (`batch_too_large`); 401; 403.

### Check-in assignments (CheckIn context, authz `checkin.manage`, activity-logged)

- `GET /v1/events/{event}/check-in-assignments`: page-paginated (bounded collection), `CheckInAssignmentData` items (`id`, `event_id`, `user_id`, `created_at`). Errors: 401, 403, 404 `event_not_found`.
- `POST /v1/events/{event}/check-in-assignments`: request `AssignCheckInUserData` (`user_id`). 201 with `CheckInAssignmentData`. Errors: 422 `user_not_member` (the user has no membership in the acting tenant), 409 `already_assigned`, 401, 403, 404.
- `DELETE /v1/check-in-assignments/{assignment}`: 204. Top-level resource to keep nesting at one level (api-conventions, URLs). Errors: 401, 403, 404 `assignment_not_found`.

## TDD sequencing

Every slice follows the master plan's double loop: outside feature test first, contract second, inside unit tests third, green, `composer types:generate`, commit. The three non-negotiable test-first rules all fire in this stage: every new tenant-scoped table starts with a failing isolation test, the first-scan-wins transition starts with a failing concurrency test, and the Stage 11 consumer side is out of scope but the producer-side idempotence (replay short-circuit) is concurrency-tested here.

### Slice 1: tables under RLS

Failing tests first:
- Isolation: two-tenant fixture attempts cross-tenant SELECT, INSERT, UPDATE, DELETE against `check_ins`, `event_signing_keys`, and `check_in_assignments`; every attempt fails under RLS before any endpoint exists.
- Unit: enum-backed `result` and `status` columns reject values outside their enums; the partial unique indexes reject a second `accepted` row per ticket and a second `active` key per event at the database level.

Implementation: three migrations, each creating its table and its RLS policy together; models with `HasUuids`, casts, factories.

### Slice 2: signing keys and rotation

Failing tests first:
- Feature: `GET /v1/events/{event}/signing-keys` returns active and retired keys with secrets, excludes revoked; `POST` rotates, incrementing `key_version`; `revoke_previous: true` marks the outgoing key revoked; both against the OpenAPI fragment; rotation appears in the activity log.
- Feature: authorization matrix rows: no capability 403, `checkin.scan` without assignment 403 `checkin_not_assigned` on GET, `checkin.scan` cannot POST, `checkin.manage` can.
- Unit: rotation Action retires via conditional UPDATE checked by affected-row count; get-or-create of the version 1 key is race-safe and seeds the secret from the Stage 7 HKDF derivation; secrets round-trip through the encrypted cast.
- Feature (the deployment-transition test): a QR payload signed by the Stage 7 derived-key provider before the swap verifies against the stored version 1 key after it; only a rotation with `revoke_previous: true` invalidates it.
- Concurrency: parallel rotation requests for one event leave exactly one active key and strictly monotonic versions.

### Slice 3: manifest

Failing tests first:
- Feature: manifest returns exactly the event's tickets with status, rotation counter, and `checked_in_at` overlay; cursor pagination with deterministic order; `filter[updated_since]` narrows on both sides of the overlay, including a ticket unchanged in Orders but checked in on another device after the filter timestamp appearing in the delta; unknown filter rejected; no PII fields present in the response shape; contract conformance.
- Feature (the master plan's scoping matrix): assigned user with `checkin.scan` succeeds on event A and gets 403 `checkin_not_assigned` on event B; role without the capability gets 403; `checkin.manage` succeeds unassigned; customer token is rejected.
- Isolation: a staff token from tenant 1 requesting tenant 2's event manifest sees 404 under RLS.
- Unit: `BuildManifest` consumes the Orders ticket-listing Action (Data objects in and out, no Orders model import; the Architecture suite enforces this globally) and overlays accepted check-ins correctly, including tickets with only `duplicate` rows showing `checked_in_at` from the accepted row only.

### Slice 4: online scan, first-scan-wins

Failing tests first:
- Concurrency (written before the endpoint exists, master plan test-first rule): N parallel `POST /v1/check-ins` for the same ticket from distinct devices produce exactly one `accepted` row, N-1 `duplicate` rows, exactly one `TicketCheckedIn` outbox row, and N-1 `DuplicateScanDetected` rows.
- Feature: happy path 201 with `result: accepted`; second scan 409 `ticket_already_checked_in` carrying `first_scanned_at` and `first_device_id` while still persisting the duplicate row and its event; replay of the same (`device_id`, `client_scan_id`) returns the original outcome and records nothing new; each rejection code in the endpoint table exercised (tampered signature, revoked key after `revoke_previous` rotation, stale rotation counter, canceled ticket, refunded ticket, unknown ticket, future `scanned_at`); all against the contract.
- Feature (rotated-keys matrix, master plan): a QR signed under key version 1 still validates after a plain rotation to version 2; the same QR fails with `qr_key_revoked` after rotation with `revoke_previous: true`; a QR signed with a key the event never issued fails `qr_signature_invalid`.
- Unit: the Orders QR verification Action covers signature, newest-first key trial (active, then retired, revoked only for classification), rotation-counter comparison, and ticket-status mapping to typed results; `RecordScan` records `TicketCheckedIn` in the same transaction as the insert (asserted by rolling back and finding neither row nor event).
- Isolation: scanning a ticket belonging to another tenant fails.

### Slice 5: batch reconciliation

Failing tests first:
- Feature (the cross-device duplicate matrix, master plan): same ticket scanned offline on two devices with overlapping queues, submitted in both orders: earlier-timestamp-first (later arrives as `duplicate`) and later-timestamp-first (swap: earlier arrival demotes the accepted row, one `DuplicateScanDetected` for the demoted scan, no second `TicketCheckedIn`); equal timestamps resolve by smallest `client_scan_id` identically regardless of submission order.
- Feature: partial outcomes in one batch (accepted, duplicate, rejected with per-scan `code`) with HTTP 200; full batch resubmission returns the recorded outcomes for accepted and duplicate scans and records nothing new for them; a scan rejected on first submission is re-verified on resubmission and succeeds once the blocking condition clears (asserted by granting the missing assignment between submissions); 501-scan batch rejected `batch_too_large`; scans for an unassigned event come back `rejected` with `checkin_not_assigned` without failing the batch; contract conformance.
- Concurrency: two devices submit overlapping batches in parallel; after both settle, every contested ticket has exactly one `accepted` row holding the earliest timestamp and exactly one `TicketCheckedIn`; a batch swap racing an online scan for the same ticket resolves to the same invariant.
- Unit: the resolution routine in isolation: demote-then-insert conditional UPDATE checked by affected-row count, retry-on-conflict against the partial unique index, tie-break determinism.

### Slice 6: assignments surface and wrap-up

Failing tests first:
- Feature: assignment CRUD with codes (`user_not_member`, `already_assigned`, `assignment_not_found`), pagination, activity-log entries, contract conformance.
- Isolation: assignment rows invisible and unwritable cross-tenant.
- Contract: full-surface conformance pass over every Stage 9 endpoint; TypeScript drift gate clean after regeneration.

## Task breakdown

Ordered; each independently mergeable. Scopes per the repo commit convention.

1. Capability registry addition `checkin.manage` plus its template-role wiring in the seeders; `checkin.scan` and the Check-in Agent template already exist from Stage 3. Scope `identity`.
2. `check_ins` migration with RLS, model, enum, factory, isolation tests. Scope `checkin`.
3. `check_in_assignments` migration with RLS, model, factory, isolation tests. Scope `checkin`.
4. `event_signing_keys` migration with RLS, model, enum, encrypted cast, factory, isolation tests. Scope `orders`.
5. Rotation Action, get-or-create-active-key Action seeding version 1 from the Stage 7 HKDF derivation, signer swap from the Stage 7 HKDF key provider to versioned event keys behind the `TicketSigningKeyProvider` seam (codec and payload format untouched), the pre-swap/post-swap deployment-transition test, rotation concurrency test. Scope `orders`.
6. Check-in policy layer: capability plus assignment evaluation, including the `CheckEventAssignment` Action the Orders signing-key endpoints consume; shared by all CheckIn endpoints. Scope `checkin`.
7. Signing-key endpoints with contract fragments and authorization matrix, authorizing through `CheckEventAssignment`. Scope `orders`.
8. Orders read Actions for CheckIn: per-event ticket listing Data and QR verification Action with typed failures, unit-tested. Scope `orders`.
9. Manifest endpoint: `BuildManifest`, cursor pagination, overlay, `filter[updated_since]`, contract fragment, scoping matrix, isolation coverage. Scope `checkin`.
10. `RecordScan` and `POST /v1/check-ins`: first-scan-wins insert, outbox recording in-transaction, replay short-circuit, full rejection-code matrix, rotated-keys matrix, concurrency test, contract fragment. Scope `checkin`.
11. `ReconcileOfflineScans` and `POST /v1/check-in-batches`: resolution routine with swap and tie-break, per-scan outcomes, batch idempotence, concurrency tests, contract fragment. Scope `checkin`.
12. Assignment endpoints with contract fragments and activity logging. Scope `checkin`.
13. Regenerate TypeScript, verify drift gate, full-surface contract pass. Scope `checkin`.
14. Update the master plan status table; update the roadmap Implementation Status table if roadmap Phase 5 is being satisfied by this stage. Scope `docs`.

## Exit criteria

The master plan gives Stage 9 a goal line, "the full check-in contract from system-design 11", rather than an exit line. Expanded into individually testable checks:

1. A staff user holding `checkin.scan` and an assignment can sync a complete manifest for their event: every issued ticket appears with status, rotation counter, and check-in state, PII-free, cursor-paginated; the same request 403s for an unassigned event and for a role without the capability, and 404s cross-tenant.
2. The manifest key surface delivers every key needed to verify any legitimately rendered QR for the event, and only those: retired keys verify, revoked keys do not and are absent.
3. Rotation produces exactly one active key per event under parallel rotation attempts, and a rotation with `revoke_previous: true` makes previously rendered QRs fail with `qr_key_revoked`.
4. Under N parallel online scans of one ticket, exactly one check-in is accepted, exactly one `TicketCheckedIn` is recorded, and every loser is persisted as a `duplicate` with its own `DuplicateScanDetected`; no simulation configuration violates the one-accepted-per-ticket invariant.
5. Offline queues from two devices containing the same ticket reconcile first-scan-wins by timestamp regardless of submission order, including the swap case, with deterministic tie-breaking; resubmitting any batch returns the recorded outcomes for accepted and duplicate scans without new writes, while rejected scans are re-verified by design.
6. Every check-in record carries device identity, the scanning user, the client-attested `scanned_at`, and the server `synced_at`.
7. Both check-in events are recorded in the same transaction as their state change with the full envelope (verified by rollback tests), and their payloads match the shapes registered here.
8. Every failure mode on every Stage 9 endpoint returns an RFC 9457 problem document with its stable `code` from the tables above, asserted by feature tests.
9. All three new tables have isolation coverage proving cross-tenant reads and writes fail; the Architecture suite shows no cross-context model imports (CheckIn reaches Orders only through Actions and Data objects).
10. Every endpoint's OpenAPI fragment is merged and conformance-checked; generated TypeScript is committed without drift; Larastan and Pint clean; all six suites green in `composer test` and CI.

## Risks and open questions

- Provider-swap continuity depends on the application-level HKDF secret being stable: version 1 seeding re-derives the Stage 7 key, so rotating that application secret between Stage 7 issuance and this stage's swap would strand every previously rendered payload. The secret must not rotate until the swap completes; if it must, every affected event needs an immediate key rotation plus ticket re-issuance through the resend pathway.
- Key resolution without a payload version: Stage 7's plan pins the payload format without a key version and promises the provider swap happens without touching the codec, so verification cannot name the key a QR was signed under and must try the event's keys newest-first. The cost is diagnostic: a QR signed with a key the event never issued is indistinguishable from tampering and returns `qr_signature_invalid` (there is no `qr_key_unknown` code), and revoked keys are still tried, solely to classify the failure as `qr_key_revoked`. Trial cost is bounded by the number of keys an event has ever rotated through, expected single digits; if rotation frequency ever makes trial verification expensive, an additive `key_version` payload field is a coordinated Stage 7 codec change, not a Stage 9 decision.
- Key ownership placement: `event_signing_keys` in Orders is a judgment call recorded above (co-location with the signer and with Stage 7's timeline). The alternative, CheckIn ownership with Orders calling a CheckIn Action to sign, was rejected because it points a Stage 7 dependency at a context that will not exist until Stage 9. The placement does give the Orders signing-key endpoints a Stage 9-internal dependency on CheckIn: their assignment check calls `CheckEventAssignment`. That is the sanctioned Action path, and it points at CheckIn only after the context exists, so the Stage 7 rationale stands. Flag at review; moving the table later is a context-boundary refactor, cheap now and expensive after Stage 11 consumes events.
- Client clock trust: first-scan-wins by `scanned_at` trusts device clocks. A skewed or hostile clock can win reconciliation. Mitigations in scope: future timestamps beyond a tolerance are rejected with `scanned_at_in_future`, and swaps never change admission (both attendees are inside), only attribution. A stronger scheme (server-anchored offsets per device sync) is deliberately out of scope; revisit if fraud follow-up shows clock gaming.
- Revocation versus already-delivered tickets: `revoke_previous` invalidates every QR already rendered into emails and PDFs for the event. That is the point (leak response) but it is operationally sharp: attendees need re-issued renders. The re-render and re-send path is Stage 7/8a machinery (resend tickets); confirm the resend action re-renders under the new key rather than reusing a cached PDF, and record the runbook alongside the rotation endpoint docs.
- Check-in window policy: nothing stops scanning a ticket for an event that has not started or ended long ago. System-design 11 is silent; deferred as a policy decision (likely a per-event setting). Recorded here so Stage 12's smoke suite does not silently encode an assumption.
- Manifest size and sync cost: for very large events the manifest is tens of thousands of rows per device. Cursor pagination plus `filter[updated_since]` bounds re-sync, but initial sync cost on venue Wi-Fi is a product concern for the PWA, not this API stage; the contract already supports delta sync so no API change is expected.
- `ticket_already_checked_in` as 409-with-side-effect: the endpoint persists the duplicate and its event while returning a problem document. That is unusual (errors normally imply no effect) but it is exactly what system-design 11 requires (flag, never drop). The contract documents the behavior explicitly; flagged so reviewers do not "fix" it into dropping duplicates.
- Reporting timestamp drift after swaps: a reconciliation swap changes which scan is the accepted one after `TicketCheckedIn` was recorded with the original scan's data. Attendance counts are unaffected; per-ticket first-scan timestamps in Stage 11 read models could lag reality by one swap. If Stage 11 needs exact timestamps, it can subscribe to `DuplicateScanDetected` (whose payload names the surviving first scan) or a future additive event; noted for that stage's plan.
