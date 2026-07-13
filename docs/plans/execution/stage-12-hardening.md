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

- [ ] T1 `data_subject_requests` migration, model, enums, conditional status transitions (identity)
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
