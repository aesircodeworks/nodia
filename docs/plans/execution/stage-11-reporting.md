# Stage 11 Execution Journal: Reporting and Exports

## Run: 2026-07-13

- Stage: 11 (docs/plans/stage-11-reporting.md)
- Date: 2026-07-13
- Branch: feat/api-implementation
- Base commit: 3d3d29ea0ee8a440beeed09521b15b2a8c15af84

### Pre-run verification

Nothing from this stage has landed. Verified against the codebase, not the docs:

- No `app/Reporting` directory: the eight context directories under `app/` are CheckIn, EventCatalog, Identity, Inventory, Orders, Payments, Tenancy, plus shared Support; there is no Reporting context, service provider, route group, or policy.
- No `report_daily_sales`, `report_event_finance`, `report_event_attendance`, or `exports` migration; the last migration is `2026_07_12_000055_add_counted_quantity_to_hold_items_table.php`.
- No `reports.view` or `reports.export` in `app/Identity/Capability.php` (registry ends at `checkin.manage`), so `SeedTemplateRoles` grants neither.
- No `reporting:rebuild` command in `app/Console/Commands`; the only outbox tooling is `ReplayOutboxCommand` and `SweepOutboxCommand`.
- No export lifecycle: no `exports` model, `ExportSource`, CSV writer, or `/v1/exports` routes.
- No reporting tests in any suite (Isolation, Feature, Contract, Concurrency, Architecture, Unit).

Dependencies are in place: Stage 4 (`SubscriberRegistry`, `OutboxReplay` with the stability window, `ProcessOutboxDelivery` idempotence), Stage 7 (`TicketIssued`, `TicketRefunded` with its Stage 8b producer `MarkTicketsRefunded`), Stage 8a and 8b (`PaymentConfirmed`, `RefundCompleted`, `payments.fee_amount` and `commission_amount`, `refunds.commission_policy`, the `ProjectLedgerEntries` precedent for an outbox projection), and Stage 9 (`TicketCheckedIn`, `DuplicateScanDetected`) are all merged, so no slice is gated.

### Task checklist

- [ ] T1 `reports.view` and `reports.export` capabilities, template role seeds, authorization matrix (identity)
- [ ] T2 Reporting context scaffolding plus `report_daily_sales` migration, model, and isolation tests (reporting)
- [ ] T3 `ProjectDailySales` projector and the Orders bulk ticket lookup Action (reporting, orders)
- [ ] T4 GET `/v1/reports/daily-sales` with Data classes, policy, OpenAPI, TypeScript (reporting)
- [ ] T5 `report_event_finance` migration plus `ProjectEventFinance` and the Payments row-facts Action (reporting, payments)
- [ ] T6 GET `/v1/reports/event-finance` (reporting)
- [ ] T7 `report_event_attendance` migration plus `ProjectEventAttendance` (reporting)
- [ ] T8 GET `/v1/reports/attendance` (reporting)
- [ ] T9 `reporting:rebuild` command with `--tenant`, `--verify`, and the advisory lock shared with the projectors (reporting)
- [ ] T10 `exports` table, model, enums, and the conditional claim transition (reporting)
- [ ] T11 `ExportSource` registry, streaming CSV writer, `BuildExport` action and job, and the four sources (reporting)
- [ ] T12 Export endpoints: create, list, show, download with expiring URL and the new problem codes (reporting)

### Review rounds

### Decisions and deviations
