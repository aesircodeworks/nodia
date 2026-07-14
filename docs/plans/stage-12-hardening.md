# Stage 12: Compliance, Operations, Hardening

Implementation plan for Stage 12 of [api-implementation-plan.md](../api-implementation-plan.md), the final stage: everything an operator or regulator needs, and the proof that the whole surface holds. It implements the GDPR and LGPD obligations from [system-design.md](../system-design.md) section 14.3, the outbox retention schedule from section 9.1, the operational backstops from section 13, and the load and end-to-end coverage mandated by section 18. The conventions in [api-conventions.md](../api-conventions.md), [data-conventions.md](../data-conventions.md), and [event-conventions.md](../event-conventions.md) are binding throughout.

## Scope and non-goals

Delivered by this stage:

- Erasure: anonymize-in-place for customer PII, marking `customers.anonymized_at` (system-design 14.3), exposed as a staff endpoint through a tracked data subject request. Orders, tickets, payments, and ledger entries are retained pseudonymously; a new `CustomerAnonymized` domain event lets other contexts scrub the PII they hold (ticket `attendee_name` in Orders, any names in Reporting read models).
- Access and portability: a per-tenant data subject export assembling the customer's records across contexts (system-design 14.3), built asynchronously, delivered as a medialibrary attachment with a time-limited download URL.
- Retention windows: scheduled pruning of raw webhook payloads and activity log rows past configurable windows (system-design 14.3), with financial records exempt per statutory retention.
- Outbox archival: rows past the retention window archived to object storage as verifiable segments, deleted from PostgreSQL only after upload verification, with the replay primitive extended to read archived segments so rebuilds still work (system-design 9.1: retained rows double as the replay log).
- Operational commands, support-safe and audited: replay failed deliveries, reconcile payments, release stuck holds (api-implementation-plan, Stage 12 line). All are dry-run by default, bounded by explicit arguments, and write activity log entries.
- Security sweep: meta-tests asserting isolation suite coverage for every tenant-scoped endpoint and contract coverage for every route; an authorization matrix completeness check asserting every mutating route maps to a capability; webhook signature negative tests re-run across all registered gateways; secret handling review with an architecture test forbidding `env()` outside `config/` (system-design 14.1, 14.2).
- Load tests on hold creation, payment initiation, and waiting room admission (system-design 18), with targets and measured headroom recorded in `docs/load-targets.md`. The load tool is selected at slice start against current documentation, per the master plan's tool-selection posture; k6 is the default candidate.

Explicitly deferred:

