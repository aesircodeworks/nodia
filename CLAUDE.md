# Commits

- Use Conventional Commits.
- Scope is one of the bounded contexts: `tenancy`, `identity`, `catalog`, `inventory`, `orders`, `payments`, `checkin`, `reporting`.
- Cross-cutting scopes: `support` (shared infra: outbox, money), `docs`, `ci`, `deps`.
- Omit the scope only for repo-wide changes that fit none of the above.