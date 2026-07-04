# Data Conventions

Rules every migration and model must follow. The conceptual data model lives in [system-design.md](system-design.md) section 8; per-feature schemas live in each feature's data-model.md and migrations.

## Tables and Keys

- Standard Eloquent conventions: snake_case plural table names, conventional foreign key names (`event_id`), `created_at` and `updated_at` timestamps.
- Primary key is `id`, UUIDv7, generated via the `HasUuids` trait (UUIDv7 is its default; ADR [005](decisions/005-uuidv7-identifiers.md)). No auto-increment columns, including internal ones.
- Timestamps are stored in UTC. Event-level timezones are data (`events.timezone`), never encoded into stored timestamps.
- Indexes and constraints use Laravel's default generated names; custom names only when the builder cannot express the index (partial or expression indexes), formatted `{table}_{purpose}_idx`.

## Tenancy

- Every tenant-scoped table has a non-null `tenant_id`, including tables where it is derivable through joins (system-design.md section 4.2).
- Every tenant-scoped table ships a single-table RLS policy comparing `tenant_id` to `current_setting('app.tenant_id')` in the same migration that creates the table (ADR [003](decisions/003-postgres-rls-for-tenant-isolation.md)). A table without its policy does not merge; the isolation test suite enforces this.
- Platform-scope rows in shared tables (activity log) use the sentinel platform tenant, never NULL.
- Published migrations from third-party packages (activitylog, medialibrary) are adjusted for UUID keys and non-null `tenant_id` before use (ADR [014](decisions/014-spatie-utility-packages.md)).

## Money

- Monetary values are integer minor units in `*_amount` columns, each paired with a `currency` code on the same row. No floats, no decimals, no amount column without a currency.
- Ledger entries are append-only: no UPDATE or DELETE on `ledger_entries` (system-design.md section 7.3). Corrections are new entries.

## Status Columns and State Transitions

- Status columns are strings backed by PHP enums; the enum is the authoritative list of states.
- State transitions that guard an invariant (inventory counters, seat status, order status, promo code usage) are conditional UPDATEs where the guard is evaluated in the same statement that mutates the row, checked by affected-row count (system-design.md section 6.1). Read-then-write transitions are forbidden on these paths.

## Translations and Attachments

- Attendee-facing translated content uses locale-keyed JSON columns via laravel-translatable; no translation tables (ADR [014](decisions/014-spatie-utility-packages.md)).
- File attachments go through medialibrary's `media` table; no bespoke path columns.

## Migrations

- Migrations are additive once a feature merges: new migrations for changes, no editing of merged ones.
- Destructive operations (dropping columns or tables) require a deprecation window: code stops reading first, a later migration drops.
- Every migration must run inside the RLS regime; migrations never disable policies to pass.
