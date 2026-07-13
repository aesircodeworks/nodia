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
- [x] T2 `AnonymizeCustomer`, `CustomerAnonymized`, erasure endpoint, registry update (identity, docs)
- [x] T3 Orders `CustomerAnonymized` consumer scrubbing `attendee_name` (orders)
- [x] T4 Reporting `CustomerAnonymized` consumer (reporting)
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

## T2: AnonymizeCustomer, CustomerAnonymized event, erasure endpoint

2026-07-13 15:52 -03

Landed stage-12 plan Slice 1's erasure branch and task breakdown item 3 in full: `AnonymizeCustomer`, the `CustomerAnonymized` event and payload, the registry update, and `POST /v1/customers/{customer}/data-subject-requests` with its contract and problem codes. Export (Slice 2) is out of scope for this task per the plan's own task breakdown ("erasure endpoint") and per Slice 1's test list, which names only erasure; the route and its Data classes are shaped so Slice 2 (task 6) is additive, not a rewrite.

What landed:

- `App\Identity\Support\AnonymizationPlaceholder`: HKDF-keyed derivation of the placeholder name and email from the customer id and the application secret, mirroring `App\Orders\Support\DerivedTicketSigningKeyProvider`'s own base64-key-handling precedent exactly. Deterministic (same customer id, same placeholder), non-reversible (keyed on a secret the placeholder never carries), and per-tenant unique (customer ids are globally unique, so the derived email can never collide against the `customers (tenant_id, email)` constraint).
- `App\Identity\Events\CustomerAnonymized` and `CustomerAnonymizedPayload`: envelope per event-conventions, aggregate `customer`, payload carrying `customer_id` and `data_subject_request_id` only, `#[Hidden]` from TypeScript generation like `CustomerRegisteredPayload`. Registered in `IdentityServiceProvider::boot()`'s `EventTypeRegistry`.
- `App\Identity\Actions\AnonymizeCustomer`: the whole erasure core in one conditional UPDATE (`anonymized_at is null`) checked by affected-row count, never read-then-write; overwrites `name`/`email` with the placeholder, nulls `password`, stamps `anonymized_at`. Zero affected rows throws `CustomerAlreadyAnonymizedException` (409 `customer_already_anonymized`) before any token revocation or outbox recording happens. On success, revokes every live access and refresh token via `RevokeAllUserTokens` (it already covers both, since a live access token's own refresh token is revoked alongside it — `RevokeRefreshTokenFamily` is Stage 3's reuse-detection primitive for one family, not needed here) and records exactly one `CustomerAnonymized` event in the same transaction.
- `App\Identity\Actions\CreateDataSubjectRequest`: creates the `data_subject_requests` row (pending), claims it (pending to processing) via the T1 model transitions, runs `AnonymizeCustomer`, then completes it. The partial unique index violation on a concurrent open request maps to `DataSubjectRequestAlreadyOpenException` (409 `data_subject_request_already_open`) by index name, mirroring `CreateRole`'s own precedent. No explicit `fail()` path: the whole request runs inside the ambient tenant transaction `App\Tenancy\Http\Middleware\ResolveTenantFromHeader` opens (confirmed by reading `TransactsRequests`/`TenantTransaction::run()`), so any thrown exception rolls back everything this call wrote, including the just-inserted request row — "a failed request records nothing" holds structurally, not by extra bookkeeping.
- `App\Identity\Data\CreateDataSubjectRequestData` and `DataSubjectRequestData`: `type` validates against `erasure` only for now via `Rule::in()` (mirroring `CreateExportData`'s registered-types restriction), even though the property itself is typed to the full `DataSubjectRequestType` enum for forward compatibility; `download_url` is always `null` until Slice 2 attaches a file. `DataSubjectRequestType`/`DataSubjectRequestStatus` marked `#[TypeScript]` now that a Data class exposes them.
- `App\Identity\Http\Controllers\DataSubjectRequestController` and the `POST /v1/customers/{customer}/data-subject-requests` route in `customers.php`: the capability required (`customers.erase` vs `customers.export`) depends on the request body's own `type`, so this route carries no static `RequireCapability` middleware entry; the controller calls `CapabilityGate::authorize()` directly after the Data object resolves (422 for an unaccepted type fires first, automatically, since laravel-data validation runs during controller-method dependency resolution, before any of the controller's own body executes). `RecordActivityAudit` still applies at the route level since it only logs a response that actually succeeded. Response status tracks the resulting request status (201 once `completed`, 202 otherwise) rather than being hardcoded, so Slice 2's `pending` export path needs no controller change.
- Two new `ErrorCode` cases (`customer_already_anonymized`, `data_subject_request_already_open`, both 409) and their `CustomerAlreadyAnonymizedException`/`DataSubjectRequestAlreadyOpenException`; a third exception, `CustomerNotFoundException`, maps the customer route param's 404 to the generic `request.not_found` code, mirroring `RoleNotFoundException`/`MembershipNotFoundException`.
- `App\Identity\Actions\ClaimGuestAccount` fix: an anonymized customer also carries a null password (erasure nulls it), so without an explicit `anonymized_at !== null` check the existing password-only guard would let a still-valid claim token re-arm a supposedly erased account with a real credential. Rendered as `claim_token_invalid`, identical to a missing customer, per the plan's own instruction that the response must not reveal the account was ever erased; no new error code introduced. Added a Unit case to the existing `ClaimGuestAccountTest.php` alongside the Feature-level proof.
- `docs/system-design.md` 9.3: added `CustomerAnonymized` to the Identity event list (list item 2 in the actual document; the stage-12 plan text calls this "group 1," a label mismatch against the document's own numbering, not acted on further — the substantive instruction, adding the event to Identity's line, is what landed).
- `docs/openapi/openapi.yaml`: the new path plus `CreateDataSubjectRequestRequest`, `DataSubjectRequest`, and `DataSubjectRequestConflictProblem` (one schema combining both 409 codes via a shared `code` enum, mirroring `ExportDownloadConflictProblem`'s own precedent, since the coverage test keys exercisers by status only). `composer -d apps/api run types:generate` regenerated `packages/api-client/src/generated/index.ts` (also picked up `Capability::CustomersErase`/`CustomersExport`, a stale-generation gap left over from T1's own no-Data-class-changed reasoning, since `Capability` was already transitively exported before this task).
- `tests/Contract/DocumentedResponseCoverageTest.php`: one exerciser per documented status (201, 401, 403, 404, 409, 422) plus the `data_subject_requests` cleanup line the new table's own tenant-scoped rows need ahead of the `customers` delete.
- `tests/Architecture/PresetTest.php`: the three new exception classes added to the Laravel preset's Throwable-location `ignoring()` list, the same way every other context exception is exempted.

