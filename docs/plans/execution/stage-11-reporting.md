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

- [x] T1 `reports.view` and `reports.export` capabilities, template role seeds, authorization matrix (identity)
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

## T1: reports.view and reports.export capabilities, template role seeds

2026-07-13 01:37 -03

Landed the registry and template half of exit criterion 9 (stage-11 plan task breakdown item 2, system-design 5.3). No endpoint, no new Data class, no OpenAPI path: this task only touches the capability enum and the global template seeds, so the double-loop's contract step doesn't apply (nothing crosses the wire yet); the endpoint slices (tasks 6, 9, 12) each carry their own contract step when they land.

What landed:

- `App\Identity\Capability`: added `ReportsView = 'reports.view'` and `ReportsExport = 'reports.export'`, additive at the end of the enum per the class's own contract.
- MFA financial-privilege decision (explicit, per the task instructions): both new capabilities are marked financially privileged in `isFinanciallyPrivileged()`, extending the `ledger.view` precedent rather than the `orders.view` one. Reasoning: `report_event_finance` (stage-11 plan, Data model) mirrors the ledger's own per-event gross, gateway fee, platform commission, and tenant net totals (system-design 7.3), the same class of settlement data that made `ledger.view` privileged in Stage 8's `62819a6`; `reports.export`'s `ledger_entries` source (task 15, not yet built) will export those same ledger rows as a downloadable file, and its `orders`/`tickets` sources carry customer PII, so it is at least as sensitive. Consequence noted, not fought: Event Manager, which only gains `reports.view`, now requires confirmed MFA under `MfaEnforcementPolicy` (already-shipped Stage 3 machinery, no code change needed here), a natural extension of "financially privileged" that the stage-11 plan doesn't call out but doesn't contradict either.
- `App\Identity\Actions\SeedTemplateRoles::templates()`: Owner and Finance gain both capabilities, Event Manager gains `reports.view` only; Box Office and Check-in Agent gain neither.
- `tests/Feature/Identity/AuthorizationMatrixTest.php` needed no edit: it is fully data-driven off `Capability::cases()` and `SeedTemplateRoles::templates()`, so it already covers the two new capabilities across every template plus the custom-role and cross-tenant legs, and it unconditionally pre-confirms MFA for the probe user, so the new financial-privilege flag doesn't interact with that suite.

Test evidence:

- Wrote the two registry assertions in `tests/Unit/Identity/CapabilityTest.php` (exact registry list, exact financially-privileged subset) and two new assertions in `tests/Unit/Identity/SeedTemplateRolesTest.php` (Owner/Finance get both, Event Manager gets only `reports.view`, Box Office/Check-in Agent get neither) first; confirmed red (`Undefined constant App\Identity\Capability::ReportsView` and the two array-diff failures) before implementing.
- Green after implementation: `./vendor/bin/pest tests/Unit/Identity/CapabilityTest.php tests/Unit/Identity/SeedTemplateRolesTest.php` — 21 tests, 21 passed, 32 assertions.
- Scoped verification per the task: `./vendor/bin/pest tests/Feature/Identity tests/Unit/Identity` — 458 tests, 458 passed, 1965 assertions.
- `composer -d apps/api run types:generate`: the `Capability` union type is spatie/typescript-transformer output (not a laravel-data class, but still generated), so `packages/api-client/src/generated/index.ts` and `typescript-transformer-manifest.json` picked up `'reports.view' | 'reports.export'` and are committed with no further drift.

Deviation from the plan: none in scope or approach. The one judgment call (marking both capabilities financially privileged) was flagged as an explicit decision point by the task itself and is recorded above rather than being a deviation.

Not run in this task (out of scope per the task's own instruction to skip full lint/analyse/whole-suite per slice; the gate phase after all tasks covers these): Pint, Larastan, the full six-suite run.

Commits: 05599c2 (feat(identity): add reports.view and reports.export capabilities).