- Chargeback and dispute ingestion: Stage 8d deferred it here pending the gateway ADR; it stays deferred until the ADR names the launch gateway's dispute mechanics, then lands as an addendum slice to this stage or to 8d. Nothing in this stage blocks on it.
- Per-tenant data residency and DPA tooling: commercial-layer concerns per system-design 14.3; the schema's bounded PII columns are the only accommodation.
- Automated erasure of staff (`users`) PII: staff offboarding is an HR process, not a data subject request flow; only `customers` are in scope, matching system-design 14.3.
- Dedicated secret store integration (Infisical or Vault, system-design 14.1): deployment-time concern under ADR 017's deferred-orchestrator posture; this stage verifies handling discipline (env-only configuration, no secrets in the repo), not the store.
- Repo secret scanner in CI (gitleaks or equivalent): dropped at the operator's direction during task 16, after the tool was selected but before it was ever run. The two static guards task 16 did land prove the environment is read only in `config/` and that no committed `.env.example` carries a credential; nothing scans the rest of the tree or the git history, so the repository's history has never been checked for a committed secret and no stage artifact claims otherwise. Reinstating a gate later (gitleaks, trufflehog, or GitHub's own push protection and secret scanning, which needs no third-party tool) is additive: it lands as a workflow and a config file, and touches no application code.
- API-level smoke suite and its CI gate: dropped at the operator's direction during task 17, before any code was written, once the coverage it would actually add was examined against what already exists. `tests/Feature/Payments/StageExitCapstoneTest.php` (Stage 8c) already drives publish, hold, order, async payment, confirmation webhook, refund, and payout over HTTP against the fake gateway, asserting the ledger's debits-equal-credits and `tenant_net` invariants after every money step, with a second case covering declines, expiries, and duplicate webhooks; the smoke suite would have re-run that loop with different setup code. The one rationale that would have justified the duplication, running the outbox on a real Redis queue rather than the `sync` queue every suite uses, does not survive inspection: `ProcessOutboxDelivery` carries two strings (event ID, subscriber name), so nothing can fail to serialize, and it establishes its own database context through `TenantTransaction::asPlatform()` then `asTenant()` precisely because a worker has no request middleware to set `app.tenant_id`. Swapping the queue driver therefore exercises Laravel's push and pop, not this codebase; the retry and deferral machinery that `sync` genuinely does not reach (`release()`, `attempts()`, the per-subscriber backoff policy) is never entered by a happy-path run anyway, so the gate would have been green and vacuous. What the suite alone would have covered is narrower: chaining the bootstrap endpoints into the flow that consumes them, and scanning a real `qr_payload` taken from a genuinely paid order (`tests/Feature/CheckIn/RecordScanEndpointTest.php` scans fixture-built tickets). That cross-context wiring is real but unproven coverage today, and it is bought at the price of a slow, order-dependent test. Reinstating it later is additive: a `tests/Smoke` directory, a seventh `phpunit.xml` testsuite, and a CI job, touching no application code. Roadmap Phase 7 separately lists end-to-end smoke tests as a deployment-layer concern; that item is untouched by this drop and is the natural place for the coverage to land instead.
- Sentry, OpenTelemetry, dashboards, alerting: roadmap Phase 7 operational rollout, not API surface; the API already logs structured JSON with correlation IDs.

## Dependencies

Required from earlier stages (this stage is last; it leans on everything):

- Stage 1: problem-document handler and code registry, fake clock (all retention windows and TTLs are clock-driven), Contract suite wiring, real Isolation and Concurrency harnesses.
- Stage 2: tenants, RLS bootstrap, sentinel platform tenant, cross-tenant platform role (system-design 4.3), which the archival and pruning commands use for cross-tenant scans. The role grants cross-tenant reads everywhere but writes on Tenancy-owned tables only, so it covers none of the deletes this stage performs; the activity log delete path ships as a migration in task 8, and the outbox archiver deletes in per-tenant transactions under the app role (Slice 4).
- Stage 3: customers with `anonymized_at` (system-design 8.1), guest claim flow (erasure must block it), capabilities and policies (new `customers.erase` and `customers.export` capabilities), activity log (audit target for commands and erasure).
- Stage 4: outbox recording, deliveries, sweeper, replay primitive (extended here to read archives), failed-jobs dead letter table (the replay-failed command's target).
- Stages 5a, 6, 7, 8a, 8b: the full publish, hold, order, payment, refund path the export assembler reads and the load scenarios drive; the Stage 8a webhook table (pruning target) and reconciliation poller (wrapped by the reconcile command); the Stage 6 hold sweeper and `ReleaseHold` Action (wrapped by the release command).
- Stage 9: check-in records, which the data subject export includes and the coverage meta-tests sweep. (Stage 9 was also the smoke suite's door segment; that suite is dropped, see Scope and non-goals.)
- Stage 10: waiting room admission for the load test's third scenario; if Stage 10 is thinned out per the MVP note in the master plan, that scenario is skipped and the load doc records the omission.
- Stage 11: reporting read models, which the `CustomerAnonymized` consumer scrubs; `BuildExport`'s cursor-paginated source pattern, reused by the data subject export assembler.

Consumed by later stages: none; launch consumes this stage. The coverage meta-tests become permanent CI gates for all future work.

## Data model

All timestamps UTC. No new money columns. One new tenant-scoped table (RLS policy in the same migration per data-conventions and ADR 003), one new platform infrastructure table (excluded from the isolation sweep alongside `failed_jobs`), and two additive columns on existing tables.

### data_subject_requests

Tracks erasure and export requests so both flows are auditable, idempotent, and safe under concurrent submission.

| Column                 | Type                            | Notes                                                       |
| ---------------------- | ------------------------------- | ----------------------------------------------------------- |
| id                     | uuid, PK                        | UUIDv7 via `HasUuids`                                       |
| tenant_id              | uuid, not null                  | RLS subject                                                 |
| customer_id            | uuid, not null, FK to customers |                                                             |
| type                   | string, not null                | enum-backed: `erasure`, `export`                            |
| status                 | string, not null                | enum-backed: `pending`, `processing`, `completed`, `failed` |
| requested_by_user_id   | uuid, not null                  | the staff member acting on the data subject's request       |
| completed_at           | timestamp, nullable             |                                                             |
| created_at, updated_at | timestamps                      |                                                             |

Constraints and indexes:

- Partial unique index on `(customer_id, type)` where `status in ('pending', 'processing')`, named `data_subject_requests_open_per_customer_idx` per the custom-name rule, so a customer has at most one open request per type.
- Index on `(tenant_id, customer_id)` for the admin list filter.
- RLS policy comparing `tenant_id` to `current_setting('app.tenant_id')`, in the same migration.

Status transitions guard the run-once invariant, so every advance is a conditional UPDATE checked by affected-row count: `pending` to `processing` (claims the request; zero rows means another worker claimed it), `processing` to `completed` or `failed`. Export files attach to the request via medialibrary (data-conventions: no bespoke path columns) and are themselves subject to a short retention window because they contain PII.

### archive_segments

Platform infrastructure manifest for archived outbox and activity log rows. Segments span tenants (they are batches by global order), so per-tenant RLS does not apply: no `tenant_id`, no policy, isolation-sweep exclusion list updated, readable only through the cross-tenant platform role, following the Stage 4 `failed_jobs` precedent.

| Column                 | Type                     | Notes                                                                     |
| ---------------------- | ------------------------ | ------------------------------------------------------------------------- |
| id                     | uuid, PK                 | UUIDv7                                                                    |
| source                 | string, not null         | enum-backed: `outbox_events`, `activity_log`                              |
| range_from             | string, not null         | first outbox `sequence` or first activity log `created_at` in the segment |
| range_to               | string, not null         | last marker in the segment                                                |
| object_key             | string, not null, unique | key in the S3-compatible store                                            |
| row_count              | integer, not null        |                                                                           |
| checksum               | string, not null         | content hash verified after upload, before source rows are deleted        |
| archived_at            | timestamp, not null      |                                                                           |
| created_at, updated_at | timestamps               |                                                                           |

Index on `(source, range_from)` so archive-aware replay can locate segments in order.

### Additive columns on existing tables

- Stage 8a webhook table: nullable `payload_pruned_at` timestamp, new migration (merged migrations are never edited). Pruning nulls the raw payload column and stamps `payload_pruned_at` in one UPDATE; the row and its unique gateway event ID survive so webhook idempotence outlives the retention window.
- No column change for erasure: `customers.anonymized_at` exists from Stage 3 (system-design 8.1). Anonymization overwrites `name` and `email` with deterministic non-reversible placeholders (per-tenant unique to keep the email uniqueness constraint satisfied), nulls `password`, and stamps `anonymized_at` only where it is currently null, as a conditional UPDATE checked by affected-row count.

## Domain events

### Produced

One new event, which requires adding `CustomerAnonymized` to the system-design 9.3 registry in the same change (event-conventions, Naming and Registry).

| Event              | Recorded by                  | Aggregate                | Payload fields                           |
| ------------------ | ---------------------------- | ------------------------ | ---------------------------------------- |
| CustomerAnonymized | AnonymizeCustomer (Identity) | `customer` / customer ID | `customer_id`, `data_subject_request_id` |

Envelope per event-conventions: UUIDv7 event ID, global `sequence`, `type`, non-null `tenant_id`, aggregate reference, `correlation_id`, `occurred_at`, snake_case laravel-data payload, recorded in the same transaction as the customer UPDATE, and only when the conditional UPDATE's affected-row count is 1. The payload carries identifiers only; carrying the erased name or email would defeat erasure, since outbox rows are retained and replayed (system-design 9.1). This constraint already binds every payload since Stage 4 (no PII in payloads); the erasure design depends on it, and a Slice 6 meta-test asserts it across all registered payload classes.

### Consumed

- Orders subscribes to `CustomerAnonymized` and scrubs `attendee_name` on the customer's tickets by updating its own models (event-conventions: consumers never write to another context's tables). Idempotent by event ID via the Stage 4 `outbox_deliveries` conditional transition; the scrub itself is naturally idempotent (overwriting a placeholder with the same placeholder).
- Reporting subscribes and scrubs any PII its read models denormalized. If Stage 11's read models carry no PII, the subscriber is still registered with a no-op assertion test, so a future read model that adds PII inherits the scrub obligation visibly.

Replay note: because outbox rows are archived, not deleted, a replayed `CustomerAnonymized` re-scrubs, which is harmless. Rebuilding a Reporting projection from replay naturally reapplies the scrub after any events that populated the PII, because replay is in `sequence` order.

### Registry

`CustomerAnonymized` is added to system-design 9.3 group 1 (Identity) in the same change that introduces the event class. No other new event types; export completion and archival are operational facts, not domain events.

## Endpoints

Staff admin endpoints, Bearer JWT with `X-Tenant-Id` per api-conventions, capability-gated, audited. All errors are RFC 9457 problem documents with stable codes. New capabilities: `customers.erase`, `customers.export` (flat capability set per system-design 5.3).

### POST /v1/customers/{customer}/data-subject-requests

Creates a request. Request Data: `CreateDataSubjectRequestData` with `type` (`erasure` or `export`). Response body: `DataSubjectRequestData` (`id`, `customer_id`, `type`, `status`, `requested_by_user_id`, `completed_at`, `download_url` nullable, `created_at`). Erasure runs synchronously inside the request (single-row anonymization, revocation of the customer's outstanding access and refresh tokens via the Stage 3 server-side revocation primitive, and the event; downstream scrubs are async consumers), so it returns 201 with `status` already `completed`; 202 would misstate HTTP semantics for work already performed. Export returns 202 with `pending` and transitions to `processing` in a queued job.

Errors:

- 401 unauthenticated; 403 without the matching capability (`customers.erase` for erasure, `customers.export` for export).
- 404 for a customer of another tenant (RLS makes this structural).
- 409 `customer_already_anonymized`: erasure requested for a customer whose `anonymized_at` is set.
- 409 `data_subject_request_already_open`: an open request of the same type exists for the customer (surfaced from the partial unique index, mapped to a problem document, never a 500).
- 422 `request.validation_failed` for an unknown `type`.

### GET /v1/data-subject-requests/{data_subject_request}

Returns `DataSubjectRequestData`. For a completed export, `download_url` is a time-limited signed URL to the medialibrary attachment; before completion it is null. 404 across tenants; 403 without either capability.

### GET /v1/data-subject-requests

Admin list via query-builder with explicit allowlists (api-conventions): `filter[customer_id]`, `filter[type]`, `filter[status]`, `sort=-created_at`. Bounded collection, page pagination, standard paginator envelope.

No other new routes. Operational commands are artisan commands, not endpoints; the load work adds no API surface. OpenAPI paths for the three routes merge with the routes; `composer types:generate` output for the new Data classes is committed with them.

## TDD sequencing

Every slice follows the double-loop from the master plan: failing tests first, then implementation, then Larastan, Pint, architecture suite, contract conformance, and TypeScript regeneration.

### Slice 1: data subject requests and erasure

Failing tests first:

- Isolation: two-tenant fixture proves cross-tenant read and write of `data_subject_requests` fails under the app role; the cross-tenant role reads both.
- Feature: erasure request by a staff member with `customers.erase` returns 201 with `completed`; the customer row has placeholder name and email, null password, `anonymized_at` set; the guest-claim flow rejects the anonymized customer; customer login with old credentials fails; an access token and a refresh token issued before erasure are both rejected after it (`AnonymizeCustomer` revokes the customer's outstanding tokens through the Stage 3 server-side revocation primitive); a second erasure returns 409 `customer_already_anonymized`; 403 without the capability; 404 cross-tenant; an activity log row records the erasure with the acting user.
- Feature: exactly one `CustomerAnonymized` outbox row with the payload above and the request's correlation ID; a failed request records nothing.
- Feature (duplicate delivery, per consumer): the Orders `attendee_name` scrub and the Reporting consumer each process `CustomerAnonymized` exactly once under duplicate delivery via the Stage 4 `outbox_deliveries` conditional transition; a second delivery causes no error and no second effect, whether the Reporting consumer scrubs or lands as the registered no-op assertion.
- Unit: `AnonymizeCustomer` placeholder derivation is deterministic, non-reversible, and per-tenant unique against the email constraint; the payload Data class contains no name or email field.
- Concurrency: two parallel erasure calls for one customer produce exactly one anonymization and one event; the conditional UPDATE on `anonymized_at is null` admits one winner by affected-row count; the loser surfaces 409.

### Slice 2: data subject export

Failing tests first:

- Feature: export request returns 202 `pending`; after the queued job runs, the request is `completed` with a `download_url`; the downloaded document contains the customer profile and the customer's orders, tickets, payments, refunds, and check-ins for that tenant only, all money as `{amount, currency}`, snake_case throughout; a parallel duplicate request returns 409 `data_subject_request_already_open`; 403 without `customers.export`.
- Feature (duplicate delivery): running the export job twice produces one attachment and one `completed` transition; the `pending` to `processing` conditional UPDATE admits one claimant.
- Feature: exporting an anonymized customer succeeds and contains placeholders, not recovered PII.
- Unit: the assembler calls other contexts' read Actions only (architecture suite extended: Identity imports no Orders, Payments, or CheckIn models); sources are cursor-paginated per api-conventions' high-volume rule.
- Isolation: the signed download URL for tenant A's export is unusable in tenant B's context.

### Slice 3: retention pruning

Failing tests first:

- Feature (fake clock): webhook rows older than the configured window have payloads nulled and `payload_pruned_at` stamped; younger rows are untouched; the unique gateway event ID survives and a duplicate webhook arriving after pruning is still deduplicated; rerunning the pruner is a no-op on already-pruned rows.
- Feature (fake clock): activity log rows older than the window are exported to an object storage segment with a manifest row, then deleted only after checksum verification; younger rows remain; ledger entries and other financial records are never touched by any pruner.
- Isolation: Stage 3 shipped `activity_log` with SELECT and INSERT policies only, so no existing role can delete rows; the task 8 migration adds the scoped delete path, and the updated append-only tests prove UPDATE stays denied for every role, DELETE stays denied under the app role in request context, and only the pruning command's path removes rows, and only rows past the window.
- Feature: export attachments older than their short window are deleted and the request's `download_url` becomes null while the request row remains as audit trail.
- Unit: all windows come from `config/retention.php`; a zero or negative window refuses to run rather than deleting everything.

### Slice 4: outbox archival and archive-aware replay

Failing tests first:

- Feature (fake clock): outbox rows past the retention window are written to a segment (NDJSON, contiguous `sequence` range), the manifest row records range, count, and checksum, and the source rows are deleted only after the uploaded object's checksum verifies; a failed upload leaves the rows in place and no manifest.
- Feature: replay for a projection whose history spans archived segments and live rows visits every event exactly once in global `sequence` order and rebuilds state identical to the incrementally built projection (the Stage 11 rebuild-equivalence test re-proven across the archive boundary).
- Feature: rows younger than the window, and rows with any `pending` delivery, are not archived; the corresponding `outbox_deliveries` rows archive or delete with their event, and the sweeper never re-enqueues an archived event.
- Unit: segment boundaries are deterministic from configuration; the archiver refuses to run when object storage is unreachable rather than deleting.

Deletion mechanics: the archiver scans and uploads under the cross-tenant platform role, which Stage 4 limited to SELECT on the outbox tables, then deletes verified rows in per-tenant transactions under the app role, iterating the tenant IDs present in the segment; the matching `outbox_deliveries` rows delete in the same transactions. Stage 4 made never-deleted an application invariant asserted by a unit test, not a database policy, so no grant changes; that invariant and its test are relaxed in task 10 to "never updated, and never deleted before the retention window or by anything but the archiver".

### Slice 5: operational commands

Failing tests first, per command:

- `outbox:replay-failed`: dry-run (default) lists matching failed jobs and stranded deliveries without side effects; `--execute` re-enqueues them once each; consumers' idempotence makes accidental double-replay harmless (asserted by a duplicate-effect probe); an activity log entry records invocation, arguments, and counts under the sentinel platform tenant with the operator identity from a required `--operator` argument.
- `payments:reconcile`: dry-run reports the `awaiting_payment` orders it would poll, bounded by required `--order` or `--tenant` plus `--before` arguments; `--execute` invokes the Stage 8a poller path for exactly those orders; state changes flow through the existing conditional-UPDATE state machine, never direct writes; audited as above.
- `holds:release-stuck`: dry-run lists holds with status `active` past `expires_at`; a `committed` hold past `expires_at` never appears in the listing (its inventory already moved held to sold on the paid transition, system-design 7.1, so it is not a release candidate); `--execute` releases them through the Stage 6 `ReleaseHold` Action (conditional UPDATE, counter arithmetic proven by asserting availability recovers exactly); refuses unbounded execution without an explicit `--all-tenants` flag; audited as above.
- Concurrency: the release command racing the Stage 6 expiry sweeper on the same holds releases each hold exactly once (the conditional UPDATE admits one winner; counters never double-decrement).

### Slice 6: security sweep

Failing tests first (these are meta-tests; each is written to fail against a deliberately introduced gap, then the gap is closed):

- Coverage completeness: a test enumerates every registered `/v1` route and asserts each appears in the isolation suite's coverage registry (tenant-scoped routes) or an explicit exemption list with a reason (health, webhook ingestion); a sibling test asserts every route has an OpenAPI path (contract coverage); a third asserts every mutating route maps to a capability in the authorization registry. A new unmapped route fails the build.
- Authorization matrix: data-driven feature test iterating capability by role by route, asserting deny for every capability the role lacks; global template roles (system-design 5.3) are the fixture.
- Webhook negatives: for every registered gateway (FakeGateway, plus the 8d adapter if merged), missing signature, tampered body, wrong key, and stale timestamp are rejected before persistence with `webhook_signature_invalid`; nothing is persisted on rejection.
- Secret handling: an architecture test forbids `env()` calls outside `config/`; a test asserts `.env.example` files contain placeholders only; the review's manual findings are fixed in-stage, not recorded as TODOs. No repo secret scanner: see the deferral in Scope and non-goals.
- Payload PII meta-test: every registered outbox payload Data class declares no field named or typed as PII (name, email, document number patterns); the test guards the erasure model's core assumption.

### Slice 7: smoke suite

Dropped; see the deferral in Scope and non-goals. The Stage 8c capstone already drives the loop over HTTP with the ledger invariant asserted throughout, and the real-Redis rationale that would have justified re-running it does not survive inspection of `ProcessOutboxDelivery`.

### Slice 8: load tests

Not Pest; scripts live in `infra/load/` and run against the compose stack. Sequencing still test-first in spirit: targets are written into `docs/load-targets.md` before the first run, then runs measure headroom against them.

- Scenarios: hold creation burst on one ticket type (the contended counter row, system-design 6.3), payment initiation with `Idempotency-Key` replays mixed in, waiting room admission at the configured rate (skipped with a recorded note if Stage 10 was thinned).
- Correctness probes ride along: after every load run, an assertion script verifies no oversell (`sold + held <= quantity`) and no duplicate payment effects, turning the load run into a large-scale concurrency test.
- A CI job runs a reduced-scale profile on demand (manual trigger), not per-commit; full-scale runs are documented with tool version, profile, environment shape, and results.

## Task breakdown

Ordered; each lands green through the full loop and is independently mergeable unless noted. Commit scopes per the conventions: `identity` for erasure and export, `payments` for webhook retention and reconcile, `inventory` for the holds command, `orders` for the ticket scrub consumer, `reporting` for the read-model scrub, `support` for archival, retention config, and coverage meta-tests, `ci` for gates, `docs` for status and targets.

1. Mark Stage 12 in progress in the api-implementation-plan status table. Scope: `docs`.
2. `data_subject_requests` migration with partial unique index and RLS policy, model, enums, conditional status transitions; isolation and unit tests first. Scope: `identity`.
3. `AnonymizeCustomer` Action, `CustomerAnonymized` event class and payload, registry update in system-design 9.3, erasure endpoint with contract and problem codes (Slice 1 tests first, concurrency included). Scope: `identity`; the registry edit rides along as `docs` within the change.
4. Orders `CustomerAnonymized` consumer scrubbing `attendee_name` (duplicate-delivery test first). Scope: `orders`. Mergeable once task 3 lands.
5. Reporting `CustomerAnonymized` consumer (duplicate-delivery idempotence test first, required whether it scrubs or lands as the registered no-op assertion, per what Stage 11 shipped). Scope: `reporting`. Independent of task 4.
6. Export assembler and queued job, download URL, list and show endpoints with contracts (Slice 2 tests first). Scope: `identity`.
7. `config/retention.php`, webhook payload pruner with `payload_pruned_at` migration, export attachment pruner, scheduler registration (Slice 3 tests first). Scope: `payments` for the webhook piece, `identity` for the attachment piece; split into two commits.
8. Activity log delete path: migration adding a scoped DELETE mechanism on `activity_log` (a DELETE policy restricted to rows past a cutoff the pruning command supplies, or an equivalently narrow dedicated path; Stage 3 shipped SELECT and INSERT policies only, so no role can delete today); amend system-design 14.2 to state the archive-then-delete reading explicitly; update the Stage 3 append-only isolation tests to the Slice 3 expectations. Scope: `support`; the design amendment rides along as `docs`.
9. `archive_segments` migration (infrastructure table, isolation-sweep exclusion updated), activity log archive-then-prune command (Slice 3 activity log tests first). Scope: `support`. Depends on task 8's delete path.
10. Outbox archiver and archive-aware replay extension (Slice 4 tests first), including relaxing Stage 4's never-deleted invariant and its unit test to never-before-the-retention-window per the Slice 4 deletion mechanics. Scope: `support`. Depends on task 9's manifest table.
11. `outbox:replay-failed` command (Slice 5 tests first). Scope: `support`.
12. `payments:reconcile` command. Scope: `payments`. Independent of task 11.
13. `holds:release-stuck` command with the sweeper-race concurrency test. Scope: `inventory`. Independent of tasks 11 and 12.
14. Coverage completeness meta-tests (isolation, contract, authorization) with exemption lists; authorization matrix test; payload PII meta-test (Slice 6 tests, each proven against a seeded gap). Scope: `support`.
15. Webhook negative matrix re-run across registered gateways. Scope: `payments`.
16. `env()` architecture test, `.env.example` assertions; fix anything found. Scope: `support`, fixes under their owning scopes. (Originally also a secret scanner in CI, dropped; see the deferral in Scope and non-goals.)
17. Dropped: smoke suite and required CI gate (Slice 7). Nothing lands; the numbering is kept so the execution journal's task references stay stable. See the deferral in Scope and non-goals.
18. Load tooling selection (documented in the PR description), `infra/load/` scenarios, correctness probes, `docs/load-targets.md` with targets then results, manual CI job. Scope: `ci`; the targets doc is `docs`.
19. Mark Stage 12 done in the status table. Scope: `docs`.

Tasks 11 through 13 are mutually independent; tasks 14 through 16 can proceed in parallel with 11 through 13.

## Exit criteria

The stage exit line, amended in task 17 to "no endpoint lacks isolation and contract coverage; load targets are documented with headroom" (it previously opened with "the smoke suite is a CI gate"; the master plan's Stage 12 section carries the same amendment), expands to:

1. Erasure over HTTP anonymizes name, email, and password in place, stamps `anonymized_at`, revokes the customer's outstanding access and refresh tokens (pre-erasure tokens are rejected afterward), blocks subsequent login and guest claim, returns 409 on repeat, and emits exactly one `CustomerAnonymized` event; two racing erasures produce one anonymization (concurrency suite).
2. Ticket `attendee_name` and any Reporting PII are scrubbed by idempotent consumers after erasure; duplicate delivery causes no error and no second effect.
3. Export over HTTP produces a downloadable per-tenant document covering profile, orders, tickets, payments, refunds, and check-ins, wire-conventional throughout; duplicate job delivery yields one attachment; the download URL expires and the attachment is pruned on schedule.
4. Webhook payloads past the retention window are nulled with idempotence by gateway event ID preserved; activity log rows past the window exist in verified object storage segments and not in PostgreSQL; no pruner can touch financial records.
5. Outbox rows past the window live in checksummed segments; a projection rebuilt by replay across the archive boundary equals its incremental state; no event with a pending delivery is archived.
6. Each of the three operational commands is dry-run by default, bounded by explicit arguments, produces its effect exactly once under races with the standing sweepers, and writes an activity log entry naming the operator.
7. The coverage meta-tests pass with empty gaps: every `/v1` route has contract coverage, every tenant-scoped route has isolation coverage or a reasoned exemption, every mutating route maps to a capability, and seeding a synthetic uncovered route makes each meta-test fail (proven once during development).
8. The authorization matrix test denies every capability a role lacks across all routes; webhook signature negatives reject before persistence for every registered gateway; no `env()` outside `config/`; no committed `.env.example` carries a credential.
9. Dropped with the smoke suite (task 17), numbering kept so the criteria below keep their numbers. The publish-through-payout loop over HTTP against the fake gateway, with the ledger balanced at every step, remains proven by the Stage 8c capstone; no criterion of this stage rests on a `Smoke` suite.
10. `docs/load-targets.md` records targets, tool, profiles, and measured results for hold creation, payment initiation, and waiting room admission, each meeting its target with headroom stated; when Stage 10 was thinned per the master plan's MVP note, the admission scenario is recorded as omitted, and this criterion counts as complete for the full API plan only once Stage 10 lands and the scenario is measured; post-run probes show zero oversell and zero duplicate payment effects.
11. All suites, Larastan, and Pint green; OpenAPI and generated TypeScript committed without drift; system-design 9.3 lists `CustomerAnonymized`; the status table marks Stage 12 done.

## Risks and open questions

- Activity log retention versus append-only. System-design 14.2 says log rows are never updated or deleted inside the application; 14.3 gives logs a retention window. This plan reconciles them as archive-then-delete by a dedicated scheduled command, with rows immutable while retained and the archive preserving them past deletion. The blocker is mechanical as well as documentary: Stage 3 shipped no UPDATE or DELETE policy on `activity_log` and Stage 2's platform role writes only to Tenancy-owned tables, so task 8 ships the scoped delete path and amends 14.2 to state this reading. If the stricter reading (no deletion ever) is intended instead, task 8 is dropped, the pruner becomes archive-only, and PostgreSQL growth needs another answer.
- Archival "independent of delivery state" (system-design 9.1) versus pending deliveries. Archiving an event a consumer has not processed would strand it. This plan reads 9.1 as meaning the schedule is not driven by delivery completion, while still refusing to archive rows with pending deliveries; with the retention window months long and the sweeper's grace window a minute, a pending delivery at archival age signals a bug worth surfacing, not archiving over. Decide before task 10.
- Erasure scope beyond `customers`. System-design 14.3 names customer name and email; tickets carry `attendee_name`, which is equally PII. This plan scrubs it via the `CustomerAnonymized` consumer. If attendees can be named who are not the customer, erasing the buyer also erases attendee names on their tickets, which is the conservative (privacy-favoring) choice; confirm this matches the legal posture.
- Export completeness. Which fields per context constitute "the customer's records" is a judgment call; this plan exports what the customer supplied or generated (profile, orders, tickets, payments, refunds, check-ins) and excludes internal projections. A regulator-facing review of the field list belongs in the PR for task 6.
- Placeholder email uniqueness. Anonymization must satisfy the per-tenant email unique constraint forever; the deterministic placeholder derives from the customer UUID, which cannot collide, but any future re-registration with the original email creates a new customer row by design. Confirm support understands the old and new records are unlinked.
- Retention window defaults. Proposed: webhook payloads 90 days, activity log 400 days, outbox archival threshold 180 days, export attachments 7 days. All config, fake-clock tested, tunable without migration; no production data exists to calibrate against, and statutory minimums per launch market must be checked before defaults ship.
- Chargebacks. Stage 8d deferred dispute ingestion here pending the gateway ADR. If the ADR lands during this stage and the launch market demands it, it becomes an addendum slice (webhook types, order and ledger effects); otherwise it is tracked as a pre-launch follow-up, and this plan does not block on it.
- Earlier-stage drift. Stages 5 through 11 are unbuilt at time of writing; table and Action names referenced here (webhook table, poller, `ReleaseHold`) follow the design and earlier plans, and attachment points move with them if those stages shipped differently. The contracts in this plan (endpoints, event payload, invariants) stay fixed.
- Load environment realism. Compose-stack numbers do not predict production absolutely (ADR 017 defers the orchestrator); targets are therefore expressed as headroom multiples on the contended writes rather than absolute production promises, and the doc must say so to prevent misreading.
- Meta-test registries. Coverage completeness depends on registries (isolation coverage map, capability map, exemption lists) staying honest; a lazily added exemption defeats the gate. Exemptions require a reason string, and reviewing them is called out as a standing review-checklist item.
