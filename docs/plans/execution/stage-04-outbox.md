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
- [x] task-04: Recording API, envelope, registry validation, architecture tests (plan task 4)
- [x] task-05: Horizon and queue plumbing, failed_jobs UUID PK (plan task 5)
- [x] task-06: `outbox_deliveries` migration, model, conditional processed transition (plan task 6)
- [x] task-07: Subscriber registry, after-commit dispatcher, delivery job, test fixtures (plan task 7)
- [x] task-08: Reconciliation sweeper, config windows, scheduler (plan task 8)
- [x] task-09: Ordered-consumption helper (plan task 9)
- [x] task-10: Replay primitive and artisan command (plan task 10)
- [x] task-11: `UserInvited` producer in InviteUser (plan task 11)
- [x] task-12: `UserRoleChanged` producer in AssignRole (plan task 12)
- [x] task-13: `CustomerRegistered` producer in RegisterCustomer (plan task 13)
- [x] task-14: `TenantCreated` and `DomainVerified` producers plus 9.3 registry rows (plan task 14)

### Review rounds

#### Review round 1 (2026-07-10 05:11 -03)

Applied Stage 4 code-review findings (blocking/important + one minor). Commits on `feat/api-implementation` after the gate at `956b2ab` / CI at `e71d00c` / journal CI IDs at `f91db35`.

| #   | Severity  | Finding                                                                                                                       | Action                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| --- | --------- | ----------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | important | Missing Redis/Horizon end-to-end delivery proof (Slice 2 / exit criterion 2). Existing e2e used `QUEUE_CONNECTION=sync` only. | Added `tests/Feature/Support/Outbox/OutboxRedisDeliveryTest.php`: requires reachable Redis (fails hard with a clear message; CI always has `redis:8-alpine`), overrides only this suite to `queue.default=redis` on a dedicated queue `outbox-redis-e2e`, records via `OutboxRecorder` in a tenant transaction, asserts the job landed in Redis (`LLEN == 1`) with zero subscriber effects yet, runs `queue:work redis --once --sleep=0` to pop and handle, asserts exactly one subscriber effect and `OutboxDeliveryStatus::Processed`, then clears Redis keys and restores `sync`. No Horizon daemon; same Redis drivers Horizon uses. |
| 2   | important | `OutboxDeliveryStatus` leaked into TypeScript client contracts.                                                               | Added `#[Hidden]` (`Spatie\TypeScriptTransformer\Attributes\Hidden`) on `App\Support\Outbox\Enums\OutboxDeliveryStatus` (same pattern as event payloads). Ran `composer -d apps/api run types:generate`; `OutboxDeliveryStatus` removed from `packages/api-client/src/generated/index.ts`.                                                                                                                                                                                                                                                                                                                                               |
| 3   | minor     | `OutboxEvent` fillable missing `id` while `OutboxRecorder` mass-assigns a pre-generated UUIDv7.                               | Added `id` to the `Fillable` attribute on `OutboxEvent`. Unit test `persists an explicit id through mass assignment on create` asserts the supplied id is stored and readable by key.                                                                                                                                                                                                                                                                                                                                                                                                                                                    |

Declined: none.

Test evidence: `composer -d apps/api run lint` (Pint) passed. `composer -d apps/api run analyse` (Larastan) 0 errors. `composer -d apps/api run types:generate` removed `OutboxDeliveryStatus`. Focused Redis e2e + OutboxRecorder unit suite green. `composer -d apps/api run test` (all six suites) passed 1074 tests, 4213 assertions, 0 failures.

#### Review round 2 (2026-07-10 05:18 -03)

Applied one Stage 4 code-review finding (important). Commit on `feat/api-implementation`: `ce99fef`.

| #   | Severity  | Finding                                                                                                              | Action                                                                                                                                                                                                                                                                                                                                                                                 |
| --- | --------- | -------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | important | `AssignRole` recorded `UserRoleChanged` even when the role did not change (same-role PATCH is an intentional no-op). | Added failing Slice 6-style feature test `records nothing when the role_id is unchanged (same-role no-op PATCH)` asserting zero `UserRoleChanged` outbox rows on a successful same-role PATCH. Gated the membership `role_id` update and `OutboxRecorder::record(UserRoleChanged...)` behind `$previousRoleId !== $role->id`, the same condition already used by the last-owner guard. |

Declined: none.

