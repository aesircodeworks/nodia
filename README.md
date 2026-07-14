# Nodia

Multi-tenant event ticketing platform: a Laravel API, a Next.js storefront, a Next.js admin portal, and a Vite React check-in PWA, sharing typed contracts and design tokens through pnpm workspace packages.

## Prerequisites

- Docker with Compose
- PHP and Composer (PHP version per `apps/api/composer.json`)
- Node.js (version per `.nvmrc`) with corepack enabled, which provides the pnpm version pinned in `package.json`

If a prerequisite is missing, `make setup` fails on the first command that needs it; install the tool it names and rerun. `make setup` is idempotent.

## Setup and Startup

```sh
make setup   # copy .env.example files to .env where missing, install Composer and pnpm dependencies
make up      # start postgres, redis, minio, and the api container via infra/compose
pnpm dev     # start the storefront, admin, and check-in dev servers on the host
```

Other stack commands: `make down` stops the containers keeping data volumes; `make fresh` recreates the stack from scratch (drops volumes, rebuilds the API image).

To run a single frontend instead of all three: `pnpm --filter storefront dev` (or `admin`, `checkin`).

## Default Ports

| Service      | Port                | Where to change it                                          |
| ------------ | ------------------- | ----------------------------------------------------------- |
| API          | 8000                | `API_PORT` in the root `.env`                               |
| PostgreSQL   | 5432                | `DB_PORT` in the root `.env`                                |
| Redis        | 6379                | `REDIS_PORT` in the root `.env`                             |
| MinIO        | 9000 (console 9001) | `MINIO_PORT` / `MINIO_CONSOLE_PORT` in the root `.env`      |
| Storefront   | 3000                | `dev` script in `apps/storefront/package.json`              |
| Admin portal | 3001                | `dev` script in `apps/admin/package.json`                   |
| Check-in PWA | 5173                | `dev` script in `apps/checkin/package.json` (Vite `--port`) |

Container ports come from the root `.env` (copied from `.env.example` by `make setup`); every variable has a default in `infra/compose/docker-compose.yml`, so only the overrides you need go in `.env`. If a port is already occupied, change the variable and rerun `make up`. When you change `API_PORT`, update `NEXT_PUBLIC_API_URL` in `apps/storefront/.env` and `apps/admin/.env` and `VITE_API_URL` in `apps/checkin/.env` to match.

## Verifying the Stack Is Healthy

```sh
curl -s localhost:8000/v1/health
```

A healthy stack returns HTTP 200 with `status: ok` and `database`, `redis`, and `storage` checks all `ok`. When a backing service is down, the endpoint returns HTTP 503 as an RFC 9457 problem document naming the failed check. Each frontend shows the same status on its landing page: [storefront](http://localhost:3000), [admin portal](http://localhost:3001), [check-in](http://localhost:5173).

## Continuous Integration

Every push and pull request runs path-filtered workflows under `.github/workflows/`: one per app (`api.yml`, `storefront.yml`, `admin.yml`, `checkin.yml`) plus `packages.yml` for the shared packages. Each workflow detects whether its paths changed; when they did not, its jobs skip and count as passing. Branch protection on `main` marks all of the following checks required, so any failing job blocks merge:

- API: `API Pint`, `API Larastan`, `API Pest`, `API Architecture`, `API Isolation`, `API Concurrency`, `API Contract Drift`
- Storefront: `Storefront ESLint`, `Storefront Typecheck`, `Storefront Vitest`, `Storefront Build`
- Admin: `Admin ESLint`, `Admin Typecheck`, `Admin Vitest`, `Admin Build`
- Check-in: `Checkin ESLint`, `Checkin Typecheck`, `Checkin Vitest`, `Checkin Build`
- Packages: `Packages ESLint`, `Packages Typecheck`, `Packages Vitest`, `Packages Build`

`API Contract Drift` regenerates the TypeScript contract types with `composer types:generate` and fails if the output differs from what is committed in `packages/api-client/src/generated`.

## Binding Conventions

These documents are binding for all code in this repository:

- [API conventions](docs/api-conventions.md)
- [Data conventions](docs/data-conventions.md)
- [Event conventions](docs/event-conventions.md)
- [UI conventions](docs/ui-conventions.md)

Architecture background lives in [docs/system-design.md](docs/system-design.md), [docs/roadmap.md](docs/roadmap.md), and the ADRs under [docs/decisions](docs/decisions).
