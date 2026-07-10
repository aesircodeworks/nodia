# Execution Journal: Stage 4, Transactional Outbox

Durable record of execution runs for [stage-04-outbox.md](../stage-04-outbox.md). Append a new run header per run; never rewrite prior entries.

## Run: 2026-07-10

- Stage: 4, Transactional Outbox
- Date: 2026-07-10
- Branch: `feat/api-implementation`
- Base commit: `83b3dca293cc9eb39d737e10fde2c2564b249978`

Verified starting state: Stages 1-3 are Done. Stage 4 is Not started. No `app/Support/Outbox` directory, no outbox migrations, no outbox tests. Identity Actions (`InviteUser`, `AssignRole`, `RegisterCustomer`) carry Stage 4 attachment-point comments only. Tenancy event classes (`TenantCreated`, `DomainVerified` and payloads) exist from Stage 2 without producers. Horizon is not installed. Correlation middleware sets the header and log context but has no request-scoped container binding for the recorder.

### Task checklist

- [x] task-02: Correlation ID container binding (plan task 2)
- [x] task-03: `outbox_events` migration, model, isolation tests (plan task 3)
- [ ] task-04: Recording API, envelope, registry validation, architecture tests (plan task 4)
- [ ] task-05: Horizon and queue plumbing, failed_jobs UUID PK (plan task 5)
- [ ] task-06: `outbox_deliveries` migration, model, conditional processed transition (plan task 6)
- [ ] task-07: Subscriber registry, after-commit dispatcher, delivery job, test fixtures (plan task 7)
- [ ] task-08: Reconciliation sweeper, config windows, scheduler (plan task 8)
- [ ] task-09: Ordered-consumption helper (plan task 9)
- [ ] task-10: Replay primitive and artisan command (plan task 10)
- [ ] task-11: `UserInvited` producer in InviteUser (plan task 11)
- [ ] task-12: `UserRoleChanged` producer in AssignRole (plan task 12)
- [ ] task-13: `CustomerRegistered` producer in RegisterCustomer (plan task 13)
- [ ] task-14: `TenantCreated` and `DomainVerified` producers plus 9.3 registry rows (plan task 14)

### Review rounds

### Decisions and deviations

#### task-02: Correlation ID container binding (2026-07-10 03:49 -03)

Commit: `7de88c3` (`feat(support): request-scoped correlation ID container binding`), plus this journal entry.

Landed plan task 2: a request-scoped container binding for the current correlation ID that services (including the future OutboxRecorder) can read without touching log context.

What landed:

- `App\Support\Correlation\CorrelationId`: request-scoped holder with `set()`, `get()` (lazy UUIDv7 on first read when unset), and `has()`. No static state.
- `AppServiceProvider::register()` binds it `scoped()`, same Octane-safe pattern as `TenantContext`.
- `App\Http\Middleware\CorrelationId` injects the holder and calls `set()` with the inbound header value or the middleware-generated UUIDv7, so the binding always matches the response header and log context for that request.
- Unit tests: generation, stability within a scope, assigned value, `has()`, and `forgetScopedInstances()` producing a fresh instance.
- Feature tests: binding returns a client-provided `X-Correlation-Id`; when the header is absent, binding equals the echoed response header.

Deviations: none. Class lives at `App\Support\Correlation\CorrelationId` as the stage plan suggests; middleware keeps its existing class name and imports the support class as `CurrentCorrelationId` to avoid a same-name collision.

Test evidence: `composer -d apps/api run lint` (Pint) passed. `composer -d apps/api run analyse` (Larastan) passed with 0 errors. `composer -d apps/api run types:generate` produced no diff. `composer -d apps/api run test` (all six suites) passed 943 tests, 3709 assertions, 0 failures. Focused `php artisan test --filter=CorrelationId` passed 11 tests, 39 assertions (6 feature, 5 unit).

#### task-03: outbox_events migration, model, isolation tests (2026-07-10 03:59 -03)

Commit: `3cc9319` (`feat(support): outbox_events table, model, and isolation tests`).

Landed plan task 3 / Slice 1 isolation: the `outbox_events` table with sequence identity, indexes, and RLS in the same migration, the append-only `OutboxEvent` model, and the two-tenant isolation suite.

What landed:

- Migration `2026_07_10_000020_create_outbox_events_table`: uuid PK, `sequence bigint generated always as identity` (unique), envelope columns per event-conventions (`type`, `tenant_id` FK, `aggregate_type`, `aggregate_id`, `correlation_id` string, `occurred_at`, jsonb `payload`, timestamps), composite index `(aggregate_type, aggregate_id, sequence)`, index `(type, sequence)`, `Rls::applyTenantPolicies('outbox_events')` (no platform write), and `GRANT USAGE, SELECT` on `outbox_events_sequence_seq` for app/platform inserts.
- `App\Support\Outbox\Models\OutboxEvent`: `HasUuids`, fillable create surface only, casts for `sequence`/`payload`/`occurred_at`, `updating`/`deleting` throw `LogicException` (append-only application invariant).
- Isolation suite: `OutboxEventFixture` plus `OutboxEventsIsolationTest` (own-tenant read, cross-tenant update/delete zero rows, foreign-tenant WITH CHECK reject, raw SQL isolation, platform read both, platform write denied, owning-tenant insert assigns sequence identity).
- Architecture `PresetTest` ignores `App\Support\Outbox\Models` the same way as `App\Support\Audit\Models`.

Deviations: none. Laravel's `bigInteger()->generatedAs()->always()` expresses the identity column without raw `ALTER TABLE`; sequence privilege grant remains raw SQL as the task notes require. Append-only is model-level only (standard CRUD grants via `applyTenantPolicies`), matching the stage plan's "application invariant" wording rather than activity_log's privilege strip.

Test evidence: `composer -d apps/api run lint` (Pint) passed. `composer -d apps/api run analyse` (Larastan) passed with 0 errors. `composer -d apps/api run types:generate` produced no diff. Focused Isolation `OutboxEventsIsolationTest` passed 8 tests, 15 assertions. `composer -d apps/api run test` (all six suites) passed 951 tests, 3724 assertions, 0 failures.