Test evidence: `composer -d apps/api run lint` (Pint) passed. `composer -d apps/api run analyse` (Larastan) 0 errors. `composer -d apps/api run types:generate` produced no diff. Focused `UserRoleChangedOutboxTest` (6) and `LastOwnerGuardTest` (7) green. `composer -d apps/api run test` (all six suites) passed 1075 tests, 4216 assertions, 0 failures.

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

#### task-04: Recording API (2026-07-10 04:07 -03)

Commit: (this commit) (`feat(support): outbox recording API with registry and domain event contract`).

Landed plan task 4 / Slice 1 recording API: the DomainEvent contract, EventTypeRegistry, OutboxEnvelope, OutboxRecorder (in-transaction only, registry-validated, no deliveries), Tenancy events adapted to the contract, and Slice 1 unit/feature/architecture tests.

What landed:

- `App\Support\Outbox\DomainEvent`: interface for type, tenant_id, aggregate coordinates, and laravel-data payload.
- `App\Support\Outbox\EventTypeRegistry`: singleton set of allowed type-name strings; production types register from owning context providers (no Support imports of context classes); tests register fixture types in setup.
- `App\Support\Outbox\OutboxEnvelope`: readonly envelope assembled at record time (id, type, tenant, aggregate, correlation_id, occurred_at, payload); sequence is identity-assigned and refreshed after insert.
- `App\Support\Outbox\OutboxRecorder`: throws outside an open transaction; rejects unregistered types; writes one `outbox_events` row with UUIDv7 id, correlation from `CorrelationId` (lazy UUIDv7 when unset), `occurred_at` from `now()` (fake-clock compatible), snake_case payload via `Data::toArray()`; does not create deliveries.
- Tenancy `TenantCreated` and `DomainVerified` implement `DomainEvent` without changing public properties or payload shapes; `TenancyServiceProvider` registers their type names.
- Architecture: contexts' Events/ trees are only used inside their owning context; `App\Support\Outbox` imports no context models.
- Unit: outside transaction throws; unregistered type throws; occurred_at UTC from fake clock; correlation_id bound or generated UUIDv7; snake_case payload; model update/delete LogicException.
- Feature (real PostgreSQL): rolled-back record leaves no row; committed record persists exactly one full envelope row.

Deviations: none material. Envelope is a readonly value object rather than a laravel-data class so it does not enter TypeScript generation (payloads already do; that exclusion remains a later cleanup). Production Tenancy type names register now so task-14 producers can record without a second registry pass; Identity types still wait on their event classes.

Test evidence: `composer -d apps/api run lint` (Pint) passed. `composer -d apps/api run analyse` (Larastan) passed with 0 errors. `composer -d apps/api run types:generate` produced no diff. Focused Outbox/Events architecture and unit/feature recording tests passed 31 tests, 72 assertions. `composer -d apps/api run test` (all six suites) passed 978 tests, 3786 assertions, 0 failures.

#### task-05: Horizon and queue plumbing (2026-07-10 04:13 -03)

Commits: `9bbf8c5` (`chore(deps): install laravel/horizon`), `499d15f` (`feat(support): Horizon queue plumbing and failed_jobs UUID PK`).

Landed plan task 5: laravel/horizon with published config, UUID primary key on `failed_jobs`, unscoped queue infrastructure tables, local-only dashboard gate, and the isolation-suite unscoped-table sweep.

What landed:

- `laravel/horizon` `^5.47` (verified against Packagist for Laravel 13 / illuminate `^13.0`; pulls `laravel/sentinel` as a transitive). Published `config/horizon.php` and `App\Providers\HorizonServiceProvider`.
- `viewHorizon` gate always returns false; parent Horizon auth still admits `app()->environment('local')` only. Horizon routes sit under `/horizon`, not `/v1`.
- Migration `2026_07_10_000021_create_failed_jobs_and_job_batches_tables`: `failed_jobs.uuid` is the uuid PK (no bigint auto-increment); `job_batches` keeps its string PK; both get `Rls::grantUnscoped` and no `tenant_id` / RLS.
- `App\Support\Queue\UuidFailedJobProvider` extends the stock database-uuids failer so listing orders by `failed_at` without a surrogate bigint id; bound via `AppServiceProvider` when the failer is `DatabaseUuidFailedJobProvider`.
- Queue defaults: connection `redis`, failed driver `database-uuids`, batching/failed DB connection `pgsql`.
- `App\Support\Database\UnscopedTables` exclusion list plus isolation sweep requiring every public base table without RLS to appear on that list (cache, oauth, failed_jobs, job_batches, etc.).