Test evidence:

- New/changed files run in isolation: `./vendor/bin/pest tests/Feature/Identity/DataSubjectErasureTest.php tests/Feature/Identity/CustomerAnonymizedOutboxTest.php tests/Unit/Identity/AnonymizationPlaceholderTest.php tests/Unit/Identity/CustomerAnonymizedPayloadTest.php tests/Unit/Identity/ClaimGuestAccountTest.php tests/Unit/Identity/DataSubjectRequestClaimTest.php tests/Isolation/DataSubjectRequestsIsolationTest.php tests/Concurrency/CustomerAnonymizationContentionTest.php tests/Unit/Problems/ErrorCodeTest.php --testsuite=Contract` — 158 tests, 158 passed, 601 assertions.
- `php artisan test --testsuite=Architecture` — 40 tests, 40 passed (after adding the three new exceptions to `PresetTest`'s ignoring list; first run failed with "not to implement Throwable" on `DataSubjectRequestAlreadyOpenException`).
- `./vendor/bin/pest --testsuite=Isolation` — 354 tests, 354 passed (regression check; no new isolation surface this task, T1 already covers `data_subject_requests`).
- `./vendor/bin/pest tests/Feature/Identity tests/Unit/Identity` — 500 tests, 500 passed.
- `./vendor/bin/pest --testsuite=Contract` (full) — 472 tests, 472 passed.
- Given the number of shared files touched (`ErrorCode`, `PresetTest`, `DocumentedResponseCoverageTest`, `IdentityServiceProvider`, `customers.php` routes), ran the whole suite once as an extra regression check beyond what the method calls for: `./vendor/bin/pest` — 3433 tests, 3433 passed, 13642 assertions, ~7m46s.
- `./vendor/bin/pint --test` and `./vendor/bin/phpstan analyse --memory-limit=1G` on every new/changed PHP file: both pass with zero errors.
- `composer -d apps/api run types:generate` regenerated `packages/api-client/src/generated/index.ts` and the manifest; `pnpm --filter api-client typecheck` passes against the regenerated output.

Deviation from the plan: none in scope. Three judgment calls recorded above: `CreateDataSubjectRequestData.type` restricted to `erasure` only until Slice 2 lands export (the plan's own Endpoints section describes the union of both slices; Slice 1's test list names only erasure); `ClaimGuestAccount`'s anonymized-customer rejection reuses `claim_token_invalid` rather than a new code (the plan names no third code for that endpoint); the system-design 9.3 "group 1" label mismatch noted but not corrected, since the plan's substantive instruction (add the event to Identity's own line) is unambiguous regardless of the label.

## T3: Orders CustomerAnonymized consumer scrubbing ticket attendee_name

2026-07-13 16:00 -03

Landed stage-12 plan Domain events "Consumed" and task breakdown item 4: the Orders subscriber for Identity's `CustomerAnonymized`. No contract step in this task's TDD double loop: it is an internal outbox consumer with no endpoint and no Data class, the same shape as `CancelOrderOnHoldExpired` (Stage 7) and `HandlePaymentConfirmed` (Stage 8a), so only the duplicate-delivery Feature test and the implementation apply.

What landed:

- `App\Orders\Jobs\ScrubTicketAttendeeNames`: an `OutboxSubscriber` that overwrites `attendee_name` with a constant placeholder (`Erased Attendee`) on every ticket whose `order_id` belongs to an order for the anonymized customer, via one UPDATE scoped by a subquery over `orders.customer_id` and `whereNotNull('attendee_name')` (a ticket that never carried a name is left alone rather than stamped with the placeholder). No per-customer HKDF derivation like `AnonymizationPlaceholder`: `attendee_name` carries no uniqueness constraint, so a single shared constant satisfies "naturally idempotent" without inventing per-row uniqueness nobody needs. Reads and writes only Orders' own `orders`/`tickets` tables (event-conventions: a consumer never writes to another context's tables); the tenant scope comes for free from `ProcessOutboxDelivery` already running the handler inside `TenantTransaction::asTenant()`, so no extra scoping call was needed in the handler itself.
- `App\Orders\OrdersServiceProvider::boot()`: registered `ScrubTicketAttendeeNames::NAME` against `['CustomerAnonymized']` in the `SubscriberRegistry`, alongside the existing Orders subscribers.
- `tests/Feature/Orders/CustomerAnonymizedConsumerTest.php`, mirroring `HoldExpiredConsumerTest.php`'s structure: subscriber-registration assertion; a scrub test proving a named ticket is overwritten with the placeholder while a null-named ticket on the same order stays null; a duplicate-delivery test running the same outbox event id through `ProcessOutboxDelivery::handle()` twice and asserting both the final `attendee_name` and exactly one `processed` row in `outbox_deliveries`; a cross-boundary test with a second customer in the same tenant and a customer in a second tenant, both with named tickets, proving neither is touched by the first customer's anonymization. The `CustomerAnonymized` event itself is produced by driving the real `AnonymizeCustomer` Action inside an explicit `DB::transaction()` (needed here since, unlike the HTTP path, there is no ambient `TransactsRequests` middleware transaction), the same "drive the real producer" posture `tests/Feature/Payments/LedgerProjectionTest.php` uses for `PaymentConfirmed` rather than hand-rolling an outbox row.

Test evidence:

- Confirmed red first: ran the new test file before `ScrubTicketAttendeeNames` existed. All four cases failed with `Class "App\Orders\Jobs\ScrubTicketAttendeeNames" not found`.
- Green after implementation: `./vendor/bin/pest tests/Feature/Orders/CustomerAnonymizedConsumerTest.php` — 4 tests, 4 passed, 8 assertions.
- `php artisan test --testsuite=Architecture` (the task's named context-boundary guard) — 40 tests, 40 passed.
- Scoped regression per the task ("plus the Architecture suite files that guard the context boundary"), widened to the whole touched directory since `OrdersServiceProvider` is shared: `./vendor/bin/pest tests/Feature/Orders tests/Unit/Orders` — 194 tests, 194 passed, 613 assertions.
- `./vendor/bin/pint --test` and `./vendor/bin/phpstan analyse --memory-limit=1G` on the two changed `app/` files: both pass with zero errors. (`phpstan.neon`'s `paths` covers `app` only, not `tests`; running phpstan directly against the test file produces `Pest\PendingCalls\TestCall` false positives on every `$this->tenantId`-style dynamic property, confirmed as pre-existing noise by reproducing the identical error shape against the untouched `HoldExpiredConsumerTest.php`, not a defect introduced here.)
- No Data class changed, so `composer -d apps/api run types:generate` was not run (nothing to regenerate).

Deviation from the plan: none. The consumer scrubs to a fixed constant rather than a per-customer derived value (unlike `AnonymizationPlaceholder` for the customer row itself); this is a judgment call, not a deviation, since the plan's own idempotence reasoning ("overwriting a placeholder with the same placeholder is a no-op") only requires a stable value, and `attendee_name` has no uniqueness constraint for a derived value to satisfy.

## T4: Reporting CustomerAnonymized consumer

2026-07-13 16:04 -03

Landed stage-12 plan Domain events "Consumed" and task breakdown item 5: the Reporting subscriber for Identity's `CustomerAnonymized`. Inspected Stage 11's three read models before writing anything (`report_daily_sales`, `report_event_finance`, `report_event_attendance` migrations and their owning models): every column across all three is `tenant_id`/`event_id`/`ticket_type_id` (identifiers), a count, a money `*_amount` column, `sales_date`/`first_scan_at`/`last_scan_at` (dates/timestamps), or `currency`/`created_at`/`updated_at`. No name, email, or any other customer-identifying field was denormalized. This forced the no-op shape the plan names as acceptable ("If Stage 11's read models carry no PII, the subscriber is still registered with a no-op assertion test"), not the scrubbing shape Orders (T3) landed.

What landed:

- `App\Reporting\Jobs\ScrubReportingPii`: an `OutboxSubscriber` whose `handle()` body is intentionally empty (see class docblock), registered against `CustomerAnonymized` all the same, so a future PII-carrying read model inherits the registration, the outbox plumbing, and the duplicate-delivery guarantee already in place, needing only an implementation.
- `App\Reporting\ReportingServiceProvider::boot()`: registered `ScrubReportingPii::NAME` against `['CustomerAnonymized']` in the `SubscriberRegistry`, alongside the three existing Reporting projectors.
- `tests/Feature/Reporting/CustomerAnonymizedConsumerTest.php`, mirroring `tests/Feature/Orders/CustomerAnonymizedConsumerTest.php`'s structure for the parts that carry over: subscriber-registration assertion; a duplicate-delivery test running the same outbox event id through `ProcessOutboxDelivery::handle()` twice and asserting exactly one `processed` row in `outbox_deliveries` (required regardless of which of the two shapes the codebase landed in, per the task); and the no-op assertion test the plan calls for in place of a scrub test — `Schema::getColumnListing()` against all three read-model tables, asserted against a PII-name-pattern blocklist (`name`, `email`, `phone`, `address`, `document`, `dob`, `birth`), so a future migration that adds a column matching any of those patterns fails this test immediately and points at `ScrubReportingPii` as the place to implement the scrub, rather than the obligation going unnoticed. The `CustomerAnonymized` event itself is produced by driving the real `AnonymizeCustomer` Action inside an explicit `DB::transaction()`, the same posture T3's Orders test and `LedgerProjectionTest.php` already use.

Test evidence:

- Confirmed red first: ran the new test file before `ScrubReportingPii` existed. The registration and duplicate-delivery cases failed with `Class "App\Reporting\Jobs\ScrubReportingPii" not found`; the PII-column assertion test passed immediately (as expected, since it asserts a true fact about the already-migrated schema, not a class that doesn't exist yet).
- Green after implementation: `./vendor/bin/pest tests/Feature/Reporting/CustomerAnonymizedConsumerTest.php` — 3 tests, 3 passed, 247 assertions.
- `php artisan test --testsuite=Architecture` (context-boundary guard) — 40 tests, 40 passed.
- Scoped regression on the touched directory (`ReportingServiceProvider` is shared): `./vendor/bin/pest tests/Feature/Reporting tests/Unit/Reporting` — 125 tests, 125 passed, 775 assertions.
- `./vendor/bin/pint --test` and `./vendor/bin/phpstan analyse --memory-limit=1G` on the two changed `app/` files: both pass with zero errors.
- No Data class changed, so `composer -d apps/api run types:generate` was not run (nothing to regenerate).

Commit: `e473add` (`feat(reporting): register CustomerAnonymized consumer as a no-op`).

Deviation from the plan: none. Per the task's own instruction to note which of the two shapes the codebase forced: the no-op-with-assertion shape, not the scrubbing shape, since Stage 11 denormalized no PII into any of the three read models.
