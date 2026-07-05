# Research: Project Foundation

Decisions resolving every open point in the plan's Technical Context. Choices already fixed by the constitution, system design, or ADRs are cited, not re-litigated.

## R1: Runtime and Framework Versions

- **Decision**: Do not pin framework versions in planning documents. Bootstrap each app with the official installer (`laravel new` via the Laravel installer, `create-next-app`, `create-vite`) at implementation time, taking the latest stable release each installer resolves. The resulting `composer.json`, `package.json`, and lockfiles become the authoritative version record. Node.js uses the current LTS line, pinned in `.nvmrc` and the CI workflow; PHP uses the newest stable version the chosen Laravel release supports, pinned in `composer.json` `require.php` and the FrankenPHP base image tag.
- **Rationale**: The binding documents (constitution, system design, ADRs) name technologies but no versions, deliberately. Pinning versions in a spec artifact months before implementation guarantees staleness; installers plus lockfiles give reproducibility without a second source of truth.
- **Alternatives considered**: Pinning exact versions in this document (rejected: rots immediately, duplicates lockfiles); tracking pre-release versions for newer features (rejected: foundation phase wants boring stability).

## R2: JS Monorepo Tooling

- **Decision**: Plain pnpm workspaces, no build orchestrator (no Turborepo, no Nx). Workspace roots: `apps/storefront`, `apps/admin`, `apps/checkin`, `packages/api-client`, `packages/ui`. Per-app scripts (`dev`, `build`, `lint`, `test`, `typecheck`) are invoked directly or via `pnpm --filter`.
- **Rationale**: System-design.md 16.1 names pnpm workspaces and per-app CI keyed on changed paths. With three apps and two packages, an orchestrator adds configuration surface without solving a problem that exists yet; CI path filtering already provides selective builds.
- **Alternatives considered**: Turborepo (rejected: caching benefits are marginal at this scale and it adds a config layer the system design does not call for); Nx (rejected: same, heavier).

## R3: CI Platform and Pipeline Shape