Deviations: none material. `failed_jobs` uses the job uuid as the sole PK rather than a separate bigint id plus uuid column, matching data-conventions; the provider subclass is the only framework adaptation required. The isolation "tenant-scoped-table sweep" named in the plan did not exist as code yet; this task introduces it as the unscoped exclusion sweep that stage-12 and later stages will extend.

Test evidence: `composer -d apps/api run lint` (Pint) passed. `composer -d apps/api run analyse` (Larastan) passed with 0 errors. `composer -d apps/api run types:generate` produced no diff. Focused Horizon/failed_jobs/unscoped-sweep tests passed 12 tests, 69 assertions. `composer -d apps/api run test` (all six suites) passed 990 tests, 3855 assertions, 0 failures.

#### task-06: outbox_deliveries migration, model, conditional processed transition (2026-07-10 04:20 -03)

Commit: (this commit) (`feat(support): outbox_deliveries table, model, and conditional mark processed`).

Landed plan task 6 / Slice 2 isolation and unit slice: the `outbox_deliveries` table with unique (event, subscriber), partial pending sweep index, and RLS in the same migration; the status enum; the `OutboxDelivery` model with conditional `markProcessed()`; isolation and unit tests. Registry, dispatcher, jobs, and sweeper remain for later tasks.

What landed:

- Migration `2026_07_10_000022_create_outbox_deliveries_table`: uuid PK, FK to `outbox_events` and `tenants`, subscriber string, status string, nullable `processed_at` / `last_enqueued_at`, timestamps; unique `(outbox_event_id, subscriber)`; partial index `outbox_deliveries_pending_sweep_idx` on `(subscriber, created_at) WHERE status = 'pending'`; `Rls::applyTenantPolicies('outbox_deliveries')` (no platform write).
- `App\Support\Outbox\Enums\OutboxDeliveryStatus`: string-backed `pending` / `processed`.
- `App\Support\Outbox\Models\OutboxDelivery`: `HasUuids`, enum cast, `markProcessed()` as conditional UPDATE `SET status=processed, processed_at=now() WHERE id=? AND status=pending` checked by affected-row count; zero rows returns false (idempotent, not error).
- Isolation suite: `OutboxDeliveryFixture` plus `OutboxDeliveriesIsolationTest` (own-tenant read, cross-tenant update/delete zero rows, foreign-tenant WITH CHECK reject, raw SQL isolation, platform read both, platform write denied, owning-tenant insert).
- Unit: status enum cases; mark processed wins once with `processed_at`; second mark returns false and leaves the first `processed_at` unchanged.
- Architecture preset ignores `OutboxDeliveryStatus` (lives under Support\Outbox, not App\Enums); `App\Support\Outbox\Models` already covers the new model.
- `composer types:generate` adds the backed enum to the api-client (same EnumTransformer path as `MembershipScope`).

Deviations: none. Conditional transition lives as a model method rather than a separate action class; that keeps the guard on the row that owns the invariant and matches the task's preferred surface.

Test evidence: `composer -d apps/api run lint` (Pint) passed. `composer -d apps/api run analyse` (Larastan) passed with 0 errors. `composer -d apps/api run types:generate` updated generated client with `OutboxDeliveryStatus`. Focused OutboxDelivery/OutboxDeliveriesIsolation tests passed 12 tests, 27 assertions. `composer -d apps/api run test` (all six suites) passed 1002 tests, 3882 assertions, 0 failures.

#### task-07: Subscriber registry, after-commit dispatcher, delivery job, test fixtures (2026-07-10 04:28 -03)

Commit: (this commit) (`feat(support): outbox subscriber registry, dispatcher, and delivery job`).

Landed plan task 7 / Slice 2 delivery machinery: static subscriber registry, delivery-row fan-out at record time, after-commit one-job-per-subscriber enqueue, the cross-tenant-then-tenant worker bootstrap with mark-processed-before-effect, idempotent test subscriber fixtures, and the Slice 2 feature/unit/concurrency suite.

What landed:

