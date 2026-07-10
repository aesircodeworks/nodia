# Execution Journal: Stage 4, Transactional Outbox

Durable record of execution runs for [stage-04-outbox.md](../stage-04-outbox.md). Append a new run header per run; never rewrite prior entries.

## Run: 2026-07-10

- Stage: 4, Transactional Outbox
- Date: 2026-07-10
- Branch: `feat/api-implementation`
- Base commit: `83b3dca293cc9eb39d737e10fde2c2564b249978`

Verified starting state: Stages 1-3 are Done. Stage 4 is Not started. No `app/Support/Outbox` directory, no outbox migrations, no outbox tests. Identity Actions (`InviteUser`, `AssignRole`, `RegisterCustomer`) carry Stage 4 attachment-point comments only. Tenancy event classes (`TenantCreated`, `DomainVerified` and payloads) exist from Stage 2 without producers. Horizon is not installed. Correlation middleware sets the header and log context but has no request-scoped container binding for the recorder.

### Task checklist

- [ ] task-02: Correlation ID container binding (plan task 2)
- [ ] task-03: `outbox_events` migration, model, isolation tests (plan task 3)
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
