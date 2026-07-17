<!--
Sync Impact Report
- Version change: template (unfilled) -> 1.0.0
- Modified principles: n/a (initial ratification)
- Added sections:
  - Core Principles (I through X)
  - Cross-Cutting Requirements
  - Development Workflow and Quality Gates
  - Governance
- Removed sections: none (template placeholders replaced)
- Templates:
  - .specify/templates/plan-template.md: updated (concrete Constitution Check gates)
  - .specify/templates/spec-template.md: no change required (technology-agnostic; unaffected)
  - .specify/templates/tasks-template.md: updated (tests note aligned with Principle X)
  - .specify/templates/checklist-template.md: no change required
- Follow-up TODOs: none
-->

# Nodia Constitution

Nodia is a white-label, multi-tenant SaaS event ticketing platform: a Laravel modular monolith (bounded contexts: tenancy, identity, catalog, inventory, orders, payments, checkin, reporting) on Octane/FrankenPHP, Next.js frontends, PostgreSQL with row-level security, and a transactional outbox with Redis/Horizon delivery. This constitution states the non-negotiable principles every feature spec, plan, and implementation is checked against.

## Core Principles

### I. Tenant Isolation Enforced by the Database

Tenant isolation is a database guarantee, never a convention. Every tenant-scoped table MUST have a non-null `tenant_id` and MUST ship its RLS policy in the same migration that creates the table; a table without its policy does not merge, and the tenant isolation test suite is the gate. Platform-scope rows in shared tables MUST use the sentinel platform tenant, never NULL. Tenant state MUST never live in static or singleton state; under Octane's long-lived workers it exists only in the request-scoped container and the transaction-local `app.tenant_id` setting.

Rationale: application-level scoping fails silently under one missed global scope; RLS fails closed.

### II. Inventory Never Oversells

For every ticket type, `sold + held <= quantity`; for every seat, at most one active hold or ticket. State transitions that guard an invariant (inventory counters, seat status, order status, promo code usage) MUST be atomic conditional UPDATEs where the guard is evaluated in the same statement that mutates the row, verified by affected-row count. Read-then-write transitions are forbidden on these paths. Redis serves availability display only and MUST never be treated as the source of truth.

Rationale: concurrent checkout is where ticketing platforms fail; the invariant must hold under any interleaving, not just the tested ones.

### III. Money Is Exact and Auditable

Monetary values are integer minor units in `*_amount` columns, each paired with a `currency` code on the same row. Floats and decimals for money are forbidden; an amount MUST never exist without a currency. The ledger is append-only: no UPDATE or DELETE on ledger entries, corrections are new entries. Clients MUST never compute totals from other amounts; totals come from the API.

Rationale: the ledger is the source of truth for tenant balances and payouts; exactness and auditability are legal obligations, not preferences.

### IV. Domain Events Through the Transactional Outbox

Every domain event MUST be recorded in the outbox in the same database transaction as the state change it describes, without exception. Event names are past-tense statements of fact owned by the emitting context. Payload evolution is additive-only: new optional fields are allowed, existing fields never change meaning or type; a breaking change is a new event type, not a version field. Consumers MUST be idempotent by event ID and MUST never write to another context's tables in response to an event.

Rationale: retained outbox rows are replayed; every consumer must handle every historical shape, and dual-write bugs are unrecoverable after the fact.

### V. Hard Bounded Context Boundaries

Contexts communicate only through Actions (passing and receiving Data objects) and domain events. A context MUST never import another context's Models or query its tables. Architecture tests enforce this boundary; a violation is a failing build, not a review comment.

Rationale: the boundaries are the seam for every future extraction and scaling option; they only stay real if the build enforces them.

### VI. Contracts Are Generated, Not Hand-Written

laravel-data objects are the single source of truth for API request and response shapes. TypeScript types are generated from them into `packages/api-client` and MUST never be hand-written. No endpoint ships without its contract merged into the OpenAPI specification.

Rationale: hand-maintained parallel type definitions drift; generated contracts make drift a build failure.

### VII. Framework-Native Inside Contexts

Inside a context, Laravel's conventions are the architecture: Eloquent models used directly, single-purpose Action classes as the use-case layer, form requests for validation, policies for authorization, jobs for async consumers. Repository patterns and Domain/Application/Infrastructure layering are forbidden. Custom code is spent only where the domain demands it (per ADR 002).

Rationale: hard boundaries justify boring internals; abstraction layers inside a context add cost without adding isolation the boundary does not already provide.