- `App\Support\Outbox\OutboxSubscriber`: handler contract (`handle(OutboxEvent)`).
- `App\Support\Outbox\SubscriberRegistry`: singleton map of stable name to event types plus handler; rejects duplicate names and empty type lists; `namesFor` returns registration-order routing; production starts empty (no config sniffing; tests register only in setup).
- `App\Support\Outbox\OutboxDispatcher`: `DB::afterCommit` enqueues one `ProcessOutboxDelivery` per subscriber and sets `last_enqueued_at` under a tenant-scoped write before dispatch so the sweeper grace window has a real enqueue timestamp.
- `App\Support\Outbox\Jobs\ProcessOutboxDelivery`: loads the envelope via `TenantTransaction::asPlatform` (SELECT only), then opens `asTenant` with the envelope tenant, conditional `markProcessed()` before the handler effect, clean no-retry exit on zero rows.
- `OutboxRecorder` creates one pending delivery per interested subscriber in the producing transaction and schedules the dispatcher; rollback removes event and deliveries and enqueues nothing.
- Test fixtures: `IdempotentTestSubscriber`, Pest helpers (`registerIdempotentOutboxSubscriber`, `processOutboxDeliveryTwice`, durable effects table for multi-process races).
- Slice 2 tests: feature delivery creation / rollback / job fan-out / e2e process / duplicate job; unit registry routing and duplicate rejection; concurrency two workers one effect.
- Architecture preset ignores `App\Support\Outbox\Jobs`.

Decisions:

