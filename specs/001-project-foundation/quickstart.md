# Quickstart: Project Foundation

Validation guide proving the Phase 0 exit criteria end to end. Command names reference the root task runner targets decided in [research.md](research.md) R4; exact tool versions are recorded in the lockfiles per R1.

## Prerequisites

- Docker (with Compose)
- PHP and Composer (version per `apps/api/composer.json`)
- Node.js LTS (version per `.nvmrc`) and pnpm
- A clone of the repository

## Setup

```sh
cp .env.example .env        # root-level env for compose; per-app .env.example files copied by make setup
make setup                  # install Composer and pnpm dependencies, copy app env files
make up                     # start postgres, redis, minio, api via infra/compose
pnpm dev                    # start storefront, admin, and checkin dev servers (or per app: pnpm --filter storefront dev)
```

Documented default ports and how to override them live in the root README (spec FR-011).

## Validation Scenarios

### 1. Full stack runs locally (spec SC-001, SC-002; User Story 1)

1. `make up` completes with all containers healthy.
2. `curl -s localhost:<api-port>/v1/health` returns HTTP 200 with body matching [contracts/v1-health.openapi.yaml](contracts/v1-health.openapi.yaml): `status: ok`, all three checks `ok`.
3. Open the storefront, admin, and check-in apps in a browser; each displays a healthy API status sourced from the health endpoint via `packages/api-client`.
4. API container logs show one structured JSON line per request including the correlation ID; sending `X-Correlation-Id` on the curl request echoes it in the response header.

Expected wall-clock time from fresh clone through step 3, with prerequisites installed: under 15 minutes.

### 2. Health endpoint reports degraded dependencies (spec edge case; User Story 1)

1. `docker compose -f infra/compose/docker-compose.yml stop redis`
2. `curl -s -w '%{http_code}' localhost:<api-port>/v1/health` returns HTTP 503 within the check timeout (no hang); the body is an `application/problem+json` document with `code: health.degraded` and extension member `checks.redis: failed`, matching the contract.
3. Restart Redis; the endpoint returns to 200.

### 3. CI validates every change (spec SC-003, SC-004; User Story 2)

1. Push a branch with a trivial passing change; every affected app's workflow runs and reports green on the PR.
2. Push a branch introducing (a) a Pint style violation, (b) a Larastan error, or (c) a failing Pest or Vitest test; the corresponding job fails and branch protection blocks the merge.
3. Push a change touching only `apps/storefront`; API jobs are skipped by path filters while storefront jobs run.

### 4. Contract pipeline holds (constitution Principle VI)

1. Modify the health Data object's TypeScript-exported shape without regenerating; CI's drift gate fails.
2. Run `composer types:generate`; the diff in `packages/api-client/src/generated/` matches the change and CI passes once committed.
3. `docs/openapi/openapi.yaml` contains the `/v1/health` path merged from this feature's contract.

### 5. Conventions are enforceable defaults (spec SC-005; User Story 3)

1. All four convention docs exist: `docs/api-conventions.md`, `docs/data-conventions.md`, `docs/event-conventions.md`, `docs/ui-conventions.md`, and are linked from the repository README.
2. The Pest architecture test suite runs (green on the empty skeleton) as a required CI job, ready to fail on the first cross-context import in Phase 1.
3. A hardcoded user-facing string literal or hardcoded color in a frontend health display fails lint per the rules wired for docs/ui-conventions.md.
