# Data Model: Project Foundation

This phase introduces no domain entities and no domain schema. The data conventions in [docs/data-conventions.md](../../docs/data-conventions.md) begin binding real tables in Phase 1.

## Persistent Data

Only Laravel's framework-default migrations ship, adjusted before the first migrate so [docs/data-conventions.md](../../docs/data-conventions.md) holds from the first schema (research.md R10):

- The `users` table and its related token tables convert to UUIDv7 primary keys via the `HasUuids` trait; no auto-increment columns exist anywhere in the schema.
- The database queue tables (`jobs`, `job_batches`, `failed_jobs`) are removed from the migration set: queues run on Redis through Horizon (system-design.md 15.3), and failed-job storage is introduced in convention-compliant form when Horizon lands in Phase 4.
- No tenant-scoped tables are created, so no RLS policies are due yet (constitution Principle I is not triggered). ADR 007 (staff on the default users table) shapes `users` further in Phase 1, when identity work begins.

## Transient Shapes

### HealthReport (API response, not persisted)

The single contract of this phase. Defined as a laravel-data object, exported to TypeScript, documented in [contracts/v1-health.openapi.yaml](contracts/v1-health.openapi.yaml).

| Field        | Type        | Constraints                                                                                 |
| ------------ | ----------- | ------------------------------------------------------------------------------------------- |
| `status`     | string enum | Always `ok` on a 200 response; a degraded system returns the problem document below instead |
| `checks`     | object      | Keys `database`, `redis`, `storage`; each value is `ok` or `failed`                         |
| `checked_at` | string      | ISO 8601 UTC timestamp of check execution                                                   |

Validation rules: `status` is derived server-side from `checks` (never client-computed, consistent with the API-computes-derived-values posture of docs/api-conventions.md); all three check keys are always present; no additional dependency details (hostnames, versions, latencies) are exposed.

State transitions: none; the report is computed per request.

### Degraded health problem (API 503 response, not persisted)

When any check fails, the endpoint returns HTTP 503 as an RFC 9457 problem document (`application/problem+json`) per docs/api-conventions.md: `type`, `title`, `status`, `detail`, and the stable machine-readable `code` `health.degraded`, with the `checks` map and `checked_at` carried as extension members so operators see exactly which dependency failed. The full shape is in [contracts/v1-health.openapi.yaml](contracts/v1-health.openapi.yaml).
