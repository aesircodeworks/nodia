# Implementation Plan: Project Foundation

**Branch**: `001-project-foundation` | **Date**: 2026-07-04 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/001-project-foundation/spec.md`

**Note**: This template is filled in by the `/speckit-plan` command. See `.specify/templates/plan-template.md` for the execution workflow.

## Summary

Assemble the runnable skeleton the roadmap's Phase 0 defines: the monorepo layout from system-design.md section 16.1, four bootstrapped applications (Laravel API, Next.js storefront, Next.js admin, static React check-in PWA), a Docker Compose stack for PostgreSQL, Redis, MinIO, and the API, CI running lint, static analysis, unit tests, and builds on every change, and the contract-generation pipeline proven end to end by a single `/v1/health` endpoint whose laravel-data shape is exported to `packages/api-client` and merged into the OpenAPI specification. No business features ship; the phase delivers the development loop and the enforcement points every later phase depends on.

## Technical Context

**Language/Version**: PHP (latest stable supported by current Laravel) for `apps/api`; TypeScript on the current Node.js LTS for all JS workspaces. Exact versions are pinned at bootstrap time by the official installers and recorded in `composer.json`, `package.json`, and lockfiles (see research.md R1).

**Primary Dependencies**: Laravel with Octane on FrankenPHP (ADR 009), laravel/passport deferred to Phase 1; spatie/laravel-data plus spatie/typescript-transformer for contracts (ADR 013); Next.js for storefront and admin, Vite static React PWA for check-in (ADRs 015, 016); pnpm workspaces for the JS side (system-design.md 16.1).

**Storage**: PostgreSQL (primary database), Redis (cache and queues), MinIO as the S3-compatible dev object store. All provisioned by Docker Compose in this phase; only connectivity is exercised, no domain schema.

**Testing**: Pest for unit and architecture tests, Larastan for static analysis, Laravel Pint for style (system-design.md 15.5, 18); ESLint plus `tsc --noEmit` and Vitest scaffolding on the JS side. Architecture, tenant isolation, and concurrency suites are wired as CI jobs now; isolation and concurrency suites gain their first real cases in Phases 1 and 3.

**Target Platform**: Linux OCI containers for the API and backing services; developer machines (macOS and Linux) run the frontends on the host against the Compose stack. Production images are Phase 7.

**Project Type**: Web monorepo: 4 applications, 2 shared packages, infra directory (system-design.md 16.1).

**Performance Goals**: None beyond spec SC-001 (clone to running stack in under 15 minutes) and a health endpoint that responds promptly; load characteristics are later-phase concerns.

**Constraints**: Constitution cross-cutting requirements apply from the first request: structured JSON logs to stdout, configuration via environment variables only, stateless processes, `X-Correlation-Id` accepted and echoed on every request (docs/api-conventions.md). No business features, no tenancy, no authentication in this phase.

**Scale/Scope**: 7 workspace roots (`apps/api`, `apps/storefront`, `apps/admin`, `apps/checkin`, `packages/api-client`, `packages/ui`, `infra`), one Compose stack, one CI system composed of per-app path-filtered workflows, and one API endpoint. `/v1/health` doubles as the API container's liveness and readiness probe in this phase; the dedicated probe split system-design.md 16.2 describes arrives with the production images in Phase 7 (research.md R6).

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design. Gates derive from `.specify/memory/constitution.md`; cite the binding convention docs (api, data, event, ui) instead of restating their rules.*

- [x] **Tenant isolation (I)**: no tenant-scoped tables are created in this phase. The framework-default migrations are brought under docs/data-conventions.md before the first migrate: UUIDv7 primary keys, no auto-increment columns, and no database queue tables since queues run on Redis (research.md R10, data-model.md). The RLS regime and the first tenant-scoped tables arrive in Phase 1 per the roadmap; nothing in this phase introduces tenant state, static or otherwise.
- [x] **Inventory invariants (II)**: no inventory code in this phase. Not applicable.
- [x] **Money (III)**: no monetary values in this phase. Not applicable.
- [x] **Events (IV)**: no domain events in this phase; the outbox lands in Phase 1 per the roadmap and docs/event-conventions.md governs it then. Not applicable.
- [x] **Context boundaries (V)**: no contexts exist yet, but this phase installs the enforcement point: Pest architecture test scaffolding runs in CI from day one so Phase 1 contexts are born under the gate.
- [x] **Contracts (VI)**: satisfied by design. The `/v1/health` response is a laravel-data object, its TypeScript type is generated into `packages/api-client`, and its contract is the first entry in the OpenAPI specification (contracts/v1-health.openapi.yaml). This proves the generation pipeline before any business endpoint exists.
- [x] **Framework-native (VII)**: the health endpoint is a plain controller returning a Data object; no repositories, no layering. Nothing in the skeleton introduces forbidden abstractions.
- [x] **Security floor (VIII)**: no card data, no exposed identifiers, no PII in this phase. The health endpoint is unauthenticated by design (spec Assumptions) and exposes dependency status only, no infrastructure details such as hostnames or versions.
- [x] **Fail fast / retry (IX)**: the health endpoint is interactive and fails fast, reporting degraded dependencies with a 503 problem document rather than hanging. No async work exists yet to need sweepers.
- [x] **Testing gates (X)**: CI wires Pint, Larastan, and Pest (including the architecture suite) as blocking jobs from this phase forward, satisfying spec FR-007/FR-008. Isolation and concurrency suites exist as harness placeholders that later phases populate; no invariant-bearing code ships in Phase 0.
- [x] **Cross-cutting**: structured JSON logs to stdout with correlation ID propagation on the API (spec FR-005, docs/api-conventions.md); frontend health displays use the i18n layer and `packages/ui` tokens per docs/ui-conventions.md; only additive framework migrations; Pint and Larastan pass as CI gates. Metrics and traces via OpenTelemetry are deferred to Phase 7 by the roadmap, which conflicts with the constitution's observability requirement; the deviation is justified in Complexity Tracking below rather than silently waived.

**Post-design re-check (after Phase 1 artifacts)**: PASS. The design artifacts introduce no tenant-scoped tables, no money, no events, and no cross-context interaction; the single contract follows docs/api-conventions.md (versioned URL, snake_case keys, and an RFC 9457 problem+json degraded response carrying the health checks as extension members). One deviation is recorded in Complexity Tracking: the OpenTelemetry metrics and traces deferral.

## Project Structure

### Documentation (this feature)

```text
specs/001-project-foundation/
├── plan.md              # This file (/speckit-plan command output)
├── research.md          # Phase 0 output (/speckit-plan command)
├── data-model.md        # Phase 1 output (/speckit-plan command)
├── quickstart.md        # Phase 1 output (/speckit-plan command)
├── contracts/           # Phase 1 output (/speckit-plan command)
│   └── v1-health.openapi.yaml
└── tasks.md             # Phase 2 output (/speckit-tasks command - NOT created by /speckit-plan)
```

### Source Code (repository root)

```text
apps/
├── api/                       # Laravel monolith (internal layout per system-design.md 3.2)
│   ├── app/
│   │   ├── Http/              # Health controller, correlation ID middleware
│   │   ├── Models/            # Framework default User only in this phase
│   │   ├── Providers/
│   │   └── Support/           # Empty in this phase; Outbox/ and Money/ arrive in Phase 1
│   ├── config/
│   ├── database/              # Framework migrations adjusted per data conventions (research.md R10)
│   ├── routes/                # /v1 route group
│   └── tests/
│       ├── Architecture/      # Pest arch test scaffolding (context boundary gate)
│       ├── Feature/           # Health endpoint tests
│       └── Unit/
├── storefront/                # Next.js buyer-facing app; health status page
├── admin/                     # Next.js organizer and platform portal; health status page
└── checkin/                   # Vite static React PWA; health status view