- **Decision**: GitHub Actions. One workflow per app plus one for the shared packages, each triggered by path filters on its workspace directory (plus shared-package paths for the apps that consume them). API workflow runs Pint (check mode), Larastan, and Pest against real PostgreSQL and Redis services; each JS workflow runs ESLint, `tsc --noEmit`, Vitest, and a production build. Branch protection marks all jobs required, satisfying spec FR-008.
- **Rationale**: The repository already uses Git with GitHub conventions (gh-based flow), and the system design requires per-app CI keyed on changed paths, which Actions path filters provide natively. Running Pest against real services from day one is required anyway by Principle X for later concurrency suites; establishing the service-container pattern now avoids a migration later.
- **Alternatives considered**: GitLab CI, CircleCI (rejected: no counterweight to the hosting platform's native CI); a single monolithic workflow (rejected: violates the changed-paths requirement and slows every change).

## R4: Local Development Orchestration

- **Decision**: One Compose file in `infra/compose/` provisioning PostgreSQL, Redis, MinIO, and the API (FrankenPHP/Octane container built from `apps/api`). Frontends run on the host via `pnpm dev` against the Compose-hosted API. A `Makefile` (or equivalent task runner file) at the repo root wraps the documented commands: `make up`, `make down`, `make fresh`, matching spec FR-003 and FR-011.
- **Rationale**: The roadmap names exactly this Compose scope for Phase 0. Frontends stay on the host because Next.js and Vite dev servers with HMR are markedly faster outside containers and need no backing services of their own; the API runs containerized because it is the deployment artifact and exercises the FrankenPHP image early (ADR 009, ADR 017).
- **Alternatives considered**: Laravel Sail (rejected: generates its own Compose conventions that would be rewritten to fit `infra/`); containerizing the frontends too (rejected: slower feedback loop, no fidelity gain in this phase); Herd or host-native PHP (rejected: diverges from the container-first ADR 017).

## R5: Check-in App Bootstrap

- **Decision**: Vite React app with TypeScript, PWA capabilities added via the Vite PWA plugin (service worker registration scaffolded, offline behavior deferred to Phase 5). Fully static output, no SSR.
- **Rationale**: ADR 015 and system-design.md 15.1 define the check-in app as a fully static, service-worker-first React PWA distinct from the Next.js apps. Vite is the standard tool for static React apps and its PWA plugin covers the manifest and service worker lifecycle.
- **Alternatives considered**: Next.js static export (rejected: carries SSR machinery the app will never use and blurs the ADR 015 separation); CRA-lineage tooling (rejected: unmaintained).

## R6: Health Endpoint Semantics

- **Decision**: `GET /v1/health`, unauthenticated. The healthy response is a laravel-data object with `status` (`ok`), a `checks` map with one entry per dependency (`database`, `redis`, `storage`, each `ok` or `failed`), and `checked_at` (ISO 8601 UTC), returned with HTTP 200. Any failed dependency returns HTTP 503 as an RFC 9457 problem document (`application/problem+json`, per docs/api-conventions.md) with the stable `code` `health.degraded` and the `checks` map plus `checked_at` carried as extension members, so operators still see exactly which dependency failed (spec edge case). Each dependency check uses a short timeout so the endpoint fails fast rather than hanging (Principle IX). Neither response exposes versions, hostnames, or connection details (Principle VIII posture).
- **Rationale**: Spec FR-004 requires per-dependency status; docs/api-conventions.md fixes the URL versioning, snake_case keys, and UTC timestamps. Returning 503 on degradation makes the endpoint directly usable as a container health check later (system-design.md 16.2 liveness/readiness) without a second endpoint shape.
- **Alternatives considered**: Laravel's built-in `/up` endpoint alone (rejected: unversioned, no dependency detail, bypasses the contract pipeline the phase must prove); returning the plain health report body on 503 (rejected: docs/api-conventions.md requires problem+json for all error responses, and RFC 9457 extension members preserve the diagnostic content); separate liveness and readiness endpoints now (rejected: Phase 7 concern per system-design.md 16.2, additive later; until then `/v1/health` serves both roles for the single API process).

## R7: Structured Logging and Correlation

- **Decision**: Configure Laravel's logger with a JSON formatter writing to stdout as the default channel. Add a correlation ID middleware that accepts `X-Correlation-Id`, generates a UUIDv7 when absent, echoes the header on the response, and pushes the ID into the log context for the request lifetime.
- **Rationale**: Constitution cross-cutting requirements and docs/api-conventions.md require both behaviors on every request; the health endpoint is the first request surface, so the middleware ships with it. Full OpenTelemetry export is explicitly Phase 7 in the roadmap.
- **Alternatives considered**: Deferring correlation IDs to Phase 1 (rejected: the convention doc says every request, and retrofitting middleware is costlier than including it in the bootstrap); adopting the OTel SDK now (rejected: roadmap sequences observability tooling in Phase 7).

## R8: Contract Generation Pipeline

- **Decision**: Install spatie/laravel-data and spatie/typescript-transformer in the API from the start. The health response Data object is annotated for TypeScript export; a Composer script (`composer types:generate`) emits types into `packages/api-client/src/generated/`, and `packages/api-client` wraps them with a thin typed fetch client. CI fails if regeneration produces a diff against the committed output (drift gate). The OpenAPI specification lives at `docs/openapi/openapi.yaml`, seeded with the health contract from this feature's `contracts/v1-health.openapi.yaml`.
- **Rationale**: Principle VI requires generated types and a merged OpenAPI contract for every endpoint, with no exception for the first one. Proving the pipeline on the trivial endpoint is the cheapest possible rehearsal before Phase 1's real contracts.
- **Alternatives considered**: Hand-writing the one health type and adding generation in Phase 1 (rejected: directly violates Principle VI and defers the risk the phase exists to retire); generating the OpenAPI spec fully automatically (deferred: tooling choice can be revisited when contract volume grows, per constitution scope note).

## R9: Frontend QA Toolchain

- **Decision**: ESLint (flat config) plus Prettier shared from the repo root, TypeScript strict mode in every workspace, Vitest scaffolded in each JS workspace with at least the health-display component under test. `packages/ui` starts with the token definitions and a minimal set of primitives; each app's health status display consumes tokens and the i18n layer (next-intl for the Next.js apps, a matching lightweight catalog for the Vite app) so docs/ui-conventions.md holds from the first screen.
- **Rationale**: Spec FR-007 requires lint, static analysis, tests, and builds across all applications; docs/ui-conventions.md forbids string literals and hardcoded styling even for the health display. Establishing the i18n and token path on a one-string screen costs minutes now and prevents the "we'll wire i18n later" debt the constitution rules out.
- **Alternatives considered**: Biome as a single lint/format tool (rejected: ecosystem plugins for Next.js and testing-library conventions are stronger on ESLint today, and the choice is revisitable per workspace later without contract impact); skipping frontend tests in Phase 0 (rejected: FR-007 names unit tests for all applications).

## R10: Framework-Default Schema Under the Data Conventions

- **Decision**: Adjust Laravel's framework-default migrations at bootstrap, before the first migrate: the `users` table and its related token tables get UUIDv7 primary keys via the `HasUuids` trait, and the database queue tables (`jobs`, `job_batches`, `failed_jobs`) are removed from the migration set because queues run on Redis through Horizon (system-design.md 15.3). No auto-increment column exists anywhere in the Phase 0 schema. Framework or package tables a later phase needs are introduced then, in convention-compliant form, consistent with docs/data-conventions.md's existing rule that published package migrations are adjusted before use.
- **Rationale**: docs/data-conventions.md requires UUIDv7 primary keys and forbids auto-increment columns including internal ones, with `outbox_events.sequence` as the sole exception. Shipping default bigint keys even briefly would put the repository's first migrations in violation of a binding convention, and the additive-only migration rule makes fixing merged migrations costlier than adjusting them before they merge.
- **Alternatives considered**: Keeping the framework defaults until Phase 1 identity work (rejected: violates the convention from the first migration and forces a conversion migration against schema that has already merged); keeping the database queue tables with UUID conversion applied (rejected: dead tables, since the queue connection is Redis; failed-job storage is decided when Horizon lands in Phase 4).
