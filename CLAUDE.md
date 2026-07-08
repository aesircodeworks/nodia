# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Nodia is a white-label, multi-tenant event ticketing platform: one Laravel API (`apps/api`), a Next.js storefront (`apps/storefront`, port 3000), a Next.js admin portal (`apps/admin`, port 3001), and a Vite React check-in PWA (`apps/checkin`, port 5173), sharing typed contracts (`packages/api-client`) and design tokens (`packages/ui`) through pnpm workspaces.

The convention docs are binding for all code: `docs/api-conventions.md`, `docs/data-conventions.md`, `docs/event-conventions.md`, `docs/ui-conventions.md`. Architecture background is in `docs/system-design.md` and the ADRs under `docs/decisions/`. `docs/roadmap.md` sequences the work and has an Implementation Status table that must be kept current as phases start and finish.

## Commands

Stack (Docker Compose runs postgres, redis, minio, and the API; frontends run on the host):

```sh
make setup   # copy .env.example files, install Composer and pnpm deps (idempotent)
make up      # start the containers
make fresh   # recreate the stack from scratch (drops volumes, rebuilds the API image)
pnpm dev     # start all three frontend dev servers; filter with pnpm --filter storefront dev
curl -s localhost:8000/v1/health   # verify the stack (200 with all checks ok)
```

Repo-wide (fan out over all workspaces plus the API via Composer):

```sh
pnpm lint         # ESLint everywhere + Pint on the API
pnpm typecheck    # tsc in every TS workspace
pnpm test         # Vitest everywhere + Pest on the API
pnpm build        # builds + Larastan on the API
pnpm format       # Prettier (format:check in CI)
```

API only (run from `apps/api`, or use `composer -d apps/api run <script>`):

```sh
composer lint             # Pint (check only)
composer analyse          # Larastan
composer test             # all Pest suites
php artisan test --testsuite=Architecture   # one suite: Feature, Architecture, Isolation, Concurrency
php artisan test --filter=SomeTest          # single test
composer types:generate   # regenerate TS contract types (see below)
```

Frontend single test: `pnpm --filter storefront exec vitest run path/to/file.test.ts` (same for `admin`, `checkin`, or a package name).

## Architecture

The API is a modular monolith with eight bounded contexts, each a directory under `app/`: Tenancy, Identity, EventCatalog, Inventory, Orders, Payments, CheckIn, Reporting. Each context owns its `Models/`, `Actions/`, `Events/`, `Http/`, `Policies/`, and `Data/` (laravel-data DTOs). Contexts communicate synchronously by calling each other's Actions and asynchronously through the transactional outbox in `app/Support/Outbox`; a context never touches another context's models or tables directly, and `tests/Architecture` enforces this.

Key mechanisms that span multiple files:

- Tenant isolation is PostgreSQL RLS. Middleware sets `app.tenant_id` per request; every tenant-scoped table has a non-null `tenant_id` and an RLS policy created in the same migration. `tests/Isolation` proves cross-tenant access fails; `tests/Concurrency` runs oversell simulations against real PostgreSQL.
- Domain events are recorded to the outbox in the same DB transaction as the state change, then delivered via Redis queues. Queue jobs carry only the event ID; consumers are idempotent by event ID and track progress in `outbox_deliveries`. Event payload evolution is additive only; a breaking change is a new event type, never a version field.
- API contract types flow one way: laravel-data objects are the source of truth, `composer types:generate` (spatie/typescript-transformer) writes to `packages/api-client/src/generated`, and the `API Contract Drift` CI check fails if the committed output differs. Never hand-edit generated files; regenerate and commit after changing a Data class.

## Conventions most likely to be violated

- Money is always integer minor units in `*_amount` columns paired with a `currency` column, and on the wire as `{amount, currency}`. Never floats, never client-side money math.
- Primary keys are UUIDv7 (`HasUuids`); no auto-increment columns except `outbox_events.sequence`.
- Wire format is snake_case JSON; errors are RFC 9457 problem documents with a stable `code` field that clients branch on.
- State transitions that guard invariants (holds, order status) use conditional UPDATEs checked by affected-row count, never read-then-write.
- Merged migrations are never edited; new tables ship their RLS policy in the same migration or the isolation suite blocks the merge.
- No user-facing string literals in components (next-intl in storefront/admin, keys like `checkout.hold_expired.title`) and no hardcoded colors, fonts, spacing, or radii; everything styles through `packages/ui` tokens (`--nodia-*` CSS custom properties). Custom ESLint rules in the root `eslint.config.mjs` enforce both.
- Dates display in the event's timezone (stored as data, timestamps stay UTC); raw IDs are never shown to end users.

## Commits

- Use Conventional Commits.
- Scope is one of the bounded contexts: `tenancy`, `identity`, `catalog`, `inventory`, `orders`, `payments`, `checkin`, `reporting`.
- Cross-cutting scopes: `support` (shared infra: outbox, money), `docs`, `ci`, `deps`.
- Omit the scope only for repo-wide changes that fit none of the above.
