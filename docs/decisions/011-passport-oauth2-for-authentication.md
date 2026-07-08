# Passport OAuth 2.0 for Authentication

## Context and Problem Statement

The API authenticates two identity populations (staff and customers) across three first-party clients (storefront, admin portal, check-in PWA), with future third-party API access likely. Token semantics must support rotation, revocation, and per-request tenant assertion.

## Considered Options

- Laravel Passport (OAuth 2.0, JWT access tokens, refresh token rotation)
- Laravel Sanctum (first-party SPA tokens)

## Decision Outcome

Chosen option: "Laravel Passport", because standard OAuth 2.0 flows cover refresh rotation with reuse detection, scoped tokens, and future third-party clients without a migration, and short-lived JWTs keep access-token validation stateless. Sanctum is simpler but would need replacing the moment external API consumers arrive.
