# Tasks: Project Foundation

**Input**: Design documents from `/specs/001-project-foundation/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/v1-health.openapi.yaml, quickstart.md

**Tests**: The spec's FR-007 requires unit tests for every application and FR-012 requires the constitution's mandatory suites as wired CI jobs, so test tasks below are in scope. No invariant-bearing code ships in this phase, so no test-first ordering is mandated.

**Organization**: Tasks are grouped by user story so each story is independently implementable and testable.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: Which user story this task belongs to (US1, US2, US3)

## Path Conventions

Monorepo per plan.md: `apps/api` (Laravel), `apps/storefront` and `apps/admin` (Next.js), `apps/checkin` (Vite React PWA), `packages/api-client`, `packages/ui`, `infra/compose`, `.github/workflows/`.

---

## Phase 1: Setup (Monorepo and App Bootstrap)

**Purpose**: Instantiate the monorepo layout from system-design.md 16.1 and bootstrap all four applications and both shared packages with their QA toolchains.

- [x] T001 Create the monorepo skeleton at the repository root: `pnpm-workspace.yaml` covering `apps/storefront`, `apps/admin`, `apps/checkin`, `packages/api-client`, `packages/ui`; root `package.json` with workspace-wide scripts; `.nvmrc` pinned to the current Node.js LTS; root `.gitignore`; directories `apps/`, `packages/`, `infra/compose/`, `.github/workflows/`
- [x] T002 [P] Bootstrap the Laravel API in `apps/api` with the Laravel installer at the latest stable release, install Octane with FrankenPHP (ADR 009), and pin the PHP version in `apps/api/composer.json` `require.php` (research.md R1)
- [x] T003 [P] Bootstrap the storefront with `create-next-app` (TypeScript, App Router) in `apps/storefront` with strict mode in `apps/storefront/tsconfig.json`
- [x] T004 [P] Bootstrap the admin portal with `create-next-app` (TypeScript, App Router) in `apps/admin` with strict mode in `apps/admin/tsconfig.json`
- [x] T005 [P] Bootstrap the check-in app with `create-vite` (React, TypeScript) in `apps/checkin` and add the Vite PWA plugin with manifest and service worker registration scaffolded, offline behavior deferred (research.md R5)
- [x] T006 [P] Scaffold `packages/api-client`: `packages/api-client/package.json`, strict `packages/api-client/tsconfig.json`, `packages/api-client/src/index.ts`, and `packages/api-client/src/generated/` reserved for generated output only (research.md R8)
- [x] T007 [P] Scaffold `packages/ui`: `packages/ui/package.json`, strict `packages/ui/tsconfig.json`, `packages/ui/src/index.ts` entry point
- [x] T008 Configure the shared frontend QA toolchain from the repo root: ESLint flat config in `eslint.config.mjs`, Prettier config, Vitest scaffolding in every JS workspace, and `dev`, `build`, `lint`, `typecheck`, `test` scripts in each workspace `package.json` (research.md R2, R9)
- [x] T009 Install and configure the API QA toolchain in `apps/api`: Laravel Pint with `apps/api/pint.json`, Larastan with `apps/api/phpstan.neon`, Pest with `apps/api/tests/Architecture/`, `apps/api/tests/Feature/`, and `apps/api/tests/Unit/` directories

**Checkpoint**: All seven workspaces exist, install cleanly, and their local lint/test/build commands run.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Cross-cutting infrastructure every user story depends on: convention-compliant schema, structured logging, correlation IDs, the contract generation pipeline, the Compose stack, and the documented command surface.

**CRITICAL**: No user story work can begin until this phase is complete.

- [x] T010 Adjust the framework-default migrations in `apps/api/database/migrations/` before the first migrate: UUIDv7 primary keys via the `HasUuids` trait on the `users` table and its related token tables (update `apps/api/app/Models/User.php` accordingly), and remove the `jobs`, `job_batches`, and `failed_jobs` queue table migrations since queues run on Redis (research.md R10, data-model.md)
- [x] T011 [P] Configure structured JSON logging to stdout as the default channel in `apps/api/config/logging.php` (spec FR-005, docs/api-conventions.md)
- [x] T012 [P] Implement correlation ID middleware in `apps/api/app/Http/Middleware/CorrelationId.php`: accept `X-Correlation-Id`, generate a UUIDv7 when absent, echo the header on the response, push the ID into the log context for the request lifetime; register it globally in `apps/api/bootstrap/app.php` (research.md R7)
- [x] T013 [P] Install spatie/laravel-data and spatie/typescript-transformer in `apps/api`, configure the transformer to emit into `packages/api-client/src/generated/`, and add the `composer types:generate` script to `apps/api/composer.json` (research.md R8)
- [x] T014 [P] Create the Compose stack in `infra/compose/docker-compose.yml` provisioning PostgreSQL, Redis, MinIO, and the API service built from a FrankenPHP/Octane `apps/api/Dockerfile`, with container healthchecks and environment wiring (research.md R4)
- [x] T015 [P] Create the root `Makefile` with `setup`, `up`, `down`, and `fresh` targets wrapping the documented commands, plus root `.env.example` and per-app `.env.example` files copied by `make setup` (research.md R4, quickstart.md)

**Checkpoint**: `make setup && make up` brings up PostgreSQL, Redis, MinIO, and the API container; every API request logs one structured JSON line with a correlation ID.

---

## Phase 3: User Story 1 - Developer Runs the Full Stack Locally (Priority: P1) MVP

**Goal**: A developer clones the repository, follows the documented steps, and sees all four applications running with each frontend visibly reporting a healthy backend via `/v1/health`.

**Independent Test**: On a clean machine with prerequisites installed, run the documented startup steps and confirm each application loads and displays a healthy backend status; stop a backing service and confirm the health endpoint names the failed dependency.

### Implementation for User Story 1

- [x] T016 [P] [US1] Create the health response laravel-data objects in `apps/api/app/Http/Data/HealthReportData.php` (fields `status`, `checks` with `database`/`redis`/`storage` keys, `checked_at` ISO 8601 UTC), annotated for TypeScript export (data-model.md, contracts/v1-health.openapi.yaml)
- [x] T017 [US1] Implement the health controller in `apps/api/app/Http/Controllers/HealthController.php`: check database, Redis, and storage connectivity each with a short timeout so the endpoint fails fast; return 200 with the HealthReport when all pass; return 503 as an RFC 9457 `application/problem+json` document with stable `code` `health.degraded` and the `checks` map plus `checked_at` as extension members when any fail; expose no hostnames, versions, or connection details (research.md R6)
- [x] T018 [US1] Register `GET /v1/health` in `apps/api/routes/api.php` inside a `/v1` route group configured so the path is exactly `/v1/health` with no `/api` prefix (spec FR-004, docs/api-conventions.md)
- [x] T019 [US1] Write Pest feature tests in `apps/api/tests/Feature/HealthEndpointTest.php`: 200 body matches the contract shape, 503 problem document with `code` `health.degraded` and failed check named when a dependency is down, `X-Correlation-Id` echoed when sent and generated when absent
- [x] T020 [US1] Run `composer types:generate`, commit the generated types in `packages/api-client/src/generated/`, and implement the typed `getHealth()` fetch client in `packages/api-client/src/index.ts` with a Vitest test in `packages/api-client/src/index.test.ts` (research.md R8)
- [x] T021 [US1] Define design tokens and the minimal health-display primitives (status badge) in `packages/ui/src/` with a Vitest test, i18n-ready per docs/ui-conventions.md (research.md R9)
- [x] T022 [P] [US1] Build the storefront health status display in `apps/storefront`: wire next-intl with a single default locale, add a health status component consuming `packages/api-client` and `packages/ui` tokens (no hardcoded strings or colors), with a Vitest component test (spec FR-006)
- [x] T023 [P] [US1] Build the admin portal health status display in `apps/admin`: same next-intl wiring, health status component consuming `packages/api-client` and `packages/ui`, with a Vitest component test (spec FR-006)
- [x] T024 [P] [US1] Build the check-in app health status view in `apps/checkin`: lightweight i18n message catalog matching the ui conventions, health status view consuming `packages/api-client` and `packages/ui`, with a Vitest component test (spec FR-006, research.md R9)
- [x] T025 [P] [US1] Seed the repository OpenAPI specification at `docs/openapi/openapi.yaml` with the `/v1/health` contract merged from `specs/001-project-foundation/contracts/v1-health.openapi.yaml` (research.md R8)
- [x] T026 [US1] Write the root `README.md` developer setup documentation: prerequisites list, setup and startup commands, default ports and how to change them, how to verify the stack is healthy, and links to the four convention docs (spec FR-011, edge cases for occupied ports and missing prerequisites)
- [x] T027 [US1] Validate quickstart.md scenarios 1 and 2: `make up` to healthy stack, all three frontends display healthy status, curl of `/v1/health` returns 200 with structured JSON logs and correlation ID echo, stopping Redis yields a fast 503 problem document naming `checks.redis: failed`, restart returns 200

**Checkpoint**: User Story 1 is fully functional: clone, documented steps, running stack, visible healthy status in all three frontends.

---

## Phase 4: User Story 2 - Every Change Is Automatically Validated (Priority: P2)

**Goal**: Every push triggers path-filtered pipelines running lint, static analysis, unit tests, and builds for each affected application, with failures blocking merge.

**Independent Test**: Push one passing change and one deliberately failing change; confirm the pipeline reports success and failure respectively, and that a change touching only one app skips the others' jobs.

### Implementation for User Story 2

- [x] T028 [P] [US2] Create the API workflow in `.github/workflows/api.yml`: path filters on `apps/api/**`, jobs for Pint in check mode, Larastan, and Pest running against real PostgreSQL and Redis service containers (research.md R3)
- [x] T029 [P] [US2] Create the storefront workflow in `.github/workflows/storefront.yml`: path filters on `apps/storefront/**` and `packages/**`, jobs for ESLint, `tsc --noEmit`, Vitest, and a production `next build` (research.md R3)
- [x] T030 [P] [US2] Create the admin workflow in `.github/workflows/admin.yml`: path filters on `apps/admin/**` and `packages/**`, jobs for ESLint, `tsc --noEmit`, Vitest, and a production `next build` (research.md R3)
- [x] T031 [P] [US2] Create the check-in workflow in `.github/workflows/checkin.yml`: path filters on `apps/checkin/**` and `packages/**`, jobs for ESLint, `tsc --noEmit`, Vitest, and a production `vite build` (research.md R3)
- [x] T032 [P] [US2] Create the shared packages workflow in `.github/workflows/packages.yml`: path filters on `packages/**`, jobs for ESLint, `tsc --noEmit`, Vitest, and builds for `packages/api-client` and `packages/ui` (research.md R3)
- [x] T033 [US2] Add the contract drift gate to `.github/workflows/api.yml`: run `composer types:generate` and fail the job if it produces a diff against the committed `packages/api-client/src/generated/` output (research.md R8, constitution Principle VI)
- [ ] T034 [US2] Configure branch protection on `main` marking every workflow job required so a failing check blocks merge (via `gh api` or repository settings), and document the required-checks list in `README.md` (spec FR-008)
- [ ] T035 [US2] Validate quickstart.md scenario 3: a trivial passing change runs green on the PR; a deliberate Pint violation, Larastan error, and failing test each fail their job and block merge; a storefront-only change skips API jobs via path filters

**Checkpoint**: User Stories 1 and 2 both work: the stack runs locally and every change is gated by CI.

---

## Phase 5: User Story 3 - Shared Conventions Exist as Enforceable Defaults (Priority: P3)

**Goal**: The binding convention documents are discoverable, and every mechanically checkable convention fails the pipeline when violated, including the constitution's mandatory suite harnesses.

**Independent Test**: Confirm each convention document exists at its constitution-named location and is linked from the README, and that a deliberate convention violation (style, static analysis, hardcoded UI string or color) fails the pipeline.

### Implementation for User Story 3

- [ ] T036 [US3] Write the first Pest architecture test in `apps/api/tests/Architecture/ContextBoundariesTest.php`, green on the empty skeleton and ready to fail on the first cross-context import, and wire the architecture suite as a distinct required job in `.github/workflows/api.yml` (spec FR-012, constitution Principle V)
- [ ] T037 [P] [US3] Wire the tenant isolation and concurrency suite harnesses as Pest suites in `apps/api/tests/Isolation/` and `apps/api/tests/Concurrency/` with distinct CI jobs in `.github/workflows/api.yml` that pass while empty and fail the pipeline once they contain failing cases (spec FR-012; populated by Phases 1 and 3 of the roadmap)
- [ ] T038 [P] [US3] Add ESLint rules to `eslint.config.mjs` enforcing the mechanically checkable ui conventions in app code: no hardcoded user-facing string literals in JSX (i18n layer required) and no hardcoded color values (tokens required), failing lint on violation (spec FR-010, docs/ui-conventions.md, quickstart.md scenario 5)
- [ ] T039 [US3] Verify the four convention documents exist at the constitution-named locations (`docs/api-conventions.md`, `docs/data-conventions.md`, `docs/event-conventions.md`, `docs/ui-conventions.md`) and are linked as binding from `README.md` (spec FR-009, SC-005)
- [ ] T040 [US3] Validate quickstart.md scenario 5: all four convention docs discoverable from the README, architecture suite green as a required job, a hardcoded string literal or color in a frontend health display fails lint

**Checkpoint**: All three user stories are independently functional.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: End-to-end validation of the phase exit criteria.

- [ ] T041 Run the full quickstart.md validation end to end on a fresh clone, timing clone-to-running-stack to confirm it lands under 15 minutes with only the documented steps (spec SC-001, SC-002)
- [ ] T042 Cleanup pass across all workspaces: remove bootstrap leftovers and example code, confirm `.env.example` files cover every required variable, confirm workspace scripts are consistent, and confirm no auto-increment column exists anywhere in the schema (research.md R10)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: T001 first; T002 through T007 then run in parallel; T008 needs the JS workspaces (T003 through T007); T009 needs the API (T002)
- **Foundational (Phase 2)**: depends on Phase 1; blocks all user stories. T010 through T015 are mutually parallelizable except T010 precedes any migrate run inside T014's stack bring-up verification
- **User Story 1 (Phase 3)**: depends on Phase 2
- **User Story 2 (Phase 4)**: depends on Phase 1 (apps must exist to build); T033 depends on T013 and T020; full validation (T035) is most meaningful after US1 exists, but the workflows themselves are independent of US1
- **User Story 3 (Phase 5)**: T036 and T037 depend on T028 (the API workflow they extend); T038 depends on T008; T039 depends on T026 (README exists)
- **Polish (Phase 6)**: depends on all three user stories

### User Story Dependencies

- **US1 (P1)**: no dependency on other stories
- **US2 (P2)**: no dependency on US1 for the workflow files; the drift gate (T033) needs the generated types from T020
- **US3 (P3)**: extends the US2 API workflow (T036, T037) and the US1 README (T039); the lint rules (T038) stand alone

### Within User Story 1

- T016 (Data objects) before T017 (controller); T017 before T018 (route) and T019 (tests)
- T020 (generated client) after T016; T021 (ui tokens) independent
- T022, T023, T024 (frontend displays) after T020 and T021, then parallel with each other
- T025 and T026 anytime within the phase; T027 last

### Parallel Opportunities

- Phase 1: T002, T003, T004, T005, T006, T007 all in parallel after T001
- Phase 2: T011, T012, T013, T014, T015 in parallel after T010 starts (different files)
- Phase 3: T022, T023, T024, T025 in parallel once T020 and T021 land
- Phase 4: T028, T029, T030, T031, T032 all in parallel (five separate workflow files)
- Phase 5: T037 and T038 in parallel after T036

---

## Parallel Example: User Story 2

```bash
# All five workflow files are independent; launch together:
Task: "Create the API workflow in .github/workflows/api.yml"
Task: "Create the storefront workflow in .github/workflows/storefront.yml"
Task: "Create the admin workflow in .github/workflows/admin.yml"
Task: "Create the check-in workflow in .github/workflows/checkin.yml"
Task: "Create the shared packages workflow in .github/workflows/packages.yml"
```

## Parallel Example: User Story 1

```bash
# Once the api-client (T020) and ui tokens (T021) exist:
Task: "Build the storefront health status display in apps/storefront"
Task: "Build the admin portal health status display in apps/admin"
Task: "Build the check-in app health status view in apps/checkin"
Task: "Seed the repository OpenAPI specification at docs/openapi/openapi.yaml"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1: Setup
2. Complete Phase 2: Foundational (blocks all stories)
3. Complete Phase 3: User Story 1
4. STOP and VALIDATE: quickstart scenarios 1 and 2 on a clean machine
5. Demo: full stack running locally with visible health status in all three frontends

### Incremental Delivery

1. Setup plus Foundational: installable, startable skeleton
2. Add US1: running stack with proven contract pipeline (MVP)
3. Add US2: every change gated by CI
4. Add US3: conventions enforced mechanically, mandatory suite harnesses wired
5. Polish: timed end-to-end validation of the exit criteria

### Notes

- Research.md R1 governs versions: take what the official installers resolve at implementation time; lockfiles are the version record
- The health endpoint doubles as the container health check; the dedicated probe split arrives in Phase 7 (research.md R6)
- Commit after each task or logical group per docs/commit conventions (scope `support` or omit for repo-wide bootstrap work)
