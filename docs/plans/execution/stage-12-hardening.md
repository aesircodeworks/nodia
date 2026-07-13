# Stage 12 Execution Journal: Compliance, Operations, Hardening

## Run: 2026-07-13

- Stage: 12 (docs/plans/stage-12-hardening.md)
- Date: 2026-07-13
- Branch: feat/api-implementation
- Base commit: a6cd0cd1e9493cf555789c7681befa0e572f3e71

### Pre-run verification

Nothing from this stage has landed. Verified against the codebase, not the docs:

- No `data_subject_requests` or `archive_segments` migration; the migration set has no `payload_pruned_at` column on `gateway_webhook_events` (`2026_07_11_000040_create_gateway_webhook_events_table.php`).
- `customers.anonymized_at` exists from Stage 3 (`2026_07_09_000017_create_customers_table.php`), but no `AnonymizeCustomer` Action, no `CustomerAnonymized` event class, and no reference to it anywhere outside the column itself.
- No `customers.erase` or `customers.export` capability in the Identity capability registry.
- No `config/retention.php`; the config set ends at `tenancy.php` with no retention windows anywhere.
- No archival, pruning, `outbox:replay-failed`, `payments:reconcile`, or `holds:release-stuck` commands. `app/Console/Commands` holds the earlier-stage commands only (`ReplayOutboxCommand`, `SweepOutboxCommand`, `ReconcilePendingPaymentsCommand`, `ReleaseExpiredHoldsCommand`, `ReportingRebuildCommand`, and the payments/search/gateway helpers).
- `phpunit.xml` declares six suites (Feature, Unit, Contract, Architecture, Isolation, Concurrency); there is no `Smoke` suite.
- No `infra/load/` directory (only `infra/compose`) and no `docs/load-targets.md`.
- No coverage-completeness meta-tests, no `env()` architecture test, no secret scanner in CI.

Dependencies are in place: Stage 3 (customers with `anonymized_at`, guest claim, activity log, `RevokeAllUserTokens` and the refresh-token family revocation primitives), Stage 4 (outbox recording, `outbox_deliveries` idempotence, `OutboxReplay` with its stability window, `failed_jobs`), Stages 5a through 9 (the full publish, hold, order, payment, refund, scan path the smoke suite drives, plus `gateway_webhook_events` and the reconcile and hold-sweeper paths the operational commands wrap), Stage 10 (waiting room admission, shipped in full, so the third load scenario is not omitted), and Stage 11 (read models for the `CustomerAnonymized` scrub, and `BuildExport`'s cursor-paginated `ExportSource` pattern plus the Orders, Payments, and CheckIn read Actions it added, which the data subject export assembler reuses).

Stage 8d is still In progress and gated on the launch-gateway ADR. Per the stage plan's Scope section, dispute ingestion stays deferred and nothing here blocks on it; the webhook negative matrix (T15) covers registered gateways, so it picks up the 8d adapter only if it has merged by then.

### Task checklist

- [x] T1 `data_subject_requests` migration, model, enums, conditional status transitions (identity)
- [ ] T2 `AnonymizeCustomer`, `CustomerAnonymized`, erasure endpoint, registry update (identity, docs)
- [ ] T3 Orders `CustomerAnonymized` consumer scrubbing `attendee_name` (orders)
- [ ] T4 Reporting `CustomerAnonymized` consumer (reporting)
- [ ] T5 Data subject export assembler and queued job (identity)
- [ ] T6 Export download URL plus list and show endpoints (identity)
- [ ] T7 `config/retention.php`, webhook payload pruner, export attachment pruner, scheduler (payments, identity)
- [ ] T8 Activity log scoped delete path plus system-design 14.2 amendment (support, docs)
- [ ] T9 `archive_segments` migration and activity log archive-then-prune command (support)
- [ ] T10 Outbox archiver and archive-aware replay (support)
- [ ] T11 `outbox:replay-failed` command (support)
- [ ] T12 `payments:reconcile` command (payments)
- [ ] T13 `holds:release-stuck` command with the sweeper-race concurrency test (inventory)
- [ ] T14 Coverage completeness, authorization matrix, and payload PII meta-tests (support)
- [ ] T15 Webhook negative matrix across registered gateways (payments)
- [ ] T16 Secret scanner in CI, `env()` architecture test, `.env.example` assertions (ci)
- [ ] T17 Smoke suite and required CI gate (support, ci)
- [ ] T18 Load tooling, `infra/load/` scenarios, `docs/load-targets.md` (ci, docs)

### Review rounds

### Decisions and deviations

## T1: data_subject_requests table, model, enums, conditional status transitions

2026-07-13 15:15 -03

Landed stage-12 plan Data model "data_subject_requests" (task breakdown item 2): the schema, the model's conditional-UPDATE transitions, and the two new capabilities the later endpoint and command tasks gate on. No Data class, no endpoint, no OpenAPI path in this task (per the task's own scope), so the double-loop's contract step doesn't apply here; task 2 (`AnonymizeCustomer`, the erasure endpoint) carries the first contract step for this table.