### VIII. Security and Compliance Floor

Card data MUST never be stored, transmitted through, or rendered by platform systems (PCI DSS SAQ-A scope). Identifiers are UUIDv7 everywhere; sequential IDs are never exposed externally. Authorization checks evaluate capabilities plus tenant context, never role names. All staff actions, cross-tenant platform operations, and financial mutations are recorded in the append-only activity log; log rows are never updated or deleted inside the application. Customer PII lives in clearly bounded columns and supports anonymize-in-place erasure (GDPR/LGPD).

Rationale: these are compliance obligations with no cheaper retrofit path; the floor must hold from the first feature.

### IX. Interactive Paths Fail Fast, Async Paths Retry

No user-interactive request ever waits on the async pipeline or on automatic retries; interactive endpoints fail fast with typed errors. Automatic retries with backoff and dead-letter handling apply only to non-interactive work. Every async loss mode MUST have a scheduled sweeper backstop (hold expiry, payment expiry, outbox delivery) so no single missed message strands state. Payment and refund creation MUST use idempotency keys so retries are safe against double charges.

Rationale: buyers under a countdown need immediate feedback; money movement needs guaranteed eventual consistency. Conflating the two breaks both.

### X. Testing Gates Define Done

A feature is not complete until it is covered by the mandatory suites: architecture tests (context boundaries), tenant isolation tests (cross-tenant reads and writes attempted for every endpoint under RLS), and concurrency tests (parallel checkout simulations asserting no oversell and no seat double-booking, run against real PostgreSQL). Invariant-bearing code (inventory accounting, ledger, state machines) MUST be written test-first. Any gate failing blocks the merge.

Rationale: the invariants in Principles I through III cannot be verified by review; only executable checks hold under change.

## Cross-Cutting Requirements

- **Observability**: every feature emits structured logs, metrics, and traces via OpenTelemetry. A correlation ID originates at the HTTP request and MUST propagate into logs, traces, and outbox events; financial mutations MUST be traceable end-to-end by correlation ID.
- **Internationalization and accessibility**: no string literals in components; every user-facing string goes through the i18n layer. WCAG 2.1 AA is the baseline. Components MUST render correctly under any tenant token set; correctness only under default tokens is a bug.
- **Runtime discipline (orchestrator-agnostic)**: stateless processes with no local state beyond scratch, configuration exclusively via environment variables, logs to stdout as structured JSON, graceful SIGTERM drain, backing services addressed by URL and never assumed co-located.
- **Migrations**: additive once a feature merges; new migrations for changes, no editing of merged ones. Destructive operations require a deprecation window: code stops reading first, a later migration drops. Migrations MUST run inside the RLS regime and never disable policies to pass.

## Development Workflow and Quality Gates

- **Binding convention documents**: `docs/api-conventions.md`, `docs/data-conventions.md`, `docs/event-conventions.md`, and `docs/ui-conventions.md` are the binding authorities for their domains. Specs and plans cite them rather than restating their rules; a conflict between an artifact and a convention doc is resolved in the doc's favor or by amending the doc.
- **ADR discipline**: architectural decisions are recorded as MADR-minimal ADRs in `docs/decisions/`. Changing a constitution-level choice requires a superseding ADR before the change lands.
- **Merge gates**: Laravel Pint and Larastan pass, plus the mandatory suites of Principle X. No gate is waivable per feature.
- **Scope of this document**: endpoint shape details, pagination rules, specific package choices, and retry budgets live in the convention docs and ADRs, not here. Duplicating them would create two sources of truth.

## Governance

This constitution supersedes all other practices. Every spec, plan, and PR is checked against it; the Constitution Check in each feature plan MUST pass before design begins and again after design completes. Complexity that violates a principle MUST be justified in the plan's Complexity Tracking table or removed.

Amendments require a documented rationale, a superseding ADR when the change touches an architectural choice, an update to this document, and propagation to the dependent templates in `.specify/templates/`. Versioning follows semantic versioning: MAJOR for backward-incompatible removals or redefinitions of principles, MINOR for new principles or materially expanded guidance, PATCH for clarifications and wording.

Compliance is reviewed continuously through the merge gates (Principle X and the quality gates above) and periodically whenever a convention doc or ADR changes, at which point this document is re-validated against them.

**Version**: 1.0.0 | **Ratified**: 2026-07-04 | **Last Amended**: 2026-07-04
