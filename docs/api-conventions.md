# API Conventions

Rules every API contract must follow. Feature specs define endpoint behavior; this document defines the shapes and semantics all endpoints share. Architecture rationale lives in [system-design.md](system-design.md) and the ADRs referenced below.

## URLs and Versioning

- All routes are versioned under `/v1`. Breaking changes require a new version prefix; additive changes do not.
- Resources are plural kebab-case nouns: `/v1/ticket-types`, `/v1/promo-codes`.
- Nesting goes at most one level deep (`/v1/events/{event}/ticket-types`). Anything deeper gets its own top-level resource with a filter.
- Route parameters are UUIDv7 strings. Sequential IDs are never exposed (ADR [005](decisions/005-uuidv7-identifiers.md)).

## Requests and Responses

- Request and response shapes are defined by laravel-data objects, which are the single source of truth; TypeScript types are generated from them via typescript-transformer into `packages/api-client`, never hand-written (ADR [013](decisions/013-laravel-data-for-dtos.md)).
- JSON only, `snake_case` keys on the wire.
- Timestamps are ISO 8601 UTC strings. Event-local display times are a client concern (see [ui-conventions.md](ui-conventions.md)).
- Money is always an object pairing integer minor units with a currency: `{"amount": 12500, "currency": "BRL"}`. Amounts never appear without a currency.
- The OpenAPI specification grows from per-feature contracts; no endpoint ships without its contract merged.

## Errors

- All error responses use RFC 9457 problem+json (`application/problem+json`) with `type`, `title`, `status`, `detail`, and a stable machine-readable `code` extension per distinct error condition.
- Validation errors add an `errors` map of field to messages.
- `code` values are stable API contract; clients branch on `code`, never on `detail` text.
- Interactive endpoints fail fast with a typed error; retry guidance, when applicable, is expressed via `Retry-After` (system-design.md section 13).

## Lists: Filtering, Sorting, Pagination

- Admin list endpoints use spatie/laravel-query-builder parameters: `filter[name]=`, `sort=-created_at`, `include=venue`, `fields[events]=id,name` (ADR [014](decisions/014-spatie-utility-packages.md)). Allowed filters, sorts, and includes are explicit per endpoint; unknown values are rejected, not ignored.
- Storefront list endpoints expose purpose-built parameters per feature spec; they do not take query-builder passthrough.
- High-volume collections (orders, tickets, activity log, ledger entries) MUST use cursor pagination (`cursorPaginate`, which requires a deterministic `order by`); bounded collections MAY use page pagination. Responses use Laravel's standard paginator envelope (`data`, `links`, `meta`).

## Authentication and Tenant Context

- Bearer JWT access tokens issued by Passport (ADR [011](decisions/011-passport-oauth2-for-authentication.md)). Access tokens are short-lived (15 minutes) and refresh tokens rotate; lifetimes are configured explicitly via `Passport::tokensExpireIn()` and `Passport::refreshTokensExpireIn()`, never left on Passport defaults.
- Staff requests assert the acting tenant with the `X-Tenant-Id` header, validated against the user's memberships on every request. Client-supplied tenant IDs are never trusted alone (system-design.md section 4.1).
- Storefront and customer requests resolve the tenant from the `Host` header; customer tokens carry the tenant ID and are valid only for it.

## Idempotency and Correlation

- POST endpoints that create payments or refunds require an `Idempotency-Key` header; replays with the same key return the original result (system-design.md section 7.5).
- Every request accepts an `X-Correlation-Id` header (one is generated when absent), echoes it in the response, and propagates it into logs, traces, and outbox events (system-design.md section 9.2).