What landed:

- `database/migrations/2026_07_13_000060_create_data_subject_requests_table.php`: UUIDv7 PK, `tenant_id`, real FK `customer_id` (not nullable, unlike `holds.customer_id`: a data subject request always names a real customer), `type` and `status` as plain strings backed by the new enums (the `orders.status`/`OrderStatus` posture, no database check constraint), real FK `requested_by_user_id` into `users` (mirroring `exports.requested_by_user_id`), nullable `completed_at`, an index on `(tenant_id, customer_id)` for the admin list filter, a partial unique index `data_subject_requests_open_per_customer_idx` on `(customer_id, type) where status in ('pending', 'processing')` (custom name per data-conventions' index-naming rule, mirroring `event_signing_keys_active_event_idx`'s own precedent for a builder-inexpressible index), and `Rls::applyTenantPolicies('data_subject_requests')` in the same migration (no platform-write policy: the plan names cross-tenant platform reads only).
- `App\Identity\Enums\DataSubjectRequestType` (`erasure`, `export`) and `App\Identity\Enums\DataSubjectRequestStatus` (`pending`, `processing`, `completed`, `failed`), neither marked `#[TypeScript]` yet: no Data class exposes them on the wire until task 2, mirroring `ExportType`/`ExportStatus`'s own precedent (stage-11 T10) of a bare backed enum with no wire attribute until something types it directly.
- `App\Identity\Models\DataSubjectRequest`: `HasUuids`, `HasFactory`, `#[Fillable]`, enum casts for `type`/`status`, `datetime` cast for `completed_at`. Two static transition methods, each a single conditional UPDATE guarded on the current status and checked by affected-row count, never read-then-write: `claim()` (pending to processing, the exactly-one-worker guard) and `complete()` (processing to completed, stamps `completed_at`); a third, `fail()` (processing to failed), does not stamp `completed_at`, mirroring `App\Reporting\Models\Export::fail()`'s own exact precedent of leaving its terminal timestamp unset on the failure edge. Placed on the model itself rather than a dedicated Action class, the same reasoning `Export`'s own docblock gives: no caller exists yet (tasks 2 and 5 are the future callers).
- `Database\Factories\Identity\Models\DataSubjectRequestFactory`: `tenant_id`, `customer_id`, and `requested_by_user_id` have no default, mirroring `ExportFactory`; defaults to `erasure`/`pending` with no `completed_at`.
- `App\Identity\Capability`: added `CustomersErase = 'customers.erase'` and `CustomersExport = 'customers.export'`, additive at the end of the enum, neither marked financially privileged (not money-shaped).
- Template role seeds decision (explicit, since the task names no specific role): granted both new capabilities to Owner only, not Event Manager, Box Office, Finance, or Check-in Agent. Reasoning: erasure and export are destructive/PII-sensitive compliance actions gated behind the highest-privilege template, distinct from the broader `customers.view` grant (Owner, Box Office, Finance) that only reads customer records; nothing in the stage-12 plan text names a second role, so the narrower grant is the minimal reading, and the two new tests below make the choice explicit rather than implicit, so a later task can widen it deliberately if a feature test demands it. Owner's automatic "every capability except tenants.manage" invariant (`tests/Unit/Identity/SeedTemplateRolesTest.php`, pre-existing) required the new capabilities on Owner's own list to keep passing, independent of this choice.
- `tests/Isolation/Support/DataSubjectRequestFixture.php` and `tests/Isolation/DataSubjectRequestsIsolationTest.php`: one open (pending) request per tenant, built on `CustomerFixture`; own-row visibility, cross-tenant select/update/delete all affecting zero rows, a foreign `tenant_id` insert rejected by `WITH CHECK`, a raw-SQL query still isolated, `nodia_platform` cross-tenant read, `nodia_platform` write rejected (no platform-write policy), and an insert violating the partial unique index rejected — the requesting staff users are created inline under `nodia_platform`, mirroring `CheckInFixture`'s own posture for `user_id`.
- `tests/Unit/Identity/DataSubjectRequestClaimTest.php`: the pending-to-processing claim wins exactly once and a second claimant gets zero affected rows (the task's named acceptance case), plus `complete()` stamping `completed_at`, `complete()` refusing an unclaimed (still-pending) row, and `fail()` succeeding without stamping `completed_at`.
- `tests/Unit/Identity/CapabilityTest.php` and `tests/Unit/Identity/SeedTemplateRolesTest.php`: extended the exact-registry list and the not-financially-privileged dataset with the two new capabilities; added a new assertion that Owner alone carries `customers.erase`/`customers.export` and the other four templates carry neither.
- `tests/Architecture/PresetTest.php`: added `DataSubjectRequestType::class` and `DataSubjectRequestStatus::class` to the Laravel preset's `ignoring()` list (the preset expects backed enums only under `App\Enums`; every other context's own status/type enum is already exempted the same way, e.g. `MembershipScope`, `ExportType`), with a docblock note following the file's own running commentary convention. No exemption needed for `DataSubjectRequest` itself: `'App\Identity\Models'` was already on the list from Stage 3.