1. Job payload is `{eventId, subscriber}` rather than event id alone. System-design 9.2 and the stage plan say jobs carry only the event ID (no envelope/payload duplicated into Redis). Multi-subscriber fan-out needs a routing key to pick one delivery row; subscriber name is that key on a single shared job class. Envelope and payload still load from PostgreSQL. Documented here as the reading of "only the event ID" = no domain state in Redis.
2. Cross-tenant platform SELECT in the worker is infrastructure, not a staff action. `PlatformRoleAudit` only fires from `PlatformRequestTransaction` on HTTP requests; `asPlatform` inside the job does not write activity_log. Stage-04 open question: audit requirement targets staff-initiated cross-tenant operations; infrastructure SELECT is exempt. No code carve-out required beyond not calling `PlatformRoleAudit` from the job.
3. E2E processing uses `QUEUE_CONNECTION=sync` (phpunit default) so after-commit dispatch runs the job inline against real PostgreSQL. Job payload and fan-out are asserted with `Queue::fake`. Full Horizon worker process is the same handle path; redis-backed worker is not spun in CI (matches task-05's phpunit sync choice).

Test evidence: `composer -d apps/api run lint` (Pint) passed. `composer -d apps/api run analyse` (Larastan) passed with 0 errors. `composer -d apps/api run types:generate` produced no diff. Focused Slice 2 outbox tests passed 12 tests, 34 assertions. `composer -d apps/api run test` (all six suites) passed 1014 tests, 3916 assertions, 0 failures.

#### task-08: Reconciliation sweeper (2026-07-10 04:30 -03)

Commit: `ae78ce6` (`feat(support): outbox reconciliation sweeper with config windows`), plus this journal entry.

Landed plan task 8 / Slice 3: config-driven stability and grace windows, the `outbox:sweep` artisan command that re-enqueues stranded pending deliveries, every-minute schedule registration, and the Slice 3 feature/unit suite.

What landed:

- `config/outbox.php`: `stability_window_seconds` default 5, `sweeper_grace_seconds` default 60 (env-overridable, no migration).
- `App\Support\Outbox\OutboxSweeper`: platform-role SELECT of pending deliveries where `coalesce(last_enqueued_at, created_at)` is past grace and the joined event's `occurred_at` is past the stability window; per row, tenant-scoped conditional update of `last_enqueued_at` then `ProcessOutboxDelivery` dispatch. Processed rows never match the pending filter; a concurrent process that marks processed between scan and update yields zero affected rows and no enqueue.
- `App\Console\Commands\SweepOutboxCommand` (`outbox:sweep`): thin artisan wrapper reporting re-enqueue count.
- `bootstrap/app.php` `withSchedule`: `outbox:sweep` every minute.
- Slice 3 tests: pending with null `last_enqueued_at` re-enqueued after grace from `created_at`; inside grace not re-enqueued; processed never re-enqueued; events younger than stability not treated as final (grace < stability clock probe); grace measured from `last_enqueued_at` when set; unit config defaults/overrides, fake-clock cutoffs, schedule expression `* * * * *`.

Deviations: none material. Stability age uses `outbox_events.occurred_at` (set from `now()` at record time in the same transaction as insert); that is the domain timestamp already on the envelope and matches the fake-clock path the recorder uses. Command lives under `App\Console\Commands` (Laravel discovery path) rather than next to Support/Outbox Jobs, so the architecture preset needs no new ignore.

Test evidence: `composer -d apps/api run lint` (Pint) passed. `composer -d apps/api run analyse` (Larastan) passed with 0 errors. `composer -d apps/api run types:generate` produced no diff. Focused Slice 3 outbox tests passed 9 tests, 28 assertions. `composer -d apps/api run test` (all six suites) passed 1023 tests, 3944 assertions, 0 failures.

#### task-09: Ordered-consumption helper (2026-07-10 04:35 -03)

Commit: `13bc8bd` (`feat(support): ordered-consumption helper with stability and predecessor gates`), plus this journal entry.

Landed plan task 9 / Slice 4: the ordered-consumption readiness gate, opt-in marker for ordered subscribers, job integration with release-based deferral and final-attempt exhaustion exit, config backoff, and the Slice 4 feature/concurrency suite.

What landed:

- `App\Support\Outbox\OrderedOutboxSubscriber`: empty marker extending `OutboxSubscriber`; implementors opt into ordering. Stage 8b may extend with payload-derived ordering keys; Stage 4 keys strictly on envelope aggregate.
- `App\Support\Outbox\OrderedConsumption`: `isReady` is false when the event is younger than `stability_window_seconds` or a same-aggregate lower-sequence delivery for the same subscriber is still pending. Different aggregates never block. Call under tenant scope so RLS applies.
- `ProcessOutboxDelivery`: before conditional `markProcessed`, ordered handlers consult `OrderedConsumption`. Unready deliveries stay pending; the job releases with `ordered_defer_seconds` backoff (default 15, under sweeper grace). On the final attempt (`attempts >= tries`) the job returns without releasing or failing so the sweeper re-enqueues rather than dead-lettering (stage-04 ordered-helper risk). Job `$tries = 40` overrides Horizon supervisor `tries = 1`.
- `config/outbox.php`: `ordered_defer_seconds` env-overridable default 15.
- Test fixtures: `OrderedTestSubscriber`, `registerOrderedOutboxSubscriber`; durable effects table uses bigserial PK plus `event_sequence` for application-order assertions.
- Slice 4 tests: predecessor unprocessed defers then processes after predecessor; different aggregate does not block; younger than stability not processed; final-attempt exhaustion leaves pending without release/fail; concurrency two workers racing out-of-order same-aggregate events apply effects in sequence order.

Deviations: none material. Deferral uses job release as the plan prefers; exhaustion is covered by the final-attempt clean return rather than switching to sweeper-only deferral. Direct `handle()` calls without a queue job treat release as a no-op (Laravel `InteractsWithQueue`); concurrency workers retry in-process until processed.

Test evidence: `composer -d apps/api run lint` (Pint) passed. `composer -d apps/api run analyse` (Larastan) passed with 0 errors. `composer -d apps/api run types:generate` produced no diff. Focused ordered-consumption / config / delivery tests passed 17 tests, 68 assertions. `composer -d apps/api run test` (all six suites) passed 1028 tests, 3980 assertions, 0 failures.

#### task-10: Replay primitive (2026-07-10 04:40 -03)

Commit: `65d3801` (`feat(support): outbox replay primitive and artisan command`), plus this journal entry.

Landed plan task 10 / Slice 5: the support API that rescans `outbox_events` in sequence order past the stability window for a subscriber's types, optional inclusive starting sequence, the `outbox:replay` artisan command, and the Slice 5 feature/unit suite.

What landed:

- `App\Support\Outbox\OutboxReplay`: platform-role SELECT of events whose `type` is in the subscriber's registered set, `occurred_at` past `stability_window_seconds`, ordered by `sequence` ascending, optional `sequence >= fromSequence`; each matching envelope is fed to the handler under a tenant-scoped transaction. Delivery rows are not read or mutated; consumer idempotence by event id makes double-replay safe.
- `SubscriberRegistry::typesFor`: exposes the registered type list for a named subscriber (replay filter source).
- `App\Console\Commands\ReplayOutboxCommand` (`outbox:replay {subscriber} {--from-sequence=}`): thin artisan wrapper reporting the number of events fed.
- Test fixtures: `ProjectionTestSubscriber` with commutative snapshot state (applied event ids + per-aggregate counts) and visit-order tracking for sequence assertions.
- Slice 5 tests: feature rebuild equivalence (incremental vs sequence-zero replay), sequence order with stability-window skip, double-replay same state, artisan command wiring; unit type filter, inclusive starting sequence, unregistered subscriber throws.

Deviations: none material. Replay intentionally bypasses `outbox_deliveries` and `ProcessOutboxDelivery` (rebuild rescans the log; live progress tracking stays on the delivery path). Stage 12 audited operational wrappers remain deferred.

Test evidence: `composer -d apps/api run lint` (Pint) passed. `composer -d apps/api run analyse` (Larastan) passed with 0 errors. `composer -d apps/api run types:generate` produced no diff. Focused OutboxReplay / SubscriberRegistry tests passed 12 tests, 47 assertions. `composer -d apps/api run test` (all six suites) passed 1036 tests, 4015 assertions, 0 failures.

#### task-11: UserInvited producer in InviteUser (2026-07-10 04:45 -03)

Commit: `8ee2bf3` (`feat(identity): UserInvited producer in InviteUser`), plus this journal entry.

Landed plan task 11 / Slice 6 identity producer: `UserInvited` event and payload, registry registration from Identity, recording inside InviteUser's producing transaction, and the Slice 6 unit/feature suite.

What landed:

- `App\Identity\Events\UserInvited` and `UserInvitedPayload`: aggregate `membership` / membership id; envelope `tenant_id` from the membership (sentinel when the membership is on the platform tenant); payload fields `user_id`, `membership_id`, `tenant_id`, `role_id`, `invited_by_user_id` only (no email or name). Payload carries `#[Hidden]` so it is excluded from TypeScript generation as an internal contract.
- `IdentityServiceProvider` registers the `UserInvited` type-name string on the outbox registry (resolved via container so direct `boot()` unit calls keep working).
- `InviteUser` injects `OutboxRecorder` and records after the membership insert in the enclosing tenant request transaction; takes `$invitedByUserId` from the controller's authenticated staff user.
- Slice 6 tests: HTTP success persists exactly one outbox row (type, aggregate, tenant, correlation ID from `X-Correlation-Id`, payload shape without PII); validation, capability, and membership-exists failures record nothing; platform-scope invite under the sentinel tenant carries the sentinel in the envelope; unit payload field set and no email/name; optional rollback coupling after record leaves no row.
- Existing invite-path feature/unit suites clear `outbox_events` / `outbox_deliveries` in afterEach so tenant teardown is not blocked by the new FK rows.

Deviations: none material. InviteUser still creates `MembershipScope::Tenant` even when `X-Tenant-Id` is the sentinel (pre-existing Stage 3 behavior); the platform-scope envelope test asserts envelope `tenant_id` equals the membership's tenant (sentinel) rather than requiring `scope = platform`.

Test evidence: `composer -d apps/api run lint` (Pint) passed. `composer -d apps/api run analyse` (Larastan) passed with 0 errors. `composer -d apps/api run types:generate` produced no diff (`UserInvitedPayload` not emitted). Focused UserInvited / InviteUserAction tests passed 14 tests, 57 assertions. `composer -d apps/api run test` (all six suites) passed 1045 tests, 4063 assertions, 0 failures.

#### task-12: UserRoleChanged producer in AssignRole (2026-07-10 04:50 -03)

Commit: `edde86d` (`feat(identity): UserRoleChanged producer in AssignRole`), plus this journal entry.

Landed plan task 12 / Slice 6 identity producer: `UserRoleChanged` event and payload, registry registration from Identity, recording inside AssignRole's producing transaction, and the Slice 6 unit/feature suite.

What landed:

- `App\Identity\Events\UserRoleChanged` and `UserRoleChangedPayload`: aggregate `membership` / membership id; envelope `tenant_id` from the membership; payload fields `membership_id`, `user_id`, `previous_role_id`, `new_role_id`, `changed_by_user_id` only (no email or name, no tenant_id in payload). Payload carries `#[Hidden]` so it is excluded from TypeScript generation as an internal contract.
- `IdentityServiceProvider` registers the `UserRoleChanged` type-name string on the outbox registry alongside `UserInvited`.
- `AssignRole` injects `OutboxRecorder`, captures `previous_role_id` before the update, and records after the membership update in the enclosing tenant request transaction; takes `$changedByUserId` from the controller's authenticated staff user.
- `MembershipController::update` passes the staff user id the same way `store` passes the inviter for InviteUser.
- Slice 6 tests: HTTP success persists exactly one outbox row (type, aggregate, tenant, correlation ID from `X-Correlation-Id`, payload shape without PII); validation, capability, and last-owner-removal failures record nothing; unit payload field set and no email/name; optional rollback coupling after record leaves no row.
- `LastOwnerGuardTest` updated for the new AssignRole signature and clears `outbox_events` / `outbox_deliveries` in afterEach so tenant teardown is not blocked by the new FK rows.

Deviations: none material. Same-role no-op reassignment still records `UserRoleChanged` with identical previous/new role ids (AssignRole always updates and records, matching the Action's pre-existing no-op path).

Test evidence: `composer -d apps/api run lint` (Pint) passed. `composer -d apps/api run analyse` (Larastan) passed with 0 errors. `composer -d apps/api run types:generate` produced no diff (`UserRoleChangedPayload` not emitted). Focused UserRoleChanged / LastOwnerGuard tests passed 15 tests, 50 assertions. `composer -d apps/api run test` (all six suites) passed 1053 tests, 4105 assertions, 0 failures.

#### task-13: CustomerRegistered producer in RegisterCustomer (2026-07-10 04:55 -03)

Commit: `3937759` (`feat(identity): CustomerRegistered producer in RegisterCustomer`), plus this journal entry.

Landed plan task 13 / Slice 6 identity producer: `CustomerRegistered` event and payload, registry registration from Identity, recording inside RegisterCustomer's producing transaction, and the Slice 6 unit/feature suite.

What landed:

- `App\Identity\Events\CustomerRegistered` and `CustomerRegisteredPayload`: aggregate `customer` / customer id; envelope `tenant_id` from the customer; payload fields `customer_id`, `is_guest` only (no email or name). `is_guest` is true when `password` is null (guest creation) and false when a password was supplied (full registration). Payload carries `#[Hidden]` so it is excluded from TypeScript generation as an internal contract.
- `IdentityServiceProvider` registers the `CustomerRegistered` type-name string on the outbox registry alongside `UserInvited` and `UserRoleChanged`.
- `RegisterCustomer` injects `OutboxRecorder` and records after the customer insert in the enclosing storefront tenant request transaction.
- Slice 6 tests: HTTP success persists exactly one outbox row for guest (`is_guest` true) and for password registration (`is_guest` false), with type, aggregate, tenant, correlation ID from `X-Correlation-Id`, and payload shape without PII; validation and email-taken failures record nothing (email-taken after a prior success leaves only the first row); unit payload field set and no email/name; optional rollback coupling after record leaves no row.
- Existing registration-path feature/unit/concurrency suites clear `outbox_events` / `outbox_deliveries` in afterEach so tenant teardown is not blocked by the new FK rows.

Deviations: none.

Test evidence: `composer -d apps/api run lint` (Pint) passed. `composer -d apps/api run analyse` (Larastan) passed with 0 errors. `composer -d apps/api run types:generate` produced no diff (`CustomerRegisteredPayload` not emitted). Focused CustomerRegistered / RegisterCustomer tests passed 14 tests, 53 assertions. `composer -d apps/api run test` (all six suites) passed 1061 tests, 4149 assertions, 0 failures.

#### task-14: TenantCreated and DomainVerified producers (2026-07-10 05:00 -03)

Commit: `be03f66` (`feat(tenancy): TenantCreated and DomainVerified outbox producers`), plus this journal entry.

Landed plan task 14 / Slice 6 Tenancy producers: record `TenantCreated` from CreateTenant and `DomainVerified` from RegisterDomain (Stage 2 settled trigger), plus the Tenancy rows in system-design 9.3, and the Slice 6 Tenancy unit/feature suite.

What landed:

- `CreateTenant` injects `OutboxRecorder` and records `TenantCreated` after the tenant insert in the enclosing platform request transaction. Envelope `tenant_id` is the sentinel platform tenant (matches `app.tenant_id` so outbox RLS WITH CHECK passes under platform posture).
- `RegisterDomain` injects `OutboxRecorder` and records `DomainVerified` after the domain insert. Envelope carries the owning tenant. Because `outbox_events` has no platform write policy, the Action switches the open transaction to `nodia_app` with `app.tenant_id` set to the owning tenant before the record call (SET LOCAL dies with the enclosing platform request transaction).
- Both payloads carry `#[Hidden]` so they are excluded from TypeScript generation as internal contracts; `types:generate` removes the previously emitted types.
- Event class docblocks updated to reflect attached producers; `TenancyServiceProvider` already registered both type-name strings (verified).
- `docs/system-design.md` section 9.3 gains a Tenancy row (`TenantCreated`, `DomainVerified`) at the top of the catalog.
- Slice 6 tests: HTTP success for tenant create (exactly one `TenantCreated` under sentinel, correlation ID, payload shape) and domain registration (exactly one `DomainVerified` under owning tenant); validation, locale, already-registered, and second-primary failures record nothing; optional rollback coupling after each producer leaves no row. Unit field-set serialization tests for both payloads.
- Existing create/register teardown paths clear `outbox_events` / `outbox_deliveries` so tenant deletes are not blocked by FK rows; `TenantFixture::clean()` clears fixture-tenant outbox rows the same way.

Deviations: none material. The mid-transaction role/tenant switch in `RegisterDomain` is required by the combination of (a) platform-admin surface under sentinel posture, (b) owning-tenant envelope, and (c) outbox tables without platform write; it is local to the producer, not a change to outbox RLS.

Test evidence: `composer -d apps/api run lint` (Pint) passed. `composer -d apps/api run analyse` (Larastan) passed with 0 errors. `composer -d apps/api run types:generate` removed `TenantCreatedPayload` and `DomainVerifiedPayload` from the generated client. Focused TenantCreated / DomainVerified / CreateTenant / RegisterDomain tests passed 37 tests, 129 assertions. `composer -d apps/api run test` (all six suites) passed 1072 tests, 4202 assertions, 0 failures.

### Gate (2026-07-10 05:00 -03)

Local quality gates after all implementation tasks:

- `composer -d apps/api run lint` (Pint): pass after one fix (`style(support): fix TenantFixture trailing newline for Pint` `956b2ab`)
- `composer -d apps/api run analyse` (Larastan): 0 errors
- `composer -d apps/api run types:generate`: no unexpected drift
- `composer -d apps/api run test`: 1072 tests, 4202 assertions, 0 failures
- `pnpm typecheck`: all workspaces pass

HEAD at gate: `956b2ab`. Pushing branch `feat/api-implementation` and watching CI.

CI runs for push at e71d00c (all success):

| Workflow   | Run ID      | Conclusion                                                                           |
| ---------- | ----------- | ------------------------------------------------------------------------------------ |
| API        | 29078427537 | success (Pint, Larastan, Pest, Isolation, Architecture, Concurrency, Contract Drift) |
| Admin      | 29078427528 | success                                                                              |
| Checkin    | 29078427535 | success                                                                              |
| Storefront | 29078427574 | success                                                                              |
| Packages   | 29078427565 | success                                                                              |

### Review rounds (summary)

| Round | Verdict     | Actionable                                                              | Outcome            |
| ----- | ----------- | ----------------------------------------------------------------------- | ------------------ |
| 1     | needs-fixes | Redis e2e missing; OutboxDeliveryStatus leaked to TS; minor fillable id | Fixed in `5e09ee5` |
| 2     | needs-fixes | AssignRole no-op recorded UserRoleChanged                               | Fixed in `ce99fef` |
| 3     | approve     | 0                                                                       | Clean              |

CI after latest review fixes (HEAD `20ac736`):

| Workflow   | Run ID      | Conclusion |
| ---------- | ----------- | ---------- |
| API        | 29079404481 | success    |
| Admin      | 29079404523 | success    |
| Checkin    | 29079404516 | success    |
| Storefront | 29079404505 | success    |
| Packages   | 29079404482 | success    |

### Final summary (2026-07-10 05:22 -03)

Stage 4 Transactional Outbox completed end to end: tasks 2-14, local gates, CI green, three review rounds with two fix rounds, no unaddressed blocking/important findings.

#### Exit criteria

1. **Met.** Slice 1 feature/unit: rollback leaves no rows; outside-transaction throws. CI Isolation/Pest green.
2. **Met.** `OutboxRedisDeliveryTest` records then `queue:work redis --once` against real Redis + PostgreSQL; delivery processed once. (Horizon is installed; worker uses the same Redis queue drivers.)
3. **Met.** Duplicate-delivery feature test + `OutboxDeliveryContentionTest` concurrency race.
4. **Met.** Sweeper feature tests: grace from created_at, inside-window skip, processed never re-enqueued, stability gate.
5. **Met.** Ordered-consumption feature + `OrderedOutboxConsumptionContentionTest`.
6. **Met.** OutboxReplay feature: rebuild equivalence, double replay, sequence order, stability skip.
7. **Met.** OutboxEventsIsolationTest + OutboxDeliveriesIsolationTest; unscoped sweep includes failed_jobs/job_batches.
8. **Met.** UserInvited/UserRoleChanged/CustomerRegistered/TenantCreated/DomainVerified outbox feature tests; system-design 9.3 Tenancy rows added.
9. **Met.** Identity payload unit tests exclude email/name; payloads `#[Hidden]`.
10. **Met.** 1075 tests locally; CI API (Pint, Larastan, Pest, Isolation, Architecture, Concurrency, Contract Drift) green; Support/Outbox architecture constraints enforced.
11. **Met** by this close-out: status table set to Done.
