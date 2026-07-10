# Execution Journal: Stage 5a, Catalog Core and Publish

Durable record of execution runs for [stage-05a-catalog-core.md](../stage-05a-catalog-core.md). Append a new run header per run; never rewrite prior entries.

## Run: 2026-07-10

- Stage: 5a, Catalog Core and Publish
- Date: 2026-07-10
- Branch: `feat/api-implementation`
- Base commit: `0cea08482e2b0d94ec793fbeab0f2f3952ceed66`

Verified starting state: Stages 1-4 are Done. Stage 5a is Not started. No `app/EventCatalog` directory, no `venues`, `events`, or `ticket_types` migrations, no settlement currency column or Tenancy read Action, no catalog routes or Data objects. `events.view`, `events.manage`, and `events.publish` exist in the Stage 3 `Capability` enum. `ResolveTenantFromHost` and `ResolveTenantFromHeader` middleware exist from Stage 2. The outbox recording API from Stage 4 is available for the catalog producers.

### Task checklist

- [ ] task-01: EventCatalog context skeleton, service provider, capability Policies and Gates, authorization matrix extension (plan tasks 1 and 2)
- [ ] task-02: `venues` table, model, endpoints, contract (plan task 3, slice 1)
- [ ] task-03: `EventStatus` enum, `AsyncPaymentPolicyData`, `events` table with CHECK constraints, translatable model, isolation tests (plan task 4)
- [ ] task-04: Event admin endpoints with `EventCreated` and `EventUpdated` outbox recording, contract fragment (plan task 5, slice 2)
- [ ] task-05: Tenancy settlement currency column, design doc update, read Action (plan task 6)
- [ ] task-06: `ticket_types` table, model, factory, isolation tests (plan task 7)
- [ ] task-07: Ticket type endpoints and Actions with currency validation and `EventUpdated` recording, contract fragment (plan task 8, slice 3)
- [ ] task-08: Publish and cancel: concurrency tests first, conditional-UPDATE Actions, transition endpoints, contract fragment (plan task 9, slice 4)
- [ ] task-09: Storefront read surface: host-resolved routes, locale negotiation resolver, storefront Data objects, isolation coverage, contract fragment (plan task 10, slice 5)
- [ ] task-10: Regenerate TypeScript contract types, confirm zero drift, update master plan status row (plan task 11)

### Review rounds

### Decisions and deviations
