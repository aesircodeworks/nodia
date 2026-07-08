# PostgreSQL Row-Level Security for Tenant Isolation

## Context and Problem Statement

Tenant data isolation is the platform's hardest compliance and trust requirement. Application-level scoping alone fails open: one missed scope leaks another tenant's data.

## Considered Options

- Shared schema with `tenant_id` on every table, enforced by PostgreSQL row-level security
- Schema per tenant
- Database per tenant
- Application-level scoping only (global Eloquent scopes)

## Decision Outcome

Chosen option: "Shared schema with row-level security", because RLS enforces isolation in the database regardless of application bugs, while a shared schema keeps migrations, connection pooling, and cross-tenant platform operations simple. Schema or database per tenant multiplies operational cost and does not scale to many small tenants.

### Consequences

- Good, because a missed application scope is a performance bug, not a data leak.
- Bad, because every request must run inside a transaction with `SET LOCAL app.tenant_id`, and RLS policies need continuous automated testing.