packages/
├── api-client/                # Generated TypeScript types and API client (never hand-written)
└── ui/                        # Design tokens and shared components; i18n-ready primitives

infra/
├── compose/                   # docker-compose.yml: postgres, redis, minio, api
└── ci/                        # Shared CI scripts if any; workflows live in .github/workflows/

.github/workflows/             # Per-app path-filtered CI pipelines
docs/                          # Existing: system design, conventions, ADRs, roadmap
```

**Structure Decision**: The monorepo layout is mandated by system-design.md section 16.1 and spec FR-001; this plan instantiates it exactly, with pnpm workspaces covering `apps/storefront`, `apps/admin`, `apps/checkin`, `packages/api-client`, and `packages/ui`, while `apps/api` manages its own Composer dependencies. CI is keyed on changed paths so each app builds and tests independently.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| Cross-cutting observability requirement partially deferred: metrics and traces via OpenTelemetry do not ship until Phase 7; Phases 0 through 6 emit structured JSON logs with correlation IDs only | The roadmap deliberately sequences the observability stack (OTel SDKs, Prometheus, Loki, Tempo, Grafana, dashboards, alerting) in Phase 7, and Phase 0 has no collector to receive exports and no business flows whose traces would be actionable | Installing the OTel SDK now with a no-op exporter was rejected: it adds bootstrap and CI surface to every early phase while producing no observable signal until the Phase 7 backends exist. Correlation IDs ship now, so end-to-end traceability of financial mutations remains achievable when tracing lands, and log structure will not need to change |