Test evidence:

- Confirmed red first: moved the migration, model, enums, and factory files aside and reverted the `Capability`/`SeedTemplateRoles` edits, then ran the new Isolation and Unit files. Isolation failed with `Class "App\Identity\Models\DataSubjectRequest" not found`; the Unit/Capability/SeedTemplateRoles files failed to load (`Capability::CustomersErase`/`CustomersExport` undefined, breaking the `->with([...])` dataset resolution). Restored all files and re-applied the two registry edits.
- Green after implementation: `./vendor/bin/pest tests/Isolation/DataSubjectRequestsIsolationTest.php tests/Unit/Identity/DataSubjectRequestClaimTest.php tests/Unit/Identity/CapabilityTest.php tests/Unit/Identity/SeedTemplateRolesTest.php tests/Unit/Identity/RoleCapabilityInvariantTest.php` — 40 tests, 40 passed, 74 assertions.
- Full-suite regression per the task ("scoped run of the new Isolation and Unit files only" plus the touched shared-file blast radius): `./vendor/bin/pest --testsuite=Isolation` — 354 tests, 354 passed; `./vendor/bin/pest --testsuite=Unit` — 1191 tests, 1191 passed; `php artisan test --testsuite=Architecture` — 40 tests, 40 passed (after the `PresetTest` enum-location fix above, first run failed on `DataSubjectRequestStatus.php` not being ignored).
- `./vendor/bin/pint --test` and `./vendor/bin/phpstan analyse --memory-limit=1G` on every new/changed file: both pass with zero errors.
- No Data class changed, so `composer -d apps/api run types:generate` was not run (nothing to regenerate).

Deviation from the plan: none in scope. One judgment call recorded above (Owner-only template grant for the two new capabilities, since the task text names no specific role).
